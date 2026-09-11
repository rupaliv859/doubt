<?php
session_start();

require_once "../includes/db.php";

// Only trainer can access this page
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "trainer") {
    header("Location: ../login.php");
    exit();
}

$trainer_id = $_SESSION["user_id"];

$message = "";
$error = "";


// =========================================================
// GET COURSE ID
// =========================================================

$course_id = isset($_GET["course_id"])
    ? intval($_GET["course_id"])
    : 0;


// =========================================================
// HANDLE COURSE ASSIGNMENT
// =========================================================

if ($_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["assign_course"])) {

    $posted_course_id = intval($_POST["course_id"]);

    // Multiple employee IDs
    $employee_ids = isset($_POST["employee_ids"])
        ? $_POST["employee_ids"]
        : [];

    // Make sure it is an array
    if (!is_array($employee_ids)) {
        $employee_ids = [];
    }

    // Convert IDs to integers and remove duplicates
    $employee_ids = array_unique(
        array_map("intval", $employee_ids)
    );

    // Remove invalid IDs
    $employee_ids = array_filter(
        $employee_ids,
        function ($id) {
            return $id > 0;
        }
    );


    // ---------------------------------------------------------
    // Validate course belongs to logged-in trainer
    // ---------------------------------------------------------

    $stmt = $conn->prepare("
        SELECT
            course_id,
            course_name,
            description,
            difficulty
        FROM courses
        WHERE course_id = ?
        AND created_by = ?
    ");

    $stmt->bind_param(
        "ii",
        $posted_course_id,
        $trainer_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $selected_course = $result->fetch_assoc();

    $stmt->close();


    if (!$selected_course) {

        $error = "Invalid course selected.";

    } elseif (count($employee_ids) === 0) {

        $error = "Please select at least one employee.";

    } else {

        $assigned_count = 0;
        $already_assigned_count = 0;
        $failed_count = 0;


        // -----------------------------------------------------
        // Start transaction
        // -----------------------------------------------------

        $conn->begin_transaction();

        try {

            foreach ($employee_ids as $employee_id) {


                // -------------------------------------------------
                // Check employee is verified with this trainer
                // -------------------------------------------------

                $stmt = $conn->prepare("
                    SELECT relationship_id
                    FROM trainer_employee
                    WHERE trainer_id = ?
                    AND employee_id = ?
                    AND status = 'approved'
                ");

                $stmt->bind_param(
                    "ii",
                    $trainer_id,
                    $employee_id
                );

                $stmt->execute();

                $result = $stmt->get_result();

                $relationship = $result->fetch_assoc();

                $stmt->close();


                // Employee is not verified
                if (!$relationship) {

                    $failed_count++;
                    continue;

                }


                // -------------------------------------------------
                // Check existing assignment
                // -------------------------------------------------

                $stmt = $conn->prepare("
                    SELECT
                        assignment_id,
                        status
                    FROM course_assignments
                    WHERE course_id = ?
                    AND employee_id = ?
                    AND assigned_by = ?
                ");

                $stmt->bind_param(
                    "iii",
                    $posted_course_id,
                    $employee_id,
                    $trainer_id
                );

                $stmt->execute();

                $result = $stmt->get_result();

                $existing = $result->fetch_assoc();

                $stmt->close();


                // -------------------------------------------------
                // Already assigned
                // -------------------------------------------------

                if ($existing && $existing["status"] !== "cancelled") {

                    $already_assigned_count++;
                    continue;

                }


                // -------------------------------------------------
                // Re-activate cancelled assignment
                // -------------------------------------------------

                if ($existing && $existing["status"] === "cancelled") {

                    $stmt = $conn->prepare("
                        UPDATE course_assignments
                        SET
                            status = 'assigned',
                            assigned_at = CURRENT_TIMESTAMP
                        WHERE assignment_id = ?
                    ");

                    $stmt->bind_param(
                        "i",
                        $existing["assignment_id"]
                    );

                    if ($stmt->execute()) {

                        $assigned_count++;

                    } else {

                        $failed_count++;

                    }

                    $stmt->close();

                } else {


                    // -------------------------------------------------
                    // Create new assignment
                    // -------------------------------------------------

                    $stmt = $conn->prepare("
                        INSERT INTO course_assignments
                        (
                            course_id,
                            employee_id,
                            assigned_by,
                            status
                        )
                        VALUES
                        (?, ?, ?, 'assigned')
                    ");

                    $stmt->bind_param(
                        "iii",
                        $posted_course_id,
                        $employee_id,
                        $trainer_id
                    );

                    if ($stmt->execute()) {

                        $assigned_count++;

                    } else {

                        $failed_count++;

                    }

                    $stmt->close();

                }

            }


            // ---------------------------------------------------------
            // Commit transaction
            // ---------------------------------------------------------

            $conn->commit();


            // ---------------------------------------------------------
            // Create result message
            // ---------------------------------------------------------

            if ($assigned_count > 0) {

                $message =
                    "Course assigned successfully to "
                    . $assigned_count
                    . " employee(s).";

            }


            if ($already_assigned_count > 0) {

                if ($message !== "") {
                    $message .= " ";
                }

                $message .=
                    $already_assigned_count
                    . " employee(s) already had this course assigned.";

            }


            if ($failed_count > 0) {

                if ($message !== "") {
                    $message .= " ";
                }

                $message .=
                    $failed_count
                    . " employee(s) could not be processed.";

            }


        } catch (Exception $e) {

            $conn->rollback();

            $error = "Something went wrong while assigning the course.";

        }

    }


    // Keep selected course after POST
    $course_id = $posted_course_id;
}


// =========================================================
// GET SELECTED COURSE
// =========================================================

$course = null;

if ($course_id > 0) {

    $stmt = $conn->prepare("
        SELECT
            course_id,
            course_name,
            description,
            difficulty
        FROM courses
        WHERE course_id = ?
        AND created_by = ?
    ");

    $stmt->bind_param(
        "ii",
        $course_id,
        $trainer_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $course = $result->fetch_assoc();

    $stmt->close();
}


// =========================================================
// GET VERIFIED EMPLOYEES
// =========================================================

$employees = [];

$stmt = $conn->prepare("
    SELECT
        e.employee_id,
        e.employee_code,
        e.full_name,
        e.department,
        e.designation,
        u.email
    FROM trainer_employee te

    INNER JOIN employees e
        ON te.employee_id = e.employee_id

    INNER JOIN users u
        ON e.user_id = u.user_id

    WHERE te.trainer_id = ?
    AND te.status = 'approved'

    ORDER BY e.full_name ASC
");

$stmt->bind_param(
    "i",
    $trainer_id
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}

$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Assign Course - OUTLIERS</title>

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
                href="courses.php"
                class="btn btn-outline-light btn-sm"
            >
                My Courses
            </a>

            <a
                href="requests.php"
                class="btn btn-outline-light btn-sm"
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


<!-- =========================================================
     MAIN CONTENT
     ========================================================= -->

<div class="container py-5">


    <!-- SUCCESS MESSAGE -->

    <?php if ($message): ?>

        <div class="alert alert-success">

            <?php
            echo htmlspecialchars($message);
            ?>

        </div>

    <?php endif; ?>


    <!-- ERROR MESSAGE -->

    <?php if ($error): ?>

        <div class="alert alert-danger">

            <?php
            echo htmlspecialchars($error);
            ?>

        </div>

    <?php endif; ?>


    <?php if (!$course): ?>


        <!-- COURSE NOT FOUND -->

        <div class="alert alert-danger">

            <h5 class="fw-bold">
                Course not found
            </h5>

            <p class="mb-3">
                The selected course does not exist or does not
                belong to your trainer account.
            </p>

            <a
                href="courses.php"
                class="btn btn-primary"
            >
                Back to Courses
            </a>

        </div>


    <?php else: ?>


        <!-- =====================================================
             PAGE HEADING
             ===================================================== -->

        <div class="mb-4">

            <h2 class="fw-bold">
                Assign Course
            </h2>

            <p class="text-muted">
                Assign this course to one or multiple verified employees.
            </p>

        </div>


        <div class="row g-4">


            <!-- =================================================
                 COURSE INFORMATION
                 ================================================= -->

            <div class="col-lg-5">

                <div class="card shadow-sm h-100">

                    <div class="card-body p-4">

                        <h4 class="fw-bold mb-3">

                            <?php
                            echo htmlspecialchars(
                                $course["course_name"]
                            );
                            ?>

                        </h4>


                        <p class="text-muted">

                            <?php
                            echo htmlspecialchars(
                                $course["description"]
                            );
                            ?>

                        </p>


                        <hr>


                        <p class="mb-2">

                            <strong>
                                Difficulty:
                            </strong>

                            <?php
                            echo htmlspecialchars(
                                $course["difficulty"]
                            );
                            ?>

                        </p>


                        <p class="mb-0">

                            <strong>
                                Verified Employees:
                            </strong>

                            <?php
                            echo count($employees);
                            ?>

                        </p>

                    </div>

                </div>

            </div>


            <!-- =================================================
                 EMPLOYEE SELECTION
                 ================================================= -->

            <div class="col-lg-7">

                <div class="card shadow-sm">

                    <div class="card-body p-4">


                        <h4 class="fw-bold mb-3">
                            Select Employees
                        </h4>


                        <?php if (count($employees) > 0): ?>


                            <form method="POST">


                                <!-- COURSE ID -->

                                <input
                                    type="hidden"
                                    name="course_id"
                                    value="<?php echo $course["course_id"]; ?>"
                                >


                                <!-- =================================================
                                     SELECT ALL
                                     ================================================= -->

                                <div class="d-flex justify-content-between
                                            align-items-center mb-3">

                                    <div>

                                        <span
                                            class="fw-semibold"
                                            id="selectedCount"
                                        >
                                            0 employees selected
                                        </span>

                                    </div>


                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        id="selectAllBtn"
                                    >
                                        Select All
                                    </button>

                                </div>


                                <!-- =================================================
                                     EMPLOYEE LIST
                                     ================================================= -->

                                <div
                                    class="border rounded p-3"
                                    style="max-height: 400px; overflow-y: auto;"
                                >

                                    <?php foreach ($employees as $employee): ?>

                                        <div
                                            class="form-check border-bottom py-3"
                                        >

                                            <input
                                                class="form-check-input employee-checkbox"
                                                type="checkbox"
                                                name="employee_ids[]"
                                                value="<?php echo $employee["employee_id"]; ?>"
                                                id="employee_<?php echo $employee["employee_id"]; ?>"
                                            >


                                            <label
                                                class="form-check-label w-100"
                                                for="employee_<?php echo $employee["employee_id"]; ?>"
                                            >

                                                <strong>

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $employee["full_name"]
                                                    );
                                                    ?>

                                                </strong>


                                                <br>


                                                <small class="text-muted">

                                                    ID:
                                                    <?php
                                                    echo htmlspecialchars(
                                                        $employee["employee_code"]
                                                    );
                                                    ?>

                                                    &nbsp; | &nbsp;

                                                    Department:
                                                    <?php
                                                    echo htmlspecialchars(
                                                        $employee["department"]
                                                    );
                                                    ?>

                                                    &nbsp; | &nbsp;

                                                    Designation:
                                                    <?php
                                                    echo htmlspecialchars(
                                                        $employee["designation"]
                                                    );
                                                    ?>

                                                </small>

                                            </label>

                                        </div>

                                    <?php endforeach; ?>

                                </div>


                                <div class="form-text mt-2 mb-4">

                                    Only employees with an approved
                                    trainer relationship are shown.

                                </div>


                                <!-- =================================================
                                     ASSIGN BUTTON
                                     ================================================= -->

                                <button
                                    type="submit"
                                    name="assign_course"
                                    class="btn btn-primary w-100"
                                    id="assignButton"
                                    disabled
                                >
                                    Assign Course
                                </button>


                            </form>


                        <?php else: ?>


                            <!-- NO EMPLOYEES -->

                            <div class="alert alert-info">

                                <h6 class="fw-bold">
                                    No verified employees
                                </h6>

                                <p class="mb-0">
                                    You currently don't have any approved
                                    employee-trainer relationships.
                                </p>

                            </div>


                            <a
                                href="requests.php"
                                class="btn btn-outline-primary"
                            >
                                View Employee Requests
                            </a>


                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>


        <!-- BACK BUTTON -->

        <div class="mt-4">

            <a
                href="courses.php"
                class="btn btn-outline-secondary"
            >
                ← Back to Courses
            </a>

        </div>


    <?php endif; ?>

</div>


<!-- =========================================================
     FOOTER
     ========================================================= -->

<footer class="bg-dark text-white text-center py-3">

    <div class="container">

        <small>
            © 2026 OUTLIERS — Think Different. Learn Smarter. Go Beyond.
        </small>

    </div>

</footer>


<!-- =========================================================
     JAVASCRIPT
     ========================================================= -->

<script>

    const checkboxes =
        document.querySelectorAll(".employee-checkbox");

    const selectAllBtn =
        document.getElementById("selectAllBtn");

    const selectedCount =
        document.getElementById("selectedCount");

    const assignButton =
        document.getElementById("assignButton");


    // ---------------------------------------------------------
    // Update selected employee count
    // ---------------------------------------------------------

    function updateSelection() {

        const selected =
            document.querySelectorAll(
                ".employee-checkbox:checked"
            ).length;


        selectedCount.textContent =
            selected + " employee"
            + (selected !== 1 ? "s" : "")
            + " selected";


        // Enable/disable assign button

        if (selected > 0) {

            assignButton.disabled = false;

        } else {

            assignButton.disabled = true;

        }


        // Update Select All button text

        if (
            selected === checkboxes.length
            && checkboxes.length > 0
        ) {

            selectAllBtn.textContent =
                "Unselect All";

        } else {

            selectAllBtn.textContent =
                "Select All";

        }

    }


    // ---------------------------------------------------------
    // Individual checkbox changes
    // ---------------------------------------------------------

    checkboxes.forEach(function (checkbox) {

        checkbox.addEventListener(
            "change",
            updateSelection
        );

    });


    // ---------------------------------------------------------
    // Select All / Unselect All
    // ---------------------------------------------------------

    selectAllBtn.addEventListener(
        "click",
        function () {

            const allSelected =
                document.querySelectorAll(
                    ".employee-checkbox:checked"
                ).length === checkboxes.length;


            checkboxes.forEach(function (checkbox) {

                checkbox.checked = !allSelected;

            });


            updateSelection();

        }
    );


    // Initial count

    updateSelection();

</script>


</body>
</html>