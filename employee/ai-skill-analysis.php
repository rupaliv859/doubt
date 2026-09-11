<?php

session_start();

require_once "../includes/db.php";
require_once "../includes/gemini.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "employee") {
    header("Location: ../employee-login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];

/* ---------------------------------------------------
   GET EMPLOYEE DETAILS
--------------------------------------------------- */

$employee_query = $conn->prepare(
    "SELECT
        employee_id,
        full_name,
        employee_code,
        department,
        designation
     FROM employees
     WHERE user_id = ?"
);

$employee_query->bind_param("i", $user_id);
$employee_query->execute();

$employee_result = $employee_query->get_result();
$employee = $employee_result->fetch_assoc();

$employee_query->close();

if (!$employee) {
    die("Employee profile not found.");
}

$employee_id = (int) $employee["employee_id"];


/* ---------------------------------------------------
   GET ROLE SKILLS + CURRENT SKILLS
--------------------------------------------------- */

$skill_query = $conn->prepare(
    "SELECT
        s.skill_id,
        s.skill_name,
        s.category,
        rs.required_level,
        COALESCE(es.current_level, 0) AS current_level
     FROM roles r

     INNER JOIN role_skills rs
        ON r.role_id = rs.role_id

     INNER JOIN skills s
        ON rs.skill_id = s.skill_id

     LEFT JOIN employee_skills es
        ON es.skill_id = s.skill_id
        AND es.employee_id = ?

     WHERE LOWER(TRIM(r.role_name)) =
           LOWER(TRIM(?))

     ORDER BY s.skill_name"
);

$skill_query->bind_param(
    "is",
    $employee_id,
    $employee["designation"]
);

$skill_query->execute();

$skill_result = $skill_query->get_result();

$skills = [];

while ($row = $skill_result->fetch_assoc()) {
    $skills[] = $row;
}

$skill_query->close();


/* ---------------------------------------------------
   GET QUIZ / ASSESSMENT PERFORMANCE
--------------------------------------------------- */

$quiz_query = $conn->prepare(
    "SELECT
        a.assessment_id,
        a.assessment_name,
        s.skill_name,
        aa.status AS assignment_status,
        at.score,
        at.total_questions,
        at.percentage,
        at.attempted_at

     FROM assessment_assignments aa

     INNER JOIN assessments a
        ON aa.assessment_id = a.assessment_id

     LEFT JOIN skills s
        ON a.skill_id = s.skill_id

     LEFT JOIN assessment_attempts at
        ON at.assessment_id = aa.assessment_id
        AND at.employee_id = aa.employee_id

     WHERE aa.employee_id = ?

     ORDER BY aa.assigned_at DESC"
);

$quiz_query->bind_param("i", $employee_id);
$quiz_query->execute();

$quiz_result = $quiz_query->get_result();

$quizzes = [];

while ($row = $quiz_result->fetch_assoc()) {
    $quizzes[] = $row;
}

$quiz_query->close();


/* ---------------------------------------------------
   GET LEARNING PROGRESS
--------------------------------------------------- */

$progress_query = $conn->prepare(
    "SELECT
        c.course_id,
        c.course_name,
        c.difficulty,
        s.skill_name,
        COALESCE(p.progress_percentage, 0) AS progress_percentage,
        e.status AS enrollment_status,
        p.last_accessed,
        p.completed_at

     FROM enrollments e

     INNER JOIN courses c
        ON e.course_id = c.course_id

     LEFT JOIN skills s
        ON c.skill_id = s.skill_id

     LEFT JOIN progress p
        ON p.enrollment_id = e.enrollment_id

     WHERE e.employee_id = ?

     ORDER BY e.enrolled_at DESC"
);

$progress_query->bind_param("i", $employee_id);
$progress_query->execute();

$progress_result = $progress_query->get_result();

$learning_progress = [];

while ($row = $progress_result->fetch_assoc()) {
    $learning_progress[] = $row;
}

$progress_query->close();


/* ---------------------------------------------------
   GENERATE AI ANALYSIS
--------------------------------------------------- */

$ai_analysis = "";
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $prompt = "
You are an AI competency analysis assistant for an employee learning platform.

Analyze the employee using ONLY the data provided below.

Employee:
Name: " . $employee["full_name"] . "
Employee Code: " . $employee["employee_code"] . "
Department: " . $employee["department"] . "
Designation: " . $employee["designation"] . "

ROLE SKILLS:
";

    if (count($skills) > 0) {

        foreach ($skills as $skill) {

            $prompt .= "
Skill: " . $skill["skill_name"] . "
Category: " . $skill["category"] . "
Required Level: " . $skill["required_level"] . "
Current Level: " . $skill["current_level"] . "
";
        }

    } else {

        $prompt .= "
No role skill mapping is available.
";
    }


    $prompt .= "

ASSESSMENT / QUIZ PERFORMANCE:
";

    if (count($quizzes) > 0) {

        foreach ($quizzes as $quiz) {

            $prompt .= "
Assessment: " . $quiz["assessment_name"] . "
Skill: " . ($quiz["skill_name"] ?? "Not specified") . "
Status: " . $quiz["assignment_status"] . "
Score: " . ($quiz["score"] ?? "Not attempted") . "
Total Questions: " . ($quiz["total_questions"] ?? "N/A") . "
Percentage: " . ($quiz["percentage"] ?? "Not attempted") . "
";
        }

    } else {

        $prompt .= "
No assessments or quizzes have been assigned.
";
    }


    $prompt .= "

LEARNING PROGRESS:
";

    if (count($learning_progress) > 0) {

        foreach ($learning_progress as $course) {

            $prompt .= "
Course: " . $course["course_name"] . "
Skill: " . ($course["skill_name"] ?? "Not specified") . "
Difficulty: " . $course["difficulty"] . "
Progress: " . $course["progress_percentage"] . "%
Enrollment Status: " . $course["enrollment_status"] . "
";
        }

    } else {

        $prompt .= "
No course learning progress is available.
";
    }


    $prompt .= "

Provide a concise but useful competency analysis.

Use the following sections:

1. Overall Competency
Give an overall assessment based on the available evidence.

2. Strengths
Mention skills where the employee appears relatively strong.

3. Skill Gaps
Identify important gaps between current and required competency.

4. Assessment Performance
Interpret quiz/assessment performance.

5. Learning Progress
Interpret the employee's course-learning activity.

6. Priority Areas
Identify the most important areas the employee should focus on.

7. Recommended Next Steps
Give practical learning actions based only on the available data.

Do not invent skills, scores, courses, or achievements.
Clearly mention when data is unavailable.
";


    try {

        $ai_analysis = askGemini($prompt);

        if (!$ai_analysis) {
            $error_message = "AI analysis could not be generated.";
        }

    } catch (Exception $e) {

        $error_message = "Unable to generate AI analysis at this time.";
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

    <title>AI Skill Analysis - OUTLIERS</title>

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

<div class="container py-5">

    <!-- HEADER -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="fw-bold mb-1">
                AI Skill Analysis
            </h2>

            <p class="text-muted mb-0">
                AI-powered analysis of your competency and learning progress.
            </p>

        </div>

        <a
            href="dashboard.php"
            class="btn btn-outline-secondary"
        >
            Back to Dashboard
        </a>

    </div>


    <!-- EMPLOYEE INFO -->

    <div class="card shadow-sm mb-4">

        <div class="card-body">

            <h5 class="fw-bold mb-3">
                Employee Profile
            </h5>

            <div class="row">

                <div class="col-md-3 mb-3">

                    <small class="text-muted">
                        Name
                    </small>

                    <div class="fw-semibold">
                        <?php echo htmlspecialchars($employee["full_name"]); ?>
                    </div>

                </div>

                <div class="col-md-3 mb-3">

                    <small class="text-muted">
                        Employee Code
                    </small>

                    <div class="fw-semibold">
                        <?php echo htmlspecialchars($employee["employee_code"]); ?>
                    </div>

                </div>

                <div class="col-md-3 mb-3">

                    <small class="text-muted">
                        Department
                    </small>

                    <div class="fw-semibold">
                        <?php echo htmlspecialchars($employee["department"]); ?>
                    </div>

                </div>

                <div class="col-md-3 mb-3">

                    <small class="text-muted">
                        Designation
                    </small>

                    <div class="fw-semibold">
                        <?php echo htmlspecialchars($employee["designation"]); ?>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- SKILL SNAPSHOT -->

    <div class="card shadow-sm mb-4">

        <div class="card-body">

            <div class="d-flex justify-content-between align-items-center mb-3">

                <h5 class="fw-bold mb-0">
                    Competency Snapshot
                </h5>

                <span class="badge bg-primary">
                    <?php echo count($skills); ?> Skills
                </span>

            </div>


            <?php if (count($skills) > 0): ?>

                <div class="table-responsive">

                    <table class="table table-bordered align-middle">

                        <thead>

                            <tr>

                                <th>
                                    Skill
                                </th>

                                <th>
                                    Category
                                </th>

                                <th>
                                    Required Level
                                </th>

                                <th>
                                    Current Level
                                </th>

                                <th>
                                    Gap
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($skills as $skill): ?>

                            <?php
                                $gap =
                                    (int)$skill["required_level"]
                                    -
                                    (int)$skill["current_level"];

                                if ($gap < 0) {
                                    $gap = 0;
                                }
                            ?>

                            <tr>

                                <td class="fw-semibold">
                                    <?php
                                        echo htmlspecialchars(
                                            $skill["skill_name"]
                                        );
                                    ?>
                                </td>

                                <td>
                                    <?php
                                        echo htmlspecialchars(
                                            $skill["category"]
                                        );
                                    ?>
                                </td>

                                <td>
                                    <?php
                                        echo (int)$skill["required_level"];
                                    ?>
                                </td>

                                <td>
                                    <?php
                                        echo (int)$skill["current_level"];
                                    ?>
                                </td>

                                <td>

                                    <?php if ($gap > 0): ?>

                                        <span class="badge bg-warning text-dark">
                                            Gap: <?php echo $gap; ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-success">
                                            No Gap
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="alert alert-warning mb-0">

                    No role-skill mapping is available for your designation.

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- QUIZ PERFORMANCE -->

    <div class="card shadow-sm mb-4">

        <div class="card-body">

            <h5 class="fw-bold mb-3">
                Assessment Performance
            </h5>

            <?php if (count($quizzes) > 0): ?>

                <div class="table-responsive">

                    <table class="table table-bordered align-middle">

                        <thead>

                            <tr>

                                <th>
                                    Assessment
                                </th>

                                <th>
                                    Skill
                                </th>

                                <th>
                                    Score
                                </th>

                                <th>
                                    Percentage
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach ($quizzes as $quiz): ?>

                            <tr>

                                <td class="fw-semibold">

                                    <?php
                                        echo htmlspecialchars(
                                            $quiz["assessment_name"]
                                        );
                                    ?>

                                </td>

                                <td>

                                    <?php
                                        echo htmlspecialchars(
                                            $quiz["skill_name"]
                                            ?? "Not specified"
                                        );
                                    ?>

                                </td>

                                <td>

                                    <?php if ($quiz["score"] !== null): ?>

                                        <?php
                                            echo (int)$quiz["score"];
                                            echo " / ";
                                            echo (int)$quiz["total_questions"];
                                        ?>

                                    <?php else: ?>

                                        -

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php if ($quiz["percentage"] !== null): ?>

                                        <?php
                                            echo number_format(
                                                (float)$quiz["percentage"],
                                                1
                                            );
                                        ?>%

                                    <?php else: ?>

                                        -

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php if ($quiz["assignment_status"] === "completed"): ?>

                                        <span class="badge bg-success">
                                            Completed
                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-warning text-dark">
                                            Pending
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <p class="text-muted mb-0">
                    No assessments have been assigned yet.
                </p>

            <?php endif; ?>

        </div>

    </div>


    <!-- LEARNING PROGRESS -->

    <div class="card shadow-sm mb-4">

        <div class="card-body">

            <h5 class="fw-bold mb-3">
                Learning Progress
            </h5>

            <?php if (count($learning_progress) > 0): ?>

                <?php foreach ($learning_progress as $course): ?>

                    <div class="mb-4">

                        <div class="d-flex justify-content-between mb-1">

                            <strong>
                                <?php
                                    echo htmlspecialchars(
                                        $course["course_name"]
                                    );
                                ?>
                            </strong>

                            <span>
                                <?php
                                    echo number_format(
                                        (float)$course["progress_percentage"],
                                        0
                                    );
                                ?>%
                            </span>

                        </div>

                        <div class="progress">

                            <div
                                class="progress-bar"
                                role="progressbar"
                                style="width: <?php echo (float)$course["progress_percentage"]; ?>%;"
                            ></div>

                        </div>

                        <small class="text-muted">

                            Skill:
                            <?php
                                echo htmlspecialchars(
                                    $course["skill_name"]
                                    ?? "Not specified"
                                );
                            ?>

                            &nbsp; | &nbsp;

                            Difficulty:
                            <?php
                                echo htmlspecialchars(
                                    $course["difficulty"]
                                );
                            ?>

                        </small>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <p class="text-muted mb-0">
                    No course learning activity is available yet.
                </p>

            <?php endif; ?>

        </div>

    </div>


    <!-- GENERATE ANALYSIS -->

    <div class="card shadow-sm mb-4">

        <div class="card-body text-center">

            <h5 class="fw-bold">
                Generate AI Competency Analysis
            </h5>

            <p class="text-muted">
                Analyze your current skills, assessment performance,
                skill gaps and learning progress.
            </p>

            <form method="POST">

                <button
                    type="submit"
                    class="btn btn-primary btn-lg"
                >
                    Generate AI Analysis
                </button>

            </form>

        </div>

    </div>


    <!-- ERROR -->

    <?php if ($error_message): ?>

        <div class="alert alert-danger">

            <?php
                echo htmlspecialchars($error_message);
            ?>

        </div>

    <?php endif; ?>


    <!-- AI RESULT -->

    <?php if ($ai_analysis): ?>

        <div class="card shadow-sm mb-4">

            <div class="card-body">

                <h4 class="fw-bold mb-4">
                    AI Competency Analysis
                </h4>

                <div
                    style="
                        white-space: pre-wrap;
                        line-height: 1.7;
                    "
                >
                    <?php
                        echo htmlspecialchars($ai_analysis);
                    ?>
                </div>

            </div>

        </div>

    <?php endif; ?>


    <!-- FOOTER ACTIONS -->

    <div class="text-center">

        <a
            href="skill-gaps.php"
            class="btn btn-outline-primary me-2"
        >
            View Skill Gaps
        </a>

        <a
            href="recommendations.php"
            class="btn btn-outline-success"
        >
            View Recommendations
        </a>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>