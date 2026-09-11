<?php
session_start();

require_once "../includes/db.php";
require_once "../includes/gemini.php";

/* =========================================================
   TRAINER AUTHENTICATION
========================================================= */

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "trainer") {
    header("Location: ../employee-login.php");
    exit;
}

$trainer_id = (int) $_SESSION["user_id"];


/* =========================================================
   PDF STRING DECODER
========================================================= */

function decodePdfString($text)
{
    // Decode common PDF escape sequences
    $text = str_replace(
        array(
            "\\(",
            "\\)",
            "\\\\",
            "\\n",
            "\\r",
            "\\t",
            "\\b",
            "\\f"
        ),
        array(
            "(",
            ")",
            "\\",
            "\n",
            "\r",
            "\t",
            "\b",
            "\f"
        ),
        $text
    );

    // Decode octal escape sequences such as \141
    $text = preg_replace_callback(
        '/\\\\([0-7]{1,3})/',
        function ($matches) {
            return chr(octdec($matches[1]));
        },
        $text
    );

    return $text;
}


/* =========================================================
   ASCII85 DECODER
   Required for PDFs using ASCII85Decode
========================================================= */

function ascii85Decode($input)
{
    // Remove whitespace
    $input = preg_replace('/\s+/', '', $input);

    // Remove ASCII85 start marker
    if (substr($input, 0, 2) === '<~') {
        $input = substr($input, 2);
    }

    // Remove ASCII85 end marker
    if (substr($input, -2) === '~>') {
        $input = substr($input, 0, -2);
    }

    $output = "";
    $group = "";

    $length = strlen($input);

    for ($i = 0; $i < $length; $i++) {

        $char = $input[$i];

        /*
         * 'z' represents four zero bytes.
         */
        if ($char === 'z' && $group === '') {
            $output .= "\x00\x00\x00\x00";
            continue;
        }

        $value = ord($char) - 33;

        /*
         * Valid ASCII85 characters are ! through u.
         */
        if ($value < 0 || $value > 84) {
            continue;
        }

        $group .= $char;

        /*
         * Five ASCII85 characters = four bytes.
         */
        if (strlen($group) === 5) {

            $number = 0;

            for ($j = 0; $j < 5; $j++) {

                $number =
                    ($number * 85) +
                    (ord($group[$j]) - 33);
            }

            $output .= pack("N", $number);

            $group = "";
        }
    }

    /*
     * Decode final partial group.
     */
    if ($group !== "") {

        $originalLength = strlen($group);

        while (strlen($group) < 5) {
            $group .= "u";
        }

        $number = 0;

        for ($j = 0; $j < 5; $j++) {

            $number =
                ($number * 85) +
                (ord($group[$j]) - 33);
        }

        $bytes = pack("N", $number);

        /*
         * N ASCII85 characters produce N-1 bytes.
         */
        $output .= substr(
            $bytes,
            0,
            $originalLength - 1
        );
    }

    return $output;
}


/* =========================================================
   PDF TEXT EXTRACTION
   Supports:
   - Normal PDF streams
   - FlateDecode
   - ASCII85Decode + FlateDecode
   - Tj text
   - TJ text arrays
   - Hexadecimal text
   No Poppler required.
========================================================= */

function extractPdfText($filePath)
{
    $pdf = @file_get_contents($filePath);

    if ($pdf === false || $pdf === "") {
        return "";
    }

    $allText = "";

    /*
     * Find all PDF streams.
     */
    preg_match_all(
        '/stream\r?\n(.*?)endstream/s',
        $pdf,
        $streamMatches
    );

    foreach ($streamMatches[1] as $stream) {

        $stream = trim($stream);

        $decodedStream = false;


        /* -------------------------------------------------
           Try normal zlib compression
        ------------------------------------------------- */

        $result = @gzuncompress($stream);

        if ($result !== false) {
            $decodedStream = $result;
        }


        /* -------------------------------------------------
           Try raw DEFLATE
        ------------------------------------------------- */

        if ($decodedStream === false) {

            $result = @gzinflate($stream);

            if ($result !== false) {
                $decodedStream = $result;
            }
        }


        /* -------------------------------------------------
           ASCII85Decode → FlateDecode
        ------------------------------------------------- */

        if ($decodedStream === false) {

            $ascii85 = ascii85Decode($stream);

            if ($ascii85 !== "") {

                $result = @gzuncompress($ascii85);

                if ($result !== false) {
                    $decodedStream = $result;
                }
            }
        }


        /* -------------------------------------------------
           ASCII85Decode → raw DEFLATE
        ------------------------------------------------- */

        if ($decodedStream === false) {

            $ascii85 = ascii85Decode($stream);

            if ($ascii85 !== "") {

                $result = @gzinflate($ascii85);

                if ($result !== false) {
                    $decodedStream = $result;
                }
            }
        }


        /* -------------------------------------------------
           Uncompressed PDF stream
        ------------------------------------------------- */

        if ($decodedStream === false) {
            $decodedStream = $stream;
        }


        /* -------------------------------------------------
           Find BT ... ET text blocks
        ------------------------------------------------- */

        preg_match_all(
            '/BT(.*?)ET/s',
            $decodedStream,
            $textBlocks
        );


        foreach ($textBlocks[1] as $block) {


            /* =============================================
               NORMAL PDF TEXT

               (Hello world) Tj
            ============================================= */

            preg_match_all(
                '/\(((?:\\\\.|[^\\\\)])*)\)\s*Tj/s',
                $block,
                $matches
            );

            foreach ($matches[1] as $match) {

                $allText .=
                    decodePdfString($match) .
                    " ";
            }


            /* =============================================
               PDF TEXT ARRAYS

               [(Hello) (world)] TJ
            ============================================= */

            preg_match_all(
                '/\[((?:.|\R)*?)\]\s*TJ/s',
                $block,
                $arrayMatches
            );

            foreach ($arrayMatches[1] as $array) {

                preg_match_all(
                    '/\(((?:\\\\.|[^\\\\)])*)\)/s',
                    $array,
                    $parts
                );

                foreach ($parts[1] as $part) {

                    $allText .=
                        decodePdfString($part) .
                        " ";
                }
            }


            /* =============================================
               HEXADECIMAL PDF TEXT

               <48656C6C6F> Tj
            ============================================= */

            preg_match_all(
                '/<([0-9A-Fa-f]+)>\s*Tj/s',
                $block,
                $hexMatches
            );

            foreach ($hexMatches[1] as $hex) {

                if (strlen($hex) % 2 === 0) {

                    $binary = @hex2bin($hex);

                    if ($binary !== false) {
                        $allText .= $binary . " ";
                    }
                }
            }
        }
    }


    /* =====================================================
       CLEAN EXTRACTED TEXT
    ===================================================== */

    $allText = preg_replace(
        '/[^\P{C}\n\t]+/u',
        ' ',
        $allText
    );

    $allText = preg_replace(
        '/[ \t]+/',
        ' ',
        $allText
    );

    $allText = preg_replace(
        '/\n{3,}/',
        "\n\n",
        $allText
    );

    return trim($allText);
}


/* =========================================================
   GET MATERIAL ID
========================================================= */

$material_id = isset($_GET["material_id"])
    ? (int) $_GET["material_id"]
    : 0;

if ($material_id <= 0) {
    die("Invalid learning material selected.");
}


/* =========================================================
   LOAD MATERIAL
   ALSO VERIFY TRAINER OWNERSHIP
========================================================= */

$stmt = $conn->prepare("
    SELECT
        lm.material_id,
        lm.course_id,
        lm.title,
        lm.file_path,
        lm.material_type,
        c.course_name,
        c.created_by,
        c.skill_id
    FROM learning_materials lm
    INNER JOIN courses c
        ON lm.course_id = c.course_id
    WHERE lm.material_id = ?
      AND c.created_by = ?
    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $material_id,
    $trainer_id
);

$stmt->execute();

$result = $stmt->get_result();

$material = $result->fetch_assoc();

$stmt->close();


if (!$material) {
    die(
        "Learning material not found or you do not have permission to use it."
    );
}


/* =========================================================
   LOAD SKILL NAME
========================================================= */

$skill_name = "General Skill";

if (!empty($material["skill_id"])) {

    $skill_id = (int) $material["skill_id"];

    $stmt = $conn->prepare("
        SELECT skill_name
        FROM skills
        WHERE skill_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $skill_id
    );

    $stmt->execute();

    $skillResult = $stmt->get_result();

    $skillRow = $skillResult->fetch_assoc();

    if ($skillRow) {
        $skill_name = $skillRow["skill_name"];
    }

    $stmt->close();
}


/* =========================================================
   VARIABLES
========================================================= */

$error = "";

$generatedQuestions = array();

$assessment_id = 0;


/* =========================================================
   GENERATE QUIZ
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    /* -----------------------------------------------------
       FIND ABSOLUTE FILE PATH
    ----------------------------------------------------- */

    $absolutePath = realpath(
        "../" . $material["file_path"]
    );


    if (
        $absolutePath === false ||
        !file_exists($absolutePath)
    ) {

        $error =
            "The learning material file could not be found.";
    }


    /* -----------------------------------------------------
       FILE SIZE
    ----------------------------------------------------- */

    if (
        $error === "" &&
        filesize($absolutePath) > 20 * 1024 * 1024
    ) {

        $error =
            "The material is larger than the allowed 20 MB limit.";
    }


    /* -----------------------------------------------------
       FILE EXTENSION
    ----------------------------------------------------- */

    $extension = strtolower(
        pathinfo(
            $absolutePath,
            PATHINFO_EXTENSION
        )
    );


    /* -----------------------------------------------------
       EXTRACT MATERIAL TEXT
    ----------------------------------------------------- */

    $materialText = "";


    if ($error === "") {

        if ($extension === "pdf") {

            /*
             * Fast local PDF extraction.
             */
            $materialText =
                extractPdfText($absolutePath);

        } elseif ($extension === "txt") {

            $materialText =
                @file_get_contents($absolutePath);

            if ($materialText === false) {
                $materialText = "";
            }

        } else {

            $error =
                "For fast AI quiz generation, only PDF and TXT materials are currently supported.";
        }
    }


    /* -----------------------------------------------------
       CHECK EXTRACTED TEXT
    ----------------------------------------------------- */

    if (
        $error === "" &&
        strlen(trim($materialText)) < 100
    ) {

        $error =
            "Could not extract enough readable text from this PDF. " .
            "Please use a text-based PDF or TXT material.";
    }


    /* -----------------------------------------------------
       LIMIT TEXT SENT TO GEMINI
    ----------------------------------------------------- */

    if ($error === "") {

        /*
         * Keep request reasonably small.
         */
        if (strlen($materialText) > 25000) {

            $materialText =
                substr(
                    $materialText,
                    0,
                    25000
                );
        }


        /* =================================================
           GEMINI PROMPT
        ================================================= */

        $prompt = "

You are an AI quiz generator for an employee learning platform.

Generate exactly 10 multiple-choice questions from the learning material provided below.

COURSE:
" . $material["course_name"] . "

SKILL:
" . $skill_name . "

IMPORTANT RULES:

1. Use ONLY information contained in the learning material.
2. Do not invent facts.
3. Do not use outside knowledge.
4. Generate exactly 10 questions.
5. Each question must have exactly four options.
6. Options must be A, B, C and D.
7. Only one option can be correct.
8. The correct_answer must contain only A, B, C or D.
9. Questions should test understanding of the material.
10. Avoid duplicate questions.
11. Return ONLY valid JSON.
12. Do not use Markdown.
13. Do not put JSON inside code fences.

REQUIRED JSON FORMAT:

[
  {
    \"question_text\": \"Question here\",
    \"option_a\": \"Option A\",
    \"option_b\": \"Option B\",
    \"option_c\": \"Option C\",
    \"option_d\": \"Option D\",
    \"correct_answer\": \"A\"
  }
]

LEARNING MATERIAL:

" . $materialText;


        /* =================================================
           CALL GEMINI
        ================================================= */

        try {

            /*
             * IMPORTANT:
             *
             * We are sending extracted TEXT,
             * NOT the complete PDF as Base64.
             */
            $response = askGemini($prompt);


            if (!$response) {

                $error =
                    "Gemini returned an empty response.";

            } else {

                $response =
                    trim($response);


                /*
                 * Remove Markdown JSON fences
                 * if Gemini accidentally adds them.
                 */
                $response = preg_replace(
                    '/^```(?:json)?\s*/i',
                    '',
                    $response
                );

                $response = preg_replace(
                    '/\s*```$/',
                    '',
                    $response
                );

                $response =
                    trim($response);


                /*
                 * Find JSON array.
                 */
                $jsonStart =
                    strpos(
                        $response,
                        "["
                    );

                $jsonEnd =
                    strrpos(
                        $response,
                        "]"
                    );


                if (
                    $jsonStart !== false &&
                    $jsonEnd !== false &&
                    $jsonEnd > $jsonStart
                ) {

                    $json =
                        substr(
                            $response,
                            $jsonStart,
                            $jsonEnd -
                            $jsonStart +
                            1
                        );


                    $generatedQuestions =
                        json_decode(
                            $json,
                            true
                        );

                } else {

                    $generatedQuestions =
                        null;
                }


                /*
                 * Validate JSON.
                 */
                if (
                    !is_array(
                        $generatedQuestions
                    )
                ) {

                    $generatedQuestions =
                        array();

                    $error =
                        "Gemini returned an invalid quiz format.";
                }
            }

        } catch (Throwable $e) {

            $error =
                "AI quiz generation failed: " .
                $e->getMessage();
        }
    }


    /* =====================================================
       VALIDATE QUESTIONS
    ===================================================== */

    if ($error === "") {

        $validQuestions = array();


        foreach (
            $generatedQuestions
            as $question
        ) {

            if (!is_array($question)) {
                continue;
            }


            $questionText =
                trim(
                    $question["question_text"] ?? ""
                );


            $optionA =
                trim(
                    $question["option_a"] ?? ""
                );


            $optionB =
                trim(
                    $question["option_b"] ?? ""
                );


            $optionC =
                trim(
                    $question["option_c"] ?? ""
                );


            $optionD =
                trim(
                    $question["option_d"] ?? ""
                );


            $correctAnswer =
                strtoupper(
                    trim(
                        $question["correct_answer"] ?? ""
                    )
                );


            /*
             * Make sure all fields exist.
             */
            if (
                $questionText === "" ||
                $optionA === "" ||
                $optionB === "" ||
                $optionC === "" ||
                $optionD === "" ||
                !in_array(
                    $correctAnswer,
                    array(
                        "A",
                        "B",
                        "C",
                        "D"
                    ),
                    true
                )
            ) {

                continue;
            }


            $validQuestions[] = array(

                "question_text" =>
                    $questionText,

                "option_a" =>
                    $optionA,

                "option_b" =>
                    $optionB,

                "option_c" =>
                    $optionC,

                "option_d" =>
                    $optionD,

                "correct_answer" =>
                    $correctAnswer
            );
        }


        $generatedQuestions =
            $validQuestions;


        /*
         * Require exactly 10 questions.
         */
        if (
            count(
                $generatedQuestions
            ) !== 10
        ) {

            $error =
                "AI generated " .
                count(
                    $generatedQuestions
                ) .
                " valid questions instead of exactly 10.";
        }
    }


    /* =====================================================
       SAVE QUIZ TO DATABASE
    ===================================================== */

    if ($error === "") {

        try {

            $conn->begin_transaction();


            /* ------------------------------------------------
               CREATE ASSESSMENT
            ------------------------------------------------ */

            $assessmentName =
                "AI Quiz - " .
                $material["title"];


            $description =
                "AI-generated assessment based on the learning material: " .
                $material["title"];


            $skill_id =
                (int) $material["skill_id"];


            $stmt = $conn->prepare("
                INSERT INTO assessments
                (
                    assessment_name,
                    description,
                    skill_id
                )
                VALUES (?, ?, ?)
            ");


            $stmt->bind_param(
                "ssi",
                $assessmentName,
                $description,
                $skill_id
            );


            $stmt->execute();


            $assessment_id =
                $conn->insert_id;


            $stmt->close();


            /* ------------------------------------------------
               INSERT 10 QUESTIONS
            ------------------------------------------------ */

            $stmt = $conn->prepare("
                INSERT INTO questions
                (
                    assessment_id,
                    question_text,
                    option_a,
                    option_b,
                    option_c,
                    option_d,
                    correct_answer
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");


            foreach (
                $generatedQuestions
                as $question
            ) {

                $stmt->bind_param(
                    "issssss",
                    $assessment_id,
                    $question["question_text"],
                    $question["option_a"],
                    $question["option_b"],
                    $question["option_c"],
                    $question["option_d"],
                    $question["correct_answer"]
                );


                $stmt->execute();
            }


            $stmt->close();


            /*
             * Everything succeeded.
             */
            $conn->commit();

        } catch (Throwable $e) {

            /*
             * Undo partial database changes.
             */
            $conn->rollback();


            $error =
                "Quiz was generated but could not be saved: " .
                $e->getMessage();


            $assessment_id = 0;
        }
    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        AI Quiz Generator - OUTLIERS
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>


<body class="bg-light">


<div class="container py-5">


    <div class="row justify-content-center">


        <div class="col-lg-10">


            <!-- ==========================================
                 PAGE HEADER
            =========================================== -->

            <div
                class="d-flex justify-content-between align-items-center mb-4"
            >

                <div>

                    <h2 class="mb-1">
                        AI Quiz Generator
                    </h2>

                    <p class="text-muted mb-0">
                        Generate 10 MCQs from your learning material
                    </p>

                </div>


                <a
                    href="materials.php?course_id=<?php echo (int) $material["course_id"]; ?>"
                    class="btn btn-outline-secondary"
                >
                    Back to Materials
                </a>

            </div>


            <!-- ==========================================
                 MATERIAL INFORMATION
            =========================================== -->

            <div class="card shadow-sm mb-4">


                <div class="card-body">


                    <h5 class="card-title">

                        <?php
                        echo htmlspecialchars(
                            $material["title"]
                        );
                        ?>

                    </h5>


                    <p class="mb-1">

                        <strong>
                            Course:
                        </strong>

                        <?php
                        echo htmlspecialchars(
                            $material["course_name"]
                        );
                        ?>

                    </p>


                    <p class="mb-1">

                        <strong>
                            Skill:
                        </strong>

                        <?php
                        echo htmlspecialchars(
                            $skill_name
                        );
                        ?>

                    </p>


                    <p class="mb-0">

                        <strong>
                            File type:
                        </strong>

                        <?php
                        echo strtoupper(
                            htmlspecialchars(
                                $material["material_type"]
                            )
                        );
                        ?>

                    </p>


                </div>

            </div>


            <!-- ==========================================
                 FAST GENERATION INFORMATION
            =========================================== -->

            <div class="alert alert-info">


                <strong>
                    Fast AI Generation:
                </strong>


                The PDF text is extracted locally and only
                the text is sent to Gemini.


                <br>


                <small class="text-muted">

                    The complete PDF is not uploaded to Gemini
                    as Base64 data.

                    No Poppler installation is required.

                </small>


            </div>


            <!-- ==========================================
                 ERROR MESSAGE
            =========================================== -->

            <?php if ($error !== ""): ?>


                <div class="alert alert-danger">


                    <strong>
                        Error:
                    </strong>


                    <?php
                    echo htmlspecialchars(
                        $error
                    );
                    ?>


                </div>


            <?php endif; ?>


            <!-- ==========================================
                 SUCCESS MESSAGE
            =========================================== -->

            <?php
            if (
                $assessment_id > 0 &&
                empty($error)
            ):
            ?>


                <div class="alert alert-success">


                    <h5 class="alert-heading">

                        Quiz Generated Successfully!

                    </h5>


                    <p class="mb-3">

                        10 MCQs were generated
                        and saved successfully.

                    </p>


                    <a 
                        href="preview-quiz.php?assessment_id=<?php echo (int) $assessment_id; ?>" 
                        class="btn btn-success" 
                        target="_blank" 
                  >
                         Preview Quiz
                </a>


                </div>


            <?php endif; ?>


            <!-- ==========================================
                 GENERATE QUIZ FORM
            =========================================== -->

            <?php
            if ($assessment_id === 0):
            ?>


                <div class="card shadow-sm mb-4">


                    <div class="card-body">


                        <h5 class="mb-3">

                            Generate Quiz

                        </h5>


                        <p class="text-muted">

                            Gemini will read the learning
                            material and create exactly
                            10 multiple-choice questions.

                        </p>


                        <form
                            method="POST"
                            id="generateQuizForm"
                        >


                            <button
                                type="submit"
                                class="btn btn-primary"
                                id="generateButton"
                            >

                                Generate AI Quiz

                            </button>


                        </form>


                    </div>


                </div>


            <?php endif; ?>


            <!-- ==========================================
                 GENERATED QUESTIONS PREVIEW
            =========================================== -->

            <?php
            if (
                !empty($generatedQuestions) &&
                empty($error)
            ):
            ?>


                <div class="card shadow-sm">


                    <div class="card-header">


                        <h5 class="mb-0">

                            Generated Questions

                        </h5>


                    </div>


                    <div class="card-body">


                        <?php
                        foreach (
                            $generatedQuestions
                            as $index => $question
                        ):
                        ?>


                            <div class="mb-4">


                                <h6>

                                    <?php
                                    echo ($index + 1) . ". ";
                                    ?>


                                    <?php
                                    echo htmlspecialchars(
                                        $question["question_text"]
                                    );
                                    ?>


                                </h6>


                                <div class="ms-3">


                                    <p class="mb-1">

                                        <strong>
                                            A.
                                        </strong>

                                        <?php
                                        echo htmlspecialchars(
                                            $question["option_a"]
                                        );
                                        ?>

                                    </p>


                                    <p class="mb-1">

                                        <strong>
                                            B.
                                        </strong>

                                        <?php
                                        echo htmlspecialchars(
                                            $question["option_b"]
                                        );
                                        ?>

                                    </p>


                                    <p class="mb-1">

                                        <strong>
                                            C.
                                        </strong>

                                        <?php
                                        echo htmlspecialchars(
                                            $question["option_c"]
                                        );
                                        ?>

                                    </p>


                                    <p class="mb-1">

                                        <strong>
                                            D.
                                        </strong>

                                        <?php
                                        echo htmlspecialchars(
                                            $question["option_d"]
                                        );
                                        ?>

                                    </p>


                                    <p class="mt-2 text-success">


                                        <strong>
                                            Correct Answer:
                                        </strong>


                                        <?php
                                        echo htmlspecialchars(
                                            $question["correct_answer"]
                                        );
                                        ?>


                                    </p>


                                </div>


                            </div>


                            <?php
                            if (
                                $index <
                                count($generatedQuestions) - 1
                            ):
                            ?>


                                <hr>


                            <?php endif; ?>


                        <?php endforeach; ?>


                    </div>


                </div>


            <?php endif; ?>


        </div>

    </div>

</div>


<script>

/*
 * Disable the button after submission
 * so the trainer cannot accidentally
 * generate the same quiz multiple times.
 */

const quizForm =
    document.getElementById("generateQuizForm");


if (quizForm) {

    quizForm.addEventListener(
        "submit",
        function () {

            const button =
                document.getElementById(
                    "generateButton"
                );


            if (button) {

                button.disabled = true;


                button.innerHTML =
                    "Generating Quiz... Please wait...";

            }

        }
    );

}

</script>


</body>

</html>