<?php
session_start();

require_once "../includes/db.php";

/* Allow only logged-in employees */
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "employee") {
    header("Location: ../employee-login.php");
    exit();
}

$user_id = $_SESSION["user_id"];
$message = "";
$message_type = "";

/* Get employee details */
$stmt = $conn->prepare("
    SELECT employee_id, full_name, employee_code
    FROM employees
    WHERE user_id = ?
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


/* Handle trainer request */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $trainer_email = trim($_POST["trainer_email"] ?? "");

    if ($trainer_email === "") {

        $message = "Please enter the trainer's email address.";
        $message_type = "danger";

    } elseif (!filter_var($trainer_email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $message_type = "danger";

    } else {

        /* Find trainer using registered email */
        $stmt = $conn->prepare("
            SELECT user_id, username, email
            FROM users
            WHERE email = ?
            AND role = 'trainer'
            LIMIT 1
        ");

        $stmt->bind_param("s", $trainer_email);
        $stmt->execute();

        $result = $stmt->get_result();
        $trainer = $result->fetch_assoc();

        $stmt->close();


        if (!$trainer) {

            $message = "No registered trainer was found with this email address.";
            $message_type = "danger";

        } else {

            $trainer_id = $trainer["user_id"];


            /* Check existing relationship */
            $stmt = $conn->prepare("
                SELECT relationship_id, status
                FROM trainer_employee
                WHERE trainer_id = ?
                AND employee_id = ?
                LIMIT 1
            ");

            $stmt->bind_param("ii", $trainer_id, $employee_id);
            $stmt->execute();

            $result = $stmt->get_result();
            $existing = $result->fetch_assoc();

            $stmt->close();


            if ($existing) {

                if ($existing["status"] === "pending") {

                    $message = "Your request to this trainer is already pending.";
                    $message_type = "warning";

                } elseif ($existing["status"] === "approved") {

                    $message = "You are already connected with this trainer.";
                    $message_type = "info";

                } elseif ($existing["status"] === "rejected") {

                    $message = "Your previous request to this trainer was rejected.";
                    $message_type = "warning";

                } elseif ($existing["status"] === "ended") {

                    $message = "Your previous relationship with this trainer has ended.";
                    $message_type = "warning";
                }

            } else {

                /* Create new trainer request */
                $stmt = $conn->prepare("
                    INSERT INTO trainer_employee
                    (trainer_id, employee_id, status)
                    VALUES (?, ?, 'pending')
                ");

                $stmt->bind_param("ii", $trainer_id, $employee_id);

                if ($stmt->execute()) {

                    $message = "Trainer request sent successfully to " . htmlspecialchars($trainer["username"]) . ".";
                    $message_type = "success";

                } else {

                    $message = "Unable to send trainer request. Please try again.";
                    $message_type = "danger";
                }

                $stmt->close();
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

    <title>Request Trainer - OUTLIERS</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../css/style.css">

</head>

<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">

    <div class="container">

        <a class="navbar-brand fw-bold" href="dashboard.php">
            OUTLIERS
        </a>

        <div class="ms-auto">

            <a href="dashboard.php" class="btn btn-outline-light btn-sm">
                Dashboard
            </a>

            <a href="../logout.php" class="btn btn-danger btn-sm">
                Logout
            </a>

        </div>

    </div>

</nav>


<section class="py-5">

    <div class="container">

        <div class="row justify-content-center">

            <div class="col-md-7 col-lg-6">

                <div class="card shadow border-0">

                    <div class="card-body p-4 p-md-5">

                        <h2 class="fw-bold mb-2">
                            Request a Trainer
                        </h2>

                        <p class="text-muted mb-4">
                            Enter the registered email address of your trainer.
                            The trainer will verify your request before the
                            relationship is approved.
                        </p>


                        <?php if ($message !== ""): ?>

                            <div class="alert alert-<?php echo $message_type; ?>">
                                <?php echo $message; ?>
                            </div>

                        <?php endif; ?>


                        <div class="bg-light rounded p-3 mb-4">

                            <strong>Employee</strong><br>

                            <?php echo htmlspecialchars($employee["full_name"]); ?>

                            <br>

                            <small class="text-muted">
                                Employee ID:
                                <?php echo htmlspecialchars($employee["employee_code"]); ?>
                            </small>

                        </div>


                        <form method="POST">

                            <div class="mb-3">

                                <label for="trainer_email" class="form-label fw-semibold">
                                    Trainer Email
                                </label>

                                <input
                                    type="email"
                                    class="form-control"
                                    id="trainer_email"
                                    name="trainer_email"
                                    placeholder="trainer@outliers.com"
                                    required
                                >

                                <div class="form-text">
                                    The email must belong to a registered OUTLIERS trainer.
                                </div>

                            </div>


                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                            >
                                Send Trainer Request
                            </button>

                        </form>


                        <div class="mt-4 text-center">

                            <a href="dashboard.php" class="text-decoration-none">
                                ← Back to Dashboard
                            </a>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


</body>

</html>