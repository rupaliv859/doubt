<?php

require_once "includes/db.php";

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = trim($_POST["full_name"]);
    $employee_code = trim($_POST["employee_code"]);
    $email = trim($_POST["email"]);
    $department = trim($_POST["department"]);
    $designation = trim($_POST["designation"]);
    $qualification = trim($_POST["qualification"]);
    $experience = trim($_POST["experience"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];

    // Check required fields
    if (
        empty($full_name) ||
        empty($employee_code) ||
        empty($email) ||
        empty($department) ||
        empty($designation) ||
        empty($password)
    ) {
        $message = "Please fill in all required fields.";
        $message_type = "danger";
    }

    // Check email
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $message_type = "danger";
    }

    // Check password
    elseif ($password !== $confirm_password) {
        $message = "Passwords do not match.";
        $message_type = "danger";
    }

    elseif (strlen($password) < 6) {
        $message = "Password must contain at least 6 characters.";
        $message_type = "danger";
    }

    else {

        // Check whether email already exists
        $check_email = $conn->prepare(
            "SELECT user_id FROM users WHERE email = ?"
        );

        $check_email->bind_param("s", $email);
        $check_email->execute();
        $email_result = $check_email->get_result();

        // Check whether employee code already exists
        $check_code = $conn->prepare(
            "SELECT employee_id FROM employees WHERE employee_code = ?"
        );

        $check_code->bind_param("s", $employee_code);
        $check_code->execute();
        $code_result = $check_code->get_result();

        if ($email_result->num_rows > 0) {

            $message = "An account with this email already exists.";
            $message_type = "danger";

        } elseif ($code_result->num_rows > 0) {

            $message = "This employee ID already exists.";
            $message_type = "danger";

        } else {

            // Hash password
            $hashed_password = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            // Username
            $username = $employee_code;

            // Employee role is automatically assigned
            $role = "employee";

            // Start transaction
            $conn->begin_transaction();

            try {

                // Insert into users
                $user_query = $conn->prepare(
                    "INSERT INTO users
                    (username, email, password, role)
                    VALUES (?, ?, ?, ?)"
                );

                $user_query->bind_param(
                    "ssss",
                    $username,
                    $email,
                    $hashed_password,
                    $role
                );

                $user_query->execute();

                // Get newly created user ID
                $user_id = $conn->insert_id;

                // Insert into employees
                $employee_query = $conn->prepare(
                    "INSERT INTO employees
                    (
                        user_id,
                        employee_code,
                        full_name,
                        department,
                        designation,
                        qualification,
                        experience_years
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?)"
                );

                $employee_query->bind_param(
                    "isssssd",
                    $user_id,
                    $employee_code,
                    $full_name,
                    $department,
                    $designation,
                    $qualification,
                    $experience
                );

                $employee_query->execute();

                // Commit transaction
                $conn->commit();

                $message = "Registration successful! You can now login.";
                $message_type = "success";

                // Clear form values
                $full_name = "";
                $employee_code = "";
                $email = "";
                $department = "";
                $designation = "";
                $qualification = "";
                $experience = "";

            } catch (Exception $e) {

                // Undo changes if something fails
                $conn->rollback();

                $message = "Registration failed. Please try again.";
                $message_type = "danger";
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Employee Registration | OUTLIERS</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="css/style.css">

</head>

<body>

<!-- NAVBAR -->

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">

    <div class="container">

        <a class="navbar-brand fw-bold" href="index.php">
            OUTLIERS
        </a>

        <div class="ms-auto">

            <a href="index.php" class="btn btn-outline-light me-2">
                Home
            </a>

            <a href="employee-login.php" class="btn btn-primary">
                Employee Login
            </a>

        </div>

    </div>

</nav>


<!-- REGISTRATION -->

<section class="login-section py-5">

    <div class="container">

        <div class="row justify-content-center">

            <div class="col-lg-7 col-md-9">

                <div class="card shadow border-0">

                    <div class="card-body p-4 p-md-5">

                        <div class="text-center mb-4">

                            <h2 class="fw-bold">
                                Employee Registration
                            </h2>

                            <p class="text-muted">
                                Create your OUTLIERS employee account
                            </p>

                        </div>


                        <!-- MESSAGE -->

                        <?php if (!empty($message)): ?>

                            <div class="alert alert-<?php echo $message_type; ?>">
                                <?php echo htmlspecialchars($message); ?>
                            </div>

                        <?php endif; ?>


                        <form method="POST" action="register.php">


                            <!-- FULL NAME -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Full Name *
                                </label>

                                <input
                                    type="text"
                                    name="full_name"
                                    class="form-control"
                                    placeholder="Enter your full name"
                                    value="<?php echo htmlspecialchars($full_name ?? ''); ?>"
                                    required
                                >

                            </div>


                            <!-- EMPLOYEE ID -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Employee ID *
                                </label>

                                <input
                                    type="text"
                                    name="employee_code"
                                    class="form-control"
                                    placeholder="Enter employee ID"
                                    value="<?php echo htmlspecialchars($employee_code ?? ''); ?>"
                                    required
                                >

                            </div>


                            <!-- EMAIL -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Email *
                                </label>

                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    placeholder="Enter email address"
                                    value="<?php echo htmlspecialchars($email ?? ''); ?>"
                                    required
                                >

                            </div>


                            <!-- DEPARTMENT -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Department *
                                </label>

                                <select
                                    name="department"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Department
                                    </option>

                                    <option value="Statistics">
                                        Statistics
                                    </option>

                                    <option value="Data Science">
                                        Data Science
                                    </option>

                                    <option value="Information Technology">
                                        Information Technology
                                    </option>

                                    <option value="Administration">
                                        Administration
                                    </option>

                                    <option value="Other">
                                        Other
                                    </option>

                                </select>

                            </div>


                            <!-- DESIGNATION -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Designation *
                                </label>

                                <input
                                    type="text"
                                    name="designation"
                                    class="form-control"
                                    placeholder="Enter designation"
                                    value="<?php echo htmlspecialchars($designation ?? ''); ?>"
                                    required
                                >

                            </div>


                            <!-- QUALIFICATION -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Qualification
                                </label>

                                <input
                                    type="text"
                                    name="qualification"
                                    class="form-control"
                                    placeholder="e.g. B.Tech, M.Tech, MSc"
                                    value="<?php echo htmlspecialchars($qualification ?? ''); ?>"
                                >

                            </div>


                            <!-- EXPERIENCE -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Experience (Years)
                                </label>

                                <input
                                    type="number"
                                    name="experience"
                                    class="form-control"
                                    min="0"
                                    step="0.1"
                                    placeholder="e.g. 2"
                                    value="<?php echo htmlspecialchars($experience ?? ''); ?>"
                                >

                            </div>


                            <!-- PASSWORD -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Password *
                                </label>

                                <input
                                    type="password"
                                    name="password"
                                    class="form-control"
                                    placeholder="Create a password"
                                    required
                                >

                            </div>


                            <!-- CONFIRM PASSWORD -->

                            <div class="mb-4">

                                <label class="form-label">
                                    Confirm Password *
                                </label>

                                <input
                                    type="password"
                                    name="confirm_password"
                                    class="form-control"
                                    placeholder="Confirm your password"
                                    required
                                >

                            </div>


                            <!-- SUBMIT -->

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >
                                Create Employee Account
                            </button>


                        </form>


                        <div class="text-center mt-4">

                            <p class="mb-0">

                                Already have an account?

                                <a href="employee-login.php">
                                    Employee Login
                                </a>

                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- FOOTER -->

<footer class="bg-dark text-white text-center py-3">

    <p class="mb-0">
        © 2026 OUTLIERS | AI-Powered Learning Platform
    </p>

</footer>

</body>

</html>