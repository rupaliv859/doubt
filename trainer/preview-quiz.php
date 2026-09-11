<?php

session_start();

require_once "../includes/db.php";

/* =========================================================
   TRAINER AUTHENTICATION
========================================================= */

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "trainer"
) {
    header("Location: ../employee-login.php");
    exit;
}


/* =========================================================
   GET ASSESSMENT ID
========================================================= */

$assessment_id = isset($_GET["assessment_id"])
    ? (int) $_GET["assessment_id"]
    : 0;

if ($assessment_id <= 0) {
    die("Invalid assessment selected.");
}


/* =========================================================
   LOAD ASSESSMENT
========================================================= */

$stmt = $conn->prepare("
    SELECT
        a.assessment_id,
        a.assessment_name,
        a.description,
        a.skill_id,
        s.skill_name
    FROM assessments a
    LEFT JOIN skills s
        ON a.skill_id = s.skill_id
    WHERE a.assessment_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "i",
    $assessment_id
);

$stmt->execute();

$result = $stmt->get_result();

$assessment = $result->fetch_assoc();

$stmt->close();


if (!$assessment) {
    die("Assessment not found.");
}


/* =========================================================
   LOAD QUESTIONS
========================================================= */

$stmt = $conn->prepare("
    SELECT
        question_id,
        question_text,
        option_a,
        option_b,
        option_c,
        option_d,
        correct_answer
    FROM questions
    WHERE assessment_id = ?
    ORDER BY question_id ASC
");

$stmt->bind_param(
    "i",
    $assessment_id
);

$stmt->execute();

$result = $stmt->get_result();

$questions = array();

while ($row = $result->fetch_assoc()) {

    $questions[] = $row;
}

$stmt->close();

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
        Preview Quiz - OUTLIERS
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>


<body class="bg-light">


<div class="container py-5">


    <div class="row justify-content-center">


        <div class="col-lg-9">


            <!-- =================================================
                 HEADER
            ================================================== -->

            <div class="card shadow-sm mb-4">

                <div class="card-body p-4">

                    <h2 class="mb-2">

                        <?php

                        echo htmlspecialchars(
                            $assessment["assessment_name"]
                        );

                        ?>

                    </h2>


                    <p class="text-muted">

                        <?php

                        echo htmlspecialchars(
                            $assessment["description"]
                        );

                        ?>

                    </p>


                    <?php

                    if (
                        !empty(
                            $assessment["skill_name"]
                        )
                    ):

                    ?>

                        <span class="badge bg-info text-dark">

                            Skill:

                            <?php

                            echo htmlspecialchars(
                                $assessment["skill_name"]
                            );

                            ?>

                        </span>

                    <?php endif; ?>


                    <span class="badge bg-primary">

                        <?php

                        echo count($questions);

                        ?>

                        Questions

                    </span>

                </div>

            </div>


            <!-- =================================================
                 TRAINER NOTICE
            ================================================== -->

            <div class="alert alert-warning">

                <strong>
                    Trainer Preview
                </strong>

                <br>

                This page is only for previewing the
                AI-generated quiz.

                <br>

                Employees will take the actual assessment
                from the employee assessment section.

            </div>


            <!-- =================================================
                 QUESTIONS
            ================================================== -->

            <?php

            if (
                count($questions) === 0
            ):

            ?>

                <div class="alert alert-danger">

                    No questions were found for this assessment.

                </div>


            <?php else: ?>


                <?php

                foreach (
                    $questions
                    as $index => $question
                ):

                ?>


                    <div class="card shadow-sm mb-4">


                        <div class="card-body p-4">


                            <!-- Question -->

                            <h5 class="mb-3">

                                Question
                                <?php echo $index + 1; ?>

                            </h5>


                            <p class="fw-semibold">

                                <?php

                                echo htmlspecialchars(
                                    $question["question_text"]
                                );

                                ?>

                            </p>


                            <!-- Option A -->

                            <div
                                class="border rounded p-3 mb-2"
                            >

                                <strong>
                                    A.
                                </strong>

                                <?php

                                echo htmlspecialchars(
                                    $question["option_a"]
                                );

                                ?>

                            </div>


                            <!-- Option B -->

                            <div
                                class="border rounded p-3 mb-2"
                            >

                                <strong>
                                    B.
                                </strong>

                                <?php

                                echo htmlspecialchars(
                                    $question["option_b"]
                                );

                                ?>

                            </div>


                            <!-- Option C -->

                            <div
                                class="border rounded p-3 mb-2"
                            >

                                <strong>
                                    C.
                                </strong>

                                <?php

                                echo htmlspecialchars(
                                    $question["option_c"]
                                );

                                ?>

                            </div>


                            <!-- Option D -->

                            <div
                                class="border rounded p-3 mb-3"
                            >

                                <strong>
                                    D.
                                </strong>

                                <?php

                                echo htmlspecialchars(
                                    $question["option_d"]
                                );

                                ?>

                            </div>


                            <!-- Correct Answer -->

                            <div
                                class="alert alert-success mb-0"
                            >

                                <strong>
                                    Correct Answer:
                                </strong>

                                <?php

                                echo htmlspecialchars(
                                    $question["correct_answer"]
                                );

                                ?>

                            </div>


                        </div>

                    </div>


                <?php endforeach; ?>


            <?php endif; ?>


            <!-- =================================================
                 BOTTOM BUTTONS
            ================================================== -->

            <div class="card shadow-sm">

                <div class="card-body p-4">

                    <a
                        href="javascript:history.back()"
                        class="btn btn-outline-secondary me-2"
                    >
                        Back
                    </a>
                    <a
                        href="assign-quiz.php?assessment_id=<?php echo (int) $assessment_id; ?>"
                        class="btn btn-primary"
                    >
                        Assign Quiz
                    </a>

                    <a
                        href="materials.php"
                        class="btn btn-outline-primary"
                    >
                        Materials
                    </a>

                </div>

            </div>


        </div>

    </div>

</div>


</body>

</html>