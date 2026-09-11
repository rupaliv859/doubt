<?php

session_start();

require_once "../includes/db.php";


/* =========================================================
   EMPLOYEE ACCESS PROTECTION
========================================================= */

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "employee"
) {

    header("Location: ../employee-login.php");
    exit();

}

$user_id = $_SESSION["user_id"];

$message = "";
$error = "";


/* =========================================================
   GET EMPLOYEE
========================================================= */

$stmt = $conn->prepare("
    SELECT
        employee_id,
        full_name,
        department,
        designation
    FROM employees
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

$employee = $result->fetch_assoc();

$stmt->close();


if (!$employee) {

    die("Employee profile not found.");

}

$employee_id = $employee["employee_id"];


/* =========================================================
   SELECT COURSE
========================================================= */

$selected_course_id = isset($_GET["course_id"])
    ? intval($_GET["course_id"])
    : 0;


/* =========================================================
   GET APPROVED TRAINER RELATIONSHIP
========================================================= */

$approved_trainer_id = 0;
$trainer_name = "";

$stmt = $conn->prepare("
    SELECT
        te.trainer_id,
        u.username
    FROM trainer_employee te

    INNER JOIN users u
        ON te.trainer_id = u.user_id

    WHERE te.employee_id = ?
      AND te.status = 'approved'

    ORDER BY te.approved_at DESC

    LIMIT 1
");

$stmt->bind_param(
    "i",
    $employee_id
);

$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {

    $approved_trainer_id =
        (int)$row["trainer_id"];

    $trainer_name =
        $row["username"];

}

$stmt->close();


/* =========================================================
   GET COURSES
========================================================= */

$courses = [];

$stmt = $conn->prepare("
    SELECT DISTINCT

        c.course_id,
        c.course_name,
        c.description,
        c.difficulty,
        c.skill_id,

        s.skill_name,

        CASE
            WHEN ca.assignment_id IS NOT NULL
            THEN 1
            ELSE 0
        END AS is_assigned,

        COALESCE(
            (
                SELECT
                    p.progress_percentage

                FROM progress p

                INNER JOIN enrollments e2
                    ON p.enrollment_id = e2.enrollment_id

                WHERE e2.employee_id = ?
                  AND e2.course_id = c.course_id

                ORDER BY p.progress_id DESC

                LIMIT 1

            ),
            0
        ) AS progress_percentage

    FROM courses c

    LEFT JOIN skills s
        ON c.skill_id = s.skill_id

    LEFT JOIN course_assignments ca
        ON c.course_id = ca.course_id
        AND ca.employee_id = ?
        AND ca.status IN ('assigned', 'completed')

    ORDER BY
        is_assigned DESC,
        c.course_name ASC
");

$stmt->bind_param(
    "ii",
    $employee_id,
    $employee_id
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $courses[] = $row;

}

$stmt->close();


/* =========================================================
   COURSE DETAILS
========================================================= */

$selected_course = null;

if ($selected_course_id > 0) {

    $stmt = $conn->prepare("
        SELECT

            c.course_id,
            c.course_name,
            c.description,
            c.difficulty,
            c.skill_id,
            c.created_by,

            s.skill_name

        FROM courses c

        LEFT JOIN skills s
            ON c.skill_id = s.skill_id

        WHERE c.course_id = ?

        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $selected_course_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $selected_course =
        $result->fetch_assoc();

    $stmt->close();

}


/* =========================================================
   CHECK COURSE ACCESS
========================================================= */

$course_access_allowed = false;

$is_assigned_course = false;

if ($selected_course) {


    /* -----------------------------------------------------
       CHECK TRAINER ASSIGNMENT
    ----------------------------------------------------- */

    $stmt = $conn->prepare("
        SELECT assignment_id

        FROM course_assignments

        WHERE course_id = ?
          AND employee_id = ?
          AND status IN ('assigned', 'completed')

        LIMIT 1
    ");

    $stmt->bind_param(
        "ii",
        $selected_course_id,
        $employee_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->fetch_assoc()) {

        $course_access_allowed = true;

        $is_assigned_course = true;

    }

    $stmt->close();


    /* -----------------------------------------------------
       GENERAL COURSE ACCESS
    ----------------------------------------------------- */

    if (!$course_access_allowed) {

        $course_access_allowed = true;

    }

}


/* =========================================================
   ENROLL EMPLOYEE INTO SELECTED COURSE
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["enroll_course"])
) {

    $course_id =
        intval($_POST["course_id"] ?? 0);


    if ($course_id <= 0) {

        $error =
            "Invalid course selected.";

    } else {


        /* -------------------------------------------------
           VERIFY COURSE EXISTS
        ------------------------------------------------- */

        $stmt = $conn->prepare("
            SELECT course_id

            FROM courses

            WHERE course_id = ?

            LIMIT 1
        ");

        $stmt->bind_param(
            "i",
            $course_id
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $course_exists =
            $result->fetch_assoc();

        $stmt->close();


        if (!$course_exists) {

            $error =
                "Course not found.";

        } else {


            /* -------------------------------------------------
               CHECK EXISTING ENROLLMENT
            ------------------------------------------------- */

            $stmt = $conn->prepare("
                SELECT
                    enrollment_id,
                    status

                FROM enrollments

                WHERE employee_id = ?
                  AND course_id = ?

                LIMIT 1
            ");

            $stmt->bind_param(
                "ii",
                $employee_id,
                $course_id
            );

            $stmt->execute();

            $result =
                $stmt->get_result();

            $existing_enrollment =
                $result->fetch_assoc();

            $stmt->close();


            $enrollment_id = 0;


            if ($existing_enrollment) {


                $enrollment_id =
                    $existing_enrollment["enrollment_id"];


                /* ---------------------------------------------
                   REACTIVATE IF NEEDED
                --------------------------------------------- */

                if (
                    $existing_enrollment["status"]
                    !== "active"
                ) {

                    $stmt = $conn->prepare("
                        UPDATE enrollments

                        SET status = 'active'

                        WHERE enrollment_id = ?
                    ");

                    $stmt->bind_param(
                        "i",
                        $enrollment_id
                    );

                    $stmt->execute();

                    $stmt->close();

                }


            } else {


                /* ---------------------------------------------
                   CREATE ENROLLMENT
                --------------------------------------------- */

                $stmt = $conn->prepare("
                    INSERT INTO enrollments
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
                    )
                ");

                $stmt->bind_param(
                    "ii",
                    $employee_id,
                    $course_id
                );


                if ($stmt->execute()) {

                    $enrollment_id =
                        $stmt->insert_id;

                } else {

                    $error =
                        "Unable to enroll in course.";

                }

                $stmt->close();

            }


            /* -------------------------------------------------
               CREATE OR UPDATE PROGRESS
            ------------------------------------------------- */

            if ($enrollment_id > 0) {

                $stmt = $conn->prepare("
                    SELECT progress_id

                    FROM progress

                    WHERE enrollment_id = ?

                    LIMIT 1
                ");

                $stmt->bind_param(
                    "i",
                    $enrollment_id
                );

                $stmt->execute();

                $result =
                    $stmt->get_result();

                $existing_progress =
                    $result->fetch_assoc();

                $stmt->close();


                if (!$existing_progress) {

                    $stmt = $conn->prepare("
                        INSERT INTO progress
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
                        )
                    ");

                    $stmt->bind_param(
                        "i",
                        $enrollment_id
                    );

                    $stmt->execute();

                    $stmt->close();


                } else {

                    $stmt = $conn->prepare("
                        UPDATE progress

                        SET last_accessed = NOW()

                        WHERE enrollment_id = ?
                    ");

                    $stmt->bind_param(
                        "i",
                        $enrollment_id
                    );

                    $stmt->execute();

                    $stmt->close();

                }


                $message =
                    "You are enrolled in this course.";

                $selected_course_id =
                    $course_id;

            }

        }

    }

}


/* =========================================================
   GET CURRENT ENROLLMENT + PROGRESS
========================================================= */

$current_enrollment_id = 0;

$current_progress = 0;

$is_enrolled = false;


if ($selected_course_id > 0) {

    $stmt = $conn->prepare("
        SELECT

            e.enrollment_id,
            e.status,

            COALESCE(
                p.progress_percentage,
                0
            ) AS progress_percentage

        FROM enrollments e

        LEFT JOIN progress p
            ON e.enrollment_id = p.enrollment_id

        WHERE e.employee_id = ?
          AND e.course_id = ?

        LIMIT 1
    ");

    $stmt->bind_param(
        "ii",
        $employee_id,
        $selected_course_id
    );

    $stmt->execute();

    $result =
        $stmt->get_result();


    if ($row = $result->fetch_assoc()) {

        $current_enrollment_id =
            $row["enrollment_id"];

        $current_progress =
            $row["progress_percentage"];

        $is_enrolled = true;

    }

    $stmt->close();

}


/* =========================================================
   GET LEARNING MATERIALS
========================================================= */

$materials = [];


if (
    $selected_course_id > 0 &&
    $selected_course
) {


    if ($approved_trainer_id > 0) {


        $course_creator =
            (int)$selected_course["created_by"];


        $stmt = $conn->prepare("
            SELECT

                lm.material_id,
                lm.title,
                lm.file_path,
                lm.material_type,
                lm.access_type,
                lm.uploaded_at

            FROM learning_materials lm

            WHERE lm.course_id = ?

              AND
              (
                    lm.access_type = 'PUBLIC'

                    OR

                    (
                        lm.access_type = 'TRAINER_ONLY'

                        AND ? = ?
                    )
              )

            ORDER BY
                lm.uploaded_at DESC
        ");


        $stmt->bind_param(
            "iii",
            $selected_course_id,
            $approved_trainer_id,
            $course_creator
        );


    } else {


        $stmt = $conn->prepare("
            SELECT

                material_id,
                title,
                file_path,
                material_type,
                access_type,
                uploaded_at

            FROM learning_materials

            WHERE course_id = ?
              AND access_type = 'PUBLIC'

            ORDER BY
                uploaded_at DESC
        ");


        $stmt->bind_param(
            "i",
            $selected_course_id
        );

    }


    $stmt->execute();

    $result =
        $stmt->get_result();


    while ($row = $result->fetch_assoc()) {

        $materials[] = $row;

    }


    $stmt->close();

}


/* =========================================================
   GET ASSIGNMENT STATUS
========================================================= */

$assignment_status = "";


if ($selected_course_id > 0) {

    $stmt = $conn->prepare("
        SELECT status

        FROM course_assignments

        WHERE course_id = ?
          AND employee_id = ?

        ORDER BY assignment_id DESC

        LIMIT 1
    ");

    $stmt->bind_param(
        "ii",
        $selected_course_id,
        $employee_id
    );

    $stmt->execute();

    $result =
        $stmt->get_result();


    if ($row = $result->fetch_assoc()) {

        $assignment_status =
            $row["status"];

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

    <title>
        Courses - OUTLIERS
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

        .material-item {

            transition:
                background-color 0.2s ease,
                transform 0.2s ease;

        }


        .material-item:hover {

            background-color: #f8f9fa;

        }


        .material-icon {

            font-size: 28px;

            min-width: 45px;

        }


        .material-title {

            font-weight: 600;

        }


        .material-meta {

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
            class="navbar-brand fw-bold"
            href="dashboard.php"
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
                href="assigned-courses.php"
                class="btn btn-outline-light btn-sm"
            >

                Assigned Courses

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
         PAGE HEADER
    ====================================================== -->

    <div class="mb-4">


        <h2 class="fw-bold">

            Learning Courses

        </h2>


        <p class="text-muted">

            Explore courses, access learning materials
            and continue developing your skills.

        </p>


    </div>



    <!-- =====================================================
         MESSAGES
    ====================================================== -->

    <?php if ($message): ?>

        <div class="alert alert-success">

            <?php

            echo htmlspecialchars(
                $message
            );

            ?>

        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="alert alert-danger">

            <?php

            echo htmlspecialchars(
                $error
            );

            ?>

        </div>

    <?php endif; ?>



    <div class="row g-4">


        <!-- =================================================
             COURSE LIST
        ================================================== -->

        <div class="col-lg-4">


            <div class="card shadow-sm">


                <div class="card-body">


                    <div
                        class="
                            d-flex
                            justify-content-between
                            align-items-center
                            mb-3
                        "
                    >


                        <h5 class="fw-bold mb-0">

                            Available Courses

                        </h5>


                        <span class="badge bg-primary">

                            <?php

                            echo count($courses);

                            ?>

                        </span>


                    </div>



                    <?php if (count($courses) > 0): ?>


                        <div class="list-group">


                            <?php foreach ($courses as $course): ?>


                                <a
                                    href="courses.php?course_id=<?php
                                        echo (int)$course["course_id"];
                                    ?>"
                                    class="
                                        list-group-item
                                        list-group-item-action

                                        <?php

                                        if (
                                            $selected_course_id ==
                                            $course["course_id"]
                                        ) {

                                            echo "active";

                                        }

                                        ?>
                                    "
                                >


                                    <div
                                        class="
                                            d-flex
                                            justify-content-between
                                        "
                                    >


                                        <strong>

                                            <?php

                                            echo htmlspecialchars(
                                                $course["course_name"]
                                            );

                                            ?>

                                        </strong>


                                        <?php if (
                                            $course["is_assigned"]
                                        ): ?>


                                            <span
                                                class="
                                                    badge
                                                    bg-warning
                                                    text-dark
                                                "
                                            >

                                                Assigned

                                            </span>


                                        <?php endif; ?>


                                    </div>


                                    <small>

                                        <?php

                                        echo htmlspecialchars(
                                            $course["skill_name"]
                                            ?? "General Skill"
                                        );

                                        ?>

                                    </small>


                                </a>


                            <?php endforeach; ?>


                        </div>


                    <?php else: ?>


                        <div class="alert alert-info mb-0">

                            No courses are available.

                        </div>


                    <?php endif; ?>


                </div>


            </div>


        </div>



        <!-- =================================================
             COURSE DETAILS
        ================================================== -->

        <div class="col-lg-8">


            <?php if (
                $selected_course &&
                $course_access_allowed
            ): ?>


                <!-- =================================================
                     COURSE INFORMATION
                ================================================== -->

                <div
                    class="
                        card
                        shadow-sm
                        mb-4
                    "
                >


                    <div class="card-body p-4">


                        <div
                            class="
                                d-flex
                                justify-content-between
                                align-items-start
                                gap-3
                            "
                        >


                            <div>


                                <h3 class="fw-bold mb-2">

                                    <?php

                                    echo htmlspecialchars(
                                        $selected_course["course_name"]
                                    );

                                    ?>

                                </h3>


                                <p class="text-muted mb-0">

                                    Skill:

                                    <strong>

                                        <?php

                                        echo htmlspecialchars(
                                            $selected_course["skill_name"]
                                            ?? "N/A"
                                        );

                                        ?>

                                    </strong>

                                </p>


                            </div>


                            <?php if (
                                $is_assigned_course
                            ): ?>


                                <span
                                    class="
                                        badge
                                        bg-warning
                                        text-dark
                                        fs-6
                                    "
                                >

                                    Trainer Assigned

                                </span>


                            <?php else: ?>


                                <span
                                    class="
                                        badge
                                        bg-primary
                                        fs-6
                                    "
                                >

                                    General Course

                                </span>


                            <?php endif; ?>


                        </div>


                        <hr>


                        <!-- DESCRIPTION -->

                        <p>

                            <?php

                            echo nl2br(
                                htmlspecialchars(
                                    $selected_course["description"]
                                    ??
                                    "No description available."
                                )
                            );

                            ?>

                        </p>



                        <!-- COURSE INFO -->

                        <div class="row g-3 mb-4">


                            <div class="col-md-4">


                                <div
                                    class="
                                        border
                                        rounded
                                        p-3
                                    "
                                >


                                    <small class="text-muted">

                                        Difficulty

                                    </small>


                                    <div class="fw-bold">

                                        <?php

                                        echo htmlspecialchars(
                                            $selected_course["difficulty"]
                                        );

                                        ?>

                                    </div>


                                </div>


                            </div>



                            <div class="col-md-4">


                                <div
                                    class="
                                        border
                                        rounded
                                        p-3
                                    "
                                >


                                    <small class="text-muted">

                                        Enrollment

                                    </small>


                                    <div class="fw-bold">


                                        <?php if (
                                            $is_enrolled
                                        ): ?>

                                            Enrolled

                                        <?php else: ?>

                                            Not Enrolled

                                        <?php endif; ?>


                                    </div>


                                </div>


                            </div>



                            <div class="col-md-4">


                                <div
                                    class="
                                        border
                                        rounded
                                        p-3
                                    "
                                >


                                    <small class="text-muted">

                                        Assignment

                                    </small>


                                    <div class="fw-bold">


                                        <?php

                                        if (
                                            $assignment_status
                                            === "assigned"
                                        ) {

                                            echo "Assigned";

                                        } elseif (
                                            $assignment_status
                                            === "completed"
                                        ) {

                                            echo "Completed";

                                        } else {

                                            echo "General";

                                        }

                                        ?>


                                    </div>


                                </div>


                            </div>


                        </div>



                        <!-- =================================================
                             PROGRESS
                        ================================================== -->

                        <?php if ($is_enrolled): ?>


                            <div class="mb-4">


                                <div
                                    class="
                                        d-flex
                                        justify-content-between
                                        mb-2
                                    "
                                >


                                    <strong>

                                        Course Progress

                                    </strong>


                                    <span>

                                        <?php

                                        echo round(
                                            $current_progress,
                                            1
                                        );

                                        ?>%

                                    </span>


                                </div>



                                <div
                                    class="progress"
                                    style="height: 10px;"
                                >


                                    <div
                                        class="progress-bar"
                                        role="progressbar"
                                        style="
                                            width:
                                            <?php

                                            echo min(
                                                100,
                                                max(
                                                    0,
                                                    $current_progress
                                                )
                                            );

                                            ?>%;
                                        "
                                    ></div>


                                </div>


                            </div>


                        <?php endif; ?>



                        <!-- =================================================
                             ENROLL
                        ================================================== -->

                        <?php if (!$is_enrolled): ?>


                            <form method="POST">


                                <input
                                    type="hidden"
                                    name="course_id"
                                    value="<?php
                                        echo (int)$selected_course_id;
                                    ?>"
                                >


                                <button
                                    type="submit"
                                    name="enroll_course"
                                    class="btn btn-primary"
                                >

                                    Start Learning

                                </button>


                            </form>


                        <?php else: ?>


                            <button
                                type="button"
                                class="btn btn-success"
                                disabled
                            >

                                You Are Enrolled

                            </button>


                        <?php endif; ?>


                    </div>


                </div>



                <!-- =================================================
                     LEARNING MATERIALS
                ================================================== -->

                <div
                    class="
                        card
                        shadow-sm
                        mb-4
                    "
                >


                    <div class="card-body p-4">


                        <div
                            class="
                                d-flex
                                justify-content-between
                                align-items-center
                                mb-3
                            "
                        >


                            <div>


                                <h4 class="fw-bold mb-1">

                                    Learning Materials

                                </h4>


                                <p
                                    class="
                                        text-muted
                                        mb-0
                                    "
                                >

                                    Study materials provided
                                    for this course.

                                </p>


                            </div>


                            <span class="badge bg-secondary">

                                <?php

                                echo count($materials);

                                ?>

                                Materials

                            </span>


                        </div>



                        <?php if (
                            count($materials) > 0
                        ): ?>


                            <div class="list-group">


                                <?php foreach (
                                    $materials
                                    as $material
                                ): ?>


                                    <?php

                                    /*
                                    =================================================
                                    FILE PATH FIX
                                    =================================================

                                    Database contains:

                                    uploads/materials/filename.pdf

                                    Employee page is inside:

                                    SIH26101/employee/

                                    Therefore we need:

                                    ../uploads/materials/filename.pdf

                                    ltrim() prevents accidental double slashes.
                                    */

                                    $material_path =
                                        ltrim(
                                            $material["file_path"],
                                            "/\\"
                                        );

                                    $material_url =
                                        "../"
                                        . $material_path;


                                    /*
                                    Material type
                                    */

                                    $material_type =
                                        !empty(
                                            $material["material_type"]
                                        )
                                        ?
                                        strtoupper(
                                            $material["material_type"]
                                        )
                                        :
                                        "DOCUMENT";


                                    /*
                                    Access badge
                                    */

                                    if (
                                        $material["access_type"]
                                        ===
                                        "TRAINER_ONLY"
                                    ) {

                                        $access_badge =
                                            '<span class="badge bg-warning text-dark">
                                                🔒 Trainer Only
                                             </span>';

                                    } else {

                                        $access_badge =
                                            '<span class="badge bg-success">
                                                🌐 Public
                                             </span>';

                                    }

                                    ?>


                                    <!-- MATERIAL ITEM -->

                                    <div
                                        class="
                                            list-group-item
                                            material-item
                                            py-3
                                        "
                                    >


                                        <div
                                            class="
                                                d-flex
                                                justify-content-between
                                                align-items-center
                                                gap-3
                                            "
                                        >


                                            <!-- LEFT -->

                                            <div
                                                class="
                                                    d-flex
                                                    align-items-center
                                                    gap-3
                                                "
                                            >


                                                <!-- ICON -->

                                                <div
                                                    class="material-icon"
                                                >


                                                    <?php

                                                    if (
                                                        $material_type
                                                        === "PDF"
                                                    ) {

                                                        echo "📕";

                                                    } elseif (
                                                        $material_type
                                                        === "PPT"
                                                        ||
                                                        $material_type
                                                        === "PPTX"
                                                    ) {

                                                        echo "📊";

                                                    } elseif (
                                                        $material_type
                                                        === "DOC"
                                                        ||
                                                        $material_type
                                                        === "DOCX"
                                                    ) {

                                                        echo "📝";

                                                    } else {

                                                        echo "📄";

                                                    }

                                                    ?>


                                                </div>



                                                <!-- DETAILS -->

                                                <div>


                                                    <h6
                                                        class="
                                                            material-title
                                                            mb-1
                                                        "
                                                    >

                                                        <?php

                                                        echo htmlspecialchars(
                                                            $material["title"]
                                                        );

                                                        ?>

                                                    </h6>


                                                    <div
                                                        class="material-meta"
                                                    >

                                                        <?php

                                                        echo htmlspecialchars(
                                                            $material_type
                                                        );

                                                        ?>

                                                        &nbsp; • &nbsp;

                                                        <?php

                                                        echo $access_badge;

                                                        ?>

                                                    </div>


                                                </div>


                                            </div>



                                            <!-- OPEN BUTTON -->

                                            <div
                                                class="flex-shrink-0"
                                            >


                                                <a
                                                     href="material.php?material_id=<?php
                                                          echo (int)$material["material_id"];
                                                               ?>"
                                                            class="
                                                                     btn
                                                                     btn-outline-primary
                                                                     btn-sm
                                                                  "
                                                                >
                                                           Open Material
                                                </a>


                                            </div>


                                        </div>


                                    </div>


                                <?php endforeach; ?>


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

                                    No learning materials
                                    available yet.

                                </strong>


                                <br>


                                Your trainer has not uploaded
                                any accessible material for
                                this course.


                            </div>


                        <?php endif; ?>


                    </div>


                </div>



            <?php elseif ($selected_course): ?>


                <!-- =================================================
                     ACCESS RESTRICTED
                ================================================== -->

                <div class="alert alert-danger">


                    <h5 class="fw-bold">

                        Course Access Restricted

                    </h5>


                    <p class="mb-0">

                        You do not currently have access
                        to this course.

                    </p>


                </div>


            <?php else: ?>


                <!-- =================================================
                     SELECT COURSE
                ================================================== -->

                <div class="card shadow-sm">


                    <div
                        class="
                            card-body
                            p-5
                            text-center
                        "
                    >


                        <div
                            style="
                                font-size: 50px;
                            "
                        >

                            📚

                        </div>


                        <h3 class="fw-bold mt-3">

                            Select a Course

                        </h3>


                        <p
                            class="
                                text-muted
                                mb-0
                            "
                        >

                            Select a course from the list
                            to view its details and
                            learning materials.

                        </p>


                    </div>


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