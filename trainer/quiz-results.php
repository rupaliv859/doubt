```php
<?php

session_start();

/* =========================================
   1. CHECK TRAINER LOGIN
========================================= */

if (
    !isset($_SESSION["user_id"]) ||
    $_SESSION["role"] !== "trainer"
) {
    header("Location: ../login.php");
    exit();
}

require_once "../includes/db.php";


/* =========================================
   2. TRAINER ID
========================================= */

$trainer_id = (int) $_SESSION["user_id"];


/* =========================================
   3. GET TRAINER INFORMATION
========================================= */

$stmt = $conn->prepare("
    SELECT username, email
    FROM users
    WHERE user_id = ?
");

$stmt->bind_param("i", $trainer_id);
$stmt->execute();

$trainer = $stmt->get_result()->fetch_assoc();

$stmt->close();


/* =========================================
   4. GET QUIZ RESULTS
========================================= */

/*
   We use trainer_employee to make sure the
   trainer only sees results of employees
   connected to this trainer.

   assessment_assignments confirms that the
   quiz was assigned to that employee.

   assessment_attempts contains the actual
   score and percentage.
*/

$results_sql = "

    SELECT

        aa.assignment_id,

        aa.assessment_id,

        aa.employee_id,

        aa.assigned_at,

        aa.status AS assignment_status,

        e.employee_code,

        e.full_name,

        a.assessment_name,

        a.description,

        s.skill_name,

        at.attempt_id,

        at.score,

        at.total_questions,

        at.percentage,

        at.attempted_at

    FROM assessment_assignments aa

    INNER JOIN employees e
        ON aa.employee_id = e.employee_id

    INNER JOIN assessments a
        ON aa.assessment_id = a.assessment_id

    LEFT JOIN skills s
        ON a.skill_id = s.skill_id

    INNER JOIN trainer_employee te
        ON te.employee_id = e.employee_id

    LEFT JOIN assessment_attempts at
        ON at.assessment_id = aa.assessment_id
        AND at.employee_id = aa.employee_id

    WHERE te.trainer_id = ?
      AND te.status = 'approved'

    ORDER BY
        CASE
            WHEN at.attempt_id IS NULL THEN 1
            ELSE 0
        END,
        at.attempted_at DESC,
        aa.assigned_at DESC
";

$stmt = $conn->prepare($results_sql);

$stmt->bind_param("i", $trainer_id);

$stmt->execute();

$results = $stmt->get_result();


/* =========================================
   5. SUMMARY COUNTERS
========================================= */

$total_assigned = 0;
$total_completed = 0;
$total_pending = 0;

$total_percentage = 0;
$completed_count_for_average = 0;


/* =========================================
   6. STORE RESULTS
========================================= */

$result_rows = [];

while ($row = $results->fetch_assoc()) {

    $result_rows[] = $row;

    $total_assigned++;

    if (!empty($row["attempt_id"])) {

        $total_completed++;

        $total_percentage +=
            (float) $row["percentage"];

        $completed_count_for_average++;

    } else {

        $total_pending++;
    }
}

$stmt->close();


/* =========================================
   7. AVERAGE SCORE
========================================= */

$average_score = 0;

if ($completed_count_for_average > 0) {

    $average_score =
        $total_percentage /
        $completed_count_for_average;
}


/* =========================================
   8. PAGE
========================================= */

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Quiz Results - OUTLIERS</title>


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Main CSS -->

    <link
        rel="stylesheet"
        href="../css/style.css"
    >


    <style>

        body {
            background: #f5f7fb;
        }


        .dashboard-header {

            background: linear-gradient(
                135deg,
                #0d1b3e,
                #0d6efd
            );

            color: white;

            border-radius: 18px;

            padding: 35px;

        }


        .stat-card {

            border: none;

            border-radius: 16px;

            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.07);

        }


        .result-card {

            border: none;

            border-radius: 16px;

            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.07);

        }


        .result-table {

            vertical-align: middle;

        }


        .result-table th {

            white-space: nowrap;

            background: #f8f9fa;

        }


        .score-badge {

            font-size: 14px;

            padding: 7px 10px;

        }


        .level-badge {

            font-size: 12px;

            padding: 6px 9px;

        }


        .table-responsive {

            border-radius: 12px;

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
            href="dashboard.php"
        >

            OUTLIERS

        </a>


        <div>

            <span class="text-white me-3">

                Trainer

            </span>


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
     MAIN
========================================= -->

<div class="container py-5">


    <!-- =====================================
         HEADER
    ====================================== -->

    <div class="dashboard-header mb-4">

        <h2 class="fw-bold">

            Quiz Results

        </h2>


        <p class="mb-1">

            View employee assessment performance

        </p>


        <small>

            Track quiz scores, competency levels
            and employee learning progress.

        </small>

    </div>


    <!-- =====================================
         SUMMARY
    ====================================== -->

    <div class="row g-4 mb-5">


        <!-- TOTAL ASSIGNED -->

        <div class="col-md-6 col-xl-3">

            <div class="card stat-card h-100">

                <div class="card-body p-4">

                    <p class="text-muted mb-1">

                        Assigned Quizzes

                    </p>


                    <h2 class="fw-bold mb-0">

                        <?php
                        echo $total_assigned;
                        ?>

                    </h2>

                </div>

            </div>

        </div>


        <!-- COMPLETED -->

        <div class="col-md-6 col-xl-3">

            <div class="card stat-card h-100">

                <div class="card-body p-4">

                    <p class="text-muted mb-1">

                        Completed

                    </p>


                    <h2 class="fw-bold text-success mb-0">

                        <?php
                        echo $total_completed;
                        ?>

                    </h2>

                </div>

            </div>

        </div>


        <!-- PENDING -->

        <div class="col-md-6 col-xl-3">

            <div class="card stat-card h-100">

                <div class="card-body p-4">

                    <p class="text-muted mb-1">

                        Pending

                    </p>


                    <h2 class="fw-bold text-warning mb-0">

                        <?php
                        echo $total_pending;
                        ?>

                    </h2>

                </div>

            </div>

        </div>


        <!-- AVERAGE -->

        <div class="col-md-6 col-xl-3">

            <div class="card stat-card h-100">

                <div class="card-body p-4">

                    <p class="text-muted mb-1">

                        Average Score

                    </p>


                    <h2 class="fw-bold text-primary mb-0">

                        <?php
                        echo round($average_score);
                        ?>%

                    </h2>

                </div>

            </div>

        </div>


    </div>


    <!-- =====================================
         RESULTS TABLE
    ====================================== -->

    <div class="card result-card">

        <div class="card-body p-4">


            <div class="d-flex
                        justify-content-between
                        align-items-center
                        mb-4">

                <div>

                    <h4 class="fw-bold mb-1">

                        Employee Quiz Performance

                    </h4>


                    <p class="text-muted mb-0">

                        Results of quizzes assigned
                        to your employees.

                    </p>

                </div>


                <a
                    href="dashboard.php"
                    class="btn btn-outline-secondary"
                >

                    Back to Dashboard

                </a>

            </div>


            <?php if (count($result_rows) > 0): ?>


                <div class="table-responsive">

                    <table
                        class="table
                               table-hover
                               result-table
                               mb-0"
                    >

                        <thead>

                            <tr>

                                <th>
                                    Employee
                                </th>

                                <th>
                                    Quiz
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
                                    Level
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Date
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php foreach (
                            $result_rows
                            as $row
                        ): ?>


                            <?php

                            /*
                             * Determine competency level.
                             */

                            if (
                                $row["attempt_id"] === null
                            ) {

                                $level = "Not Assessed";

                                $level_class =
                                    "bg-secondary";

                            } else {

                                $percentage =
                                    (float)
                                    $row["percentage"];


                                if ($percentage >= 80) {

                                    $level = "Advanced";

                                    $level_class =
                                        "bg-success";

                                } elseif (
                                    $percentage >= 60
                                ) {

                                    $level =
                                        "Intermediate";

                                    $level_class =
                                        "bg-primary";

                                } elseif (
                                    $percentage >= 40
                                ) {

                                    $level = "Basic";

                                    $level_class =
                                        "bg-warning text-dark";

                                } else {

                                    $level = "Beginner";

                                    $level_class =
                                        "bg-danger";
                                }
                            }

                            ?>


                            <tr>


                                <!-- EMPLOYEE -->

                                <td>

                                    <div class="fw-bold">

                                        <?php

                                        echo htmlspecialchars(
                                            $row["full_name"]
                                        );

                                        ?>

                                    </div>


                                    <small
                                        class="text-muted"
                                    >

                                        <?php

                                        echo htmlspecialchars(
                                            $row[
                                                "employee_code"
                                            ]
                                        );

                                        ?>

                                    </small>

                                </td>


                                <!-- QUIZ -->

                                <td>

                                    <div class="fw-semibold">

                                        <?php

                                        echo htmlspecialchars(
                                            $row[
                                                "assessment_name"
                                            ]
                                        );

                                        ?>

                                    </div>

                                </td>


                                <!-- SKILL -->

                                <td>

                                    <?php

                                    if (
                                        !empty(
                                            $row["skill_name"]
                                        )
                                    ):

                                    ?>

                                        <span
                                            class="badge
                                                   bg-light
                                                   text-dark
                                                   border"
                                        >

                                            <?php

                                            echo htmlspecialchars(
                                                $row[
                                                    "skill_name"
                                                ]
                                            );

                                            ?>

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="text-muted"
                                        >

                                            —

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- SCORE -->

                                <td>

                                    <?php

                                    if (
                                        $row["attempt_id"]
                                        !== null
                                    ):

                                    ?>

                                        <span
                                            class="badge
                                                   bg-primary
                                                   score-badge"
                                        >

                                            <?php

                                            echo (int)
                                                $row["score"];

                                            ?>

                                            /

                                            <?php

                                            echo (int)
                                                $row[
                                                    "total_questions"
                                                ];

                                            ?>

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="text-muted"
                                        >

                                            —

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- PERCENTAGE -->

                                <td>

                                    <?php

                                    if (
                                        $row["attempt_id"]
                                        !== null
                                    ):

                                    ?>

                                        <strong>

                                            <?php

                                            echo round(
                                                (float)
                                                $row[
                                                    "percentage"
                                                ]
                                            );

                                            ?>%

                                        </strong>

                                    <?php else: ?>

                                        <span
                                            class="text-muted"
                                        >

                                            —

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- LEVEL -->

                                <td>

                                    <span
                                        class="badge
                                               <?php
                                               echo $level_class;
                                               ?>
                                               level-badge"
                                    >

                                        <?php

                                        echo $level;

                                        ?>

                                    </span>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <?php

                                    if (
                                        $row["attempt_id"]
                                        !== null
                                    ):

                                    ?>

                                        <span
                                            class="badge
                                                   bg-success"
                                        >

                                            Completed

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="badge
                                                   bg-warning
                                                   text-dark"
                                        >

                                            Pending

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- DATE -->

                                <td>

                                    <?php

                                    if (
                                        !empty(
                                            $row[
                                                "attempted_at"
                                            ]
                                        )
                                    ) {

                                        echo date(
                                            "d M Y",
                                            strtotime(
                                                $row[
                                                    "attempted_at"
                                                ]
                                            )
                                        );

                                    } else {

                                        echo "—";

                                    }

                                    ?>

                                </td>


                            </tr>


                        <?php endforeach; ?>


                        </tbody>

                    </table>

                </div>


            <?php else: ?>


                <div
                    class="alert alert-info mb-0"
                >

                    <strong>
                        No quiz assignments found.
                    </strong>

                    <br>

                    Once you assign quizzes to your
                    employees, their results will
                    appear here.

                </div>


            <?php endif; ?>


        </div>

    </div>


</div>


<!-- =========================================
     FOOTER
========================================= -->

<footer
    class="bg-dark
           text-white
           text-center
           py-3
           mt-5"
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