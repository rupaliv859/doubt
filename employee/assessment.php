```php
<?php

session_start();

require_once "../includes/db.php";


/* -----------------------------------------
   1. CHECK EMPLOYEE LOGIN
----------------------------------------- */

if (
    !isset($_SESSION["user_id"]) ||
    $_SESSION["role"] !== "employee"
) {
    header("Location: ../employee-login.php");
    exit();
}

$user_id = $_SESSION["user_id"];


/* -----------------------------------------
   2. GET EMPLOYEE INFORMATION
----------------------------------------- */

$employee_sql = "
    SELECT employee_id, full_name
    FROM employees
    WHERE user_id = ?
";

$stmt = $conn->prepare($employee_sql);

$stmt->bind_param(
    "i",
    $user_id
);

$stmt->execute();

$employee_result = $stmt->get_result();

if ($employee_result->num_rows === 0) {

    die("Employee profile not found.");

}

$employee = $employee_result->fetch_assoc();

$employee_id = (int) $employee["employee_id"];

$full_name = $employee["full_name"];

$stmt->close();


/* -----------------------------------------
   3. RESULT VARIABLES
----------------------------------------- */

$result_message = "";

$result_percentage = null;

$result_level = null;

$result_correct = null;

$result_total = null;


/* -----------------------------------------
   4. GET ASSESSMENT ID
----------------------------------------- */

$assessment_id = isset($_GET["assessment_id"])
    ? intval($_GET["assessment_id"])
    : 0;


/* -----------------------------------------
   5. HANDLE ASSESSMENT SUBMISSION
----------------------------------------- */

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    $assessment_id = isset($_POST["assessment_id"])
        ? intval($_POST["assessment_id"])
        : 0;


    if ($assessment_id <= 0) {

        die("Invalid assessment.");

    }


    /* -----------------------------------------
       CHECK WHETHER QUIZ IS ASSIGNED
    ----------------------------------------- */

    $assignment_sql = "
        SELECT assignment_id, status
        FROM assessment_assignments
        WHERE assessment_id = ?
        AND employee_id = ?
    ";

    $stmt = $conn->prepare($assignment_sql);

    $stmt->bind_param(
        "ii",
        $assessment_id,
        $employee_id
    );

    $stmt->execute();

    $assignment_result = $stmt->get_result();

    if ($assignment_result->num_rows === 0) {

        $stmt->close();

        die(
            "This assessment has not been assigned to you."
        );

    }

    $assignment = $assignment_result->fetch_assoc();

    $assignment_id =
        (int) $assignment["assignment_id"];

    $assignment_status =
        $assignment["status"];

    $stmt->close();


    /* -----------------------------------------
       PREVENT COMPLETED QUIZ FROM SUBMITTING AGAIN
    ----------------------------------------- */

    if ($assignment_status === "completed") {

        die(
            "You have already completed this assessment."
        );

    }


    /* -----------------------------------------
       GET ALL QUESTIONS
    ----------------------------------------- */

    $question_sql = "
        SELECT
            question_id,
            correct_answer
        FROM questions
        WHERE assessment_id = ?
    ";

    $stmt = $conn->prepare($question_sql);

    $stmt->bind_param(
        "i",
        $assessment_id
    );

    $stmt->execute();

    $question_result = $stmt->get_result();

    $questions = [];


    while (
        $row = $question_result->fetch_assoc()
    ) {

        $questions[
            (int) $row["question_id"]
        ] = $row["correct_answer"];

    }

    $stmt->close();


    /* -----------------------------------------
       CHECK THAT QUESTIONS EXIST
    ----------------------------------------- */

    if (count($questions) === 0) {

        die(
            "This assessment does not contain any questions."
        );

    }


    /* -----------------------------------------
       CALCULATE SCORE
    ----------------------------------------- */

    $correct = 0;

    $total = count($questions);


    foreach (
        $questions as $question_id => $correct_answer
    ) {


        $user_answer = isset(
            $_POST["answer_" . $question_id]
        )
            ? $_POST["answer_" . $question_id]
            : "";


        $user_answer = strtoupper(
            trim($user_answer)
        );


        $correct_answer = strtoupper(
            trim($correct_answer)
        );


        if (
            $user_answer === $correct_answer
        ) {

            $correct++;

        }

    }


    /* -----------------------------------------
       CALCULATE PERCENTAGE
    ----------------------------------------- */

    $percentage = 0;


    if ($total > 0) {

        $percentage =
            ($correct / $total) * 100;

    }


    /* -----------------------------------------
       CONVERT SCORE TO COMPETENCY LEVEL

       0 = Not Assessed
       1 = Beginner
       2 = Basic
       3 = Intermediate
       4 = Advanced
    ----------------------------------------- */

    if ($percentage >= 80) {

        $level = 4;

        $level_text = "Advanced";

    } elseif ($percentage >= 60) {

        $level = 3;

        $level_text = "Intermediate";

    } elseif ($percentage >= 40) {

        $level = 2;

        $level_text = "Basic";

    } elseif ($percentage > 0) {

        $level = 1;

        $level_text = "Beginner";

    } else {

        $level = 0;

        $level_text = "Not Assessed";

    }


    /* -----------------------------------------
       GET SKILL ID OF ASSESSMENT
    ----------------------------------------- */

    $skill_sql = "
        SELECT skill_id
        FROM assessments
        WHERE assessment_id = ?
    ";

    $stmt = $conn->prepare($skill_sql);

    $stmt->bind_param(
        "i",
        $assessment_id
    );

    $stmt->execute();

    $skill_result = $stmt->get_result();


    if ($skill_result->num_rows === 0) {

        $stmt->close();

        die("Assessment not found.");

    }


    $skill_data =
        $skill_result->fetch_assoc();


    $skill_id =
        $skill_data["skill_id"];


    $stmt->close();


    /* -----------------------------------------
       SAVE ASSESSMENT ATTEMPT
    ----------------------------------------- */

    $attempt_sql = "
        INSERT INTO assessment_attempts
        (
            employee_id,
            assessment_id,
            score,
            total_questions,
            percentage
        )
        VALUES (?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($attempt_sql);

    $stmt->bind_param(
        "iiidd",
        $employee_id,
        $assessment_id,
        $correct,
        $total,
        $percentage
    );

    if (!$stmt->execute()) {

        $stmt->close();

        die(
            "Unable to save assessment result."
        );

    }

    $stmt->close();


    /* -----------------------------------------
       UPDATE EMPLOYEE SKILL
       
       First try to update an existing skill.
       If no row exists, create one.
    ----------------------------------------- */

    $skill_exists_sql = "
        SELECT employee_skill_id
        FROM employee_skills
        WHERE employee_id = ?
        AND skill_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare(
        $skill_exists_sql
    );

    $stmt->bind_param(
        "ii",
        $employee_id,
        $skill_id
    );

    $stmt->execute();

    $skill_exists_result =
        $stmt->get_result();

    $existing_skill =
        $skill_exists_result->fetch_assoc();

    $stmt->close();


    if ($existing_skill) {


        /* -----------------------------------------
           UPDATE EXISTING SKILL
        ----------------------------------------- */

        $skill_update_sql = "
            UPDATE employee_skills
            SET
                current_level = ?,
                last_assessed_at = NOW()
            WHERE employee_skill_id = ?
        ";

        $stmt = $conn->prepare(
            $skill_update_sql
        );

        $employee_skill_id =
            (int) $existing_skill[
                "employee_skill_id"
            ];

        $stmt->bind_param(
            "ii",
            $level,
            $employee_skill_id
        );

        $stmt->execute();

        $stmt->close();


    } else {


        /* -----------------------------------------
           CREATE NEW EMPLOYEE SKILL RECORD
        ----------------------------------------- */

        $skill_insert_sql = "
            INSERT INTO employee_skills
            (
                employee_id,
                skill_id,
                current_level,
                last_assessed_at
            )
            VALUES (?, ?, ?, NOW())
        ";

        $stmt = $conn->prepare(
            $skill_insert_sql
        );

        $stmt->bind_param(
            "iii",
            $employee_id,
            $skill_id,
            $level
        );

        $stmt->execute();

        $stmt->close();

    }


    /* -----------------------------------------
       MARK QUIZ AS COMPLETED
    ----------------------------------------- */

    $complete_sql = "
        UPDATE assessment_assignments
        SET status = 'completed'
        WHERE assignment_id = ?
        AND employee_id = ?
        AND assessment_id = ?
    ";

    $stmt = $conn->prepare(
        $complete_sql
    );

    $stmt->bind_param(
        "iii",
        $assignment_id,
        $employee_id,
        $assessment_id
    );

    $stmt->execute();

    $stmt->close();


    /* -----------------------------------------
       RESULT
    ----------------------------------------- */

    $result_correct = $correct;

    $result_total = $total;

    $result_percentage = round(
        $percentage,
        2
    );

    $result_level = $level_text;

    $result_message =
        "Assessment completed successfully!";

}


/* -----------------------------------------
   6. GET AVAILABLE ASSESSMENTS
----------------------------------------- */

$assessment_sql = "
    SELECT
        a.assessment_id,
        a.assessment_name,
        a.description,
        s.skill_name
    FROM assessments a
    LEFT JOIN skills s
        ON a.skill_id = s.skill_id
    ORDER BY a.assessment_id ASC
";

$assessment_result =
    $conn->query($assessment_sql);


/* -----------------------------------------
   7. GET SELECTED ASSESSMENT
----------------------------------------- */

$selected_assessment = null;

$questions_result = null;


if ($assessment_id > 0) {


    /* -----------------------------------------
       CHECK ASSIGNMENT BEFORE SHOWING QUIZ
    ----------------------------------------- */

    $assignment_check_sql = "
        SELECT
            assignment_id,
            status
        FROM assessment_assignments
        WHERE assessment_id = ?
        AND employee_id = ?
    ";

    $stmt = $conn->prepare(
        $assignment_check_sql
    );

    $stmt->bind_param(
        "ii",
        $assessment_id,
        $employee_id
    );

    $stmt->execute();

    $assignment_check_result =
        $stmt->get_result();


    if (
        $assignment_check_result->num_rows === 0
    ) {

        $stmt->close();

        $assessment_id = 0;

    } else {


        $assignment_check =
            $assignment_check_result->fetch_assoc();


        $assignment_status =
            $assignment_check["status"];


        $stmt->close();


        /* -----------------------------------------
           ONLY SHOW QUIZ IF NOT COMPLETED
        ----------------------------------------- */

        if (
            $assignment_status !== "completed"
        ) {


            $selected_sql = "
                SELECT
                    a.assessment_id,
                    a.assessment_name,
                    a.description,
                    s.skill_name
                FROM assessments a
                LEFT JOIN skills s
                    ON a.skill_id = s.skill_id
                WHERE a.assessment_id = ?
            ";


            $stmt = $conn->prepare(
                $selected_sql
            );

            $stmt->bind_param(
                "i",
                $assessment_id
            );

            $stmt->execute();

            $selected_result =
                $stmt->get_result();


            if (
                $selected_result->num_rows > 0
            ) {


                $selected_assessment =
                    $selected_result->fetch_assoc();


                /* -----------------------------------------
                   GET QUESTIONS
                ----------------------------------------- */

                $questions_sql = "
                    SELECT
                        question_id,
                        question_text,
                        option_a,
                        option_b,
                        option_c,
                        option_d
                    FROM questions
                    WHERE assessment_id = ?
                    ORDER BY question_id ASC
                ";


                $stmt2 = $conn->prepare(
                    $questions_sql
                );

                $stmt2->bind_param(
                    "i",
                    $assessment_id
                );

                $stmt2->execute();

                $questions_result =
                    $stmt2->get_result();

                $stmt2->close();

            }

            $stmt->close();


        } else {

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
        Competency Assessment - OUTLIERS
    </title>


    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <link
        rel="stylesheet"
        href="../css/style.css"
    >

</head>


<body>


<!-- =========================================
     NAVBAR
========================================= -->

<nav
    class="navbar
           navbar-expand-lg
           navbar-dark
           bg-dark"
>

    <div class="container">


        <a
            class="navbar-brand fw-bold"
            href="dashboard.php"
        >

            OUTLIERS

        </a>


        <div>


            <a
                href="dashboard.php"
                class="btn btn-outline-light btn-sm"
            >

                Dashboard

            </a>


            <a
                href="../logout.php"
                class="btn btn-danger btn-sm"
            >

                Logout

            </a>


        </div>


    </div>

</nav>


<!-- =========================================
     MAIN CONTENT
========================================= -->

<div class="container py-5">


    <div class="mb-4">


        <h2 class="fw-bold">

            Competency Assessment

        </h2>


        <p class="text-muted">

            Welcome,
            <?php
            echo htmlspecialchars($full_name);
            ?>.

            Assess your skills to help OUTLIERS
            identify competency gaps and recommend
            suitable learning.

        </p>


    </div>


    <!-- =====================================
         RESULT
    ====================================== -->

    <?php if ($result_message !== ""): ?>


        <div
            class="card
                   shadow-sm
                   border-0
                   mb-4"
        >

            <div class="card-body text-center">


                <h4 class="text-success">

                    <?php

                    echo $result_message;

                    ?>

                </h4>


                <div class="row mt-4">


                    <!-- SCORE -->

                    <div class="col-md-4">

                        <h5>

                            Score

                        </h5>


                        <h3 class="fw-bold">

                            <?php

                            echo $result_correct .
                                 " / " .
                                 $result_total;

                            ?>

                        </h3>

                    </div>


                    <!-- PERCENTAGE -->

                    <div class="col-md-4">

                        <h5>

                            Percentage

                        </h5>


                        <h3 class="fw-bold">

                            <?php

                            echo $result_percentage .
                                 "%";

                            ?>

                        </h3>

                    </div>


                    <!-- COMPETENCY -->

                    <div class="col-md-4">

                        <h5>

                            Competency Level

                        </h5>


                        <h3 class="fw-bold text-primary">

                            <?php

                            echo htmlspecialchars(
                                $result_level
                            );

                            ?>

                        </h3>

                    </div>


                </div>


                <div class="mt-4">


                    <a
                        href="assessment.php"
                        class="btn btn-primary"
                    >

                        Take Another Assessment

                    </a>


                    <a
                        href="dashboard.php"
                        class="btn btn-outline-secondary"
                    >

                        Back to Dashboard

                    </a>


                </div>


            </div>

        </div>


    <?php endif; ?>


    <!-- =====================================
         ASSESSMENT LIST
    ====================================== -->

    <?php if ($assessment_id === 0): ?>


        <div class="row">


            <?php if (
                $assessment_result &&
                $assessment_result->num_rows > 0
            ): ?>


                <?php

                while (
                    $assessment =
                    $assessment_result->fetch_assoc()
                ):

                ?>


                    <div class="col-md-6 mb-4">


                        <div
                            class="card
                                   h-100
                                   shadow-sm
                                   border-0"
                        >

                            <div class="card-body">


                                <span
                                    class="badge
                                           bg-primary
                                           mb-2"
                                >

                                    <?php

                                    echo htmlspecialchars(
                                        $assessment[
                                            "skill_name"
                                        ] ?? "General"
                                    );

                                    ?>

                                </span>


                                <h4 class="card-title">

                                    <?php

                                    echo htmlspecialchars(
                                        $assessment[
                                            "assessment_name"
                                        ]
                                    );

                                    ?>

                                </h4>


                                <p class="text-muted">

                                    <?php

                                    echo htmlspecialchars(
                                        $assessment[
                                            "description"
                                        ] ?? ""
                                    );

                                    ?>

                                </p>


                                <a
                                    href="assessment.php?assessment_id=<?php echo (int) $assessment["assessment_id"]; ?>"
                                    class="btn btn-primary"
                                >

                                    Start Assessment

                                </a>


                            </div>

                        </div>


                    </div>


                <?php endwhile; ?>


            <?php else: ?>


                <div class="alert alert-info">

                    No assessments are available yet.

                </div>


            <?php endif; ?>


        </div>


    <?php endif; ?>


    <!-- =====================================
         SELECTED ASSESSMENT
    ====================================== -->

    <?php if (
        $selected_assessment !== null
    ): ?>


        <div
            class="card
                   shadow-sm
                   border-0"
        >

            <div class="card-body p-4">


                <div class="mb-4">


                    <span class="badge bg-primary">

                        <?php

                        echo htmlspecialchars(
                            $selected_assessment[
                                "skill_name"
                            ] ?? "General"
                        );

                        ?>

                    </span>


                    <h3 class="fw-bold mt-2">

                        <?php

                        echo htmlspecialchars(
                            $selected_assessment[
                                "assessment_name"
                            ]
                        );

                        ?>

                    </h3>


                    <p class="text-muted">

                        <?php

                        echo htmlspecialchars(
                            $selected_assessment[
                                "description"
                            ] ?? ""
                        );

                        ?>

                    </p>


                </div>


                <?php if (
                    $questions_result !== null &&
                    $questions_result->num_rows > 0
                ): ?>


                    <form
                        method="POST"
                        action="assessment.php?assessment_id=<?php echo (int) $assessment_id; ?>"
                    >


                        <input
                            type="hidden"
                            name="assessment_id"
                            value="<?php echo (int) $assessment_id; ?>"
                        >


                        <?php

                        $question_number = 1;

                        while (
                            $question =
                            $questions_result->fetch_assoc()
                        ):

                        ?>


                            <div class="mb-5">


                                <h5 class="fw-bold">

                                    <?php

                                    echo $question_number .
                                         ". " .
                                         htmlspecialchars(
                                             $question[
                                                 "question_text"
                                             ]
                                         );

                                    ?>

                                </h5>


                                <div class="mt-3">


                                    <!-- OPTION A -->

                                    <div
                                        class="form-check mb-2"
                                    >

                                        <input
                                            class="form-check-input"
                                            type="radio"
                                            name="answer_<?php echo (int) $question["question_id"]; ?>"
                                            value="A"
                                            id="q<?php echo (int) $question["question_id"]; ?>a"
                                            required
                                        >


                                        <label
                                            class="form-check-label"
                                            for="q<?php echo (int) $question["question_id"]; ?>a"
                                        >

                                            A.
                                            <?php

                                            echo htmlspecialchars(
                                                $question[
                                                    "option_a"
                                                ]
                                            );

                                            ?>

                                        </label>

                                    </div>


                                    <!-- OPTION B -->

                                    <div
                                        class="form-check mb-2"
                                    >

                                        <input
                                            class="form-check-input"
                                            type="radio"
                                            name="answer_<?php echo (int) $question["question_id"]; ?>"
                                            value="B"
                                            id="q<?php echo (int) $question["question_id"]; ?>b"
                                        >


                                        <label
                                            class="form-check-label"
                                            for="q<?php echo (int) $question["question_id"]; ?>b"
                                        >

                                            B.
                                            <?php

                                            echo htmlspecialchars(
                                                $question[
                                                    "option_b"
                                                ]
                                            );

                                            ?>

                                        </label>

                                    </div>


                                    <!-- OPTION C -->

                                    <div
                                        class="form-check mb-2"
                                    >

                                        <input
                                            class="form-check-input"
                                            type="radio"
                                            name="answer_<?php echo (int) $question["question_id"]; ?>"
                                            value="C"
                                            id="q<?php echo (int) $question["question_id"]; ?>c"
                                        >


                                        <label
                                            class="form-check-label"
                                            for="q<?php echo (int) $question["question_id"]; ?>c"
                                        >

                                            C.
                                            <?php

                                            echo htmlspecialchars(
                                                $question[
                                                    "option_c"
                                                ]
                                            );

                                            ?>

                                        </label>

                                    </div>


                                    <!-- OPTION D -->

                                    <div
                                        class="form-check mb-2"
                                    >

                                        <input
                                            class="form-check-input"
                                            type="radio"
                                            name="answer_<?php echo (int) $question["question_id"]; ?>"
                                            value="D"
                                            id="q<?php echo (int) $question["question_id"]; ?>d"
                                        >


                                        <label
                                            class="form-check-label"
                                            for="q<?php echo (int) $question["question_id"]; ?>d"
                                        >

                                            D.
                                            <?php

                                            echo htmlspecialchars(
                                                $question[
                                                    "option_d"
                                                ]
                                            );

                                            ?>

                                        </label>

                                    </div>


                                </div>


                            </div>


                        <?php

                            $question_number++;

                        endwhile;

                        ?>


                        <div class="mt-4">


                            <button
                                type="submit"
                                class="btn btn-primary btn-lg"
                            >

                                Submit Assessment

                            </button>


                            <a
                                href="assessment.php"
                                class="btn btn-outline-secondary btn-lg"
                            >

                                Cancel

                            </a>


                        </div>


                    </form>


                <?php else: ?>


                    <div
                        class="alert alert-warning"
                    >

                        No questions have been added
                        to this assessment yet.

                    </div>


                <?php endif; ?>


            </div>

        </div>


    <?php endif; ?>


</div>


<!-- =========================================
     FOOTER
========================================= -->

<footer
    class="bg-dark
           text-white
           text-center
           py-3"
>

    <p class="mb-0">

        © <?php echo date("Y"); ?> OUTLIERS |

        AI-Powered Skill Intelligence
        & Learning Platform

    </p>

</footer>


</body>

</html>
```