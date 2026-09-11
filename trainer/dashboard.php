<?php

session_start();

/* =========================================
   1. CHECK TRAINER LOGIN
========================================= */

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "trainer") {
    header("Location: ../login.php");
    exit();
}

require_once "../includes/db.php";


/* =========================================
   2. GET TRAINER INFORMATION
========================================= */

$user_id = $_SESSION["user_id"];

$stmt = $conn->prepare("
    SELECT username, email
    FROM users
    WHERE user_id = ?
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$trainer = $stmt->get_result()->fetch_assoc();

$stmt->close();


/* =========================================
   3. TOTAL COURSES
========================================= */

$result = $conn->query("
    SELECT COUNT(*) AS total_courses
    FROM courses
");

$total_courses = $result->fetch_assoc()["total_courses"];


/* =========================================
   4. TOTAL LEARNING MATERIALS
========================================= */

$result = $conn->query("
    SELECT COUNT(*) AS total_materials
    FROM learning_materials
");

$total_materials = $result->fetch_assoc()["total_materials"];


/* =========================================
   5. TOTAL ENROLLMENTS
========================================= */

$result = $conn->query("
    SELECT COUNT(*) AS total_enrollments
    FROM enrollments
");

$total_enrollments = $result->fetch_assoc()["total_enrollments"];


/* =========================================
   6. COMPLETED ENROLLMENTS
========================================= */

$result = $conn->query("
    SELECT COUNT(*) AS completed_enrollments
    FROM enrollments
    WHERE status = 'completed'
");

$completed_enrollments =
    $result->fetch_assoc()["completed_enrollments"];


/* =========================================
   7. GET COURSE OVERVIEW
========================================= */

$course_sql = "
    SELECT
        c.course_id,
        c.course_name,
        c.difficulty,
        s.skill_name,

        COUNT(DISTINCT e.enrollment_id)
        AS total_students,

        COALESCE(
            AVG(p.progress_percentage),
            0
        ) AS average_progress

    FROM courses c

    LEFT JOIN skills s
        ON c.skill_id = s.skill_id

    LEFT JOIN enrollments e
        ON c.course_id = e.course_id

    LEFT JOIN progress p
        ON e.enrollment_id = p.enrollment_id

    GROUP BY
        c.course_id,
        c.course_name,
        c.difficulty,
        s.skill_name

    ORDER BY c.course_id DESC
";

$courses_result = $conn->query($course_sql);


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

    <title>Trainer Dashboard - OUTLIERS</title>


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

            transition: 0.2s;

        }


        .stat-card:hover {

            transform: translateY(-3px);

        }


        .stat-icon {

            width: 50px;

            height: 50px;

            border-radius: 12px;

            display: flex;

            align-items: center;

            justify-content: center;

            background: #e8f1ff;

            color: #0d6efd;

            font-weight: bold;

            font-size: 18px;

        }


        .dashboard-card {

            border: none;

            border-radius: 16px;

            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.07);

        }


        .course-row {

            border-bottom: 1px solid #eeeeee;

            padding: 18px 0;

        }


        .course-row:last-child {

            border-bottom: none;

        }


        .progress {

            height: 8px;

            border-radius: 10px;

        }


        .quick-action {

            text-decoration: none;

            color: inherit;

        }


        .quick-action-card {

            border: none;

            border-radius: 15px;

            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.06);

            transition: 0.2s;

        }


        .quick-action-card:hover {

            transform: translateY(-3px);

            box-shadow:
                0 7px 20px
                rgba(0, 0, 0, 0.10);

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

            Trainer Dashboard

        </h2>


        <p class="mb-1">

            Welcome,

            <?php

            echo htmlspecialchars(
                $trainer["username"] ?? "Trainer"
            );

            ?>

        </p>


        <small>

            Manage learning content,
            courses and employee learning activities.

        </small>

    </div>


    <!-- =====================================
         STATISTICS
    ====================================== -->

    <div class="row g-4 mb-5">


        <!-- COURSES -->

        <div class="col-md-6 col-xl-3">

            <div class="card stat-card h-100">

                <div class="card-body p-4">

                    <div class="d-flex
                                justify-content-between
                                align-items-center">

                        <div>

                            <p class="text-muted mb-1">

                                Total Courses

                            </p>


                            <h2 class="fw-bold mb-0">

                                <?php

                                echo $total_courses;

                                ?>

                            </h2>

                        </div>


                        <div class="stat-icon">

                            C

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- MATERIALS -->

        <div class="col-md-6 col-xl-3">

            <div class="card stat-card h-100">

                <div class="card-body p-4">

                    <div class="d-flex
                                justify-content-between
                                align-items-center">

                        <div>

                            <p class="text-muted mb-1">

                                Learning Materials

                            </p>


                            <h2 class="fw-bold mb-0">

                                <?php

                                echo $total_materials;

                                ?>

                            </h2>

                        </div>


                        <div class="stat-icon">

                            M

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- ENROLLMENTS -->

        <div class="col-md-6 col-xl-3">

            <div class="card stat-card h-100">

                <div class="card-body p-4">

                    <div class="d-flex
                                justify-content-between
                                align-items-center">

                        <div>

                            <p class="text-muted mb-1">

                                Total Enrollments

                            </p>


                            <h2 class="fw-bold mb-0">

                                <?php

                                echo $total_enrollments;

                                ?>

                            </h2>

                        </div>


                        <div class="stat-icon">

                            E

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- COMPLETED -->

        <div class="col-md-6 col-xl-3">

            <div class="card stat-card h-100">

                <div class="card-body p-4">

                    <div class="d-flex
                                justify-content-between
                                align-items-center">

                        <div>

                            <p class="text-muted mb-1">

                                Completed

                            </p>


                            <h2 class="fw-bold mb-0">

                                <?php

                                echo $completed_enrollments;

                                ?>

                            </h2>

                        </div>


                        <div class="stat-icon">

                            ✓

                        </div>

                    </div>

                </div>

            </div>

        </div>


    </div>


    <!-- =====================================
         QUICK ACTIONS
    ====================================== -->

    <h4 class="fw-bold mb-3">

        Quick Actions

    </h4>


    <div class="row g-4 mb-5">


        <!-- MANAGE COURSES -->
                                                             <!-- COURSE PROGRESS -->

<div class="col-md-6 col-lg-3">

    <a
        href="course-progress.php"
        class="quick-action"
    >

        <div class="card quick-action-card h-100">

            <div class="card-body p-4">

                <h5 class="fw-bold">

                    Course Progress

                </h5>

                <p class="text-muted mb-0">

                    Monitor employee learning progress,
                    completion and activity.

                </p>

            </div>

        </div>

    </a>

</div>
<!-- AI SKILL ANALYSIS -->

<div class="col-md-6 col-lg-3">

    <a
        href="ai-skill-analysis.php"
        class="quick-action"
    >

        <div class="card quick-action-card h-100">

            <div class="card-body p-4">

                <h5 class="fw-bold">
                    AI Skill Analysis
                </h5>

                <p class="text-muted mb-0">

                    Analyze employee competency gaps
                    and training priorities.

                </p>

            </div>

        </div>

    </a>

</div>
<!-- AI TRAINING RECOMMENDATIONS -->

<div class="col-md-6 col-lg-3">

    <a
        href="ai-training-recommendations.php"
        class="quick-action"
    >

        <div class="card quick-action-card h-100">

            <div class="card-body p-4">

                <h5 class="fw-bold">
                    AI Training Recommendations
                </h5>

                <p class="text-muted mb-0">

                    Get personalized training actions
                    for employee skill gaps.

                </p>

            </div>

        </div>

    </a>

</div>
        <div class="col-md-6 col-lg-3">

            <a
                href="courses.php"
                class="quick-action"
            >

                <div class="card
                            quick-action-card
                            h-100">

                    <div class="card-body p-4">

                        <h5 class="fw-bold">

                            Manage Courses

                        </h5>


                        <p class="text-muted mb-0">

                            Create and manage
                            employee learning courses.

                        </p>

                    </div>

                </div>

            </a>

        </div>


        <!-- MATERIALS -->

        <div class="col-md-6 col-lg-3">

            <a
                href="materials.php"
                class="quick-action"
            >

                <div class="card
                            quick-action-card
                            h-100">

                    <div class="card-body p-4">

                        <h5 class="fw-bold">

                            Learning Materials

                        </h5>


                        <p class="text-muted mb-0">

                            Upload PDFs and other
                            learning resources.

                        </p>

                    </div>

                </div>

            </a>

        </div>


        <!-- LEARNERS -->

        <div class="col-md-6 col-lg-3">

            <a
                href="learners.php"
                class="quick-action"
            >

                <div class="card
                            quick-action-card
                            h-100">

                    <div class="card-body p-4">

                        <h5 class="fw-bold">

                            Learners

                        </h5>


                        <p class="text-muted mb-0">

                            View employee enrollment
                            and learning progress.

                        </p>

                    </div>

                </div>

            </a>

        </div>


        <!-- EMPLOYEE REQUESTS -->
		                         <!-- QUIZ RESULTS -->

<div class="col-md-6 col-lg-3">

    <a
        href="quiz-results.php"
        class="quick-action"
    >

        <div class="card
                    quick-action-card
                    h-100">

            <div class="card-body p-4">

                <h5 class="fw-bold">

                    Quiz Results

                </h5>


                <p class="text-muted mb-0">

                    View employee quiz scores,
                    percentages and competency levels.

                </p>

            </div>

        </div>

    </a>

</div>

        <div class="col-md-6 col-lg-3">

            <a
                href="requests.php"
                class="quick-action"
            >

                <div class="card
                            quick-action-card
                            h-100">

                    <div class="card-body p-4">

                        <h5 class="fw-bold">

                            Employee Requests

                        </h5>


                        <p class="text-muted mb-0">

                            Review and verify
                            employee trainer requests.

                        </p>

                    </div>

                </div>

            </a>

        </div>


    </div>


    <!-- =====================================
         COURSE OVERVIEW
    ====================================== -->

    <div class="card dashboard-card">

        <div class="card-body p-4">

            <div class="d-flex
                        justify-content-between
                        align-items-center
                        mb-3">

                <h4 class="fw-bold mb-0">

                    Course Overview

                </h4>


                <span class="badge bg-primary">

                    <?php

                    echo $total_courses;

                    ?>

                    Courses

                </span>

            </div>


            <?php

            if (
                $courses_result &&
                $courses_result->num_rows > 0
            ):

            ?>


                <?php

                while (
                    $course =
                    $courses_result->fetch_assoc()
                ):

                ?>


                    <div class="course-row">


                        <div class="row
                                    align-items-center">


                            <!-- COURSE -->

                            <div class="col-lg-5 mb-3 mb-lg-0">

                                <h5 class="fw-bold mb-1">

                                    <?php

                                    echo htmlspecialchars(
                                        $course["course_name"]
                                    );

                                    ?>

                                </h5>


                                <div>

                                    <span
                                        class="badge
                                               bg-secondary
                                               me-1"
                                    >

                                        <?php

                                        echo htmlspecialchars(
                                            $course["difficulty"]
                                        );

                                        ?>

                                    </span>


                                    <?php

                                    if (
                                        $course["skill_name"]
                                    ):

                                    ?>

                                        <span
                                            class="badge
                                                   bg-primary"
                                        >

                                            <?php

                                            echo htmlspecialchars(
                                                $course["skill_name"]
                                            );

                                            ?>

                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <!-- STUDENTS -->

                            <div class="col-lg-2 mb-3 mb-lg-0">

                                <small
                                    class="text-muted"
                                >

                                    Learners

                                </small>


                                <div class="fw-bold">

                                    <?php

                                    echo $course[
                                        "total_students"
                                    ];

                                    ?>

                                </div>

                            </div>


                            <!-- PROGRESS -->

                            <div class="col-lg-3 mb-3 mb-lg-0">

                                <div class="d-flex
                                            justify-content-between">

                                    <small
                                        class="text-muted"
                                    >

                                        Average Progress

                                    </small>


                                    <small
                                        class="fw-bold"
                                    >

                                        <?php

                                        echo round(
                                            $course[
                                                "average_progress"
                                            ]
                                        );

                                        ?>%

                                    </small>

                                </div>


                                <div class="progress mt-2">

                                    <div
                                        class="progress-bar"
                                        role="progressbar"
                                        style="width:
                                            <?php

                                            echo min(
                                                100,
                                                max(
                                                    0,
                                                    $course[
                                                        "average_progress"
                                                    ]
                                                )
                                            );

                                            ?>%;"
                                    >

                                    </div>

                                </div>

                            </div>


                            <!-- ACTION -->

                            <div
                                class="col-lg-2
                                       text-lg-end"
                            >

                                <!-- FIXED: OPEN MATERIALS PAGE -->

                                <a
                                    href="materials.php?course_id=<?php echo $course["course_id"]; ?>"
                                    class="btn btn-outline-primary btn-sm"
                                >

                                    Manage

                                </a>

                            </div>

                        </div>

                    </div>


                <?php endwhile; ?>


            <?php else: ?>


                <div class="alert alert-info mb-0">

                    No courses are available yet.

                </div>


            <?php endif; ?>

        </div>

    </div>


</div>


<!-- =========================================
     FOOTER
========================================= -->

<footer class="bg-dark text-white text-center py-3">

    <p class="mb-0">

        © <?php echo date("Y"); ?> OUTLIERS |

        AI-Powered Skill Intelligence
        & Learning Platform

    </p>

</footer>


</body>

</html>