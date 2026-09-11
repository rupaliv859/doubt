<?php

session_start();

require_once "includes/db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    if (empty($email) || empty($password)) {

        $error = "Please enter your email and password.";

    } else {

        $query = $conn->prepare(
            "SELECT user_id, username, email, password, role
             FROM users
             WHERE email = ? AND role = 'employee'
             LIMIT 1"
        );

        $query->bind_param("s", $email);
        $query->execute();

        $result = $query->get_result();

        if ($result->num_rows === 1) {

            $user = $result->fetch_assoc();

            if (password_verify($password, $user["password"])) {

                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["email"] = $user["email"];
                $_SESSION["role"] = $user["role"];

                header("Location: employee/dashboard.php");
                exit();

            } else {

                $error = "Incorrect password.";

            }

        } else {

            $error = "Employee account not found.";

        }

    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Employee Login | OUTLIERS</title>

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

            <a href="index.html" class="btn btn-outline-light me-2">
                Home
            </a>

            <a href="register.php" class="btn btn-primary">
                Register
            </a>

        </div>

    </div>

</nav>


<!-- LOGIN SECTION -->

<section class="login-section">

    <div class="container">

        <div class="row justify-content-center">

            <div class="col-md-6 col-lg-5">

                <div class="card shadow border-0">

                    <div class="card-body p-4 p-md-5">

                        <div class="text-center mb-4">

                            <h2 class="fw-bold">
                                Employee Login
                            </h2>

                            <p class="text-muted">
                                Login to your OUTLIERS account
                            </p>

                        </div>


                        <!-- ERROR MESSAGE -->

                        <?php if (!empty($error)): ?>

                            <div class="alert alert-danger">
                                <?php echo htmlspecialchars($error); ?>
                            </div>

                        <?php endif; ?>


                        <form method="POST" action="employee-login.php">

                            <!-- EMAIL -->

                            <div class="mb-3">

                                <label class="form-label">
                                    Email Address
                                </label>

                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    placeholder="Enter your email"
                                    required
                                >

                            </div>


                            <!-- PASSWORD -->

                            <div class="mb-4">

                                <label class="form-label">
                                    Password
                                </label>

                                <input
                                    type="password"
                                    name="password"
                                    class="form-control"
                                    placeholder="Enter your password"
                                    required
                                >

                            </div>


                            <!-- LOGIN BUTTON -->

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >
                                Login
                            </button>

                        </form>


                        <div class="text-center mt-4">

                            <p class="mb-2">
                                Don't have an account?
                            </p>

                            <a href="register.php">
                                Register as Employee
                            </a>

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