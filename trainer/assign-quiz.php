<?php

session_start();

require_once "../includes/db.php";

// --------------------------------------------------
// Trainer Login Check
// --------------------------------------------------
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "trainer") {
    header("Location: ../login.php");
    exit;
}

$trainer_id = (int) $_SESSION["user_id"];

// --------------------------------------------------
// Get Assessment ID
// --------------------------------------------------
$assessment_id = isset($_GET["assessment_id"])
    ? (int) $_GET["assessment_id"]
    : (isset($_POST["assessment_id"]) ? (int) $_POST["assessment_id"] : 0);

if ($assessment_id <= 0) {
    die("Invalid assessment ID.");
}

$message = "";
$message_type = "success";

// --------------------------------------------------
// Load Assessment
// --------------------------------------------------
$stmt = $conn->prepare("
    SELECT 
        a.assessment_id,
        a.assessment_name,
        a.description,
        s.skill_name
    FROM assessments a
    LEFT JOIN skills s 
        ON a.skill_id = s.skill_id
    WHERE a.assessment_id = ?
");

$stmt->bind_param("i", $assessment_id);
$stmt->execute();

$assessment_result = $stmt->get_result();
$assessment = $assessment_result->fetch_assoc();

$stmt->close();

if (!$assessment) {
    die("Assessment not found.");
}

// --------------------------------------------------
// Load Employees Under This Trainer
// --------------------------------------------------
$stmt = $conn->prepare("
    SELECT 
        e.employee_id,
        e.full_name,
        e.employee_code,
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

$stmt->bind_param("i", $trainer_id);
$stmt->execute();

$employees_result = $stmt->get_result();

$employees = [];

while ($row = $employees_result->fetch_assoc()) {
    $employees[] = $row;
}

$stmt->close();

// --------------------------------------------------
// Process Assignment
// --------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $assign_type = $_POST["assign_type"] ?? "";

    $employee_ids = [];

    // ----------------------------------------------
    // Assign to ALL employees
    // ----------------------------------------------
    if ($assign_type === "all") {

        foreach ($employees as $employee) {
            $employee_ids[] = (int) $employee["employee_id"];
        }
    }

    // ----------------------------------------------
    // Assign to SPECIFIC employees
    // ----------------------------------------------
    elseif ($assign_type === "specific") {

        if (isset($_POST["employee_ids"]) && is_array($_POST["employee_ids"])) {

            foreach ($_POST["employee_ids"] as $employee_id) {

                $employee_id = (int) $employee_id;

                if ($employee_id > 0) {
                    $employee_ids[] = $employee_id;
                }
            }
        }
    }

    // ----------------------------------------------
    // Validate selection
    // ----------------------------------------------
    if (empty($employee_ids)) {

        $message = "Please select at least one employee.";
        $message_type = "danger";

    } else {

        $assigned_count = 0;
        $already_assigned = 0;

        // ------------------------------------------
        // Verify employee belongs to trainer
        // ------------------------------------------
        $verify_stmt = $conn->prepare("
            SELECT employee_id
            FROM trainer_employee
            WHERE trainer_id = ?
              AND employee_id = ?
              AND status = 'approved'
            LIMIT 1
        ");

        // ------------------------------------------
        // Insert assignment
        // ------------------------------------------
        $insert_stmt = $conn->prepare("
            INSERT INTO assessment_assignments
                (assessment_id, employee_id, assigned_by)
            VALUES
                (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                assigned_by = VALUES(assigned_by)
        ");

        foreach ($employee_ids as $employee_id) {

            // Verify trainer-employee relationship
            $verify_stmt->bind_param(
                "ii",
                $trainer_id,
                $employee_id
            );

            $verify_stmt->execute();

            $verify_result = $verify_stmt->get_result();

            if ($verify_result->num_rows === 0) {
                continue;
            }

            // Check whether assignment already exists
            $check_stmt = $conn->prepare("
                SELECT assignment_id
                FROM assessment_assignments
                WHERE assessment_id = ?
                  AND employee_id = ?
                LIMIT 1
            ");

            $check_stmt->bind_param(
                "ii",
                $assessment_id,
                $employee_id
            );

            $check_stmt->execute();

            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows > 0) {

                $already_assigned++;

            } else {

                $insert_stmt->bind_param(
                    "iii",
                    $assessment_id,
                    $employee_id,
                    $trainer_id
                );

                if ($insert_stmt->execute()) {
                    $assigned_count++;
                }
            }

            $check_stmt->close();
        }

        $verify_stmt->close();
        $insert_stmt->close();

        // ------------------------------------------
        // Result message
        // ------------------------------------------
        if ($assigned_count > 0 && $already_assigned > 0) {

            $message =
                $assigned_count .
                " employee(s) assigned successfully. " .
                $already_assigned .
                " employee(s) already had this quiz.";

        } elseif ($assigned_count > 0) {

            $message =
                $assigned_count .
                " employee(s) assigned successfully.";

        } elseif ($already_assigned > 0) {

            $message =
                "This quiz was already assigned to the selected employee(s).";

        } else {

            $message =
                "No employees were assigned.";

            $message_type = "warning";
        }
    }
}

// --------------------------------------------------
// Get Existing Assignments
// --------------------------------------------------
$assigned_ids = [];

$stmt = $conn->prepare("
    SELECT employee_id
    FROM assessment_assignments
    WHERE assessment_id = ?
");

$stmt->bind_param("i", $assessment_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $assigned_ids[] = (int) $row["employee_id"];
}

$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Assign Quiz - OUTLIERS</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

</head>

<body class="bg-light">

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-lg-9">

            <div class="card shadow-sm">

                <div class="card-body p-4">

                    <h2 class="mb-1">
                        Assign Quiz
                    </h2>

                    <p class="text-muted mb-4">
                        Assign this assessment to employees under your supervision.
                    </p>


                    <!-- Assessment Information -->

                    <div class="alert alert-info">

                        <h5 class="mb-2">
                            <?php echo htmlspecialchars($assessment["assessment_name"]); ?>
                        </h5>

                        <?php if (!empty($assessment["skill_name"])): ?>

                            <div>
                                <strong>Skill:</strong>
                                <?php echo htmlspecialchars($assessment["skill_name"]); ?>
                            </div>

                        <?php endif; ?>

                        <?php if (!empty($assessment["description"])): ?>

                            <div class="mt-1">
                                <?php echo htmlspecialchars($assessment["description"]); ?>
                            </div>

                        <?php endif; ?>

                    </div>


                    <!-- Message -->

                    <?php if (!empty($message)): ?>

                        <div class="alert alert-<?php echo $message_type; ?>">

                            <?php echo htmlspecialchars($message); ?>

                        </div>

                    <?php endif; ?>


                    <?php if (empty($employees)): ?>

                        <div class="alert alert-warning">

                            No approved employees are currently connected
                            to your trainer account.

                        </div>

                    <?php else: ?>


                    <!-- Assignment Form -->

                    <form method="POST">

                        <input
                            type="hidden"
                            name="assessment_id"
                            value="<?php echo $assessment_id; ?>"
                        >


                        <h5 class="mb-3">
                            Assign To
                        </h5>


                        <!-- ALL -->

                        <div class="form-check mb-3">

                            <input
                                class="form-check-input"
                                type="radio"
                                name="assign_type"
                                id="assignAll"
                                value="all"
                                checked
                            >

                            <label
                                class="form-check-label fw-semibold"
                                for="assignAll"
                            >
                                All employees under me
                            </label>

                        </div>


                        <!-- SPECIFIC -->

                        <div class="form-check mb-3">

                            <input
                                class="form-check-input"
                                type="radio"
                                name="assign_type"
                                id="assignSpecific"
                                value="specific"
                            >

                            <label
                                class="form-check-label fw-semibold"
                                for="assignSpecific"
                            >
                                Specific employees
                            </label>

                        </div>


                        <!-- Employee List -->

                        <div
                            id="employeeList"
                            class="border rounded p-3 mb-4"
                            style="display:none;"
                        >

                            <div class="mb-3">

                                <strong>
                                    Select employees:
                                </strong>

                            </div>


                            <?php foreach ($employees as $employee): ?>

                                <?php
                                $employee_id = (int) $employee["employee_id"];

                                $is_assigned =
                                    in_array(
                                        $employee_id,
                                        $assigned_ids,
                                        true
                                    );
                                ?>

                                <div class="form-check mb-2">

                                    <input
                                        class="form-check-input employee-checkbox"
                                        type="checkbox"
                                        name="employee_ids[]"
                                        value="<?php echo $employee_id; ?>"
                                        id="employee_<?php echo $employee_id; ?>"
                                    >

                                    <label
                                        class="form-check-label"
                                        for="employee_<?php echo $employee_id; ?>"
                                    >

                                        <strong>
                                            <?php echo htmlspecialchars($employee["full_name"]); ?>
                                        </strong>

                                        <?php if (!empty($employee["employee_code"])): ?>

                                            <span class="text-muted">
                                                (<?php echo htmlspecialchars($employee["employee_code"]); ?>)
                                            </span>

                                        <?php endif; ?>

                                        <br>

                                        <small class="text-muted">

                                            <?php echo htmlspecialchars($employee["email"]); ?>

                                            <?php if ($is_assigned): ?>

                                                <span class="text-success ms-2">
                                                    Already assigned
                                                </span>

                                            <?php endif; ?>

                                        </small>

                                    </label>

                                </div>

                            <?php endforeach; ?>

                        </div>


                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-success"
                            >
                                Assign Quiz
                            </button>

                            <a
                                href="preview-quiz.php?assessment_id=<?php echo $assessment_id; ?>"
                                class="btn btn-secondary"
                            >
                                Back to Preview
                            </a>

                        </div>

                    </form>


                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>


<script>

const assignAll = document.getElementById("assignAll");
const assignSpecific = document.getElementById("assignSpecific");
const employeeList = document.getElementById("employeeList");

function updateEmployeeList() {

    if (assignSpecific.checked) {

        employeeList.style.display = "block";

    } else {

        employeeList.style.display = "none";

    }

}

assignAll.addEventListener("change", updateEmployeeList);

assignSpecific.addEventListener("change", updateEmployeeList);

</script>

</body>

</html>