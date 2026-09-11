<?php

session_start();

require_once "../includes/db.php";


/* =========================================
   1. CHECK TRAINER LOGIN
========================================= */

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "trainer") {
    header("Location: ../login.php");
    exit();
}


$message = "";
$message_type = "success";


/* =========================================
   2. GET COURSE ID
========================================= */

$course_id = isset($_GET["course_id"])
    ? intval($_GET["course_id"])
    : intval($_POST["course_id"] ?? 0);


if ($course_id <= 0) {
    header("Location: courses.php");
    exit();
}


/* =========================================
   3. GET COURSE
========================================= */

$stmt = $conn->prepare("
    SELECT
        c.course_id,
        c.course_name,
        c.description,
        c.difficulty,
        s.skill_name
    FROM courses c
    LEFT JOIN skills s
        ON c.skill_id = s.skill_id
    WHERE c.course_id = ?
");

$stmt->bind_param("i", $course_id);
$stmt->execute();

$course_result = $stmt->get_result();

$course = $course_result->fetch_assoc();

$stmt->close();


if (!$course) {
    die("Course not found.");
}


/* =========================================
   4. UPLOAD MATERIAL
========================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["upload_material"])) {


    $title = trim($_POST["title"] ?? "");


    if ($title === "") {

        $message = "Please enter a material title.";
        $message_type = "danger";

    } elseif (!isset($_FILES["material_file"])
        || $_FILES["material_file"]["error"] !== UPLOAD_ERR_OK) {

        $message = "Please select a file to upload.";
        $message_type = "danger";

    } else {


        $file = $_FILES["material_file"];

        $original_name = $file["name"];
        $tmp_name = $file["tmp_name"];
        $file_size = $file["size"];


        /* =================================
           FILE EXTENSION
        ================================= */

        $extension = strtolower(
            pathinfo(
                $original_name,
                PATHINFO_EXTENSION
            )
        );


        /* =================================
           ALLOWED FILE TYPES
        ================================= */

        $allowed_extensions = [
            "pdf",
            "ppt",
            "pptx",
            "doc",
            "docx",
            "txt"
        ];


        if (!in_array(
            $extension,
            $allowed_extensions
        )) {

            $message =
                "Invalid file type. Allowed files: PDF, PPT, PPTX, DOC, DOCX and TXT.";

            $message_type = "danger";

        } elseif ($file_size > 10 * 1024 * 1024) {

            $message =
                "File size must be less than 10 MB.";

            $message_type = "danger";

        } else {


            /* =================================
               CREATE UPLOAD DIRECTORY
            ================================= */

            $upload_directory =
                "../uploads/materials/";


            if (!is_dir($upload_directory)) {

                mkdir(
                    $upload_directory,
                    0777,
                    true
                );

            }


            /* =================================
               CREATE UNIQUE FILE NAME
            ================================= */

            $safe_name =
                preg_replace(
                    "/[^A-Za-z0-9._-]/",
                    "_",
                    pathinfo(
                        $original_name,
                        PATHINFO_FILENAME
                    )
                );


            $new_file_name =
                time()
                . "_"
                . uniqid()
                . "_"
                . $safe_name
                . "."
                . $extension;


            $destination =
                $upload_directory
                . $new_file_name;


            /* =================================
               MOVE FILE
            ================================= */

            if (move_uploaded_file(
                $tmp_name,
                $destination
            )) {


                /* =================================
                   MATERIAL TYPE
                ================================= */

                if ($extension === "pdf") {

                    $material_type = "PDF";

                } elseif (
                    $extension === "ppt"
                    || $extension === "pptx"
                ) {

                    $material_type = "Presentation";

                } elseif (
                    $extension === "doc"
                    || $extension === "docx"
                ) {

                    $material_type = "Document";

                } else {

                    $material_type = "Text";

                }


                /* =================================
                   DATABASE PATH
                ================================= */

                $database_path =
                    "uploads/materials/"
                    . $new_file_name;


                /* =================================
                   INSERT MATERIAL
                ================================= */

                $stmt = $conn->prepare("
                    INSERT INTO learning_materials
                    (
                        course_id,
                        title,
                        file_path,
                        material_type
                    )
                    VALUES (?, ?, ?, ?)
                ");


                $stmt->bind_param(
                    "isss",
                    $course_id,
                    $title,
                    $database_path,
                    $material_type
                );


                if ($stmt->execute()) {

                    $message =
                        "Learning material uploaded successfully.";

                    $message_type = "success";

                } else {

                    /* Delete uploaded file if DB insert fails */

                    if (file_exists($destination)) {

                        unlink($destination);

                    }

                    $message =
                        "File uploaded, but database entry failed.";

                    $message_type = "danger";

                }


                $stmt->close();

            } else {

                $message =
                    "Unable to upload the file.";

                $message_type = "danger";

            }

        }

    }

}


/* =========================================
   5. GET EXISTING MATERIALS
========================================= */

$stmt = $conn->prepare("
    SELECT
        material_id,
        title,
        file_path,
        material_type,
        uploaded_at
    FROM learning_materials
    WHERE course_id = ?
    ORDER BY uploaded_at DESC
");

$stmt->bind_param("i", $course_id);

$stmt->execute();

$materials_result = $stmt->get_result();

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
        Learning Materials - OUTLIERS
    </title>


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


        .dashboard-card {

            border: none;

            border-radius: 16px;

            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.07);

        }


        .material-card {

            border: none;

            border-radius: 15px;

            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.06);

        }


        .material-icon {

            width: 50px;

            height: 50px;

            border-radius: 12px;

            background: #e8f1ff;

            color: #0d6efd;

            display: flex;

            align-items: center;

            justify-content: center;

            font-weight: bold;

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

            <a
                href="courses.php"
                class="btn btn-outline-light btn-sm me-2"
            >

                Courses

            </a>


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
         COURSE HEADER
    ====================================== -->

    <div class="page-header mb-4">

        <h2 class="fw-bold">

            Learning Materials

        </h2>


        <p class="mb-2">

            <?php
            echo htmlspecialchars(
                $course["course_name"]
            );
            ?>

        </p>


        <span class="badge bg-light text-dark">

            <?php
            echo htmlspecialchars(
                $course["difficulty"]
            );
            ?>

        </span>


        <?php if ($course["skill_name"]): ?>

            <span class="badge bg-light text-dark">

                <?php
                echo htmlspecialchars(
                    $course["skill_name"]
                );
                ?>

            </span>

        <?php endif; ?>

    </div>


    <!-- =====================================
         MESSAGE
    ====================================== -->

    <?php if ($message !== ""): ?>

        <div class="alert alert-<?php echo $message_type; ?>">

            <?php
            echo htmlspecialchars($message);
            ?>

        </div>

    <?php endif; ?>


    <!-- =====================================
         UPLOAD MATERIAL
    ====================================== -->

    <div class="card dashboard-card mb-5">

        <div class="card-body p-4">

            <h4 class="fw-bold mb-4">

                Upload Learning Material

            </h4>


            <form
                method="POST"
                enctype="multipart/form-data"
            >


                <input
                    type="hidden"
                    name="course_id"
                    value="<?php echo $course_id; ?>"
                >


                <!-- TITLE -->

                <div class="mb-3">

                    <label
                        for="title"
                        class="form-label fw-semibold"
                    >

                        Material Title

                    </label>


                    <input
                        type="text"
                        class="form-control"
                        id="title"
                        name="title"
                        placeholder="Example: Introduction to Data Analysis"
                        required
                    >

                </div>


                <!-- FILE -->

                <div class="mb-3">

                    <label
                        for="material_file"
                        class="form-label fw-semibold"
                    >

                        Select File

                    </label>


                    <input
                        type="file"
                        class="form-control"
                        id="material_file"
                        name="material_file"
                        accept=".pdf,.ppt,.pptx,.doc,.docx,.txt"
                        required
                    >


                    <div class="form-text">

                        Maximum file size: 10 MB.
                        Supported: PDF, PPT, PPTX, DOC, DOCX, TXT.

                    </div>

                </div>


                <!-- SUBMIT -->

                <button
                    type="submit"
                    name="upload_material"
                    class="btn btn-primary"
                >

                    Upload Material

                </button>


            </form>

        </div>

    </div>


    <!-- =====================================
         EXISTING MATERIALS
    ====================================== -->

    <div class="d-flex
                justify-content-between
                align-items-center
                mb-4">

        <h3 class="fw-bold mb-0">

            Uploaded Materials

        </h3>


        <span class="badge bg-primary">

            <?php
            echo $materials_result->num_rows;
            ?>

            Materials

        </span>

    </div>


    <div class="row g-4">


        <?php if (
            $materials_result &&
            $materials_result->num_rows > 0
        ): ?>


            <?php while (
                $material =
                $materials_result->fetch_assoc()
            ): ?>


                <div class="col-md-6">


                    <div class="card material-card h-100">

                        <div class="card-body p-4">


                            <div class="d-flex
                                        align-items-start
                                        gap-3">


                                <!-- ICON -->

                                <div class="material-icon">

                                    <?php
                                    echo htmlspecialchars(
                                        substr(
                                            $material["material_type"],
                                            0,
                                            1
                                        )
                                    );
                                    ?>

                                </div>


                                <!-- DETAILS -->

                                <div class="flex-grow-1">

                                    <h5 class="fw-bold">

                                        <?php
                                        echo htmlspecialchars(
                                            $material["title"]
                                        );
                                        ?>

                                    </h5>


                                    <span
                                        class="badge bg-secondary"
                                    >

                                        <?php
                                        echo htmlspecialchars(
                                            $material[
                                                "material_type"
                                            ]
                                        );
                                        ?>

                                    </span>


                                    <p
                                        class="text-muted small mt-2"
                                    >

                                        Uploaded:

                                        <?php
                                        echo date(
                                            "d M Y",
                                            strtotime(
                                                $material[
                                                    "uploaded_at"
                                                ]
                                            )
                                        );
                                        ?>

                                    </p>


                                    <a
                                        href="../<?php
                                            echo htmlspecialchars(
                                                $material["file_path"]
                                            );
                                        ?>"
                                        target="_blank"
                                        class="btn btn-outline-primary btn-sm"
                                    >

                                        Open Material

                                    </a>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


            <?php endwhile; ?>


        <?php else: ?>


            <div class="col-12">

                <div class="alert alert-info">

                    No learning materials have been
                    uploaded for this course yet.

                </div>

            </div>


        <?php endif; ?>


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