<?php

session_start();

require_once "../includes/db.php";


/*
|--------------------------------------------------------------------------
| CHECK EMPLOYEE LOGIN
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "employee"
) {

    header("Location: ../employee-login.php");
    exit();

}


$user_id = $_SESSION["user_id"];


/*
|--------------------------------------------------------------------------
| HANDLE PROFILE UPDATE
|--------------------------------------------------------------------------
*/

$success_message = "";
$error_message = "";


if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = trim($_POST["full_name"] ?? "");
    $department = trim($_POST["department"] ?? "");
    $designation = trim($_POST["designation"] ?? "");
    $qualification = trim($_POST["qualification"] ?? "");
    $experience_years = trim($_POST["experience_years"] ?? "");


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($full_name === "") {

        $error_message = "Full name is required.";

    } elseif ($department === "") {

        $error_message = "Department is required.";

    } elseif ($designation === "") {

        $error_message = "Designation is required.";

    } elseif ($qualification === "") {

        $error_message = "Qualification is required.";

    } elseif (
        $experience_years === "" ||
        !is_numeric($experience_years) ||
        $experience_years < 0
    ) {

        $error_message =
            "Please enter a valid experience value.";

    } else {


        /*
        |--------------------------------------------------------------------------
        | UPDATE EMPLOYEE PROFILE
        |--------------------------------------------------------------------------
        */

        $update_query = $conn->prepare(
            "UPDATE employees
             SET
                full_name = ?,
                department = ?,
                designation = ?,
                qualification = ?,
                experience_years = ?
             WHERE user_id = ?"
        );


        $experience_years =
            (float)$experience_years;


        $update_query->bind_param(
            "ssssdi",
            $full_name,
            $department,
            $designation,
            $qualification,
            $experience_years,
            $user_id
        );


        if ($update_query->execute()) {

            $success_message =
                "Your profile has been updated successfully.";

        } else {

            $error_message =
                "Unable to update profile. Please try again.";

        }


        $update_query->close();

    }

}


/*
|--------------------------------------------------------------------------
| GET EMPLOYEE PROFILE
|--------------------------------------------------------------------------
*/

$employee_query = $conn->prepare(
    "SELECT
        e.employee_id,
        e.employee_code,
        e.full_name,
        e.department,
        e.designation,
        e.qualification,
        e.experience_years,
        e.created_at,
        u.email,
        u.role
    FROM employees e
    INNER JOIN users u
        ON e.user_id = u.user_id
    WHERE e.user_id = ?
    LIMIT 1"
);


$employee_query->bind_param(
    "i",
    $user_id
);


$employee_query->execute();


$employee_result =
    $employee_query->get_result();


if ($employee_result->num_rows !== 1) {

    die("Employee profile not found.");

}


$employee =
    $employee_result->fetch_assoc();


$employee_id =
    $employee["employee_id"];


$employee_query->close();


/*
|--------------------------------------------------------------------------
| GET TRAINER INFORMATION
|--------------------------------------------------------------------------
*/

$trainer_query = $conn->prepare(
    "SELECT
        te.status,
        te.requested_at,
        te.approved_at,
        u.username AS trainer_username,
        u.email AS trainer_email
    FROM trainer_employee te
    INNER JOIN users u
        ON te.trainer_id = u.user_id
    WHERE te.employee_id = ?
    ORDER BY te.relationship_id DESC
    LIMIT 1"
);


$trainer_query->bind_param(
    "i",
    $employee_id
);


$trainer_query->execute();


$trainer_result =
    $trainer_query->get_result();


$trainer = null;


if ($trainer_result->num_rows > 0) {

    $trainer =
        $trainer_result->fetch_assoc();

}


$trainer_query->close();


/*
|--------------------------------------------------------------------------
| GET SKILL GAP COUNT
|--------------------------------------------------------------------------
*/

$gap_query = $conn->prepare(
    "SELECT COUNT(*) AS gap_count
     FROM employee_skills es
     INNER JOIN role_skills rs
        ON es.skill_id = rs.skill_id
     INNER JOIN roles r
        ON rs.role_id = r.role_id
     INNER JOIN employees e
        ON e.employee_id = es.employee_id
     WHERE es.employee_id = ?
     AND es.current_level < rs.required_level
     AND r.role_name = e.designation"
);


$gap_query->bind_param(
    "i",
    $employee_id
);


$gap_query->execute();


$gap_result =
    $gap_query->get_result();


$gap_data =
    $gap_result->fetch_assoc();


$skill_gap_count =
    $gap_data["gap_count"] ?? 0;


$gap_query->close();


/*
|--------------------------------------------------------------------------
| GET LEARNING PROGRESS
|--------------------------------------------------------------------------
*/

$progress_query = $conn->prepare(
    "SELECT
        AVG(p.progress_percentage) AS average_progress
     FROM progress p
     INNER JOIN enrollments e
        ON p.enrollment_id = e.enrollment_id
     WHERE e.employee_id = ?"
);


$progress_query->bind_param(
    "i",
    $employee_id
);


$progress_query->execute();


$progress_result =
    $progress_query->get_result();


$progress_data =
    $progress_result->fetch_assoc();


$average_progress =
    $progress_data["average_progress"] ?? 0;


$average_progress =
    round($average_progress);


$average_progress =
    min(
        100,
        max(
            0,
            $average_progress
        )
    );


$progress_query->close();


/*
|--------------------------------------------------------------------------
| GET OVERALL COMPETENCY
|--------------------------------------------------------------------------
*/

$competency_query = $conn->prepare(
    "SELECT
        AVG(
            CASE
                WHEN rs.required_level > 0
                THEN
                    (es.current_level / rs.required_level) * 100
                ELSE 0
            END
        ) AS competency_score
     FROM employee_skills es
     INNER JOIN role_skills rs
        ON es.skill_id = rs.skill_id
     INNER JOIN roles r
        ON rs.role_id = r.role_id
     INNER JOIN employees e
        ON e.employee_id = es.employee_id
     WHERE es.employee_id = ?
     AND r.role_name = e.designation"
);


$competency_query->bind_param(
    "i",
    $employee_id
);


$competency_query->execute();


$competency_result =
    $competency_query->get_result();


$competency_data =
    $competency_result->fetch_assoc();


$competency_score =
    $competency_data["competency_score"] ?? 0;


$competency_score =
    round($competency_score);


$competency_score =
    min(
        100,
        max(
            0,
            $competency_score
        )
    );


$competency_query->close();


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
        My Profile | OUTLIERS
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
            href="../index.php"
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
     MAIN CONTAINER
========================================================= -->

<div class="container py-5">


    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div
        class="d-flex
               justify-content-between
               align-items-center
               mb-4"
    >

        <div>

            <h2 class="fw-bold mb-1">
                My Profile
            </h2>


            <p class="text-muted mb-0">

                View and manage your employee profile.

            </p>

        </div>


        <a
            href="dashboard.php"
            class="btn btn-outline-primary"
        >
            ← Back to Dashboard
        </a>

    </div>



    <!-- =====================================================
         SUCCESS MESSAGE
    ====================================================== -->

    <?php if ($success_message !== ""): ?>

        <div
            class="alert alert-success
                   alert-dismissible fade show"
            role="alert"
        >

            <?php

            echo htmlspecialchars(
                $success_message
            );

            ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>



    <!-- =====================================================
         ERROR MESSAGE
    ====================================================== -->

    <?php if ($error_message !== ""): ?>

        <div
            class="alert alert-danger
                   alert-dismissible fade show"
            role="alert"
        >

            <?php

            echo htmlspecialchars(
                $error_message
            );

            ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>



    <!-- =====================================================
         PROFILE INFORMATION
    ====================================================== -->

    <div class="row g-4">


        <!-- =================================================
             LEFT SIDE
        ================================================== -->

        <div class="col-lg-8">


            <div
                class="card
                       border-0
                       shadow-sm
                       mb-4"
            >

                <div class="card-body p-4">


                    <h5 class="fw-bold mb-4">
                        Personal & Employee Information
                    </h5>



                    <!-- =================================================
                         EMPLOYEE ID + EMAIL
                    ================================================== -->

                    <div class="row g-4 mb-3">


                        <div class="col-md-6">

                            <label
                                class="form-label
                                       text-muted
                                       small"
                            >
                                Employee ID
                            </label>


                            <div
                                class="form-control
                                       bg-light"
                            >

                                <?php

                                echo htmlspecialchars(
                                    $employee["employee_code"]
                                );

                                ?>

                            </div>

                        </div>



                        <div class="col-md-6">

                            <label
                                class="form-label
                                       text-muted
                                       small"
                            >
                                Email
                            </label>


                            <div
                                class="form-control
                                       bg-light"
                            >

                                <?php

                                echo htmlspecialchars(
                                    $employee["email"]
                                );

                                ?>

                            </div>

                        </div>


                    </div>



                    <!-- =================================================
                         ROLE
                    ================================================== -->

                    <div class="mb-4">

                        <label
                            class="form-label
                                   text-muted
                                   small"
                        >
                            Account Role
                        </label>


                        <div>

                            <span class="badge bg-primary">

                                <?php

                                echo htmlspecialchars(
                                    ucfirst(
                                        $employee["role"]
                                    )
                                );

                                ?>

                            </span>

                        </div>

                    </div>



                    <!-- =================================================
                         EDIT PROFILE FORM
                    ================================================== -->

                    <form
                        method="POST"
                        action=""
                    >


                        <!-- FULL NAME -->

                        <div class="mb-3">

                            <label
                                for="full_name"
                                class="form-label fw-semibold"
                            >
                                Full Name
                            </label>


                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                class="form-control"
                                value="<?php
                                echo htmlspecialchars(
                                    $employee["full_name"]
                                );
                                ?>"
                                required
                            >

                        </div>



                        <!-- DEPARTMENT -->

                        <div class="mb-3">

                            <label
                                for="department"
                                class="form-label fw-semibold"
                            >
                                Department
                            </label>


                            <input
                                type="text"
                                id="department"
                                name="department"
                                class="form-control"
                                value="<?php
                                echo htmlspecialchars(
                                    $employee["department"]
                                );
                                ?>"
                                required
                            >

                        </div>



                        <!-- DESIGNATION -->

                        <div class="mb-3">

                            <label
                                for="designation"
                                class="form-label fw-semibold"
                            >
                                Designation
                            </label>


                            <input
                                type="text"
                                id="designation"
                                name="designation"
                                class="form-control"
                                value="<?php
                                echo htmlspecialchars(
                                    $employee["designation"]
                                );
                                ?>"
                                required
                            >

                        </div>



                        <!-- QUALIFICATION -->

                        <div class="mb-3">

                            <label
                                for="qualification"
                                class="form-label fw-semibold"
                            >
                                Qualification
                            </label>


                            <input
                                type="text"
                                id="qualification"
                                name="qualification"
                                class="form-control"
                                value="<?php
                                echo htmlspecialchars(
                                    $employee["qualification"]
                                );
                                ?>"
                                required
                            >

                        </div>



                        <!-- EXPERIENCE -->

                        <div class="mb-4">

                            <label
                                for="experience_years"
                                class="form-label fw-semibold"
                            >
                                Experience (Years)
                            </label>


                            <input
                                type="number"
                                id="experience_years"
                                name="experience_years"
                                class="form-control"
                                min="0"
                                step="0.1"
                                value="<?php
                                echo htmlspecialchars(
                                    $employee["experience_years"]
                                );
                                ?>"
                                required
                            >

                        </div>



                        <!-- BUTTON -->

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            Save Changes
                        </button>


                    </form>


                </div>

            </div>


        </div>



        <!-- =================================================
             RIGHT SIDE
        ================================================== -->

        <div class="col-lg-4">


            <!-- =================================================
                 ACCOUNT SUMMARY
            ================================================== -->

            <div
                class="card
                       border-0
                       shadow-sm
                       mb-4"
            >

                <div class="card-body p-4">


                    <h5 class="fw-bold mb-4">
                        Learning Summary
                    </h5>



                    <!-- COMPETENCY -->

                    <div class="mb-4">

                        <div
                            class="d-flex
                                   justify-content-between
                                   mb-2"
                        >

                            <span>
                                Competency
                            </span>


                            <strong>

                                <?php
                                echo $competency_score;
                                ?>%

                            </strong>

                        </div>


                        <div
                            class="progress"
                            style="height: 8px;"
                        >

                            <div
                                class="progress-bar"
                                role="progressbar"
                                style="
                                    width:
                                    <?php
                                    echo $competency_score;
                                    ?>%;
                                "
                            ></div>

                        </div>

                    </div>



                    <!-- SKILL GAPS -->

                    <div
                        class="d-flex
                               justify-content-between
                               align-items-center
                               mb-4"
                    >

                        <span>
                            Skill Gaps
                        </span>


                        <span class="badge bg-warning text-dark">

                            <?php
                            echo $skill_gap_count;
                            ?>

                        </span>

                    </div>



                    <!-- LEARNING PROGRESS -->

                    <div>

                        <div
                            class="d-flex
                                   justify-content-between
                                   mb-2"
                        >

                            <span>
                                Learning Progress
                            </span>


                            <strong>

                                <?php
                                echo $average_progress;
                                ?>%

                            </strong>

                        </div>


                        <div
                            class="progress"
                            style="height: 8px;"
                        >

                            <div
                                class="progress-bar bg-success"
                                role="progressbar"
                                style="
                                    width:
                                    <?php
                                    echo $average_progress;
                                    ?>%;
                                "
                            ></div>

                        </div>

                    </div>


                </div>

            </div>



            <!-- =================================================
                 TRAINER CARD
            ================================================== -->

            <div
                class="card
                       border-0
                       shadow-sm"
            >

                <div class="card-body p-4">


                    <h5 class="fw-bold mb-4">
                        My Trainer
                    </h5>



                    <?php if ($trainer === null): ?>


                        <div class="text-center">

                            <span
                                class="badge
                                       bg-secondary
                                       mb-3"
                            >
                                Not Connected
                            </span>


                            <p class="text-muted small">

                                You currently don't have
                                a trainer connected.

                            </p>


                            <a
                                href="request-trainer.php"
                                class="btn btn-primary btn-sm"
                            >
                                Request Trainer
                            </a>

                        </div>


                    <?php elseif (
                        $trainer["status"] === "approved"
                    ): ?>


                        <div>

                            <span
                                class="badge
                                       bg-success
                                       mb-3"
                            >
                                Verified Trainer
                            </span>


                            <h6 class="fw-bold">

                                <?php

                                echo htmlspecialchars(
                                    $trainer["trainer_username"]
                                );

                                ?>

                            </h6>


                            <p
                                class="text-muted
                                      small
                                      mb-3"
                            >

                                <?php

                                echo htmlspecialchars(
                                    $trainer["trainer_email"]
                                );

                                ?>

                            </p>


                            <a
                                href="request-trainer.php"
                                class="btn btn-outline-success btn-sm"
                            >
                                View Trainer
                            </a>

                        </div>


                    <?php elseif (
                        $trainer["status"] === "pending"
                    ): ?>


                        <div class="text-center">

                            <span
                                class="badge
                                       bg-warning
                                       text-dark
                                       mb-3"
                            >
                                Pending
                            </span>


                            <p class="text-muted small">

                                Your trainer request is
                                waiting for approval.

                            </p>


                            <a
                                href="request-trainer.php"
                                class="btn btn-outline-primary btn-sm"
                            >
                                View Request
                            </a>

                        </div>


                    <?php elseif (
                        $trainer["status"] === "rejected"
                    ): ?>


                        <div class="text-center">

                            <span
                                class="badge
                                       bg-danger
                                       mb-3"
                            >
                                Rejected
                            </span>


                            <p class="text-muted small">

                                Your previous trainer request
                                was rejected.

                            </p>


                            <a
                                href="request-trainer.php"
                                class="btn btn-primary btn-sm"
                            >
                                Request Another Trainer
                            </a>

                        </div>


                    <?php elseif (
                        $trainer["status"] === "ended"
                    ): ?>


                        <div class="text-center">

                            <span
                                class="badge
                                       bg-secondary
                                       mb-3"
                            >
                                Ended
                            </span>


                            <p class="text-muted small">

                                Your previous trainer relationship
                                has ended.

                            </p>


                            <a
                                href="request-trainer.php"
                                class="btn btn-primary btn-sm"
                            >
                                Request New Trainer
                            </a>

                        </div>


                    <?php endif; ?>


                </div>

            </div>


        </div>


    </div>


</div>



<!-- =========================================================
     FOOTER
========================================================= -->

<footer
    class="bg-dark
           text-white
           text-center
           py-3
           mt-5"
>

    <p class="mb-0">

        © 2026 OUTLIERS |
        AI-Powered Learning Platform

    </p>

</footer>



<!-- Bootstrap JS -->

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>


</body>

</html>