<?php

session_start();

require_once "../includes/db.php";

/* =========================================
   1. CHECK EMPLOYEE LOGIN
========================================= */

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "employee") {
    header("Location: ../employee-login.php");
    exit();
}

$user_id = $_SESSION["user_id"];


/* =========================================
   2. GET EMPLOYEE INFORMATION
========================================= */

$employee_sql = "SELECT employee_id, full_name, designation
                 FROM employees
                 WHERE user_id = ?";

$stmt = $conn->prepare($employee_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();

$employee_result = $stmt->get_result();

if ($employee_result->num_rows === 0) {
    die("Employee profile not found.");
}

$employee = $employee_result->fetch_assoc();

$employee_id = $employee["employee_id"];
$full_name = $employee["full_name"];
$designation = $employee["designation"];

$stmt->close();


/* =========================================
   3. GET SKILL GAPS
========================================= */

/*
   Required level comes from role_skills.
   Current level comes from employee_skills.

   The employee designation is matched
   with the role_name.
*/

$gap_sql = "SELECT
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

            WHERE r.role_name = ?

            ORDER BY
                (rs.required_level - COALESCE(es.current_level, 0)) DESC,
                s.skill_name ASC";


$stmt = $conn->prepare($gap_sql);

$stmt->bind_param(
    "is",
    $employee_id,
    $designation
);

$stmt->execute();

$gap_result = $stmt->get_result();

$stmt->close();


/* =========================================
   4. HELPER FUNCTIONS
========================================= */

function getLevelName($level)
{
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


function getGapStatus($gap)
{
    if ($gap <= 0) {
        return "No Gap";
    }

    if ($gap == 1) {
        return "Low Gap";
    }

    if ($gap == 2) {
        return "Medium Gap";
    }

    return "High Gap";
}


function getStatusClass($gap)
{
    if ($gap <= 0) {
        return "success";
    }

    if ($gap == 1) {
        return "warning";
    }

    if ($gap == 2) {
        return "orange";
    }

    return "danger";
}

?>


<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Skill Gaps - OUTLIERS</title>


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet">


    <!-- Main CSS -->

    <link rel="stylesheet"
          href="../css/style.css">


    <style>

        .skill-card {

            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: 0.2s;

        }

        .skill-card:hover {

            transform: translateY(-2px);

        }


        .level-badge {

            padding: 7px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;

        }


        .level-advanced {

            background: #d1e7dd;
            color: #0f5132;

        }


        .level-intermediate {

            background: #cff4fc;
            color: #055160;

        }


        .level-basic {

            background: #fff3cd;
            color: #664d03;

        }


        .level-beginner {

            background: #f8d7da;
            color: #842029;

        }


        .level-not {

            background: #e2e3e5;
            color: #41464b;

        }


        .gap-success {

            color: #198754;
            font-weight: 600;

        }


        .gap-warning {

            color: #997404;
            font-weight: 600;

        }


        .gap-orange {

            color: #fd7e14;
            font-weight: 600;

        }


        .gap-danger {

            color: #dc3545;
            font-weight: 600;

        }


        .progress {

            height: 8px;
            border-radius: 10px;

        }


        .page-header {

            background: linear-gradient(
                135deg,
                #0d1b3e,
                #0d6efd
            );

            color: white;
            border-radius: 18px;
            padding: 30px;

        }

    </style>

</head>


<body>


<!-- =========================================
     NAVBAR
========================================= -->

<nav class="navbar navbar-dark bg-dark">

    <div class="container">

        <a class="navbar-brand fw-bold"
           href="dashboard.php">

            OUTLIERS

        </a>


        <div>

            <a href="dashboard.php"
               class="btn btn-outline-light btn-sm">

                Dashboard

            </a>


            <a href="../logout.php"
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


    <!-- PAGE HEADER -->

    <div class="page-header mb-4">

        <h2 class="fw-bold">

            Skill Gap Analysis

        </h2>

        <p class="mb-0">

            Hello <?php echo htmlspecialchars($full_name); ?>.
            OUTLIERS compares your current competency
            with the competency required for your role.

        </p>

    </div>


    <!-- =====================================
         EMPLOYEE ROLE
    ====================================== -->

    <div class="card skill-card mb-4">

        <div class="card-body">

            <h5 class="fw-bold">

                Your Role

            </h5>

            <p class="mb-0 text-muted">

                <?php echo htmlspecialchars($designation); ?>

            </p>

        </div>

    </div>


    <!-- =====================================
         SKILL TABLE
    ====================================== -->

    <div class="card skill-card">

        <div class="card-body p-4">

            <div class="d-flex justify-content-between
                        align-items-center mb-4">

                <div>

                    <h4 class="fw-bold mb-1">

                        Competency Gaps

                    </h4>

                    <p class="text-muted mb-0">

                        Required level vs current level

                    </p>

                </div>


                <a href="assessment.php"
                   class="btn btn-primary">

                    Take Assessment

                </a>

            </div>


            <?php if ($gap_result->num_rows > 0): ?>


                <div class="table-responsive">

                    <table class="table align-middle">

                        <thead>

                            <tr>

                                <th>Skill</th>

                                <th>Category</th>

                                <th>Required Level</th>

                                <th>Current Level</th>

                                <th>Gap</th>

                                <th>Status</th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php while ($skill = $gap_result->fetch_assoc()): ?>


                            <?php

                            $required = (int)$skill["required_level"];

                            $current = (int)$skill["current_level"];

                            $gap = $required - $current;

                            if ($gap < 0) {
                                $gap = 0;
                            }

                            $status = getGapStatus($gap);

                            $status_class = getStatusClass($gap);

                            ?>


                            <tr>


                                <!-- SKILL -->

                                <td>

                                    <strong>

                                        <?php
                                        echo htmlspecialchars(
                                            $skill["skill_name"]
                                        );
                                        ?>

                                    </strong>

                                </td>


                                <!-- CATEGORY -->

                                <td>

                                    <span class="badge bg-light text-dark">

                                        <?php
                                        echo htmlspecialchars(
                                            $skill["category"]
                                        );
                                        ?>

                                    </span>

                                </td>


                                <!-- REQUIRED -->

                                <td>

                                    <span class="level-badge
                                        <?php
                                        echo 'level-' .
                                             strtolower(
                                                 str_replace(
                                                     ' ',
                                                     '-',
                                                     getLevelName($required)
                                                 )
                                             );
                                        ?>">

                                        <?php
                                        echo getLevelName($required);
                                        ?>

                                    </span>

                                </td>


                                <!-- CURRENT -->

                                <td>

                                    <span class="level-badge
                                        <?php
                                        echo 'level-' .
                                             strtolower(
                                                 str_replace(
                                                     ' ',
                                                     '-',
                                                     getLevelName($current)
                                                 )
                                             );
                                        ?>">

                                        <?php
                                        echo getLevelName($current);
                                        ?>

                                    </span>

                                </td>


                                <!-- GAP -->

                                <td>

                                    <strong>

                                        <?php echo $gap; ?>

                                    </strong>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <span class="gap-<?php echo $status_class; ?>">

                                        <?php echo $status; ?>

                                    </span>

                                </td>


                            </tr>


                        <?php endwhile; ?>


                        </tbody>

                    </table>

                </div>


            <?php else: ?>


                <div class="text-center py-5">

                    <h5 class="fw-bold">

                        No competency data available

                    </h5>

                    <p class="text-muted">

                        Make sure your designation matches
                        one of the roles configured in OUTLIERS
                        and complete an assessment.

                    </p>


                    <a href="assessment.php"
                       class="btn btn-primary">

                        Start Assessment

                    </a>

                </div>


            <?php endif; ?>


        </div>

    </div>


    <!-- =====================================
         EXPLANATION
    ====================================== -->

    <div class="alert alert-info mt-4">

        <strong>How Skill Gap Detection works:</strong>

        <br>

        Required Competency − Current Competency = Skill Gap

        <br><br>

        A larger gap means that the employee needs
        more learning in that particular skill.

    </div>


</div>


<!-- =========================================
     FOOTER
========================================= -->

<footer class="bg-dark text-white text-center py-3">

    <p class="mb-0">

        © <?php echo date("Y"); ?> OUTLIERS |
        AI-Powered Skill Intelligence & Learning Platform

    </p>

</footer>


</body>

</html>