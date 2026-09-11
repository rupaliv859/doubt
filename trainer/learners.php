<?php

session_start();

require_once "../includes/db.php";


// Only trainer can access this page
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "trainer") {
    header("Location: ../login.php");
    exit();
}


$trainer_id = $_SESSION["user_id"];


// Get course ID
$course_id = isset($_GET["course_id"]) ? intval($_GET["course_id"]) : 0;


/*
|--------------------------------------------------------------------------
| GET COURSE DETAILS
|--------------------------------------------------------------------------
*/

$course = null;

if ($course_id > 0) {

    $stmt = $conn->prepare("
        SELECT
            c.course_id,
            c.course_name,
            s.skill_name,
            c.difficulty
        FROM courses c
        LEFT JOIN skills s
            ON c.skill_id = s.skill_id
        WHERE c.course_id = ?
    ");

    $stmt->bind_param("i", $course_id);
    $stmt->execute();

    $result = $stmt->get_result();

    $course = $result->fetch_assoc();

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| GET VERIFIED EMPLOYEES
|--------------------------------------------------------------------------
|
| These are employees whose trainer request has been approved
| for the currently logged-in trainer.
|
*/

$verified_employees = [];

$stmt = $conn->prepare("
    SELECT
        te.relationship_id,
        e.employee_id,
        e.employee_code,
        e.full_name,
        e.department,
        e.designation,
        e.qualification,
        e.experience_years,
        u.email AS employee_email,
        te.approved_at

    FROM trainer_employee te

    INNER JOIN employees e
        ON te.employee_id = e.employee_id

    INNER JOIN users u
        ON e.user_id = u.user_id

    WHERE te.trainer_id = ?
    AND te.status = 'approved'

    ORDER BY te.approved_at DESC
");

$stmt->bind_param("i", $trainer_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $verified_employees[] = $row;
}

$stmt->close();


/*
|--------------------------------------------------------------------------
| GET LEARNERS ENROLLED IN THIS COURSE
|--------------------------------------------------------------------------
*/

$learners = [];

if ($course_id > 0) {

    $stmt = $conn->prepare("
        SELECT
            e.employee_code,
            e.full_name,
            e.department,
            e.designation,
            en.enrolled_at,
            en.status,
            COALESCE(p.progress_percentage, 0) AS progress_percentage

        FROM enrollments en

        INNER JOIN employees e
            ON en.employee_id = e.employee_id

        LEFT JOIN progress p
            ON en.enrollment_id = p.enrollment_id

        WHERE en.course_id = ?

        ORDER BY en.enrolled_at DESC
    ");

    $stmt->bind_param("i", $course_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $learners[] = $row;
    }

    $stmt->close();
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

    <title>Learners - OUTLIERS</title>

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


<!-- ==========================================
     NAVBAR
========================================== -->

<nav class="navbar navbar-dark bg-dark">

    <div class="container">

        <a
            class="navbar-brand fw-bold"
            href="dashboard.php"
        >
            OUTLIERS
        </a>


        <div>

            <a
                href="requests.php"
                class="btn btn-outline-light btn-sm me-2"
            >
                Employee Requests
            </a>

            <a
                href="dashboard.php"
                class="btn btn-outline-light btn-sm"
            >
                Dashboard
            </a>

        </div>

    </div>

</nav>



<div class="container py-5">


    <!-- ==========================================
         VERIFIED EMPLOYEES
    =========================================== -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="fw-bold mb-1">
                My Verified Employees
            </h2>

            <p class="text-muted mb-0">

                Employees whose trainer relationship has been approved.

            </p>

        </div>


        <span class="badge bg-success fs-6">

            <?php echo count($verified_employees); ?>

            Verified

        </span>

    </div>


    <?php if (count($verified_employees) > 0): ?>


        <div class="card shadow-sm border-0 mb-5">

            <div class="card-body">

                <div class="table-responsive">

                    <table class="table table-hover align-middle">

                        <thead class="table-light">

                            <tr>

                                <th>Employee ID</th>

                                <th>Name</th>

                                <th>Email</th>

                                <th>Department</th>

                                <th>Designation</th>

                                <th>Qualification</th>

                                <th>Experience</th>

                                <th>Verified On</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($verified_employees as $employee): ?>

                                <tr>


                                    <!-- EMPLOYEE ID -->

                                    <td>

                                        <?php
                                        echo htmlspecialchars(
                                            $employee["employee_code"]
                                        );
                                        ?>

                                    </td>


                                    <!-- NAME -->

                                    <td class="fw-semibold">

                                        <?php
                                        echo htmlspecialchars(
                                            $employee["full_name"]
                                        );
                                        ?>

                                    </td>


                                    <!-- EMAIL -->

                                    <td>

                                        <?php
                                        echo htmlspecialchars(
                                            $employee["employee_email"]
                                        );
                                        ?>

                                    </td>


                                    <!-- DEPARTMENT -->

                                    <td>

                                        <?php
                                        echo htmlspecialchars(
                                            $employee["department"]
                                        );
                                        ?>

                                    </td>


                                    <!-- DESIGNATION -->

                                    <td>

                                        <?php
                                        echo htmlspecialchars(
                                            $employee["designation"]
                                        );
                                        ?>

                                    </td>


                                    <!-- QUALIFICATION -->

                                    <td>

                                        <?php
                                        echo htmlspecialchars(
                                            $employee["qualification"]
                                        );
                                        ?>

                                    </td>


                                    <!-- EXPERIENCE -->

                                    <td>

                                        <?php
                                        echo htmlspecialchars(
                                            $employee["experience_years"]
                                        );
                                        ?>

                                        years

                                    </td>


                                    <!-- VERIFIED DATE -->

                                    <td>

                                        <?php

                                        if (!empty($employee["approved_at"])) {

                                            echo date(
                                                "d M Y",
                                                strtotime(
                                                    $employee["approved_at"]
                                                )
                                            );

                                        } else {

                                            echo "-";

                                        }

                                        ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>


    <?php else: ?>


        <div class="card shadow-sm border-0 mb-5">

            <div class="card-body text-center py-5">

                <h4>
                    No verified employees yet
                </h4>

                <p class="text-muted mb-3">

                    Employees who request you as their trainer will
                    appear here after you approve their request.

                </p>


                <a
                    href="requests.php"
                    class="btn btn-primary"
                >
                    View Employee Requests
                </a>

            </div>

        </div>


    <?php endif; ?>



    <!-- ==========================================
         COURSE DETAILS / COURSE LEARNERS
    =========================================== -->

    <?php if ($course): ?>


        <div class="d-flex justify-content-between align-items-center mb-4">

            <div>

                <h2 class="fw-bold">
                    Course Learners
                </h2>

                <p class="text-muted mb-0">

                    <?php
                    echo htmlspecialchars(
                        $course["course_name"]
                    );
                    ?>


                    <?php if (!empty($course["skill_name"])): ?>

                        ·

                        <?php
                        echo htmlspecialchars(
                            $course["skill_name"]
                        );
                        ?>

                    <?php endif; ?>

                </p>

            </div>


            <a
                href="courses.php"
                class="btn btn-outline-primary"
            >
                Back to Courses
            </a>

        </div>



        <?php if (count($learners) > 0): ?>


            <div class="card shadow-sm border-0">

                <div class="card-body">

                    <div class="table-responsive">

                        <table class="table table-hover align-middle">

                            <thead class="table-light">

                                <tr>

                                    <th>Employee ID</th>

                                    <th>Name</th>

                                    <th>Department</th>

                                    <th>Designation</th>

                                    <th>Progress</th>

                                    <th>Status</th>

                                    <th>Enrolled On</th>

                                </tr>

                            </thead>


                            <tbody>

                                <?php foreach ($learners as $learner): ?>

                                    <tr>


                                        <!-- EMPLOYEE ID -->

                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $learner["employee_code"]
                                            );
                                            ?>

                                        </td>


                                        <!-- NAME -->

                                        <td class="fw-semibold">

                                            <?php
                                            echo htmlspecialchars(
                                                $learner["full_name"]
                                            );
                                            ?>

                                        </td>


                                        <!-- DEPARTMENT -->

                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $learner["department"]
                                            );
                                            ?>

                                        </td>


                                        <!-- DESIGNATION -->

                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $learner["designation"]
                                            );
                                            ?>

                                        </td>


                                        <!-- PROGRESS -->

                                        <td style="min-width: 150px;">

                                            <div class="progress">

                                                <div
                                                    class="progress-bar"
                                                    role="progressbar"
                                                    style="width: <?php echo $learner["progress_percentage"]; ?>%;"
                                                >

                                                    <?php

                                                    echo round(
                                                        $learner[
                                                            "progress_percentage"
                                                        ]
                                                    );

                                                    ?>%

                                                </div>

                                            </div>

                                        </td>


                                        <!-- STATUS -->

                                        <td>

                                            <?php if (
                                                $learner["status"]
                                                === "completed"
                                            ): ?>

                                                <span
                                                    class="badge bg-success"
                                                >
                                                    Completed
                                                </span>


                                            <?php elseif (
                                                $learner["status"]
                                                === "dropped"
                                            ): ?>

                                                <span
                                                    class="badge bg-danger"
                                                >
                                                    Dropped
                                                </span>


                                            <?php else: ?>

                                                <span
                                                    class="badge bg-primary"
                                                >
                                                    Enrolled
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <!-- ENROLLED DATE -->

                                        <td>

                                            <?php

                                            echo date(
                                                "d M Y",
                                                strtotime(
                                                    $learner["enrolled_at"]
                                                )
                                            );

                                            ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>


        <?php else: ?>


            <div class="card shadow-sm border-0">

                <div class="card-body text-center py-5">

                    <h4>
                        No learners yet
                    </h4>

                    <p class="text-muted">

                        No employees have enrolled in this course yet.

                    </p>

                </div>

            </div>


        <?php endif; ?>


    <?php else: ?>


        <div class="alert alert-warning">

            <h5>
                Course not selected
            </h5>

            <p class="mb-0">

                Please select a course from the Courses page to view
                its learners.

            </p>

        </div>


        <a
            href="courses.php"
            class="btn btn-primary"
        >
            Go to Courses
        </a>


    <?php endif; ?>


</div>



<!-- ==========================================
     FOOTER
========================================== -->

<footer class="bg-dark text-white text-center py-3 mt-5">

    <p class="mb-0">

        © 2026 OUTLIERS | AI-Powered Learning Platform

    </p>

</footer>


</body>

</html>