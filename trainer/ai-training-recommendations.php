<?php

session_start();

require_once "../includes/db.php";
require_once "../includes/gemini.php";


/*
|--------------------------------------------------------------------------
| TRAINER AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "trainer"
) {
    header("Location: ../employee-login.php");
    exit;
}

$trainer_id = (int) $_SESSION["user_id"];


/*
|--------------------------------------------------------------------------
| VARIABLES
|--------------------------------------------------------------------------
*/

$recommendations = [];
$error_message = "";
$success_message = "";
$generated = false;


/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

function cleanGeminiJson($text)
{
    $text = trim((string) $text);

    if ($text === "") {
        return "";
    }

    /*
     * Remove markdown code fences if Gemini happens
     * to return them.
     */

    $text = preg_replace(
        '/^```json\s*/i',
        '',
        $text
    );

    $text = preg_replace(
        '/^```\s*/',
        '',
        $text
    );

    $text = preg_replace(
        '/\s*```$/',
        '',
        $text
    );

    return trim($text);
}


function getLevelName($level)
{
    $level = (int) $level;

    switch ($level) {

        case 4:
            return "Advanced";

        case 3:
            return "Intermediate";

        case 2:
            return "Basic";

        case 1:
            return "Beginner";

        default:
            return "Not Assessed";
    }
}


function getDifficultyFromLevel($current_level)
{
    $current_level = (int) $current_level;

    if ($current_level <= 1) {
        return "Beginner";
    }

    if ($current_level == 2) {
        return "Intermediate";
    }

    return "Advanced";
}


function getPriorityFromGap($gap)
{
    $gap = (int) $gap;

    if ($gap >= 2) {
        return "High";
    }

    return "Medium";
}


/*
|--------------------------------------------------------------------------
| LOAD EMPLOYEES + ROLE SKILLS + EMPLOYEE SKILLS
|--------------------------------------------------------------------------
|
| We use the same normalized role matching:
| LOWER(TRIM(role_name)) = LOWER(TRIM(designation))
|
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        e.employee_id,
        e.employee_code,
        e.full_name,
        e.department,
        e.designation,

        s.skill_id,
        s.skill_name,
        s.category,

        rs.required_level,

        COALESCE(
            es.current_level,
            0
        ) AS current_level

    FROM trainer_employee te

    INNER JOIN employees e
        ON te.employee_id = e.employee_id

    INNER JOIN roles r
        ON LOWER(TRIM(r.role_name))
           =
           LOWER(TRIM(e.designation))

    INNER JOIN role_skills rs
        ON r.role_id = rs.role_id

    INNER JOIN skills s
        ON rs.skill_id = s.skill_id

    LEFT JOIN employee_skills es
        ON es.employee_id = e.employee_id
        AND es.skill_id = s.skill_id

    WHERE
        te.trainer_id = ?
        AND te.status = 'approved'

    ORDER BY
        e.full_name ASC,
        s.skill_name ASC
";


$stmt = $conn->prepare($sql);

if (!$stmt) {

    $error_message =
        "Database error while loading employee skill data: "
        . $conn->error;

} else {

    $stmt->bind_param(
        "i",
        $trainer_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $employee_data = [];

    while ($row = $result->fetch_assoc()) {

        $employee_id =
            (int) $row["employee_id"];

        $current_level =
            (int) $row["current_level"];

        $required_level =
            (int) $row["required_level"];

        $gap =
            $required_level -
            $current_level;

        if ($gap < 0) {
            $gap = 0;
        }


        if (
            !isset(
                $employee_data[$employee_id]
            )
        ) {

            $employee_data[$employee_id] = [

                "employee_id" =>
                    $employee_id,

                "employee_code" =>
                    $row["employee_code"],

                "full_name" =>
                    $row["full_name"],

                "department" =>
                    $row["department"],

                "designation" =>
                    $row["designation"],

                "skills" => []
            ];
        }


        $employee_data[
            $employee_id
        ]["skills"][] = [

            "skill_id" =>
                (int) $row["skill_id"],

            "skill_name" =>
                $row["skill_name"],

            "category" =>
                $row["category"],

            "current_level" =>
                $current_level,

            "required_level" =>
                $required_level,

            "gap" =>
                $gap
        ];
    }

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| GENERATE RECOMMENDATIONS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    empty($error_message)
) {

    /*
    |--------------------------------------------------------------------------
    | Collect only actual skill gaps
    |--------------------------------------------------------------------------
    */

    $gap_data = [];

    foreach (
        $employee_data as $employee
    ) {

        foreach (
            $employee["skills"] as $skill
        ) {

            if (
                (int) $skill["gap"] <= 0
            ) {
                continue;
            }


            $gap_data[] = [

                "employee_id" =>
                    (int)
                    $employee["employee_id"],

                "employee_name" =>
                    $employee["full_name"],

                "employee_code" =>
                    $employee["employee_code"],

                "department" =>
                    $employee["department"],

                "designation" =>
                    $employee["designation"],

                "skill_id" =>
                    (int)
                    $skill["skill_id"],

                "skill" =>
                    $skill["skill_name"],

                "category" =>
                    $skill["category"],

                "current_level" =>
                    (int)
                    $skill["current_level"],

                "required_level" =>
                    (int)
                    $skill["required_level"],

                "gap" =>
                    (int)
                    $skill["gap"]
            ];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | No gaps
    |--------------------------------------------------------------------------
    */

    if (
        count($gap_data) === 0
    ) {

        $error_message =
            "No skill gaps were found for the approved employees assigned to you.";

    } else {


        /*
        |--------------------------------------------------------------------------
        | Prepare JSON DATA for Gemini
        |--------------------------------------------------------------------------
        */

        $gap_json = json_encode(
            $gap_data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE
        );


        if ($gap_json === false) {

            $error_message =
                "Unable to prepare employee skill-gap data for Gemini.";

        } else {


            /*
            |--------------------------------------------------------------------------
            | GEMINI PROMPT
            |--------------------------------------------------------------------------
            */

            $prompt = <<<PROMPT
You are the AI Training Recommendation Engine for the OUTLIERS learning platform.

Your task is to analyze employee competency gaps and recommend suitable training actions.

IMPORTANT RULES:

1. Use ONLY the employees and skills supplied in the input data.
2. Do NOT invent employee IDs.
3. Do NOT invent skill IDs.
4. Do NOT recommend training for a skill whose gap is 0.
5. Every recommendation must correspond to one of the supplied employee/skill combinations.
6. Give practical and concise training recommendations.
7. Recommend an appropriate learning difficulty:
   - Beginner
   - Intermediate
   - Advanced
8. Assign priority:
   - High when the competency gap is 2 or more levels.
   - Medium when the competency gap is 1 level.
9. The current_level and required_level values must come from the supplied data.
10. Return only the requested structured JSON output.

EMPLOYEE COMPETENCY GAP DATA:

$gap_json
PROMPT;


            /*
            |--------------------------------------------------------------------------
            | DIRECT GEMINI REQUEST
            |--------------------------------------------------------------------------
            |
            | askGemini() from includes/gemini.php is used.
            |
            | We do NOT use fallback.
            | We do NOT retry.
            |
            |--------------------------------------------------------------------------
            */

            $response = askGemini(
                $prompt
            );


            /*
            |--------------------------------------------------------------------------
            | Check Gemini API error
            |--------------------------------------------------------------------------
            */

            if (
                is_string($response) &&
                strpos(
                    $response,
                    "ERROR:"
                ) === 0
            ) {

                $error_message =
                    $response;

            } else {

                /*
                |--------------------------------------------------------------------------
                | Clean Gemini response
                |--------------------------------------------------------------------------
                */

                $clean_json =
                    cleanGeminiJson(
                        $response
                    );


                /*
                |--------------------------------------------------------------------------
                | Decode JSON
                |--------------------------------------------------------------------------
                */

                $decoded =
                    json_decode(
                        $clean_json,
                        true
                    );


                if (
                    json_last_error()
                    !==
                    JSON_ERROR_NONE
                ) {

                    $error_message =
                        "Gemini returned invalid JSON. JSON error: "
                        .
                        json_last_error_msg();

                } elseif (
                    !is_array($decoded)
                ) {

                    $error_message =
                        "Gemini returned an unexpected recommendation format.";

                } else {


                    /*
                    |--------------------------------------------------------------------------
                    | Gemini can return either:
                    |
                    | [
                    |   {...},
                    |   {...}
                    | ]
                    |
                    | OR:
                    |
                    | {
                    |   "recommendations": [...]
                    | }
                    |--------------------------------------------------------------------------
                    */

                    if (
                        isset(
                            $decoded[
                                "recommendations"
                            ]
                        ) &&
                        is_array(
                            $decoded[
                                "recommendations"
                            ]
                        )
                    ) {

                        $decoded =
                            $decoded[
                                "recommendations"
                            ];
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Validate recommendations
                    |--------------------------------------------------------------------------
                    */

                    $valid_count = 0;

                    foreach (
                        $decoded as $item
                    ) {

                        if (
                            !is_array($item)
                        ) {
                            continue;
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Required Gemini fields
                        |--------------------------------------------------------------------------
                        */

                        $employee_id =
                            isset(
                                $item[
                                    "employee_id"
                                ]
                            )
                            ?
                            (int)
                            $item[
                                "employee_id"
                            ]
                            :
                            0;


                        $skill_id =
                            isset(
                                $item[
                                    "skill_id"
                                ]
                            )
                            ?
                            (int)
                            $item[
                                "skill_id"
                            ]
                            :
                            0;


                        if (
                            $employee_id <= 0 ||
                            $skill_id <= 0
                        ) {
                            continue;
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Verify employee really belongs to trainer
                        |--------------------------------------------------------------------------
                        */

                        if (
                            !isset(
                                $employee_data[
                                    $employee_id
                                ]
                            )
                        ) {
                            continue;
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Verify skill really belongs to employee's
                        | role skill mapping
                        |--------------------------------------------------------------------------
                        */

                        $actual_skill = null;

                        foreach (
                            $employee_data[
                                $employee_id
                            ]["skills"] as $db_skill
                        ) {

                            if (
                                (int)
                                $db_skill[
                                    "skill_id"
                                ]
                                ===
                                $skill_id
                            ) {

                                $actual_skill =
                                    $db_skill;

                                break;
                            }
                        }


                        if (
                            $actual_skill === null
                        ) {
                            continue;
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Ignore skills with no gap
                        |--------------------------------------------------------------------------
                        */

                        $gap =
                            (int)
                            $actual_skill[
                                "gap"
                            ];

                        if (
                            $gap <= 0
                        ) {
                            continue;
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Use DATABASE values for factual data
                        |--------------------------------------------------------------------------
                        */

                        $current_level =
                            (int)
                            $actual_skill[
                                "current_level"
                            ];

                        $required_level =
                            (int)
                            $actual_skill[
                                "required_level"
                            ];

                        $skill_name =
                            $actual_skill[
                                "skill_name"
                            ];


                        /*
                        |--------------------------------------------------------------------------
                        | Difficulty
                        |--------------------------------------------------------------------------
                        */

                        $difficulty =
                            trim(
                                $item[
                                    "recommended_difficulty"
                                ]
                                ??
                                $item[
                                    "difficulty"
                                ]
                                ??
                                ""
                            );


                        $allowed_difficulties = [
                            "Beginner",
                            "Intermediate",
                            "Advanced"
                        ];


                        if (
                            !in_array(
                                $difficulty,
                                $allowed_difficulties,
                                true
                            )
                        ) {

                            $difficulty =
                                getDifficultyFromLevel(
                                    $current_level
                                );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Priority
                        |--------------------------------------------------------------------------
                        */

                        $priority =
                            trim(
                                $item[
                                    "priority"
                                ]
                                ??
                                ""
                            );


                        if (
                            !in_array(
                                $priority,
                                [
                                    "High",
                                    "Medium"
                                ],
                                true
                            )
                        ) {

                            $priority =
                                getPriorityFromGap(
                                    $gap
                                );
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Training action
                        |--------------------------------------------------------------------------
                        */

                        $training_action =
                            trim(
                                $item[
                                    "training_action"
                                ]
                                ??
                                ""
                            );


                        if (
                            $training_action === ""
                        ) {

                            $training_action =
                                "Complete "
                                .
                                $difficulty
                                .
                                " level training for "
                                .
                                $skill_name
                                .
                                ".";
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Reason
                        |--------------------------------------------------------------------------
                        */

                        $reason =
                            trim(
                                $item[
                                    "reason"
                                ]
                                ??
                                ""
                            );


                        if (
                            $reason === ""
                        ) {

                            $reason =
                                "The employee currently has a competency gap in "
                                .
                                $skill_name
                                .
                                ".";
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | Store final recommendation
                        |--------------------------------------------------------------------------
                        */

                        $recommendations[] = [

                            "employee_id" =>
                                $employee_id,

                            "employee_name" =>
                                $employee_data[
                                    $employee_id
                                ]["full_name"],

                            "employee_code" =>
                                $employee_data[
                                    $employee_id
                                ]["employee_code"],

                            "department" =>
                                $employee_data[
                                    $employee_id
                                ]["department"],

                            "designation" =>
                                $employee_data[
                                    $employee_id
                                ]["designation"],

                            "skill_id" =>
                                $skill_id,

                            "skill" =>
                                $skill_name,

                            "current_level" =>
                                $current_level,

                            "required_level" =>
                                $required_level,

                            "gap" =>
                                $gap,

                            "priority" =>
                                $priority,

                            "difficulty" =>
                                $difficulty,

                            "training_action" =>
                                $training_action,

                            "reason" =>
                                $reason
                        ];


                        $valid_count++;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Final validation
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $valid_count === 0
                    ) {

                        $error_message =
                            "Gemini returned recommendations, but none matched the actual employee skill gaps in the database.";

                    } else {

                        $generated = true;

                        $success_message =
                            "Live Gemini AI recommendations generated successfully.";
                    }
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Sort recommendations
|--------------------------------------------------------------------------
*/

if (
    count($recommendations) > 0
) {

    usort(
        $recommendations,
        function (
            $a,
            $b
        ) {

            $priority_order = [

                "High" => 1,

                "Medium" => 2
            ];


            $a_priority =
                $priority_order[
                    $a["priority"]
                ]
                ??
                3;


            $b_priority =
                $priority_order[
                    $b["priority"]
                ]
                ??
                3;


            if (
                $a_priority
                !==
                $b_priority
            ) {

                return
                    $a_priority
                    <=>
                    $b_priority;
            }


            return
                $b["gap"]
                <=>
                $a["gap"];
        }
    );
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


    <!-- PAGE HEADER -->

    <div
        class="d-flex
               justify-content-between
               align-items-center
               mb-4"
    >

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

    <div
        class="alert alert-info"
    >

        <strong>
            Live Gemini AI:
        </strong>

        OUTLIERS sends the actual employee competency
        gaps to Gemini and receives personalized
        training recommendations.

    </div>


    <!-- SUCCESS -->

    <?php if ($success_message !== ""): ?>

        <div
            class="alert alert-success"
        >

            <?php

                echo htmlspecialchars(
                    $success_message
                );

            ?>

        </div>

    <?php endif; ?>


    <!-- ERROR -->

    <?php if ($error_message !== ""): ?>

        <div
            class="alert alert-danger"
        >

            <strong>
                Gemini / System Error:
            </strong>

            <br>

            <?php

                echo nl2br(
                    htmlspecialchars(
                        $error_message
                    )
                );

            ?>

        </div>

    <?php endif; ?>


    <!-- GENERATE BUTTON -->

    <div
        class="card
               shadow-sm
               mb-4"
    >

        <div
            class="card-body
                   text-center
                   py-4"
        >

            <h5 class="fw-bold">

                Generate Live AI Recommendations

            </h5>


            <p class="text-muted">

                Gemini will analyze the competency
                gaps of your approved employees.

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


    <!-- RECOMMENDATIONS -->

    <?php if ($generated): ?>


        <div
            class="d-flex
                   justify-content-between
                   align-items-center
                   mb-3"
        >

            <h4 class="fw-bold mb-0">

                Gemini AI Recommendations

            </h4>


            <span
                class="badge bg-primary"
            >

                <?php

                    echo count(
                        $recommendations
                    );

                ?>

                Recommendations

            </span>

        </div>


        <div class="row g-4">


            <?php foreach (
                $recommendations
                as $recommendation
            ): ?>


                <?php

                    if (
                        $recommendation[
                            "priority"
                        ]
                        ===
                        "High"
                    ) {

                        $priority_class =
                            "bg-danger";

                    } else {

                        $priority_class =
                            "bg-warning text-dark";
                    }

                ?>


                <div class="col-md-6">


                    <div
                        class="card
                               shadow-sm
                               h-100"
                    >

                        <div class="card-body">


                            <!-- EMPLOYEE -->

                            <div
                                class="d-flex
                                       justify-content-between
                                       align-items-start"
                            >

                                <div>

                                    <h5
                                        class="fw-bold mb-1"
                                    >

                                        <?php

                                            echo htmlspecialchars(
                                                $recommendation[
                                                    "employee_name"
                                                ]
                                            );

                                        ?>

                                    </h5>


                                    <small
                                        class="text-muted"
                                    >

                                        Employee Code:

                                        <?php

                                            echo htmlspecialchars(
                                                $recommendation[
                                                    "employee_code"
                                                ]
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
                                            $recommendation[
                                                "priority"
                                            ]
                                        );

                                    ?>

                                    Priority

                                </span>

                            </div>


                            <hr>


                            <!-- AI BADGE -->

                            <span
                                class="badge
                                       bg-success
                                       mb-3"
                            >

                                Gemini AI Generated

                            </span>


                            <!-- SKILL -->

                            <h5 class="fw-bold">

                                <?php

                                    echo htmlspecialchars(
                                        $recommendation[
                                            "skill"
                                        ]
                                    );

                                ?>

                            </h5>


                            <!-- COMPETENCY -->

                            <div
                                class="row
                                       text-center
                                       mb-3"
                            >

                                <div class="col-4">

                                    <small
                                        class="text-muted"
                                    >

                                        Current Level

                                    </small>


                                    <div
                                        class="fw-bold fs-5"
                                    >

                                        <?php

                                            echo
                                                (int)
                                                $recommendation[
                                                    "current_level"
                                                ];

                                        ?>

                                    </div>

                                </div>


                                <div class="col-4">

                                    <small
                                        class="text-muted"
                                    >

                                        Required Level

                                    </small>


                                    <div
                                        class="fw-bold fs-5"
                                    >

                                        <?php

                                            echo
                                                (int)
                                                $recommendation[
                                                    "required_level"
                                                ];

                                        ?>

                                    </div>

                                </div>


                                <div class="col-4">

                                    <small
                                        class="text-muted"
                                    >

                                        Skill Gap

                                    </small>


                                    <div
                                        class="fw-bold
                                               fs-5
                                               text-danger"
                                    >

                                        <?php

                                            echo
                                                (int)
                                                $recommendation[
                                                    "gap"
                                                ];

                                        ?>

                                    </div>

                                </div>

                            </div>


                            <!-- DIFFICULTY -->

                            <p class="mb-2">

                                <strong>
                                    Recommended Difficulty:
                                </strong>


                                <span
                                    class="badge
                                           bg-secondary"
                                >

                                    <?php

                                        echo htmlspecialchars(
                                            $recommendation[
                                                "difficulty"
                                            ]
                                        );

                                    ?>

                                </span>

                            </p>


                            <!-- TRAINING ACTION -->

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
                                    );

                                ?>

                            </p>


                            <!-- REASON -->

                            <p class="mb-3">

                                <strong>
                                    AI Reason:
                                </strong>

                                <br>

                                <span
                                    class="text-muted"
                                >

                                    <?php

                                        echo htmlspecialchars(
                                            $recommendation[
                                                "reason"
                                            ]
                                        );

                                    ?>

                                </span>

                            </p>


                            <!-- FIND COURSE -->

                            <a
                                href="recommendation-assign.php?employee_id=<?php
                                    echo (int)
                                        $recommendation[
                                            "employee_id"
                                        ];
                                ?>&skill=<?php
                                    echo urlencode(
                                        $recommendation[
                                            "skill"
                                        ]
                                    );
                                ?>&difficulty=<?php
                                    echo urlencode(
                                        $recommendation[
                                            "difficulty"
                                        ]
                                    );
                                ?>"
                                class="btn btn-primary"
                            >

                                Find Matching Course

                            </a>


                        </div>

                    </div>

                </div>


            <?php endforeach; ?>


        </div>


    <?php endif; ?>


    <!-- FOOTER LINKS -->

    <div
        class="text-center
               mt-5"
    >

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