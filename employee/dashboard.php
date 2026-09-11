<?php

session_start();

require_once "../includes/db.php";


/*
|--------------------------------------------------------------------------
| CHECK LOGIN
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
| GET EMPLOYEE INFORMATION
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
        u.email
    FROM employees e
    INNER JOIN users u
        ON e.user_id = u.user_id
    WHERE e.user_id = ?
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
| TRAINER REQUEST / STATUS
|--------------------------------------------------------------------------
*/

$trainer_query = $conn->prepare(
    "SELECT
        te.relationship_id,
        te.status,
        te.requested_at,
        te.approved_at,
        u.user_id AS trainer_user_id,
        u.username AS trainer_username,
        u.email AS trainer_email
    FROM trainer_employee te
    INNER JOIN users u
        ON te.trainer_id = u.user_id
    WHERE te.employee_id = ?
    ORDER BY te.relationship_id DESC
    LIMIT 1"
);

$trainer_query->bind_param("i", $employee_id);

$trainer_query->execute();

$trainer_result = $trainer_query->get_result();

$trainer_data = null;


if ($trainer_result->num_rows > 0) {

    $trainer_data = $trainer_result->fetch_assoc();

}

$trainer_query->close();


/*
|--------------------------------------------------------------------------
| OVERALL COMPETENCY SCORE
|--------------------------------------------------------------------------
*/

$competency_query = $conn->prepare(
    "SELECT
        AVG(
            CASE
                WHEN rs.required_level > 0
                THEN (es.current_level / rs.required_level) * 100
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

$competency_query->bind_param("i", $employee_id);

$competency_query->execute();

$competency_result = $competency_query->get_result();

$competency_data = $competency_result->fetch_assoc();

$competency_score =
    $competency_data["competency_score"] ?? 0;

$competency_score = round($competency_score);

$competency_score = min(
    100,
    max(
        0,
        $competency_score
    )
);

$competency_query->close();


/*
|--------------------------------------------------------------------------
| SKILL GAP COUNT
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

$gap_query->bind_param("i", $employee_id);

$gap_query->execute();

$gap_result = $gap_query->get_result();

$gap_data = $gap_result->fetch_assoc();

$skill_gap_count =
    $gap_data["gap_count"] ?? 0;

$gap_query->close();


/*
|--------------------------------------------------------------------------
| RECOMMENDATION COUNT
|--------------------------------------------------------------------------
*/

$recommendation_query = $conn->prepare(
    "SELECT COUNT(*) AS recommendation_count
    FROM recommendations
    WHERE employee_id = ?"
);

$recommendation_query->bind_param(
    "i",
    $employee_id
);

$recommendation_query->execute();

$recommendation_result =
    $recommendation_query->get_result();

$recommendation_data =
    $recommendation_result->fetch_assoc();

$recommendation_count =
    $recommendation_data["recommendation_count"] ?? 0;

$recommendation_query->close();


/*
|--------------------------------------------------------------------------
| LEARNING PROGRESS
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
| SKILL GAPS DETAILS
|--------------------------------------------------------------------------
*/

$skills_query = $conn->prepare(
    "SELECT
        s.skill_name,
        s.category,
        es.current_level,
        rs.required_level,
        (rs.required_level - es.current_level) AS gap
    FROM employee_skills es
    INNER JOIN skills s
        ON es.skill_id = s.skill_id
    INNER JOIN role_skills rs
        ON es.skill_id = rs.skill_id
    INNER JOIN roles r
        ON rs.role_id = r.role_id
    INNER JOIN employees e
        ON e.employee_id = es.employee_id
    WHERE es.employee_id = ?
    AND es.current_level < rs.required_level
    AND r.role_name = e.designation
    ORDER BY gap DESC
    LIMIT 5"
);

$skills_query->bind_param(
    "i",
    $employee_id
);

$skills_query->execute();

$skills_result =
    $skills_query->get_result();


/*
|--------------------------------------------------------------------------
| RECOMMENDED COURSES
|--------------------------------------------------------------------------
*/

$courses_query = $conn->prepare(
    "SELECT
        c.course_id,
        c.course_name,
        c.description,
        c.difficulty,
        s.skill_name
    FROM recommendations r
    INNER JOIN courses c
        ON r.course_id = c.course_id
    LEFT JOIN skills s
        ON r.skill_id = s.skill_id
    WHERE r.employee_id = ?
    ORDER BY
        CASE r.priority
            WHEN 'High' THEN 1
            WHEN 'Medium' THEN 2
            WHEN 'Low' THEN 3
            ELSE 4
        END,
        r.created_at DESC
    LIMIT 5"
);

$courses_query->bind_param(
    "i",
    $employee_id
);

$courses_query->execute();

$courses_result =
    $courses_query->get_result();


/*
|--------------------------------------------------------------------------
| CURRENT LEARNING
|--------------------------------------------------------------------------
*/

$learning_query = $conn->prepare(
    "SELECT
        c.course_id,
        c.course_name,
        p.progress_percentage,
        p.last_accessed,
        e.status
    FROM progress p
    INNER JOIN enrollments e
        ON p.enrollment_id = e.enrollment_id
    INNER JOIN courses c
        ON e.course_id = c.course_id
    WHERE e.employee_id = ?
    ORDER BY p.last_accessed DESC
    LIMIT 5"
);

$learning_query->bind_param(
    "i",
    $employee_id
);

$learning_query->execute();

$learning_result =
    $learning_query->get_result();


/*
|--------------------------------------------------------------------------
| ASSIGNED QUIZZES
|--------------------------------------------------------------------------
|
| Get quizzes assigned to this employee by the trainer.
|
*/

$assigned_quizzes = [];

$quiz_query = $conn->prepare(
    "SELECT
        aa.assignment_id,
        aa.assessment_id,
        aa.assigned_at,
        aa.status,
        a.assessment_name,
        a.description,
        s.skill_name
    FROM assessment_assignments aa
    INNER JOIN assessments a
        ON aa.assessment_id = a.assessment_id
    LEFT JOIN skills s
        ON a.skill_id = s.skill_id
    WHERE aa.employee_id = ?
    ORDER BY aa.assigned_at DESC"
);

$quiz_query->bind_param(
    "i",
    $employee_id
);

$quiz_query->execute();

$quiz_result = $quiz_query->get_result();


while ($quiz_row = $quiz_result->fetch_assoc()) {

    $assigned_quizzes[] = $quiz_row;

}

$quiz_query->close();

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
        Employee Dashboard | OUTLIERS
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
     DASHBOARD
========================================================= -->

<div class="container-fluid px-4 py-4">


    <!-- =====================================================
         WELCOME
    ====================================================== -->

    <div class="mb-4">

        <h2 class="fw-bold">

            Welcome Back,

            <?php

            echo htmlspecialchars(
                $employee["full_name"]
            );

            ?>

            👋

        </h2>


        <p class="text-muted mb-0">

            Here's your personalized learning and
            competency overview.

        </p>

    </div>



    <!-- =====================================================
         EMPLOYEE INFORMATION
    ====================================================== -->

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <div class="row g-4">


                <!-- EMPLOYEE ID -->

                <div class="col-md-3">

                    <small class="text-muted">
                        Employee ID
                    </small>

                    <h6 class="fw-bold">

                        <?php

                        echo htmlspecialchars(
                            $employee["employee_code"]
                        );

                        ?>

                    </h6>

                </div>


                <!-- DEPARTMENT -->

                <div class="col-md-3">

                    <small class="text-muted">
                        Department
                    </small>

                    <h6 class="fw-bold">

                        <?php

                        echo htmlspecialchars(
                            $employee["department"]
                        );

                        ?>

                    </h6>

                </div>


                <!-- DESIGNATION -->

                <div class="col-md-3">

                    <small class="text-muted">
                        Designation
                    </small>

                    <h6 class="fw-bold">

                        <?php

                        echo htmlspecialchars(
                            $employee["designation"]
                        );

                        ?>

                    </h6>

                </div>


                <!-- QUALIFICATION -->

                <div class="col-md-3">

                    <small class="text-muted">
                        Qualification
                    </small>

                    <h6 class="fw-bold">

                        <?php

                        echo htmlspecialchars(
                            $employee["qualification"]
                        );

                        ?>

                    </h6>

                </div>

            </div>

        </div>

    </div>



    <!-- =====================================================
         MY TRAINER
    ====================================================== -->

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">


            <div
                class="d-flex
                       justify-content-between
                       align-items-center
                       mb-3"
            >

                <div>

                    <h5 class="fw-bold mb-1">
                        My Trainer
                    </h5>

                    <p class="text-muted small mb-0">
                        Manage your verified trainer relationship.
                    </p>

                </div>


                <span class="badge bg-primary">
                    Trainer
                </span>

            </div>



            <!-- =================================================
                 NO TRAINER
            ================================================== -->

            <?php if ($trainer_data === null): ?>

                <div class="row align-items-center">

                    <div class="col-lg-8">

                        <h6 class="fw-bold">
                            No trainer connected yet
                        </h6>

                        <p class="text-muted mb-3">

                            Request a registered trainer to unlock
                            trainer-specific learning, materials
                            and assessments.

                        </p>

                    </div>


                    <div class="col-lg-4 text-lg-end">

                        <a
                            href="request-trainer.php"
                            class="btn btn-primary"
                        >
                            Request Trainer
                        </a>

                    </div>

                </div>


            <!-- =================================================
                 PENDING
            ================================================== -->

            <?php elseif ($trainer_data["status"] === "pending"): ?>

                <div class="row align-items-center">

                    <div class="col-lg-8">

                        <span
                            class="badge
                                   bg-warning
                                   text-dark
                                   mb-2"
                        >
                            Pending
                        </span>


                        <h6 class="fw-bold mb-1">
                            Trainer request sent
                        </h6>


                        <p class="text-muted mb-2">

                            Your request is waiting for the
                            trainer's verification and approval.

                        </p>


                        <small class="text-muted">

                            Trainer:

                            <?php

                            echo htmlspecialchars(
                                $trainer_data["trainer_email"]
                            );

                            ?>

                        </small>

                    </div>


                    <div class="col-lg-4 text-lg-end">

                        <a
                            href="request-trainer.php"
                            class="btn btn-outline-primary"
                        >
                            View Request
                        </a>

                    </div>

                </div>


            <!-- =================================================
                 APPROVED
            ================================================== -->

            <?php elseif ($trainer_data["status"] === "approved"): ?>

                <div class="row align-items-center">

                    <div class="col-lg-8">

                        <span
                            class="badge
                                   bg-success
                                   mb-2"
                        >
                            Verified Trainer
                        </span>


                        <h6 class="fw-bold mb-1">

                            <?php

                            echo htmlspecialchars(
                                $trainer_data["trainer_username"]
                            );

                            ?>

                        </h6>


                        <p class="text-muted mb-1">

                            Trainer Email:

                            <?php

                            echo htmlspecialchars(
                                $trainer_data["trainer_email"]
                            );

                            ?>

                        </p>


                        <small class="text-success">

                            Your trainer relationship has been approved.

                        </small>

                    </div>


                    <div class="col-lg-4 text-lg-end">

                        <a
                            href="request-trainer.php"
                            class="btn btn-outline-success"
                        >
                            View Trainer
                        </a>

                    </div>

                </div>


            <!-- =================================================
                 REJECTED
            ================================================== -->

            <?php elseif ($trainer_data["status"] === "rejected"): ?>

                <div class="row align-items-center">

                    <div class="col-lg-8">

                        <span
                            class="badge
                                   bg-danger
                                   mb-2"
                        >
                            Rejected
                        </span>


                        <h6 class="fw-bold mb-1">

                            Trainer request rejected

                        </h6>


                        <p class="text-muted mb-2">

                            Your previous trainer request was rejected.
                            You may request another registered trainer.

                        </p>


                        <small class="text-muted">

                            Previous trainer:

                            <?php

                            echo htmlspecialchars(
                                $trainer_data["trainer_email"]
                            );

                            ?>

                        </small>

                    </div>


                    <div class="col-lg-4 text-lg-end">

                        <a
                            href="request-trainer.php"
                            class="btn btn-primary"
                        >
                            Request Another Trainer
                        </a>

                    </div>

                </div>


            <!-- =================================================
                 ENDED
            ================================================== -->

            <?php elseif ($trainer_data["status"] === "ended"): ?>

                <div class="row align-items-center">

                    <div class="col-lg-8">

                        <span
                            class="badge
                                   bg-secondary
                                   mb-2"
                        >
                            Ended
                        </span>


                        <h6 class="fw-bold mb-1">

                            Previous trainer relationship ended

                        </h6>


                        <p class="text-muted mb-2">

                            Your previous trainer relationship is
                            no longer active. You can request a new trainer.

                        </p>


                        <small class="text-muted">

                            Previous trainer:

                            <?php

                            echo htmlspecialchars(
                                $trainer_data["trainer_email"]
                            );

                            ?>

                        </small>

                    </div>


                    <div class="col-lg-4 text-lg-end">

                        <a
                            href="request-trainer.php"
                            class="btn btn-primary"
                        >
                            Request New Trainer
                        </a>

                    </div>

                </div>

            <?php endif; ?>


        </div>

    </div>



    <!-- =====================================================
         STATISTICS
    ====================================================== -->

    <div class="row g-4 mb-4">


        <!-- OVERALL COMPETENCY -->

        <div class="col-md-6 col-xl-3">

            <div
                class="card
                       border-0
                       shadow-sm
                       h-100"
            >

                <div class="card-body">

                    <p class="text-muted mb-1">
                        Overall Competency
                    </p>


                    <h2 class="fw-bold mb-2">

                        <?php
                        echo $competency_score;
                        ?>%

                    </h2>


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

            </div>

        </div>



        <!-- SKILL GAPS -->

        <div class="col-md-6 col-xl-3">

            <div
                class="card
                       border-0
                       shadow-sm
                       h-100"
            >

                <div class="card-body">

                    <p class="text-muted mb-1">
                        Skill Gaps
                    </p>


                    <h2 class="fw-bold">

                        <?php
                        echo $skill_gap_count;
                        ?>

                    </h2>


                    <a
                        href="skill-gaps.php"
                        class="small text-decoration-none"
                    >
                        View skill gaps →
                    </a>

                </div>

            </div>

        </div>



        <!-- RECOMMENDATIONS -->

        <div class="col-md-6 col-xl-3">

            <div
                class="card
                       border-0
                       shadow-sm
                       h-100"
            >

                <div class="card-body">

                    <p class="text-muted mb-1">
                        Recommended Courses
                    </p>


                    <h2 class="fw-bold">

                        <?php
                        echo $recommendation_count;
                        ?>

                    </h2>


                    <a
                        href="recommendations.php"
                        class="small text-decoration-none"
                    >
                        View recommendations →
                    </a>

                </div>

            </div>

        </div>



        <!-- LEARNING PROGRESS -->

        <div class="col-md-6 col-xl-3">

            <div
                class="card
                       border-0
                       shadow-sm
                       h-100"
            >

                <div class="card-body">

                    <p class="text-muted mb-1">
                        Learning Progress
                    </p>


                    <h2 class="fw-bold">

                        <?php
                        echo $average_progress;
                        ?>%

                    </h2>


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


    </div>



    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <div class="row g-4">


        <!-- =================================================
             SKILL GAPS
        ================================================== -->

        <div class="col-lg-6">

            <div
                class="card
                       border-0
                       shadow-sm
                       h-100"
            >

                <div class="card-body">


                    <div
                        class="d-flex
                               justify-content-between
                               align-items-center
                               mb-3"
                    >

                        <h5 class="fw-bold mb-0">
                            Skill Gaps
                        </h5>


                        <a
                            href="skill-gaps.php"
                            class="small text-decoration-none"
                        >
                            View All
                        </a>

                    </div>



                    <?php if ($skills_result->num_rows > 0): ?>


                        <?php while (
                            $skill =
                            $skills_result->fetch_assoc()
                        ): ?>


                            <?php

                            $required =
                                (int)$skill["required_level"];

                            $current =
                                (int)$skill["current_level"];

                            $percentage = 0;


                            if ($required > 0) {

                                $percentage =
                                    round(
                                        ($current / $required) * 100
                                    );

                            }


                            $percentage =
                                min(
                                    100,
                                    max(
                                        0,
                                        $percentage
                                    )
                                );

                            ?>


                            <div class="mb-4">


                                <div
                                    class="d-flex
                                           justify-content-between"
                                >

                                    <span class="fw-semibold">

                                        <?php

                                        echo htmlspecialchars(
                                            $skill["skill_name"]
                                        );

                                        ?>

                                    </span>


                                    <span class="text-muted">

                                        <?php
                                        echo $current;
                                        ?>

                                        /

                                        <?php
                                        echo $required;
                                        ?>

                                    </span>

                                </div>



                                <div
                                    class="progress mt-2"
                                    style="height: 8px;"
                                >

                                    <div
                                        class="progress-bar bg-warning"
                                        role="progressbar"
                                        style="
                                            width:
                                            <?php
                                            echo $percentage;
                                            ?>%;
                                        "
                                    ></div>

                                </div>



                                <small class="text-muted">

                                    <?php

                                    echo htmlspecialchars(
                                        $skill["category"]
                                    );

                                    ?>

                                </small>

                            </div>


                        <?php endwhile; ?>


                    <?php else: ?>


                        <div class="text-center py-4">

                            <h6>
                                No skill gaps found 🎉
                            </h6>


                            <p class="text-muted mb-0">

                                Your current competencies meet
                                the available requirements.

                            </p>

                        </div>


                    <?php endif; ?>


                </div>

            </div>

        </div>



        <!-- =================================================
             AI RECOMMENDATIONS
        ================================================== -->

        <div class="col-lg-6">

            <div
                class="card
                       border-0
                       shadow-sm
                       h-100"
            >

                <div class="card-body">


                    <div
                        class="d-flex
                               justify-content-between
                               align-items-center
                               mb-3"
                    >

                        <h5 class="fw-bold mb-0">
                            AI Recommendations
                        </h5>


                        <span class="badge bg-primary">
                            AI
                        </span>

                    </div>



                    <?php if ($courses_result->num_rows > 0): ?>


                        <?php while (
                            $course =
                            $courses_result->fetch_assoc()
                        ): ?>


                            <div
                                class="border
                                       rounded
                                       p-3
                                       mb-3"
                            >


                                <div
                                    class="d-flex
                                           justify-content-between
                                           align-items-start
                                           gap-2"
                                >

                                    <h6 class="fw-bold mb-1">

                                        <?php

                                        echo htmlspecialchars(
                                            $course["course_name"]
                                        );

                                        ?>

                                    </h6>


                                    <span
                                        class="badge bg-primary"
                                    >
                                        AI
                                    </span>

                                </div>



                                <?php if (
                                    !empty(
                                        $course["skill_name"]
                                    )
                                ): ?>


                                    <span
                                        class="badge
                                               bg-light
                                               text-dark
                                               mb-2"
                                    >

                                        Skill:

                                        <?php

                                        echo htmlspecialchars(
                                            $course["skill_name"]
                                        );

                                        ?>

                                    </span>


                                <?php endif; ?>



                                <p class="text-muted small mb-2">

                                    <?php

                                    echo htmlspecialchars(
                                        $course["description"]
                                    );

                                    ?>

                                </p>



                                <span class="badge bg-secondary">

                                    <?php

                                    echo htmlspecialchars(
                                        $course["difficulty"]
                                    );

                                    ?>

                                </span>


                                <a
                                    href="courses.php?course_id=<?php echo (int)$course["course_id"]; ?>"
                                    class="btn btn-sm btn-outline-primary ms-2"
                                >
                                    View Course
                                </a>


                            </div>


                        <?php endwhile; ?>


                    <?php else: ?>


                        <div class="text-center py-4">

                            <h6>
                                No recommendations yet
                            </h6>


                            <p class="text-muted mb-0">

                                Complete your competency assessment
                                to receive personalized recommendations.

                            </p>

                        </div>


                    <?php endif; ?>


                </div>

            </div>

        </div>


    </div>



    <!-- =====================================================
         ASSIGNED QUIZZES
    ====================================================== -->

    <div class="card border-0 shadow-sm mt-4">

        <div class="card-body">


            <div
                class="d-flex
                       justify-content-between
                       align-items-center
                       mb-3"
            >

                <div>

                    <h5 class="fw-bold mb-1">
                        Assigned Quizzes
                    </h5>

                    <p class="text-muted small mb-0">
                        Quizzes assigned to you by your trainer.
                    </p>

                </div>


                <span class="badge bg-primary">

                    <?php
                    echo count($assigned_quizzes);
                    ?>

                    Quiz<?php echo count($assigned_quizzes) == 1 ? "" : "zes"; ?>

                </span>

            </div>



            <?php if (empty($assigned_quizzes)): ?>


                <div class="text-center py-4">

                    <div class="mb-2">

                        <span
                            class="badge
                                   bg-light
                                   text-dark
                                   border
                                   p-2"
                        >
                            No quizzes
                        </span>

                    </div>


                    <h6 class="fw-bold">
                        No quizzes assigned yet
                    </h6>


                    <p class="text-muted mb-0">

                        Your trainer has not assigned any quizzes
                        to you yet.

                    </p>

                </div>


            <?php else: ?>


                <div class="row g-3">


                    <?php foreach (
                        $assigned_quizzes
                        as $quiz
                    ): ?>


                        <div class="col-md-6 col-lg-4">


                            <div
                                class="card
                                       h-100
                                       border"
                            >

                                <div class="card-body">


                                    <h5 class="card-title fw-bold">

                                        <?php

                                        echo htmlspecialchars(
                                            $quiz["assessment_name"]
                                        );

                                        ?>

                                    </h5>



                                    <?php if (
                                        !empty(
                                            $quiz["skill_name"]
                                        )
                                    ): ?>


                                        <span
                                            class="badge
                                                   bg-info
                                                   text-dark
                                                   mb-2"
                                        >

                                            Skill:

                                            <?php

                                            echo htmlspecialchars(
                                                $quiz["skill_name"]
                                            );

                                            ?>

                                        </span>


                                    <?php endif; ?>



                                    <?php if (
                                        !empty(
                                            $quiz["description"]
                                        )
                                    ): ?>


                                        <p
                                            class="card-text
                                                   text-muted
                                                   small"
                                        >

                                            <?php

                                            echo htmlspecialchars(
                                                $quiz["description"]
                                            );

                                            ?>

                                        </p>


                                    <?php endif; ?>



                                    <div class="mb-3">


                                        <?php if (
                                            $quiz["status"] === "completed"
                                        ): ?>


                                            <span
                                                class="badge bg-success"
                                            >
                                                Completed
                                            </span>


                                        <?php else: ?>


                                            <span
                                                class="badge
                                                       bg-warning
                                                       text-dark"
                                            >
                                                Assigned
                                            </span>


                                        <?php endif; ?>


                                    </div>



                                    <?php if (
                                        $quiz["status"] === "completed"
                                    ): ?>


                                        <a
                                            href="assessment.php?assessment_id=<?php echo (int)$quiz["assessment_id"]; ?>"
                                            class="btn btn-outline-success w-100"
                                        >
                                            View Quiz
                                        </a>


                                    <?php else: ?>


                                        <a
                                            href="assessment.php?assessment_id=<?php echo (int)$quiz["assessment_id"]; ?>"
                                            class="btn btn-primary w-100"
                                        >
                                            Take Quiz
                                        </a>


                                    <?php endif; ?>


                                </div>

                            </div>

                        </div>


                    <?php endforeach; ?>


                </div>


            <?php endif; ?>


        </div>

    </div>



    <!-- =====================================================
         CURRENT LEARNING
    ====================================================== -->

    <div
        class="card
               border-0
               shadow-sm
               mt-4"
    >

        <div class="card-body">


            <div
                class="d-flex
                       justify-content-between
                       align-items-center
                       mb-3"
            >

                <h5 class="fw-bold mb-0">
                    Current Learning
                </h5>


                <a
                    href="courses.php"
                    class="small text-decoration-none"
                >
                    View Courses
                </a>

            </div>



            <?php if ($learning_result->num_rows > 0): ?>


                <?php while (
                    $learning =
                    $learning_result->fetch_assoc()
                ): ?>


                    <?php

                    $progress_value =
                        round(
                            $learning["progress_percentage"]
                        );

                    $progress_value =
                        min(
                            100,
                            max(
                                0,
                                $progress_value
                            )
                        );

                    ?>


                    <div class="mb-4">


                        <div
                            class="d-flex
                                   justify-content-between
                                   align-items-center
                                   mb-2"
                        >

                            <a
                                href="courses.php?course_id=<?php echo (int)$learning["course_id"]; ?>"
                                class="fw-semibold text-decoration-none"
                            >

                                <?php

                                echo htmlspecialchars(
                                    $learning["course_name"]
                                );

                                ?>

                            </a>


                            <span class="text-muted">

                                <?php
                                echo $progress_value;
                                ?>%

                            </span>

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
                                    echo $progress_value;
                                    ?>%;
                                "
                            ></div>

                        </div>


                    </div>


                <?php endwhile; ?>


            <?php else: ?>


                <div class="text-center py-4">

                    <p class="text-muted mb-0">

                        You are not enrolled in any courses yet.

                    </p>


                    <a
                        href="courses.php"
                        class="btn btn-primary btn-sm mt-3"
                    >
                        Browse Courses
                    </a>

                </div>


            <?php endif; ?>


        </div>

    </div>



    <!-- =====================================================
         QUICK ACTIONS
    ====================================================== -->

    <div class="mt-4">


        <h5 class="fw-bold mb-3">
            Quick Actions
        </h5>


        <div class="row g-3">


            <!-- =================================================
                 MY PROFILE
            ================================================== -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="profile.php"
                    class="btn btn-outline-primary w-100"
                >
                    My Profile
                </a>

            </div>



            <!-- =================================================
                 ASSESSMENT
            ================================================== -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="assessment.php"
                    class="btn btn-outline-primary w-100"
                >
                    Take Assessment
                </a>

            </div>



            <!-- =================================================
                 BROWSE COURSES
            ================================================== -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="courses.php"
                    class="btn btn-outline-primary w-100"
                >
                    Learning material
                </a>

            </div>



            <!-- =================================================
                 MY TRAINER
            ================================================== -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="request-trainer.php"
                    class="btn btn-outline-success w-100"
                >

                    <?php

                    if (
                        $trainer_data !== null &&
                        $trainer_data["status"] === "approved"
                    ) {

                        echo "My Trainer";

                    } elseif (
                        $trainer_data !== null &&
                        $trainer_data["status"] === "pending"
                    ) {

                        echo "Trainer Request Pending";

                    } elseif (
                        $trainer_data !== null &&
                        $trainer_data["status"] === "rejected"
                    ) {

                        echo "Request Another Trainer";

                    } elseif (
                        $trainer_data !== null &&
                        $trainer_data["status"] === "ended"
                    ) {

                        echo "Request New Trainer";

                    } else {

                        echo "Request Trainer";

                    }

                    ?>

                </a>

            </div>



            <!-- =================================================
                 TRAINER ASSIGNED COURSES
            ================================================== -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="assigned-courses.php"
                    class="btn btn-outline-primary w-100"
                >
                    Trainer Assigned Courses
                </a>

            </div>



            <!-- =================================================
                 AI SKILL ANALYSIS
            ================================================== -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="ai-skill-analysis.php"
                    class="btn btn-outline-info w-100"
                >
                    AI Skill Analysis
                </a>

            </div>



            <!-- =================================================
                 AI ASSISTANT
            ================================================== -->

            <div class="col-md-6 col-lg-4">

                <a
                    href="assistant.php"
                    class="btn btn-primary w-100"
                >
                    AI Learning Assistant
                </a>

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

        © 2026 OUTLIERS | AI-Powered Learning Platform

    </p>

</footer>


</body>

</html>