<?php

session_start();

require_once "../includes/db.php";


/*
|--------------------------------------------------------------------------
| CHECK TRAINER LOGIN
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["user_id"]) ||
    $_SESSION["role"] !== "trainer"
) {

    header("Location: ../login.php");
    exit();

}

$trainer_id = (int) $_SESSION["user_id"];


/*
|--------------------------------------------------------------------------
| VARIABLES
|--------------------------------------------------------------------------
*/

$message = "";
$message_type = "";

$selected_course_id = 0;


/*
|--------------------------------------------------------------------------
| GET COURSE ID
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["course_id"]) &&
    is_numeric($_GET["course_id"])
) {

    $selected_course_id = (int) $_GET["course_id"];

}


/*
|--------------------------------------------------------------------------
| DELETE MATERIAL
|--------------------------------------------------------------------------
*/

if (
    isset($_GET["delete"]) &&
    is_numeric($_GET["delete"])
) {

    $material_id = (int) $_GET["delete"];


    /*
    |--------------------------------------------------------------------------
    | FIND MATERIAL AND VERIFY COURSE OWNERSHIP
    |--------------------------------------------------------------------------
    */

    $delete_check = $conn->prepare(
        "SELECT
            lm.material_id,
            lm.file_path,
            c.course_id,
            c.created_by
        FROM learning_materials lm

        INNER JOIN courses c
            ON lm.course_id = c.course_id

        WHERE lm.material_id = ?
        AND c.created_by = ?

        LIMIT 1"
    );

    $delete_check->bind_param(
        "ii",
        $material_id,
        $trainer_id
    );

    $delete_check->execute();

    $delete_result =
        $delete_check->get_result();


    if ($delete_result->num_rows === 1) {

        $material =
            $delete_result->fetch_assoc();

        $delete_check->close();


        /*
        |--------------------------------------------------------------------------
        | DELETE DATABASE RECORD
        |--------------------------------------------------------------------------
        */

        $delete_query = $conn->prepare(
            "DELETE FROM learning_materials
             WHERE material_id = ?"
        );

        $delete_query->bind_param(
            "i",
            $material_id
        );

        $delete_query->execute();

        $delete_query->close();


        /*
        |--------------------------------------------------------------------------
        | DELETE PHYSICAL FILE
        |--------------------------------------------------------------------------
        */

        $file_path = "../" . $material["file_path"];

        if (
            file_exists($file_path) &&
            is_file($file_path)
        ) {

            unlink($file_path);

        }


        $selected_course_id =
            (int) $material["course_id"];

        $message =
            "Learning material deleted successfully.";

        $message_type =
            "success";


    } else {

        $delete_check->close();

        $message =
            "You are not authorized to delete this material.";

        $message_type =
            "danger";

    }

}


/*
|--------------------------------------------------------------------------
| HANDLE MATERIAL UPLOAD
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    /*
    |--------------------------------------------------------------------------
    | COURSE ID
    |--------------------------------------------------------------------------
    */

    if (
        !isset($_POST["course_id"]) ||
        !is_numeric($_POST["course_id"])
    ) {

        $message =
            "Invalid course selected.";

        $message_type =
            "danger";


    } else {

        $course_id =
            (int) $_POST["course_id"];

        $selected_course_id =
            $course_id;


        /*
        |--------------------------------------------------------------------------
        | MATERIAL TITLE
        |--------------------------------------------------------------------------
        */

        $title =
            trim($_POST["title"] ?? "");


        /*
        |--------------------------------------------------------------------------
        | ACCESS TYPE
        |--------------------------------------------------------------------------
        */

        $access_type =
            $_POST["access_type"] ?? "PUBLIC";


        /*
        |--------------------------------------------------------------------------
        | VALIDATE ACCESS TYPE
        |--------------------------------------------------------------------------
        */

        if (
            $access_type !== "PUBLIC" &&
            $access_type !== "TRAINER_ONLY"
        ) {

            $access_type =
                "PUBLIC";

        }


        /*
        |--------------------------------------------------------------------------
        | VERIFY COURSE OWNERSHIP
        |--------------------------------------------------------------------------
        */

        $course_check = $conn->prepare(
            "SELECT
                course_id,
                course_name
             FROM courses
             WHERE course_id = ?
             AND created_by = ?
             LIMIT 1"
        );

        $course_check->bind_param(
            "ii",
            $course_id,
            $trainer_id
        );

        $course_check->execute();

        $course_result =
            $course_check->get_result();


        if ($course_result->num_rows !== 1) {

            $course_check->close();

            $message =
                "You can only upload materials to courses created by you.";

            $message_type =
                "danger";


        } else {

            $course =
                $course_result->fetch_assoc();

            $course_check->close();


            /*
            |--------------------------------------------------------------------------
            | VALIDATE TITLE
            |--------------------------------------------------------------------------
            */

            if ($title === "") {

                $message =
                    "Please enter a material title.";

                $message_type =
                    "danger";

            }


            /*
            |--------------------------------------------------------------------------
            | CHECK FILE
            |--------------------------------------------------------------------------
            */

            elseif (
                !isset($_FILES["material"]) ||
                $_FILES["material"]["error"] !== UPLOAD_ERR_OK
            ) {

                $message =
                    "Please select a valid file.";

                $message_type =
                    "danger";

            } else {


                /*
                |--------------------------------------------------------------------------
                | FILE INFORMATION
                |--------------------------------------------------------------------------
                */

                $original_name =
                    $_FILES["material"]["name"];

                $temporary_file =
                    $_FILES["material"]["tmp_name"];

                $file_size =
                    $_FILES["material"]["size"];


                /*
                |--------------------------------------------------------------------------
                | MAX FILE SIZE = 10 MB
                |--------------------------------------------------------------------------
                */

                $max_file_size =
                    10 * 1024 * 1024;


                if ($file_size > $max_file_size) {

                    $message =
                        "File size must not exceed 10 MB.";

                    $message_type =
                        "danger";


                } else {


                    /*
                    |--------------------------------------------------------------------------
                    | ALLOWED EXTENSIONS
                    |--------------------------------------------------------------------------
                    */

                    $allowed_extensions = [

                        "pdf",
                        "ppt",
                        "pptx",
                        "doc",
                        "docx",
                        "txt"

                    ];


                    /*
                    |--------------------------------------------------------------------------
                    | GET EXTENSION
                    |--------------------------------------------------------------------------
                    */

                    $extension =
                        strtolower(
                            pathinfo(
                                $original_name,
                                PATHINFO_EXTENSION
                            )
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | CHECK EXTENSION
                    |--------------------------------------------------------------------------
                    */

                    if (
                        !in_array(
                            $extension,
                            $allowed_extensions,
                            true
                        )
                    ) {

                        $message =
                            "Invalid file type. Allowed files: PDF, PPT, PPTX, DOC, DOCX and TXT.";

                        $message_type =
                            "danger";


                    } else {


                        /*
                        |--------------------------------------------------------------------------
                        | GENERATE SAFE FILE NAME
                        |--------------------------------------------------------------------------
                        */

                        $safe_file_name =
                            bin2hex(
                                random_bytes(16)
                            )
                            . "."
                            . $extension;


                        /*
                        |--------------------------------------------------------------------------
                        | UPLOAD DIRECTORY
                        |--------------------------------------------------------------------------
                        */

                        $upload_directory =
                            "../uploads/materials/";


                        /*
                        |--------------------------------------------------------------------------
                        | CREATE DIRECTORY
                        |--------------------------------------------------------------------------
                        */

                        if (
                            !is_dir(
                                $upload_directory
                            )
                        ) {

                            mkdir(
                                $upload_directory,
                                0755,
                                true
                            );

                        }


                        /*
                        |--------------------------------------------------------------------------
                        | FINAL FILE PATH
                        |--------------------------------------------------------------------------
                        */

                        $final_file =
                            $upload_directory
                            . $safe_file_name;


                        /*
                        |--------------------------------------------------------------------------
                        | DATABASE FILE PATH
                        |--------------------------------------------------------------------------
                        */

                        $database_file_path =
                            "uploads/materials/"
                            . $safe_file_name;


                        /*
                        |--------------------------------------------------------------------------
                        | MOVE FILE
                        |--------------------------------------------------------------------------
                        */

                        if (
                            move_uploaded_file(
                                $temporary_file,
                                $final_file
                            )
                        ) {


                            /*
                            |--------------------------------------------------------------------------
                            | INSERT MATERIAL
                            |--------------------------------------------------------------------------
                            */

                            $insert_material =
                                $conn->prepare(
                                    "INSERT INTO learning_materials
                                    (
                                        course_id,
                                        title,
                                        file_path,
                                        material_type,
                                        access_type,
                                        uploaded_at
                                    )
                                    VALUES
                                    (
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        NOW()
                                    )"
                                );


                            /*
                            |--------------------------------------------------------------------------
                            | MATERIAL TYPE
                            |--------------------------------------------------------------------------
                            */

                            $material_type =
                                strtoupper(
                                    $extension
                                );


                            $insert_material->bind_param(
                                "issss",
                                $course_id,
                                $title,
                                $database_file_path,
                                $material_type,
                                $access_type
                            );


                            if (
                                $insert_material->execute()
                            ) {

                                $message =
                                    "Learning material uploaded successfully.";

                                $message_type =
                                    "success";


                            } else {


                                /*
                                |--------------------------------------------------------------------------
                                | DELETE FILE IF DATABASE INSERT FAILED
                                |--------------------------------------------------------------------------
                                */

                                if (
                                    file_exists(
                                        $final_file
                                    )
                                ) {

                                    unlink(
                                        $final_file
                                    );

                                }


                                $message =
                                    "Material could not be saved.";

                                $message_type =
                                    "danger";

                            }


                            $insert_material->close();


                        } else {

                            $message =
                                "File upload failed. Please try again.";

                            $message_type =
                                "danger";

                        }

                    }

                }

            }

        }

    }

}


/*
|--------------------------------------------------------------------------
| GET TRAINER COURSES
|--------------------------------------------------------------------------
*/

$courses_query = $conn->prepare(
    "SELECT
        course_id,
        course_name,
        description,
        difficulty
     FROM courses
     WHERE created_by = ?
     ORDER BY course_id DESC"
);

$courses_query->bind_param(
    "i",
    $trainer_id
);

$courses_query->execute();

$courses_result =
    $courses_query->get_result();


/*
|--------------------------------------------------------------------------
| GET SELECTED COURSE DETAILS
|--------------------------------------------------------------------------
*/

$selected_course = null;

if ($selected_course_id > 0) {

    $selected_course_query =
        $conn->prepare(
            "SELECT
                course_id,
                course_name,
                description,
                difficulty
             FROM courses
             WHERE course_id = ?
             AND created_by = ?
             LIMIT 1"
        );

    $selected_course_query->bind_param(
        "ii",
        $selected_course_id,
        $trainer_id
    );

    $selected_course_query->execute();

    $selected_course_result =
        $selected_course_query->get_result();


    if (
        $selected_course_result->num_rows === 1
    ) {

        $selected_course =
            $selected_course_result->fetch_assoc();

    }

    $selected_course_query->close();

}


/*
|--------------------------------------------------------------------------
| IF NO COURSE SELECTED, SELECT FIRST COURSE
|--------------------------------------------------------------------------
*/

if (
    $selected_course === null &&
    $courses_result->num_rows > 0
) {


    /*
    |--------------------------------------------------------------------------
    | RESET RESULT POINTER
    |--------------------------------------------------------------------------
    */

    $courses_result->data_seek(0);

    $first_course =
        $courses_result->fetch_assoc();

    $selected_course =
        $first_course;

    $selected_course_id =
        (int) $first_course["course_id"];


    /*
    |--------------------------------------------------------------------------
    | RESET RESULT POINTER
    |--------------------------------------------------------------------------
    */

    $courses_result->data_seek(0);

}


/*
|--------------------------------------------------------------------------
| GET MATERIALS FOR SELECTED COURSE
|--------------------------------------------------------------------------
*/

$materials_result = null;

if ($selected_course !== null) {

    $materials_query =
        $conn->prepare(
            "SELECT
                material_id,
                title,
                file_path,
                material_type,
                access_type,
                uploaded_at
             FROM learning_materials
             WHERE course_id = ?
             ORDER BY uploaded_at DESC"
        );

    $materials_query->bind_param(
        "i",
        $selected_course_id
    );

    $materials_query->execute();

    $materials_result =
        $materials_query->get_result();

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
        Manage Materials | OUTLIERS
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

<nav class="navbar navbar-dark bg-dark">

    <div class="container-fluid px-4">


        <a
            href="dashboard.php"
            class="navbar-brand fw-bold"
        >
            OUTLIERS
        </a>


        <div class="d-flex align-items-center">


            <a
                href="dashboard.php"
                class="btn btn-outline-light btn-sm me-2"
            >
                Dashboard
            </a>


            <a
                href="requests.php"
                class="btn btn-outline-light btn-sm me-2"
            >
                Employee Requests
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

<div class="container-fluid px-4 py-4">


    <!-- PAGE HEADER -->

    <div class="mb-4">

        <h2 class="fw-bold">
            Manage Learning Materials
        </h2>

        <p class="text-muted">
            Upload and manage learning materials
            for your courses.
        </p>

    </div>


    <!-- =====================================================
         MESSAGE
    ====================================================== -->

    <?php if (!empty($message)): ?>

        <div
            class="alert alert-<?php
            echo htmlspecialchars(
                $message_type
            );
            ?>"
        >

            <?php

            echo htmlspecialchars(
                $message
            );

            ?>

        </div>

    <?php endif; ?>


    <div class="row g-4">


        <!-- =================================================
             COURSE LIST
        ================================================== -->

        <div class="col-lg-4">


            <div class="card border-0 shadow-sm">

                <div class="card-body">


                    <h5 class="fw-bold mb-3">
                        My Courses
                    </h5>


                    <?php if (
                        $courses_result->num_rows > 0
                    ): ?>


                        <?php while (
                            $course =
                            $courses_result->fetch_assoc()
                        ): ?>


                            <a
                                href="materials.php?course_id=<?php
                                echo (int)
                                    $course["course_id"];
                                ?>"
                                class="text-decoration-none"
                            >


                                <div
                                    class="border rounded
                                           p-3 mb-3
                                           <?php

                                           if (
                                               $selected_course_id
                                               ==
                                               $course["course_id"]
                                           ) {

                                               echo "border-primary";

                                           }

                                           ?>"
                                >


                                    <h6 class="fw-bold mb-1">

                                        <?php

                                        echo htmlspecialchars(
                                            $course["course_name"]
                                        );

                                        ?>

                                    </h6>


                                    <small
                                        class="text-muted"
                                    >

                                        <?php

                                        echo htmlspecialchars(
                                            $course["difficulty"]
                                        );

                                        ?>

                                    </small>


                                </div>


                            </a>


                        <?php endwhile; ?>


                    <?php else: ?>


                        <div class="text-center py-4">


                            <p class="text-muted">

                                You have not created any
                                courses yet.

                            </p>


                            <a
                                href="courses.php"
                                class="btn btn-primary"
                            >
                                Create Course
                            </a>


                        </div>


                    <?php endif; ?>


                </div>

            </div>


        </div>


        <!-- =================================================
             MAIN AREA
        ================================================== -->

        <div class="col-lg-8">


            <?php if (
                $selected_course !== null
            ): ?>


                <!-- =========================================
                     SELECTED COURSE
                ========================================== -->

                <div
                    class="card border-0 shadow-sm mb-4"
                >

                    <div class="card-body">


                        <h4 class="fw-bold">

                            <?php

                            echo htmlspecialchars(
                                $selected_course["course_name"]
                            );

                            ?>

                        </h4>


                        <p class="text-muted">

                            <?php

                            echo htmlspecialchars(
                                $selected_course["description"]
                            );

                            ?>

                        </p>


                    </div>

                </div>


                <!-- =========================================
                     UPLOAD MATERIAL
                ========================================== -->

                <div
                    class="card border-0 shadow-sm mb-4"
                >

                    <div class="card-body">


                        <h5 class="fw-bold mb-3">

                            Upload Learning Material

                        </h5>


                        <form
                            method="POST"
                            enctype="multipart/form-data"
                        >


                            <!-- COURSE -->

                            <input
                                type="hidden"
                                name="course_id"
                                value="<?php
                                echo (int)
                                    $selected_course_id;
                                ?>"
                            >


                            <!-- TITLE -->

                            <div class="mb-3">

                                <label
                                    class="form-label fw-semibold"
                                >
                                    Material Title
                                </label>


                                <input
                                    type="text"
                                    name="title"
                                    class="form-control"
                                    placeholder="Example: Introduction to R Programming"
                                    required
                                >

                            </div>


                            <!-- FILE -->

                            <div class="mb-3">

                                <label
                                    class="form-label fw-semibold"
                                >
                                    Select File
                                </label>


                                <input
                                    type="file"
                                    name="material"
                                    class="form-control"
                                    accept=".pdf,.ppt,.pptx,.doc,.docx,.txt"
                                    required
                                >


                                <small
                                    class="text-muted"
                                >

                                    Allowed:
                                    PDF, PPT, PPTX,
                                    DOC, DOCX, TXT.
                                    Maximum size: 10 MB.

                                </small>

                            </div>


                            <!-- ACCESS TYPE -->

                            <div class="mb-3">

                                <label
                                    class="form-label fw-semibold"
                                >
                                    Material Access
                                </label>


                                <select
                                    name="access_type"
                                    class="form-select"
                                >


                                    <option
                                        value="PUBLIC"
                                    >

                                        Public —
                                        Available to general
                                        course learners

                                    </option>


                                    <option
                                        value="TRAINER_ONLY"
                                    >

                                        Trainer Only —
                                        Available only to employees
                                        assigned under this trainer

                                    </option>


                                </select>


                                <small
                                    class="text-muted"
                                >

                                    Use Trainer Only for
                                    private trainer-specific
                                    learning material.

                                </small>

                            </div>


                            <!-- BUTTON -->

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Upload Material
                            </button>


                        </form>


                    </div>

                </div>


                <!-- =========================================
                     EXISTING MATERIALS
                ========================================== -->

                <div
                    class="card border-0 shadow-sm"
                >

                    <div class="card-body">


                        <h5 class="fw-bold mb-3">

                            Course Materials

                        </h5>


                        <?php if (
                            $materials_result !== null &&
                            $materials_result->num_rows > 0
                        ): ?>


                            <div
                                class="table-responsive"
                            >

                                <table
                                    class="table
                                           align-middle"
                                >


                                    <thead>

                                        <tr>

                                            <th>
                                                Material
                                            </th>

                                            <th>
                                                Type
                                            </th>

                                            <th>
                                                Access
                                            </th>

                                            <th>
                                                Uploaded
                                            </th>

                                            <th>
                                                Action
                                            </th>

                                        </tr>

                                    </thead>


                                    <tbody>


                                        <?php while (
                                            $material =
                                            $materials_result
                                            ->fetch_assoc()
                                        ): ?>


                                            <tr>


                                                <!-- TITLE -->

                                                <td>

                                                    <strong>

                                                        <?php

                                                        echo htmlspecialchars(
                                                            $material["title"]
                                                        );

                                                        ?>

                                                    </strong>

                                                </td>


                                                <!-- TYPE -->

                                                <td>

                                                    <span
                                                        class="badge
                                                               bg-secondary"
                                                    >

                                                        <?php

                                                        echo htmlspecialchars(
                                                            $material["material_type"]
                                                        );

                                                        ?>

                                                    </span>

                                                </td>


                                                <!-- ACCESS -->

                                                <td>


                                                    <?php if (
                                                        $material[
                                                            "access_type"
                                                        ]
                                                        ===
                                                        "TRAINER_ONLY"
                                                    ): ?>


                                                        <span
                                                            class="badge
                                                                   bg-warning
                                                                   text-dark"
                                                        >

                                                            Trainer Only

                                                        </span>


                                                    <?php else: ?>


                                                        <span
                                                            class="badge
                                                                   bg-success"
                                                        >

                                                            Public

                                                        </span>


                                                    <?php endif; ?>


                                                </td>


                                                <!-- DATE -->

                                                <td>

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

                                                </td>


                                                <!-- ACTION -->

                                                <td>


                                                    <!-- OPEN -->

                                                    <a
                                                        href="../<?php
                                                        echo htmlspecialchars(
                                                            $material[
                                                                "file_path"
                                                            ]
                                                        );
                                                        ?>"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="btn
                                                               btn-sm
                                                               btn-outline-primary
                                                               me-1 mb-1"
                                                    >

                                                        Open

                                                    </a>


                                                    <!-- AI QUIZ -->

                                                    <a
                                                        href="ai-quiz-generator.php?material_id=<?php
                                                        echo (int)
                                                            $material[
                                                                "material_id"
                                                            ];
                                                        ?>"
                                                        class="btn
                                                               btn-sm
                                                               btn-outline-success
                                                               me-1 mb-1"
                                                    >

                                                        Generate AI Quiz

                                                    </a>


                                                    <!-- DELETE -->

                                                    <a
                                                        href="materials.php?course_id=<?php
                                                        echo (int)
                                                            $selected_course_id;
                                                        ?>&delete=<?php
                                                        echo (int)
                                                            $material[
                                                                "material_id"
                                                            ];
                                                        ?>"
                                                        class="btn
                                                               btn-sm
                                                               btn-outline-danger
                                                               mb-1"
                                                        onclick="
                                                            return confirm(
                                                                'Are you sure you want to delete this material?'
                                                            );
                                                        "
                                                    >

                                                        Delete

                                                    </a>


                                                </td>


                                            </tr>


                                        <?php endwhile; ?>


                                    </tbody>

                                </table>

                            </div>


                        <?php else: ?>


                            <div
                                class="text-center
                                       py-4"
                            >


                                <div
                                    class="display-6 mb-3"
                                >
                                    📚
                                </div>


                                <h6 class="fw-bold">

                                    No materials uploaded yet.

                                </h6>


                                <p class="text-muted mb-0">

                                    Upload your first learning
                                    material using the form above.

                                </p>


                            </div>


                        <?php endif; ?>


                    </div>

                </div>


            <?php else: ?>


                <!-- =========================================
                     NO COURSE
                ========================================== -->

                <div
                    class="card border-0 shadow-sm"
                >

                    <div
                        class="card-body
                               text-center
                               py-5"
                    >


                        <div
                            class="display-4 mb-3"
                        >
                            📚
                        </div>


                        <h4 class="fw-bold">

                            No Course Selected

                        </h4>


                        <p class="text-muted">

                            Create a course first,
                            then upload learning materials.

                        </p>


                        <a
                            href="courses.php"
                            class="btn btn-primary"
                        >

                            Manage Courses

                        </a>


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

if (
    isset($materials_query) &&
    $materials_query instanceof mysqli_stmt
) {

    $materials_query->close();

}

?>