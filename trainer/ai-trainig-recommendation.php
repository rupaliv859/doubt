<?php

session_start();

require_once "../includes/db.php";
require_once "../includes/gemini.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "trainer") {
    header("Location: ../employee-login.php");
    exit;
}

$trainer_id = (int) $_SESSION["user_id"];

$recommendations = [];
$error_message = "";
$generated = false;


/* ---------------------------------------------------
   GET TRAINER EMPLOYEES AND THEIR SKILL DATA
--------------------------------------------------- */

$query = $conn->prepare(
    "SELECT
        e.employee_id,
        e.employee_code,
        e.full_name,
        e.department,
        e.designation,

        s.skill_id,
        s.skill_name,
        s.category,

        rs.required_level,

        COALESCE(es.current_level, 0) AS current_level

     FROM trainer_employee te

     INNER JOIN employees e
        ON te.employee_id = e.employee_id

     INNER JOIN roles r
        ON LOWER(TRIM(r.role_name)) =
           LOWER(TRIM(e.designation))

     INNER JOIN role_skills rs
        ON r.role_id = rs.role_id

     INNER JOIN skills s
        ON rs.skill_id = s.skill_id

     LEFT JOIN employee_skills es
        ON es.employee_id = e.employee_id
        AND es.skill_id = s.skill_id

     WHERE te.trainer_id = ?
       AND te.status = 'approved'

     ORDER BY e.full_name, s.skill_name"
);

$query->bind_param("i", $trainer_id);
$query->execute();

$result = $query->get_result();

$employee_data = [];

while ($row = $result->fetch_assoc()) {

    $employee_id = (int) $row["employee_id"];

    if (!isset($employee_data[$employee_id])) {

        $employee_data[$employee_id] = [
            "employee_id" => $employee_id,
            "employee_code" => $row["employee_code"],
            "full_name" => $row["full_name"],
            "department" => $row["department"],
            "designation" => $row["designation"],
            "skills" => []
        ];
    }

    $gap =
        (int)$row["required_level"]
        -
        (int)$row["current_level"];

    if ($gap < 0) {
        $gap = 0;
    }

    $employee_data[$employee_id]["skills"][] = [
        "skill_id" => (int)$row["skill_id"],
        "skill_name" => $row["skill_name"],
        "category" => $row["category"],
        "required_level" => (int)$row["required_level"],
        "current_level" => (int)$row["current_level"],
        "gap" => $gap
    ];
}

$query->close();


/* ---------------------------------------------------
   GENERATE AI RECOMMENDATIONS
--------------------------------------------------- */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (count($employee_data) === 0) {

        $error_message =
            "No approved employees with role-skill mappings were found.";

    } else {

        $prompt = "
You are an AI training recommendation assistant for an employee
learning and competency management platform.

Generate personalized training recommendations for employees.

Use ONLY the employee and skill information provided below.

Do not invent employees, skills, scores, courses, or achievements.

For each employee:

1. Identify the most important skill gaps.
2. Give training priority.
3. Recommend a practical learning action.
4. Suggest the appropriate difficulty level.
5. Explain why the recommendation is needed.

Use these priority rules:

- Gap 2 or more = High priority
- Gap 1 = Medium priority
- Gap 0 = No immediate skill-gap training required

Recommended difficulty:

- Current level 0 or 1 = Beginner
- Current level 2 = Intermediate
- Current level 3 or above = Advanced

Return the result ONLY as valid JSON.

JSON format:

[
  {
    \"employee_id\": 1,
    \"employee_name\": \"Employee Name\",
    \"skill\": \"Skill Name\",
    \"current_level\": 1,
    \"required_level\": 3,
    \"gap\": 2,
    \"priority\": \"High\",
    \"recommended_difficulty\": \"Beginner\",
    \"training_action\": \"Complete foundational training for the skill.\",
    \"reason\": \"The current competency is below the required role level.\"
  }
]

EMPLOYEE DATA:
";


        foreach ($employee_data as $employee) {

            $prompt .= "

Employee ID: " .
                $employee["employee_id"];

            $prompt .= "
Employee Name: " .
                $employee["full_name"];

            $prompt .= "
Employee Code: " .
                $employee["employee_code"];

            $prompt .= "
Designation: " .
                $employee["designation"];

            $prompt .= "
Department: " .
                $employee["department"];

            $prompt .= "
Skills:
";

            foreach ($employee["skills"] as $skill) {

                $prompt .= "
Skill ID: " .
                    $skill["skill_id"];

                $prompt .= "
Skill Name: " .
                    $skill["skill_name"];

                $prompt .= "
Category: " .
                    $skill["category"];

                $prompt .= "
Current Level: " .
                    $skill["current_level"];

                $prompt .= "
Required Level: " .
                    $skill["required_level"];

                $prompt .= "
Gap: " .
                    $skill["gap"];

                $prompt .= "
";
            }
        }


        try {

            $response = askGemini($prompt);

            if (!$response) {

                $error_message =
                    "AI did not return a recommendation.";

            } else {

                /* Remove markdown code fences */

                $response = trim($response);

                $response = preg_replace(
                    '/```json\s*/i',
                    '',
                    $response
                );

                $response = preg_replace(
                    '/```\s*/',
                    '',
                    $response
                );

                $response = trim($response);


                /* Find JSON array */

                $start = strpos($response, "[");

                $end = strrpos($response, "]");

                if ($start !== false && $end !== false) {

                    $response =
                        substr(
                            $response,
                            $start,
                            $end - $start + 1
                        );
                }


                $decoded = json_decode(
                    $response,
                    true
                );


                if (
                    !is_array($decoded)
                    ||
                    count($decoded) === 0
                ) {

                    $error_message =
                        "AI returned an invalid recommendation format.";

                } else {

                    $recommendations = $decoded;

                    $generated = true;
                }
            }

        } catch (Exception $e) {

            $error_message =
                "Unable to generate AI recommendations right now.";
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
        AI Training Recommendations - OUTLIERS
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

<div class="container py-5">


    <!-- HEADER -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="fw-bold mb-1">
                AI Training Recommendations
            </h2>

            <p class="text-muted mb-0">

                Personalized training recommendations
                based on employee competency gaps.

            </p>

        </div>

        <a
            href="dashboard.php"
            class="btn btn-outline-secondary"
        >
            Back to Dashboard
        </a>

    </div>


    <!-- INFORMATION -->

    <div class="alert alert-info mb-4">

        <strong>AI Recommendation Engine:</strong>

        OUTLIERS analyzes employee competency gaps and recommends
        appropriate training priorities and learning actions.

    </div>


    <!-- GENERATE -->

    <div class="card shadow-sm mb-4">

        <div class="card-body text-center py-4">

            <h5 class="fw-bold">
                Generate Personalized Recommendations
            </h5>

            <p class="text-muted">

                The AI will analyze the competency data of your
                approved employees.

            </p>

            <form method="POST">

                <button
                    type="submit"
                    class="btn btn-primary btn-lg"
                >
                    Generate AI Recommendations
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


    <!-- RESULTS -->

    <?php if ($generated): ?>

        <div class="d-flex justify-content-between align-items-center mb-3">

            <h4 class="fw-bold mb-0">
                Training Recommendations
            </h4>

            <span class="badge bg-primary">

                <?php
                    echo count($recommendations);
                ?>

                Recommendations

            </span>

        </div>


        <div class="row g-4">

        <?php foreach ($recommendations as $recommendation): ?>

            <?php

                $priority =
                    $recommendation["priority"]
                    ?? "Medium";

                if (strcasecmp($priority, "High") === 0) {

                    $priority_class =
                        "bg-danger";

                } elseif (
                    strcasecmp($priority, "Medium") === 0
                ) {

                    $priority_class =
                        "bg-warning text-dark";

                } else {

                    $priority_class =
                        "bg-success";
                }

            ?>


            <div class="col-md-6">

                <div class="card shadow-sm h-100">

                    <div class="card-body">


                        <!-- EMPLOYEE -->

                        <div class="d-flex justify-content-between">

                            <div>

                                <h5 class="fw-bold mb-1">

                                    <?php
                                        echo htmlspecialchars(
                                            $recommendation[
                                                "employee_name"
                                            ]
                                            ?? "Employee"
                                        );
                                    ?>

                                </h5>

                                <small class="text-muted">

                                    Employee ID:
                                    <?php
                                        echo htmlspecialchars(
                                            (string)(
                                                $recommendation[
                                                    "employee_id"
                                                ]
                                                ?? ""
                                            )
                                        );
                                    ?>

                                </small>

                            </div>


                            <span
                                class="badge
                                <?php
                                    echo $priority_class;
                                ?>"
                            >

                                <?php
                                    echo htmlspecialchars(
                                        $priority
                                    );
                                ?>

                            </span>

                        </div>


                        <hr>


                        <!-- SKILL -->

                        <h6 class="fw-bold">

                            Skill Gap:
                            <?php
                                echo htmlspecialchars(
                                    $recommendation["skill"]
                                    ?? "Not specified"
                                );
                            ?>

                        </h6>


                        <!-- LEVELS -->

                        <div class="row text-center mb-3">

                            <div class="col-4">

                                <small class="text-muted">
                                    Current
                                </small>

                                <div class="fw-bold">

                                    <?php
                                        echo (int)(
                                            $recommendation[
                                                "current_level"
                                            ] ?? 0
                                        );
                                    ?>

                                </div>

                            </div>


                            <div class="col-4">

                                <small class="text-muted">
                                    Required
                                </small>

                                <div class="fw-bold">

                                    <?php
                                        echo (int)(
                                            $recommendation[
                                                "required_level"
                                            ] ?? 0
                                        );
                                    ?>

                                </div>

                            </div>


                            <div class="col-4">

                                <small class="text-muted">
                                    Gap
                                </small>

                                <div class="fw-bold">

                                    <?php
                                        echo (int)(
                                            $recommendation[
                                                "gap"
                                            ] ?? 0
                                        );
                                    ?>

                                </div>

                            </div>

                        </div>


                        <!-- DIFFICULTY -->

                        <p class="mb-2">

                            <strong>
                                Recommended Difficulty:
                            </strong>

                            <?php
                                echo htmlspecialchars(
                                    $recommendation[
                                        "recommended_difficulty"
                                    ]
                                    ?? "Not specified"
                                );
                            ?>

                        </p>


                        <!-- ACTION -->

                        <p class="mb-2">

                            <strong>
                                Training Action:
                            </strong>

                            <br>

                            <?php
                                echo htmlspecialchars(
                                    $recommendation[
                                        "training_action"
                                    ]
                                    ?? "No action specified."
                                );
                            ?>

                        </p>


                        <!-- REASON -->

                        <p class="mb-0">

                            <strong>
                                Reason:
                            </strong>

                            <br>

                            <span class="text-muted">

                                <?php
                                    echo htmlspecialchars(
                                        $recommendation[
                                            "reason"
                                        ]
                                        ?? "No reason provided."
                                    );
                                ?>

                            </span>

                        </p>

                    </div>

                </div>

            </div>


        <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <!-- FOOTER ACTIONS -->

    <div class="text-center mt-5">

        <a
            href="ai-skill-analysis.php"
            class="btn btn-outline-primary me-2"
        >
            AI Skill Analysis
        </a>

        <a
            href="course-progress.php"
            class="btn btn-outline-primary me-2"
        >
            Course Progress
        </a>

        <a
            href="quiz-results.php"
            class="btn btn-outline-success"
        >
            Quiz Results
        </a>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>