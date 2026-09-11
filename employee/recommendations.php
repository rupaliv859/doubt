<?php

session_start();

require_once "../includes/db.php";


/* =========================================
   1. CHECK EMPLOYEE LOGIN
========================================= */

if (
    !isset($_SESSION["user_id"]) ||
    $_SESSION["role"] !== "employee"
) {
    header("Location: ../employee-login.php");
    exit();
}

$user_id = (int) $_SESSION["user_id"];


/* =========================================
   2. GET EMPLOYEE INFORMATION
========================================= */

$employee_sql = "SELECT
                    employee_id,
                    full_name,
                    designation
                 FROM employees
                 WHERE user_id = ?";

$stmt = $conn->prepare($employee_sql);

if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();

$employee_result = $stmt->get_result();

if ($employee_result->num_rows === 0) {
    die("Employee profile not found.");
}

$employee = $employee_result->fetch_assoc();

$employee_id = (int) $employee["employee_id"];
$full_name = $employee["full_name"];
$designation = $employee["designation"];

$stmt->close();


/* =========================================
   3. FIND EMPLOYEE ROLE
========================================= */

/*
   The employee designation is matched
   with roles.role_name.

   TRIM + LOWER makes the matching more
   tolerant of capitalization/spaces.
*/

$role_sql = "SELECT
                role_id,
                role_name
             FROM roles
             WHERE LOWER(TRIM(role_name))
                   = LOWER(TRIM(?))
             LIMIT 1";

$stmt = $conn->prepare($role_sql);

if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param("s", $designation);
$stmt->execute();

$role_result = $stmt->get_result();

$role = null;

if ($role_result->num_rows > 0) {
    $role = $role_result->fetch_assoc();
}

$stmt->close();


/* =========================================
   4. PREPARE RECOMMENDATIONS ARRAY
========================================= */

$recommendations = [];

$role_found = false;

if ($role !== null) {

    $role_found = true;

    $role_id = (int) $role["role_id"];


    /* =========================================
       5. GET SKILL GAPS
    ========================================= */

    /*
       For every required skill:

       Required Level - Current Level = Gap

       Only skills where Gap > 0 are selected.
    */

    $gap_sql = "SELECT
                    s.skill_id,
                    s.skill_name,
                    s.category,

                    rs.required_level,

                    COALESCE(
                        es.current_level,
                        0
                    ) AS current_level

                FROM role_skills rs

                INNER JOIN skills s
                    ON rs.skill_id = s.skill_id

                LEFT JOIN employee_skills es
                    ON es.skill_id = s.skill_id
                    AND es.employee_id = ?

                WHERE rs.role_id = ?

                AND rs.required_level >
                    COALESCE(es.current_level, 0)

                ORDER BY
                    (
                        rs.required_level -
                        COALESCE(es.current_level, 0)
                    ) DESC,

                    s.skill_name ASC";


    $stmt = $conn->prepare($gap_sql);

    if (!$stmt) {
        die("Database error: " . $conn->error);
    }

    $stmt->bind_param(
        "ii",
        $employee_id,
        $role_id
    );

    $stmt->execute();

    $gap_result = $stmt->get_result();


    /* =========================================
       6. FIND ONE BEST COURSE PER SKILL
    ========================================= */

    /*
       We loop through every skill gap.

       For each skill, we select ONE course.

       Priority for selecting a course:

       1. Course exists for the skill.
       2. Prefer a course whose difficulty
          matches the employee's gap.
       3. If multiple courses still match,
          use the lowest course_id.

       This prevents multiple courses for
       the same skill from appearing.
    */

    while ($gap = $gap_result->fetch_assoc()) {

        $skill_id = (int) $gap["skill_id"];

        $skill_name = $gap["skill_name"];

        $category = $gap["category"];

        $required_level =
            (int) $gap["required_level"];

        $current_level =
            (int) $gap["current_level"];


        $skill_gap =
            $required_level - $current_level;


        if ($skill_gap <= 0) {
            continue;
        }


        /* =====================================
           DETERMINE RECOMMENDED DIFFICULTY
        ===================================== */

        if ($current_level <= 1) {

            $preferred_difficulty = "Beginner";

        } elseif ($current_level == 2) {

            $preferred_difficulty = "Intermediate";

        } else {

            $preferred_difficulty = "Advanced";
        }


        /* =====================================
           GET ONE BEST COURSE
        ===================================== */

        /*
           First try to find a course matching
           the preferred difficulty.

           If none exists, take the first course
           available for that skill.
        */

        $course_sql = "SELECT
                            course_id,
                            course_name,
                            description,
                            difficulty

                       FROM courses

                       WHERE skill_id = ?

                       ORDER BY
                           CASE
                               WHEN LOWER(TRIM(difficulty))
                                    =
                                    LOWER(TRIM(?))
                               THEN 0
                               ELSE 1
                           END,

                           course_id ASC

                       LIMIT 1";


        $course_stmt = $conn->prepare($course_sql);

        if (!$course_stmt) {
            continue;
        }

        $course_stmt->bind_param(
            "is",
            $skill_id,
            $preferred_difficulty
        );

        $course_stmt->execute();

        $course_result =
            $course_stmt->get_result();


        if ($course_result->num_rows === 0) {

            $course_stmt->close();

            continue;
        }


        $course =
            $course_result->fetch_assoc();

        $course_stmt->close();


        $course_id =
            (int) $course["course_id"];


        /* =====================================
           DETERMINE PRIORITY
        ===================================== */

        if ($skill_gap >= 3) {

            $priority = "High";

        } elseif ($skill_gap == 2) {

            $priority = "Medium";

        } else {

            $priority = "Low";
        }


        /* =====================================
           CREATE REASON
        ===================================== */

        $reason =
            "Your current competency in " .
            $skill_name .
            " is " .
            getLevelName($current_level) .
            ", while your role requires " .
            getLevelName($required_level) .
            ".";


        /* =====================================
           7. SAVE RECOMMENDATION
        ===================================== */

        /*
           Check whether this exact recommendation
           already exists.

           This prevents duplicate rows when the
           employee refreshes the page.
        */

        $check_sql = "SELECT
                        recommendation_id
                      FROM recommendations
                      WHERE employee_id = ?
                      AND skill_id = ?
                      AND course_id = ?
                      LIMIT 1";

        $check_stmt =
            $conn->prepare($check_sql);


        if ($check_stmt) {

            $check_stmt->bind_param(
                "iii",
                $employee_id,
                $skill_id,
                $course_id
            );

            $check_stmt->execute();

            $check_result =
                $check_stmt->get_result();


            if ($check_result->num_rows === 0) {

                /*
                   generated_by_ai = 0

                   because this recommendation
                   is currently generated using
                   competency-gap rules.
                */

                $insert_sql =
                    "INSERT INTO recommendations
                    (
                        employee_id,
                        skill_id,
                        course_id,
                        reason,
                        priority,
                        generated_by_ai
                    )
                    VALUES (?, ?, ?, ?, ?, 0)";


                $insert_stmt =
                    $conn->prepare($insert_sql);


                if ($insert_stmt) {

                    $insert_stmt->bind_param(
                        "iiiss",
                        $employee_id,
                        $skill_id,
                        $course_id,
                        $reason,
                        $priority
                    );

                    $insert_stmt->execute();

                    $insert_stmt->close();
                }
            }


            $check_stmt->close();
        }


        /* =====================================
           8. ADD TO DISPLAY ARRAY
        ===================================== */

        $recommendations[] = [

            "skill_id" =>
                $skill_id,

            "skill_name" =>
                $skill_name,

            "category" =>
                $category,

            "required_level" =>
                $required_level,

            "current_level" =>
                $current_level,

            "gap" =>
                $skill_gap,

            "course_id" =>
                $course_id,

            "course_name" =>
                $course["course_name"],

            "course_description" =>
                $course["description"],

            "difficulty" =>
                $course["difficulty"],

            "priority" =>
                $priority,

            "reason" =>
                $reason
        ];
    }


    $stmt->close();
}


/* =========================================
   9. HELPER FUNCTIONS
========================================= */

function getLevelName($level)
{
    switch ((int) $level) {

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


function getPriorityClass($priority)
{
    switch ($priority) {

        case "High":
            return "danger";

        case "Medium":
            return "warning";

        default:
            return "info";
    }
}


function getPriorityText($priority)
{
    switch ($priority) {

        case "High":
            return "High Priority";

        case "Medium":
            return "Medium Priority";

        default:
            return "Low Priority";
    }
}

?>


<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        Recommendations - OUTLIERS
    </title>


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">


    <!-- Main CSS -->

    <link
        rel="stylesheet"
        href="../css/style.css">


    <style>

        .recommendation-card {

            border: none;
            border-radius: 16px;

            box-shadow:
                0 4px 15px rgba(0,0,0,0.08);

            transition: 0.2s;

        }


        .recommendation-card:hover {

            transform: translateY(-3px);

        }


        .page-header {

            background:
                linear-gradient(
                    135deg,
                    #0d1b3e,
                    #0d6efd
                );

            color: white;

            border-radius: 18px;

            padding: 30px;

        }


        .priority-badge {

            font-size: 12px;

            padding: 7px 11px;

            border-radius: 20px;

        }


        .gap-box {

            background: #f5f7fb;

            border-radius: 10px;

            padding: 12px;

        }


        .course-icon {

            width: 50px;

            height: 50px;

            border-radius: 12px;

            background: #e8f1ff;

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: bold;

            color: #0d6efd;

            font-size: 18px;

        }


        .skill-name {

            color: #0d6efd;

        }

    </style>

</head>


<body>


<!-- =========================================
     NAVBAR
========================================= -->

<nav class="navbar navbar-dark bg-dark">

    <div class="container">

        <a
            class="navbar-brand fw-bold"
            href="dashboard.php">

            OUTLIERS

        </a>


        <div>

            <a
                href="dashboard.php"
                class="btn btn-outline-light btn-sm">

                Dashboard

            </a>


            <a
                href="../logout.php"
                class="btn btn-danger btn-sm">

                Logout

            </a>

        </div>

    </div>

</nav>


<!-- =========================================
     MAIN CONTENT
========================================= -->

<div class="container py-5">


    <!-- =====================================
         PAGE HEADER
    ====================================== -->

    <div class="page-header mb-4">

        <h2 class="fw-bold">

            Personalized Learning Recommendations

        </h2>


        <p class="mb-0">

            Hello
            <?php
            echo htmlspecialchars($full_name);
            ?>.

            Based on your competency gaps,
            OUTLIERS recommends the most relevant
            course for each skill that needs improvement.

        </p>

    </div>


    <!-- =====================================
         ROLE INFORMATION
    ====================================== -->

    <div class="card recommendation-card mb-4">

        <div class="card-body">

            <h5 class="fw-bold mb-2">

                Recommendations for

            </h5>


            <span class="badge bg-primary">

                <?php
                echo htmlspecialchars($designation);
                ?>

            </span>


            <?php if (!$role_found): ?>

                <div class="alert alert-warning mt-3 mb-0">

                    <strong>
                        Role mapping not found.
                    </strong>

                    <br>

                    Your designation does not currently
                    match a role configured in OUTLIERS.
                    Please ask the administrator to map
                    your role to the required skills.

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================
         RECOMMENDATIONS
    ====================================== -->

    <?php if (count($recommendations) > 0): ?>


        <div class="row">


            <?php
            foreach ($recommendations as $recommendation):
            ?>


                <?php

                $priority_class =
                    getPriorityClass(
                        $recommendation["priority"]
                    );

                $priority_text =
                    getPriorityText(
                        $recommendation["priority"]
                    );

                ?>


                <div class="col-lg-6 mb-4">


                    <div
                        class="card recommendation-card h-100">


                        <div class="card-body p-4">


                            <!-- COURSE HEADER -->

                            <div
                                class="d-flex
                                       align-items-start
                                       gap-3
                                       mb-3">


                                <div class="course-icon">

                                    AI

                                </div>


                                <div
                                    class="flex-grow-1">


                                    <div class="mb-1">

                                        <span
                                            class="badge bg-light text-dark">

                                            <?php
                                            echo htmlspecialchars(
                                                $recommendation[
                                                    "category"
                                                ]
                                            );
                                            ?>

                                        </span>

                                    </div>


                                    <h4
                                        class="fw-bold mt-2 mb-1">

                                        <?php
                                        echo htmlspecialchars(
                                            $recommendation[
                                                "course_name"
                                            ]
                                        );
                                        ?>

                                    </h4>


                                    <span
                                        class="badge bg-secondary">

                                        <?php
                                        echo htmlspecialchars(
                                            $recommendation[
                                                "difficulty"
                                            ]
                                        );
                                        ?>

                                    </span>

                                </div>

                            </div>


                            <!-- SKILL -->

                            <div class="mb-3">

                                <small class="text-muted">

                                    Skill to improve

                                </small>


                                <div
                                    class="fw-bold skill-name">

                                    <?php
                                    echo htmlspecialchars(
                                        $recommendation[
                                            "skill_name"
                                        ]
                                    );
                                    ?>

                                </div>

                            </div>


                            <!-- COURSE DESCRIPTION -->

                            <p class="text-muted">

                                <?php
                                echo htmlspecialchars(
                                    $recommendation[
                                        "course_description"
                                    ]
                                );
                                ?>

                            </p>


                            <!-- GAP INFORMATION -->

                            <div
                                class="gap-box mb-3">


                                <div
                                    class="row text-center">


                                    <!-- REQUIRED -->

                                    <div class="col-4">

                                        <small
                                            class="text-muted">

                                            Required

                                        </small>

                                        <br>

                                        <strong>

                                            <?php
                                            echo getLevelName(
                                                $recommendation[
                                                    "required_level"
                                                ]
                                            );
                                            ?>

                                        </strong>

                                    </div>


                                    <!-- CURRENT -->

                                    <div class="col-4">

                                        <small
                                            class="text-muted">

                                            Current

                                        </small>

                                        <br>

                                        <strong>

                                            <?php
                                            echo getLevelName(
                                                $recommendation[
                                                    "current_level"
                                                ]
                                            );
                                            ?>

                                        </strong>

                                    </div>


                                    <!-- GAP -->

                                    <div class="col-4">

                                        <small
                                            class="text-muted">

                                            Skill Gap

                                        </small>

                                        <br>

                                        <strong
                                            class="text-danger">

                                            <?php
                                            echo $recommendation[
                                                "gap"
                                            ];
                                            ?>

                                        </strong>

                                    </div>


                                </div>

                            </div>


                            <!-- WHY -->

                            <div class="mb-3">

                                <strong>

                                    Why this course?

                                </strong>


                                <p
                                    class="text-muted
                                           mb-0
                                           mt-1">

                                    <?php
                                    echo htmlspecialchars(
                                        $recommendation[
                                            "reason"
                                        ]
                                    );
                                    ?>

                                </p>

                            </div>


                            <!-- PRIORITY -->

                            <div class="mb-3">

                                <span
                                    class="badge
                                           bg-<?php
                                           echo $priority_class;
                                           ?>
                                           priority-badge">

                                    <?php
                                    echo $priority_text;
                                    ?>

                                </span>

                            </div>


                            <!-- START LEARNING -->

                            <a
                                href="courses.php?course_id=<?php
                                echo (int)
                                    $recommendation[
                                        "course_id"
                                    ];
                                ?>"
                                class="btn btn-primary">

                                Start Learning

                            </a>


                        </div>

                    </div>

                </div>


            <?php endforeach; ?>


        </div>


    <?php else: ?>


        <!-- =================================
             NO RECOMMENDATIONS
        ================================== -->

        <div
            class="card recommendation-card">


            <div
                class="card-body
                       text-center
                       py-5">


                <?php if ($role_found): ?>


                    <h3
                        class="fw-bold text-success">

                        Great Job! 🎉

                    </h3>


                    <p class="text-muted">

                        No competency gaps were found
                        for your current role.

                    </p>


                <?php else: ?>


                    <h3
                        class="fw-bold">

                        No Recommendations Available

                    </h3>


                    <p class="text-muted">

                        Your role has not been mapped
                        to a competency profile yet.

                    </p>


                <?php endif; ?>


                <a
                    href="assessment.php"
                    class="btn btn-primary">

                    Take Assessment

                </a>


            </div>

        </div>


    <?php endif; ?>


    <!-- =====================================
         HOW IT WORKS
    ====================================== -->

    <div
        class="alert alert-info mt-4">


        <strong>
            How OUTLIERS recommends courses:
        </strong>


        <br><br>


        Your role defines the required competency
        level.


        <br>


        Your assessment determines your current
        competency.


        <br>


        OUTLIERS calculates the skill gap.


        <br>


        For each skill gap, OUTLIERS selects
        one relevant course associated with
        that skill.


        <br>


        Larger competency gaps receive higher
        priority.


    </div>


</div>


<!-- =========================================
     FOOTER
========================================= -->

<footer
    class="bg-dark
           text-white
           text-center
           py-3">


    <p class="mb-0">

        © <?php
        echo date("Y");
        ?> OUTLIERS |

        AI-Powered Skill Intelligence &
        Learning Platform

    </p>

</footer>


</body>

</html>