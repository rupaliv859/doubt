<?php

session_start();

require_once "../includes/db.php";


/*
|--------------------------------------------------------------------------
| CHECK EMPLOYEE LOGIN
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "employee") {

    header("Location: ../employee-login.php");
    exit();

}

$user_id = $_SESSION["user_id"];


/*
|--------------------------------------------------------------------------
| GET EMPLOYEE ID
|--------------------------------------------------------------------------
*/

$employee_query = $conn->prepare(
    "SELECT employee_id, full_name
     FROM employees
     WHERE user_id = ?
     LIMIT 1"
);

$employee_query->bind_param("i", $user_id);

$employee_query->execute();

$employee_result = $employee_query->get_result();


if ($employee_result->num_rows !== 1) {

    die("Employee profile not found.");

}


$employee = $employee_result->fetch_assoc();

$employee_id = $employee["employee_id"];

$employee_query->close();


/*
|--------------------------------------------------------------------------
| START LEARNING
|--------------------------------------------------------------------------
|
| When employee clicks Start Learning:
|
| 1. Verify that the course was assigned to this employee.
| 2. Check whether enrollment already exists.
| 3. If not, create enrollment.
| 4. Create progress record.
| 5. Redirect to the course page.
|
*/

if (
    isset($_GET["start"])
    &&
    is_numeric($_GET["start"])
) {

    $course_id = (int) $_GET["start"];


    /*
    |--------------------------------------------------------------------------
    | VERIFY COURSE ASSIGNMENT
    |--------------------------------------------------------------------------
    */

    $verify_query = $conn->prepare(
        "SELECT
            ca.assignment_id,
            ca.course_id,
            ca.employee_id,
            ca.status,
            c.course_name
        FROM course_assignments ca
        INNER JOIN courses c
            ON ca.course_id = c.course_id
        WHERE ca.course_id = ?
        AND ca.employee_id = ?
        AND ca.status = 'assigned'
        LIMIT 1"
    );

    $verify_query->bind_param(
        "ii",
        $course_id,
        $employee_id
    );

    $verify_query->execute();

    $verify_result = $verify_query->get_result();


    if ($verify_result->num_rows !== 1) {

        $verify_query->close();

        header(
            "Location: assigned-courses.php?error=invalid"
        );

        exit();

    }


    $assigned_course =
        $verify_result->fetch_assoc();

    $verify_query->close();


    /*
    |--------------------------------------------------------------------------
    | CHECK EXISTING ENROLLMENT
    |--------------------------------------------------------------------------
    */

    $enrollment_query = $conn->prepare(
        "SELECT enrollment_id
         FROM enrollments
         WHERE employee_id = ?
         AND course_id = ?
         LIMIT 1"
    );

    $enrollment_query->bind_param(
        "ii",
        $employee_id,
        $course_id
    );

    $enrollment_query->execute();

    $enrollment_result =
        $enrollment_query->get_result();


    /*
    |--------------------------------------------------------------------------
    | ENROLL EMPLOYEE IF NOT ALREADY ENROLLED
    |--------------------------------------------------------------------------
    */

    if ($enrollment_result->num_rows === 0) {


        $enrollment_query->close();


        /*
        |--------------------------------------------------------------------------
        | CREATE ENROLLMENT
        |--------------------------------------------------------------------------
        */

        $insert_enrollment = $conn->prepare(
            "INSERT INTO enrollments
                (
                    employee_id,
                    course_id,
                    enrolled_at,
                    status
                )
             VALUES
                (
                    ?,
                    ?,
                    NOW(),
                    'active'
                )"
        );

        $insert_enrollment->bind_param(
            "ii",
            $employee_id,
            $course_id
        );

        $insert_enrollment->execute();

        $enrollment_id =
            $insert_enrollment->insert_id;

        $insert_enrollment->close();


        /*
        |--------------------------------------------------------------------------
        | CREATE INITIAL PROGRESS
        |--------------------------------------------------------------------------
        */

        $insert_progress = $conn->prepare(
            "INSERT INTO progress
                (
                    enrollment_id,
                    progress_percentage,
                    last_accessed
                )
             VALUES
                (
                    ?,
                    0,
                    NOW()
                )"
        );

        $insert_progress->bind_param(
            "i",
            $enrollment_id
        );

        $insert_progress->execute();

        $insert_progress->close();


    } else {


        /*
        |--------------------------------------------------------------------------
        | EXISTING ENROLLMENT
        |--------------------------------------------------------------------------
        */

        $existing_enrollment =
            $enrollment_result->fetch_assoc();

        $enrollment_id =
            $existing_enrollment["enrollment_id"];

        $enrollment_query->close();


        /*
        |--------------------------------------------------------------------------
        | CHECK WHETHER PROGRESS EXISTS
        |--------------------------------------------------------------------------
        */

        $progress_check = $conn->prepare(
            "SELECT progress_id
             FROM progress
             WHERE enrollment_id = ?
             LIMIT 1"
        );

        $progress_check->bind_param(
            "i",
            $enrollment_id
        );

        $progress_check->execute();

        $progress_result =
            $progress_check->get_result();


        /*
        |--------------------------------------------------------------------------
        | CREATE PROGRESS IF MISSING
        |--------------------------------------------------------------------------
        */

        if ($progress_result->num_rows === 0) {

            $progress_check->close();


            $insert_progress = $conn->prepare(
                "INSERT INTO progress
                    (
                        enrollment_id,
                        progress_percentage,
                        last_accessed
                    )
                 VALUES
                    (
                        ?,
                        0,
                        NOW()
                    )"
            );

            $insert_progress->bind_param(
                "i",
                $enrollment_id
            );

            $insert_progress->execute();

            $insert_progress->close();

        } else {

            $progress_check->close();

        }

    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE LAST ACCESSED
    |--------------------------------------------------------------------------
    */

    $update_progress = $conn->prepare(
        "UPDATE progress
         SET last_accessed = NOW()
         WHERE enrollment_id = ?"
    );

    $update_progress->bind_param(
        "i",
        $enrollment_id
    );

    $update_progress->execute();

    $update_progress->close();


    /*
    |--------------------------------------------------------------------------
    | REDIRECT TO COURSE
    |--------------------------------------------------------------------------
    */

    header(
        "Location: courses.php?course_id=" . $course_id
    );

    exit();

}


/*
|--------------------------------------------------------------------------
| GET ERROR MESSAGE
|--------------------------------------------------------------------------
*/

$error_message = "";

if (
    isset($_GET["error"])
    &&
    $_GET["error"] === "invalid"
) {

    $error_message =
        "You are not authorized to access this assigned course.";

}


/*
|--------------------------------------------------------------------------
| GET ASSIGNED COURSES
|--------------------------------------------------------------------------
*/

$courses_query = $conn->prepare(
    "SELECT
        ca.assignment_id,
        ca.course_id,
        ca.assigned_at,
        ca.status,

        c.course_name,
        c.description,
        c.difficulty,

        u.username AS trainer_name,
        u.email AS trainer_email,

        COALESCE(
            (
                SELECT p.progress_percentage
                FROM enrollments e
                INNER JOIN progress p
                    ON e.enrollment_id = p.enrollment_id
                WHERE e.employee_id = ca.employee_id
                AND e.course_id = ca.course_id
                LIMIT 1
            ),
            0
        ) AS progress_percentage

    FROM course_assignments ca

    INNER JOIN courses c
        ON ca.course_id = c.course_id

    INNER JOIN users u
        ON ca.assigned_by = u.user_id

    WHERE ca.employee_id = ?

    ORDER BY ca.assigned_at DESC"
);

$courses_query->bind_param(
    "i",
    $employee_id
);

$courses_query->execute();

$courses_result =
    $courses_query->get_result();

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
        Trainer Assigned Courses | OUTLIERS
    </title>


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- OUTLIERS CSS -->

    <link
        rel="stylesheet"
        href="../css/style.css"
    >

</head>


<body class="bg-light">


<!-- =========================================================
     NAVBAR
========================================================= -->

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">

    <div class="container-fluid px-4">


        <a
            class="navbar-brand fw-bold"
            href="dashboard.php"
        >
            OUTLIERS
        </a>


        <div class="d-flex align-items-center">


            <span class="text-white me-3">

                <?php

                echo htmlspecialchars(
                    $employee["full_name"]
                );

                ?>

            </span>


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


    <!-- PAGE HEADER -->

    <div class="mb-4">

        <h2 class="fw-bold">

            Trainer Assigned Courses

        </h2>


        <p class="text-muted">

            Courses specifically assigned to you
            by your verified trainer.

        </p>

    </div>



    <!-- =====================================================
         ERROR MESSAGE
    ====================================================== -->

    <?php if (!empty($error_message)): ?>

        <div
            class="alert alert-danger"
            role="alert"
        >

            <?php

            echo htmlspecialchars(
                $error_message
            );

            ?>

        </div>

    <?php endif; ?>



    <!-- =====================================================
         INFO MESSAGE
    ====================================================== -->

    <div class="alert alert-info">

        <strong>Trainer-specific learning:</strong>

        These courses have been assigned to you
        by your trainer. You can start learning
        directly from this page.

    </div>



    <!-- =====================================================
         ASSIGNED COURSES
    ====================================================== -->

    <?php if ($courses_result->num_rows > 0): ?>


        <div class="row g-4">


            <?php while (
                $course =
                $courses_result->fetch_assoc()
            ): ?>


                <?php

                $progress =
                    round(
                        $course["progress_percentage"]
                    );

                $progress =
                    min(
                        100,
                        max(
                            0,
                            $progress
                        )
                    );

                ?>


                <div class="col-md-6 col-lg-4">


                    <div
                        class="card border-0
                               shadow-sm h-100"
                    >

                        <div class="card-body
                                    d-flex
                                    flex-column">


                            <!-- COURSE NAME -->

                            <h5 class="fw-bold">

                                <?php

                                echo htmlspecialchars(
                                    $course["course_name"]
                                );

                                ?>

                            </h5>



                            <!-- DIFFICULTY -->

                            <div class="mb-2">

                                <span
                                    class="badge
                                           bg-secondary"
                                >

                                    <?php

                                    echo htmlspecialchars(
                                        $course["difficulty"]
                                    );

                                    ?>

                                </span>

                            </div>



                            <!-- DESCRIPTION -->

                            <p class="text-muted small">

                                <?php

                                echo htmlspecialchars(
                                    $course["description"]
                                );

                                ?>

                            </p>



                            <hr>



                            <!-- TRAINER -->

                            <p class="mb-1">

                                <strong>
                                    Trainer:
                                </strong>

                                <?php

                                echo htmlspecialchars(
                                    $course["trainer_name"]
                                );

                                ?>

                            </p>


                            <p class="small text-muted">

                                <?php

                                echo htmlspecialchars(
                                    $course["trainer_email"]
                                );

                                ?>

                            </p>



                            <!-- ASSIGNED DATE -->

                            <p class="small text-muted">

                                Assigned on:

                                <?php

                                echo date(
                                    "d M Y",
                                    strtotime(
                                        $course["assigned_at"]
                                    )
                                );

                                ?>

                            </p>



                            <!-- =================================================
                                 PROGRESS
                            ================================================== -->

                            <div class="mt-auto">


                                <div
                                    class="d-flex
                                           justify-content-between
                                           mb-1"
                                >

                                    <small
                                        class="text-muted"
                                    >
                                        Progress
                                    </small>


                                    <small
                                        class="fw-bold"
                                    >

                                        <?php
                                        echo $progress;
                                        ?>%

                                    </small>

                                </div>



                                <div
                                    class="progress mb-3"
                                    style="height: 8px;"
                                >

                                    <div
                                        class="progress-bar
                                               bg-success"
                                        role="progressbar"
                                        style="
                                            width:
                                            <?php
                                            echo $progress;
                                            ?>%;
                                        "
                                        aria-valuenow="
                                            <?php
                                            echo $progress;
                                            ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                    ></div>

                                </div>



                                <!-- =================================================
                                     STATUS
                                ================================================== -->

                                <?php if (
                                    $course["status"] ===
                                    "completed"
                                ): ?>


                                    <span
                                        class="badge
                                               bg-success
                                               mb-2"
                                    >

                                        Completed

                                    </span>


                                <?php elseif (
                                    $course["status"] ===
                                    "cancelled"
                                ): ?>


                                    <span
                                        class="badge
                                               bg-danger
                                               mb-2"
                                    >

                                        Cancelled

                                    </span>


                                <?php else: ?>


                                    <span
                                        class="badge
                                               bg-primary
                                               mb-2"
                                    >

                                        Assigned

                                    </span>


                                <?php endif; ?>



                                <!-- =================================================
                                     START LEARNING BUTTON
                                ================================================== -->

                                <?php if (
                                    $course["status"] ===
                                    "assigned"
                                ): ?>


                                    <a
                                        href="assigned-courses.php?start=<?php
                                        echo (int)
                                            $course["course_id"];
                                        ?>"
                                        class="btn btn-primary w-100"
                                    >

                                        Start Learning

                                    </a>


                                <?php elseif (
                                    $course["status"] ===
                                    "completed"
                                ): ?>


                                    <a
                                        href="courses.php?course_id=<?php
                                        echo (int)
                                            $course["course_id"];
                                        ?>"
                                        class="btn btn-outline-success w-100"
                                    >

                                        Continue Course

                                    </a>


                                <?php else: ?>


                                    <button
                                        class="btn btn-secondary
                                               w-100"
                                        disabled
                                    >

                                        Course Cancelled

                                    </button>


                                <?php endif; ?>


                            </div>


                        </div>

                    </div>


                </div>


            <?php endwhile; ?>


        </div>


    <?php else: ?>


        <!-- =================================================
             NO COURSES
        ================================================== -->

        <div class="card border-0 shadow-sm">

            <div class="card-body text-center py-5">


                <div
                    class="display-4 mb-3"
                >
                    📚
                </div>


                <h4 class="fw-bold">

                    No Trainer Assigned Courses

                </h4>


                <p class="text-muted">

                    Your trainer has not assigned
                    any courses to you yet.

                </p>


                <a
                    href="courses.php"
                    class="btn btn-primary"
                >

                    Browse General Courses

                </a>


            </div>

        </div>


    <?php endif; ?>


    <!-- =====================================================
         BACK TO DASHBOARD
    ====================================================== -->

    <div class="mt-4">

        <a
            href="dashboard.php"
            class="btn btn-outline-secondary"
        >

            ← Back to Dashboard

        </a>

    </div>


</div>



<!-- =========================================================
     FOOTER
========================================================= -->

<footer
    class="bg-dark text-white
           text-center py-3 mt-5"
>

    <p class="mb-0">

        © 2026 OUTLIERS |
        AI-Powered Learning Platform

    </p>

</footer>


</body>

</html>


<?php

$courses_query->close();

?>