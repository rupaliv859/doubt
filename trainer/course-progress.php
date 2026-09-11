<?php

session_start();

require_once "../includes/db.php";


/* =========================================================
   TRAINER ACCESS PROTECTION
========================================================= */

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "trainer"
) {
    header("Location: ../login.php");
    exit();
}

$trainer_id = (int) $_SESSION["user_id"];


/* =========================================================
   GET TRAINER INFORMATION
========================================================= */

$stmt = $conn->prepare("
    SELECT
        user_id,
        username,
        email
    FROM users
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "i",
    $trainer_id
);

$stmt->execute();

$result = $stmt->get_result();

$trainer = $result->fetch_assoc();

$stmt->close();


if (!$trainer) {
    die("Trainer profile not found.");
}


/* =========================================================
   GET COURSE PROGRESS
========================================================= */

$progress_data = [];

$stmt = $conn->prepare("
    SELECT

        e.employee_id,
        e.employee_code,
        e.full_name,
        e.department,
        e.designation,

        c.course_id,
        c.course_name,
        c.difficulty,

        ca.assignment_id,
        ca.assigned_at,
        ca.status AS assignment_status,

        en.enrollment_id,
        en.status AS enrollment_status,
        en.enrolled_at,

        COALESCE(
            p.progress_percentage,
            0
        ) AS progress_percentage,

        p.last_accessed,
        p.completed_at

    FROM course_assignments ca

    INNER JOIN employees e
        ON ca.employee_id = e.employee_id

    INNER JOIN courses c
        ON ca.course_id = c.course_id

    LEFT JOIN enrollments en
        ON en.employee_id = e.employee_id
        AND en.course_id = c.course_id

    LEFT JOIN progress p
        ON p.enrollment_id = en.enrollment_id

    INNER JOIN trainer_employee te
        ON te.employee_id = e.employee_id
        AND te.trainer_id = ca.assigned_by
        AND te.status = 'approved'

    WHERE ca.assigned_by = ?

    ORDER BY
        ca.assigned_at DESC,
        e.full_name ASC,
        c.course_name ASC
");

$stmt->bind_param(
    "i",
    $trainer_id
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $progress_data[] = $row;

}

$stmt->close();


/* =========================================================
   SUMMARY COUNTS
========================================================= */

$total_assignments = count($progress_data);

$completed_courses = 0;

$in_progress_courses = 0;

$not_started_courses = 0;

$total_progress = 0;


foreach ($progress_data as $item) {

    $percentage =
        (float) $item["progress_percentage"];

    $total_progress += $percentage;


    if ($percentage >= 100) {

        $completed_courses++;

    } elseif ($percentage > 0) {

        $in_progress_courses++;

    } else {

        $not_started_courses++;

    }

}


$average_progress = 0;

if ($total_assignments > 0) {

    $average_progress =
        $total_progress / $total_assignments;

}

$average_progress =
    round(
        $average_progress,
        1
    );


/* =========================================================
   HELPER: PROGRESS STATUS
========================================================= */

function getProgressStatus($percentage)
{

    $percentage =
        (float) $percentage;


    if ($percentage >= 100) {

        return "Completed";

    }


    if ($percentage > 0) {

        return "In Progress";

    }


    return "Not Started";

}


/* =========================================================
   HELPER: STATUS BADGE
========================================================= */

function getProgressBadge($percentage)
{

    $percentage =
        (float) $percentage;


    if ($percentage >= 100) {

        return "bg-success";

    }


    if ($percentage > 0) {

        return "bg-primary";

    }


    return "bg-secondary";

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
        Course Progress - OUTLIERS
    </title>


    <!-- =====================================================
         BOOTSTRAP
    ====================================================== -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- =====================================================
         OUTLIERS CSS
    ====================================================== -->

    <link
        rel="stylesheet"
        href="../css/style.css"
    >


    <style>

        .stat-card {

            border: none;

            border-radius: 12px;

        }


        .progress {

            height: 9px;

        }


        .employee-name {

            font-weight: 600;

        }


        .course-name {

            font-weight: 600;

        }


        .small-muted {

            font-size: 13px;

            color: #6c757d;

        }

    </style>

</head>


<body>


<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-dark bg-dark">

    <div class="container">


        <a
            href="dashboard.php"
            class="navbar-brand fw-bold"
        >

            OUTLIERS

        </a>


        <div class="d-flex gap-2">


            <a
                href="dashboard.php"
                class="btn btn-outline-light btn-sm"
            >

                Dashboard

            </a>


            <a
                href="quiz-results.php"
                class="btn btn-outline-light btn-sm"
            >

                Quiz Results

            </a>


            <a
                href="../logout.php"
                class="btn btn-outline-light btn-sm"
            >

                Logout

            </a>


        </div>

    </div>

</nav>


<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<div class="container py-5">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div
        class="
            d-flex
            justify-content-between
            align-items-center
            mb-4
        "
    >


        <div>

            <h2 class="fw-bold mb-1">

                Course Progress

            </h2>


            <p class="text-muted mb-0">

                Monitor employee learning progress
                across assigned courses.

            </p>

        </div>


        <a
            href="dashboard.php"
            class="btn btn-outline-secondary"
        >

            Back to Dashboard

        </a>


    </div>



    <!-- =====================================================
         SUMMARY CARDS
    ====================================================== -->

    <div class="row g-4 mb-4">


        <!-- TOTAL -->

        <div class="col-md-6 col-lg-3">

            <div
                class="
                    card
                    stat-card
                    shadow-sm
                    h-100
                "
            >

                <div class="card-body">

                    <small class="text-muted">

                        Total Assignments

                    </small>


                    <h2 class="fw-bold mt-2 mb-0">

                        <?php

                        echo $total_assignments;

                        ?>

                    </h2>

                </div>

            </div>

        </div>


        <!-- COMPLETED -->

        <div class="col-md-6 col-lg-3">

            <div
                class="
                    card
                    stat-card
                    shadow-sm
                    h-100
                "
            >

                <div class="card-body">

                    <small class="text-muted">

                        Completed

                    </small>


                    <h2 class="fw-bold mt-2 mb-0 text-success">

                        <?php

                        echo $completed_courses;

                        ?>

                    </h2>

                </div>

            </div>

        </div>


        <!-- IN PROGRESS -->

        <div class="col-md-6 col-lg-3">

            <div
                class="
                    card
                    stat-card
                    shadow-sm
                    h-100
                "
            >

                <div class="card-body">

                    <small class="text-muted">

                        In Progress

                    </small>


                    <h2 class="fw-bold mt-2 mb-0 text-primary">

                        <?php

                        echo $in_progress_courses;

                        ?>

                    </h2>

                </div>

            </div>

        </div>


        <!-- AVERAGE -->

        <div class="col-md-6 col-lg-3">

            <div
                class="
                    card
                    stat-card
                    shadow-sm
                    h-100
                "
            >

                <div class="card-body">

                    <small class="text-muted">

                        Average Progress

                    </small>


                    <h2 class="fw-bold mt-2 mb-0">

                        <?php

                        echo $average_progress;

                        ?>%

                    </h2>

                </div>

            </div>

        </div>

    </div>



    <!-- =====================================================
         PROGRESS TABLE
    ====================================================== -->

    <div class="card shadow-sm">


        <div class="card-body p-4">


            <div
                class="
                    d-flex
                    justify-content-between
                    align-items-center
                    mb-4
                "
            >


                <div>

                    <h5 class="fw-bold mb-1">

                        Employee Learning Progress

                    </h5>


                    <p class="text-muted mb-0">

                        Track assigned courses and
                        completion status.

                    </p>

                </div>


                <span class="badge bg-primary">

                    <?php

                    echo $total_assignments;

                    ?>

                    Records

                </span>


            </div>



            <?php if ($total_assignments > 0): ?>


                <div class="table-responsive">


                    <table
                        class="
                            table
                            table-hover
                            align-middle
                        "
                    >


                        <thead>

                            <tr>

                                <th>
                                    Employee
                                </th>

                                <th>
                                    Course
                                </th>

                                <th>
                                    Progress
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Assigned
                                </th>

                                <th>
                                    Last Accessed
                                </th>

                                <th>
                                    Completed
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                            <?php foreach (
                                $progress_data
                                as $item
                            ): ?>


                                <?php

                                $percentage =
                                    (float)
                                    $item[
                                        "progress_percentage"
                                    ];

                                ?>


                                <tr>


                                    <!-- EMPLOYEE -->

                                    <td>


                                        <div
                                            class="
                                                employee-name
                                            "
                                        >

                                            <?php

                                            echo htmlspecialchars(
                                                $item[
                                                    "full_name"
                                                ]
                                            );

                                            ?>

                                        </div>


                                        <div
                                            class="
                                                small-muted
                                            "
                                        >

                                            <?php

                                            echo htmlspecialchars(
                                                $item[
                                                    "employee_code"
                                                ]
                                            );

                                            ?>


                                            <?php

                                            if (
                                                !empty(
                                                    $item[
                                                        "designation"
                                                    ]
                                                )
                                            ) {

                                                echo " • "
                                                    .
                                                    htmlspecialchars(
                                                        $item[
                                                            "designation"
                                                        ]
                                                    );

                                            }

                                            ?>

                                        </div>


                                    </td>



                                    <!-- COURSE -->

                                    <td>


                                        <div
                                            class="
                                                course-name
                                            "
                                        >

                                            <?php

                                            echo htmlspecialchars(
                                                $item[
                                                    "course_name"
                                                ]
                                            );

                                            ?>

                                        </div>


                                        <div
                                            class="
                                                small-muted
                                            "
                                        >

                                            Difficulty:

                                            <?php

                                            echo htmlspecialchars(
                                                $item[
                                                    "difficulty"
                                                ]
                                            );

                                            ?>

                                        </div>


                                    </td>



                                    <!-- PROGRESS -->

                                    <td
                                        style="
                                            min-width: 180px;
                                        "
                                    >


                                        <div
                                            class="
                                                d-flex
                                                justify-content-between
                                                mb-1
                                            "
                                        >

                                            <small>

                                                Progress

                                            </small>


                                            <small
                                                class="fw-bold"
                                            >

                                                <?php

                                                echo round(
                                                    $percentage,
                                                    1
                                                );

                                                ?>%

                                            </small>

                                        </div>


                                        <div
                                            class="progress"
                                        >

                                            <div
                                                class="
                                                    progress-bar
                                                "
                                                role="progressbar"
                                                style="
                                                    width:
                                                    <?php

                                                    echo min(
                                                        100,
                                                        max(
                                                            0,
                                                            $percentage
                                                        )
                                                    );

                                                    ?>%;
                                                "
                                            ></div>

                                        </div>


                                    </td>



                                    <!-- STATUS -->

                                    <td>


                                        <span
                                            class="
                                                badge
                                                <?php

                                                echo getProgressBadge(
                                                    $percentage
                                                );

                                                ?>
                                            "
                                        >

                                            <?php

                                            echo getProgressStatus(
                                                $percentage
                                            );

                                            ?>

                                        </span>


                                    </td>



                                    <!-- ASSIGNED DATE -->

                                    <td>


                                        <small>

                                            <?php

                                            if (
                                                !empty(
                                                    $item[
                                                        "assigned_at"
                                                    ]
                                                )
                                            ) {

                                                echo date(
                                                    "d M Y",
                                                    strtotime(
                                                        $item[
                                                            "assigned_at"
                                                        ]
                                                    )
                                                );

                                            } else {

                                                echo "-";

                                            }

                                            ?>

                                        </small>


                                    </td>



                                    <!-- LAST ACCESSED -->

                                    <td>


                                        <small>

                                            <?php

                                            if (
                                                !empty(
                                                    $item[
                                                        "last_accessed"
                                                    ]
                                                )
                                            ) {

                                                echo date(
                                                    "d M Y H:i",
                                                    strtotime(
                                                        $item[
                                                            "last_accessed"
                                                        ]
                                                    )
                                                );

                                            } else {

                                                echo "Not accessed";

                                            }

                                            ?>

                                        </small>


                                    </td>



                                    <!-- COMPLETED -->

                                    <td>


                                        <?php

                                        if (
                                            !empty(
                                                $item[
                                                    "completed_at"
                                                ]
                                            )
                                        ):

                                        ?>


                                            <span
                                                class="
                                                    text-success
                                                    fw-semibold
                                                "
                                            >

                                                ✓

                                                <?php

                                                echo date(
                                                    "d M Y",
                                                    strtotime(
                                                        $item[
                                                            "completed_at"
                                                        ]
                                                    )
                                                );

                                                ?>

                                            </span>


                                        <?php else: ?>


                                            <span
                                                class="
                                                    text-muted
                                                "
                                            >

                                                —

                                            </span>


                                        <?php endif; ?>


                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        </tbody>


                    </table>


                </div>


            <?php else: ?>


                <div
                    class="
                        alert
                        alert-info
                        mb-0
                    "
                >

                    <strong>

                        No course assignments yet.

                    </strong>


                    <br>


                    Once you assign courses to employees,
                    their learning progress will appear here.

                </div>


            <?php endif; ?>


        </div>


    </div>


</div>


<!-- =========================================================
     FOOTER
========================================================= -->

<footer
    class="
        bg-dark
        text-white
        text-center
        py-3
        mt-5
    "
>

    <div class="container">

        <small>

            © 2026 OUTLIERS —
            Think Different. Learn Smarter. Go Beyond.

        </small>

    </div>

</footer>


</body>

</html>