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

$user_id = (int) $_SESSION["user_id"];


/* =========================================================
   GET EMPLOYEE
========================================================= */

$stmt = $conn->prepare("
    SELECT
        employee_id,
        full_name
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

$employee_id = (int) $employee["employee_id"];


/* =========================================================
   GET MATERIAL ID
========================================================= */

$material_id = isset($_GET["material_id"])
    ? (int) $_GET["material_id"]
    : 0;


if ($material_id <= 0) {
    die("Invalid learning material.");
}


/* =========================================================
   GET MATERIAL + COURSE
========================================================= */

$stmt = $conn->prepare("
    SELECT
        lm.material_id,
        lm.title,
        lm.file_path,
        lm.material_type,
        lm.access_type,
        lm.uploaded_at,

        c.course_id,
        c.course_name,
        c.description

    FROM learning_materials lm

    INNER JOIN courses c
        ON lm.course_id = c.course_id

    WHERE lm.material_id = ?

    LIMIT 1
");

$stmt->bind_param(
    "i",
    $material_id
);

$stmt->execute();

$result = $stmt->get_result();

$material = $result->fetch_assoc();

$stmt->close();


if (!$material) {
    die("Learning material not found.");
}


$course_id = (int) $material["course_id"];


/* =========================================================
   CHECK EMPLOYEE ENROLLMENT
========================================================= */

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

$result = $stmt->get_result();

$enrollment = $result->fetch_assoc();

$stmt->close();


if (!$enrollment) {

    die("
        <div style='
            font-family: Arial;
            padding: 40px;
            text-align: center;
        '>

            <h2>Course Access Required</h2>

            <p>
                Please enroll in this course before
                accessing its learning materials.
            </p>

            <a href='courses.php?course_id={$course_id}'>
                Back to Course
            </a>

        </div>
    ");

}


$enrollment_id =
    (int) $enrollment["enrollment_id"];


/* =========================================================
   RECORD MATERIAL AS OPENED
========================================================= */

/*
   Because material_progress has:

   UNIQUE(employee_id, material_id)

   the same material will never be counted twice.
*/

$stmt = $conn->prepare("
    INSERT IGNORE INTO material_progress
    (
        material_id,
        employee_id,
        opened_at
    )

    VALUES
    (
        ?,
        ?,
        NOW()
    )
");

$stmt->bind_param(
    "ii",
    $material_id,
    $employee_id
);

$stmt->execute();

$stmt->close();


/* =========================================================
   COUNT TOTAL COURSE MATERIALS
========================================================= */

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_materials

    FROM learning_materials

    WHERE course_id = ?
");

$stmt->bind_param(
    "i",
    $course_id
);

$stmt->execute();

$result = $stmt->get_result();

$row = $result->fetch_assoc();

$total_materials =
    (int) $row["total_materials"];

$stmt->close();


/* =========================================================
   COUNT OPENED COURSE MATERIALS
========================================================= */

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS opened_materials

    FROM material_progress mp

    INNER JOIN learning_materials lm
        ON mp.material_id = lm.material_id

    WHERE mp.employee_id = ?
      AND lm.course_id = ?
");

$stmt->bind_param(
    "ii",
    $employee_id,
    $course_id
);

$stmt->execute();

$result = $stmt->get_result();

$row = $result->fetch_assoc();

$opened_materials =
    (int) $row["opened_materials"];

$stmt->close();


/* =========================================================
   CALCULATE PROGRESS
========================================================= */

if ($total_materials > 0) {

    $current_progress =
        ($opened_materials / $total_materials) * 100;

} else {

    $current_progress = 0;

}


/*
   Keep percentage between 0 and 100.
*/

$current_progress =
    min(
        100,
        max(
            0,
            $current_progress
        )
    );


$current_progress =
    round(
        $current_progress,
        1
    );


/* =========================================================
   UPDATE COURSE PROGRESS
========================================================= */

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

$result = $stmt->get_result();

$existing_progress =
    $result->fetch_assoc();

$stmt->close();


if ($existing_progress) {

    $stmt = $conn->prepare("
        UPDATE progress

        SET
            progress_percentage = ?,
            last_accessed = NOW(),
            completed_at =
                CASE
                    WHEN ? >= 100
                    THEN NOW()
                    ELSE completed_at
                END

        WHERE enrollment_id = ?
    ");

    $stmt->bind_param(
        "ddi",
        $current_progress,
        $current_progress,
        $enrollment_id
    );

    $stmt->execute();

    $stmt->close();

} else {

    $completed_at =
        ($current_progress >= 100)
        ? date("Y-m-d H:i:s")
        : null;


    $stmt = $conn->prepare("
        INSERT INTO progress
        (
            enrollment_id,
            progress_percentage,
            last_accessed,
            completed_at
        )

        VALUES
        (
            ?,
            ?,
            NOW(),
            ?
        )
    ");

    $stmt->bind_param(
        "ids",
        $enrollment_id,
        $current_progress,
        $completed_at
    );

    $stmt->execute();

    $stmt->close();

}


/* =========================================================
   MARK ENROLLMENT COMPLETED
========================================================= */

if ($current_progress >= 100) {

    $stmt = $conn->prepare("
        UPDATE enrollments

        SET status = 'completed'

        WHERE enrollment_id = ?
    ");

    $stmt->bind_param(
        "i",
        $enrollment_id
    );

    $stmt->execute();

    $stmt->close();

}


/* =========================================================
   MATERIAL FILE PATH
========================================================= */

$material_path =
    ltrim(
        $material["file_path"],
        "/\\"
    );

$material_url =
    "../" . $material_path;


/* =========================================================
   MATERIAL TYPE
========================================================= */

$material_type =
    !empty($material["material_type"])
    ?
    strtoupper(
        $material["material_type"]
    )
    :
    "DOCUMENT";


/* =========================================================
   MATERIAL ICON
========================================================= */

if ($material_type === "PDF") {

    $material_icon = "📕";

} elseif (
    $material_type === "PPT" ||
    $material_type === "PPTX"
) {

    $material_icon = "📊";

} elseif (
    $material_type === "DOC" ||
    $material_type === "DOCX"
) {

    $material_icon = "📝";

} else {

    $material_icon = "📄";

}


/* =========================================================
   PROGRESS STATUS
========================================================= */

if ($current_progress >= 100) {

    $progress_status = "Completed";
    $progress_class = "bg-success";

} elseif ($current_progress > 0) {

    $progress_status = "In Progress";
    $progress_class = "bg-primary";

} else {

    $progress_status = "Not Started";
    $progress_class = "bg-secondary";

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

        <?php

        echo htmlspecialchars(
            $material["title"]
        );

        ?>

        - OUTLIERS

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
                href="courses.php?course_id=<?php
                    echo $course_id;
                ?>"
                class="btn btn-outline-light btn-sm"
            >

                Back to Course

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


    <!-- COURSE HEADER -->

    <div class="mb-4">

        <h2 class="fw-bold">

            <?php

            echo htmlspecialchars(
                $material["course_name"]
            );

            ?>

        </h2>


        <p class="text-muted mb-0">

            Learning Material

        </p>

    </div>



    <!-- MATERIAL CARD -->

    <div class="card shadow-sm mb-4">

        <div class="card-body p-4">


            <div
                class="
                    d-flex
                    justify-content-between
                    align-items-center
                    gap-3
                "
            >


                <!-- MATERIAL INFO -->

                <div
                    class="
                        d-flex
                        align-items-center
                        gap-3
                    "
                >


                    <div
                        style="
                            font-size: 45px;
                        "
                    >

                        <?php

                        echo $material_icon;

                        ?>

                    </div>


                    <div>

                        <h4 class="fw-bold mb-1">

                            <?php

                            echo htmlspecialchars(
                                $material["title"]
                            );

                            ?>

                        </h4>


                        <p class="text-muted mb-0">

                            Type:

                            <strong>

                                <?php

                                echo htmlspecialchars(
                                    $material_type
                                );

                                ?>

                            </strong>

                        </p>

                    </div>

                </div>


                <!-- STATUS -->

                <div class="text-end">

                    <span
                        class="
                            badge
                            <?php
                            echo $progress_class;
                            ?>
                            fs-6
                        "
                    >

                        <?php

                        echo $progress_status;

                        ?>

                    </span>

                </div>


            </div>


        </div>

    </div>



    <!-- PROGRESS -->

    <div class="card shadow-sm mb-4">

        <div class="card-body p-4">


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


                <strong>

                    <?php

                    echo $current_progress;

                    ?>%

                </strong>

            </div>


            <div
                class="progress"
                style="height: 12px;"
            >

                <div
                    class="progress-bar
                        <?php
                        echo $progress_class;
                        ?>"
                    role="progressbar"
                    style="
                        width:
                        <?php
                        echo $current_progress;
                        ?>%;
                    "
                ></div>

            </div>


            <div class="mt-2">

                <small class="text-muted">

                    <?php

                    echo $opened_materials;

                    ?>

                    of

                    <?php

                    echo $total_materials;

                    ?>

                    learning materials opened.

                </small>

            </div>


        </div>

    </div>



    <!-- OPEN MATERIAL -->

    <div class="card shadow-sm">

        <div class="card-body p-4">


            <h5 class="fw-bold mb-3">

                Open Learning Material

            </h5>


            <p class="text-muted">

                Your access has been verified and this
                material has been recorded as opened.

            </p>


            <a
                href="<?php

                    echo htmlspecialchars(
                        $material_url
                    );

                ?>"
                target="_blank"
                rel="noopener noreferrer"
                class="
                    btn
                    btn-primary
                    btn-lg
                "
            >

                <?php

                echo $material_icon;

                ?>

                Open Material

            </a>


            <?php if ($current_progress >= 100): ?>


                <div
                    class="
                        alert
                        alert-success
                        mt-4
                        mb-0
                    "
                >

                    <strong>

                        🎉 Course Completed!

                    </strong>

                    <br>

                    You have opened all available learning
                    materials for this course.

                </div>


            <?php else: ?>


                <div
                    class="
                        alert
                        alert-info
                        mt-4
                        mb-0
                    "
                >

                    Your progress is automatically updated
                    when you open different learning
                    materials.

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