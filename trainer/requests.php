<?php
session_start();

require_once "../includes/db.php";

/* Allow only logged-in trainers */
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "trainer") {
    header("Location: ../login.php");
    exit();
}

$trainer_id = $_SESSION["user_id"];
$message = "";
$message_type = "";


/* ==============================
   HANDLE ACCEPT / REJECT
   ============================== */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $relationship_id = intval($_POST["relationship_id"] ?? 0);
    $action = $_POST["action"] ?? "";

    if ($relationship_id <= 0) {

        $message = "Invalid request.";
        $message_type = "danger";

    } elseif (!in_array($action, ["approve", "reject"])) {

        $message = "Invalid action.";
        $message_type = "danger";

    } else {

        /*
         * Update only requests that belong
         * to the currently logged-in trainer.
         */
        if ($action === "approve") {

            $new_status = "approved";

        } else {

            $new_status = "rejected";
        }


        $stmt = $conn->prepare("
            UPDATE trainer_employee
            SET status = ?,
                approved_at = CASE
                    WHEN ? = 'approved' THEN CURRENT_TIMESTAMP
                    ELSE NULL
                END
            WHERE relationship_id = ?
            AND trainer_id = ?
            AND status = 'pending'
        ");

        $stmt->bind_param(
            "ssii",
            $new_status,
            $new_status,
            $relationship_id,
            $trainer_id
        );


        if ($stmt->execute()) {

            if ($stmt->affected_rows > 0) {

                if ($new_status === "approved") {

                    $message = "Employee request approved successfully.";
                    $message_type = "success";

                } else {

                    $message = "Employee request rejected.";
                    $message_type = "warning";
                }

            } else {

                $message = "Request was not found or has already been processed.";
                $message_type = "warning";
            }

        } else {

            $message = "Unable to update the request.";
            $message_type = "danger";
        }

        $stmt->close();
    }
}


/* ==============================
   FETCH TRAINER INFORMATION
   ============================== */

$stmt = $conn->prepare("
    SELECT username, email
    FROM users
    WHERE user_id = ?
    AND role = 'trainer'
");

$stmt->bind_param("i", $trainer_id);
$stmt->execute();

$result = $stmt->get_result();
$trainer = $result->fetch_assoc();

$stmt->close();


/* ==============================
   FETCH TRAINER REQUESTS
   ============================== */

$stmt = $conn->prepare("
    SELECT
        te.relationship_id,
        te.status,
        te.requested_at,
        te.approved_at,

        e.employee_id,
        e.employee_code,
        e.full_name,
        e.department,
        e.designation,
        e.qualification,
        e.experience_years,

        u.email

    FROM trainer_employee te

    INNER JOIN employees e
        ON te.employee_id = e.employee_id

    INNER JOIN users u
        ON e.user_id = u.user_id

    WHERE te.trainer_id = ?

    ORDER BY
        CASE
            WHEN te.status = 'pending' THEN 1
            WHEN te.status = 'approved' THEN 2
            WHEN te.status = 'rejected' THEN 3
            ELSE 4
        END,
        te.requested_at DESC
");

$stmt->bind_param("i", $trainer_id);
$stmt->execute();

$requests = $stmt->get_result();

$stmt->close();


/* Count requests */

$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;

foreach ($requests as $request) {

    if ($request["status"] === "pending") {
        $pending_count++;
    }

    if ($request["status"] === "approved") {
        $approved_count++;
    }

    if ($request["status"] === "rejected") {
        $rejected_count++;
    }
}


/*
 * Run the query again because foreach above
 * moves the result pointer.
 */
$stmt = $conn->prepare("
    SELECT
        te.relationship_id,
        te.status,
        te.requested_at,
        te.approved_at,

        e.employee_code,
        e.full_name,
        e.department,
        e.designation,
        e.qualification,
        e.experience_years

    FROM trainer_employee te

    INNER JOIN employees e
        ON te.employee_id = e.employee_id

    WHERE te.trainer_id = ?

    ORDER BY
        CASE
            WHEN te.status = 'pending' THEN 1
            WHEN te.status = 'approved' THEN 2
            WHEN te.status = 'rejected' THEN 3
            ELSE 4
        END,
        te.requested_at DESC
");

$stmt->bind_param("i", $trainer_id);
$stmt->execute();

$requests = $stmt->get_result();

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

    <title>Trainer Requests - OUTLIERS</title>

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


<!-- ==============================
     NAVBAR
     ============================== -->

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
                href="dashboard.php"
                class="btn btn-outline-light btn-sm me-2"
            >
                Dashboard
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



<!-- ==============================
     MAIN CONTENT
     ============================== -->

<section class="py-5">

    <div class="container">


        <!-- PAGE HEADER -->

        <div class="mb-4">

            <h2 class="fw-bold">
                Employee Requests
            </h2>

            <p class="text-muted mb-0">

                Review and verify employees who want
                to work under your training.

            </p>

        </div>



        <!-- TRAINER INFORMATION -->

        <div class="card border-0 shadow-sm mb-4">

            <div class="card-body">

                <h5 class="fw-bold mb-1">

                    <?php
                    echo htmlspecialchars($trainer["username"]);
                    ?>

                </h5>

                <p class="text-muted mb-0">

                    <?php
                    echo htmlspecialchars($trainer["email"]);
                    ?>

                </p>

            </div>

        </div>



        <!-- STATISTICS -->

        <div class="row g-3 mb-4">


            <div class="col-md-4">

                <div class="card border-0 shadow-sm">

                    <div class="card-body">

                        <h6 class="text-muted">
                            Pending Requests
                        </h6>

                        <h2 class="fw-bold text-warning">
                            <?php echo $pending_count; ?>
                        </h2>

                    </div>

                </div>

            </div>


            <div class="col-md-4">

                <div class="card border-0 shadow-sm">

                    <div class="card-body">

                        <h6 class="text-muted">
                            Approved Employees
                        </h6>

                        <h2 class="fw-bold text-success">
                            <?php echo $approved_count; ?>
                        </h2>

                    </div>

                </div>

            </div>


            <div class="col-md-4">

                <div class="card border-0 shadow-sm">

                    <div class="card-body">

                        <h6 class="text-muted">
                            Rejected Requests
                        </h6>

                        <h2 class="fw-bold text-danger">
                            <?php echo $rejected_count; ?>
                        </h2>

                    </div>

                </div>

            </div>

        </div>



        <!-- MESSAGE -->

        <?php if ($message !== ""): ?>

            <div class="alert alert-<?php echo $message_type; ?>">

                <?php
                echo htmlspecialchars($message);
                ?>

            </div>

        <?php endif; ?>



        <!-- REQUEST TABLE -->

        <div class="card border-0 shadow-sm">

            <div class="card-body">

                <h5 class="fw-bold mb-4">
                    Trainer-Employee Requests
                </h5>


                <?php if ($requests->num_rows > 0): ?>

                    <div class="table-responsive">

                        <table class="table table-hover align-middle">

                            <thead class="table-light">

                                <tr>

                                    <th>Employee</th>

                                    <th>Department</th>

                                    <th>Designation</th>

                                    <th>Experience</th>

                                    <th>Status</th>

                                    <th>Requested</th>

                                    <th>Action</th>

                                </tr>

                            </thead>


                            <tbody>

                                <?php while ($request = $requests->fetch_assoc()): ?>

                                    <tr>


                                        <!-- EMPLOYEE -->

                                        <td>

                                            <strong>
                                                <?php
                                                echo htmlspecialchars(
                                                    $request["full_name"]
                                                );
                                                ?>
                                            </strong>

                                            <br>

                                            <small class="text-muted">

                                                ID:
                                                <?php
                                                echo htmlspecialchars(
                                                    $request["employee_code"]
                                                );
                                                ?>

                                            </small>

                                        </td>


                                        <!-- DEPARTMENT -->

                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $request["department"]
                                            );
                                            ?>

                                        </td>


                                        <!-- DESIGNATION -->

                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $request["designation"]
                                            );
                                            ?>

                                        </td>


                                        <!-- EXPERIENCE -->

                                        <td>

                                            <?php
                                            echo htmlspecialchars(
                                                $request["experience_years"]
                                            );
                                            ?>
                                            years

                                        </td>


                                        <!-- STATUS -->

                                        <td>

                                            <?php if ($request["status"] === "pending"): ?>

                                                <span class="badge bg-warning text-dark">
                                                    Pending
                                                </span>

                                            <?php elseif ($request["status"] === "approved"): ?>

                                                <span class="badge bg-success">
                                                    Approved
                                                </span>

                                            <?php elseif ($request["status"] === "rejected"): ?>

                                                <span class="badge bg-danger">
                                                    Rejected
                                                </span>

                                            <?php else: ?>

                                                <span class="badge bg-secondary">
                                                    Ended
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <!-- REQUEST DATE -->

                                        <td>

                                            <small>

                                                <?php
                                                echo date(
                                                    "d M Y",
                                                    strtotime(
                                                        $request["requested_at"]
                                                    )
                                                );
                                                ?>

                                            </small>

                                        </td>


                                        <!-- ACTION -->

                                        <td>


                                            <?php if ($request["status"] === "pending"): ?>


                                                <div class="d-flex gap-2">


                                                    <!-- APPROVE -->

                                                    <form
                                                        method="POST"
                                                        class="d-inline"
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="relationship_id"
                                                            value="<?php
                                                            echo $request["relationship_id"];
                                                            ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="approve"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="btn btn-success btn-sm"
                                                        >
                                                            Accept
                                                        </button>

                                                    </form>



                                                    <!-- REJECT -->

                                                    <form
                                                        method="POST"
                                                        class="d-inline"
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="relationship_id"
                                                            value="<?php
                                                            echo $request["relationship_id"];
                                                            ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="reject"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="btn btn-outline-danger btn-sm"
                                                        >
                                                            Reject
                                                        </button>

                                                    </form>

                                                </div>


                                            <?php elseif ($request["status"] === "approved"): ?>

                                                <span class="text-success fw-semibold">
                                                    ✓ Connected
                                                </span>


                                            <?php elseif ($request["status"] === "rejected"): ?>

                                                <span class="text-danger">
                                                    Rejected
                                                </span>


                                            <?php else: ?>

                                                <span class="text-muted">
                                                    Ended
                                                </span>

                                            <?php endif; ?>


                                        </td>

                                    </tr>

                                <?php endwhile; ?>

                            </tbody>

                        </table>

                    </div>


                <?php else: ?>

                    <div class="text-center py-5">

                        <h5 class="text-muted">
                            No trainer requests yet.
                        </h5>

                        <p class="text-muted mb-0">

                            Employee requests will appear here
                            when someone enters your registered
                            trainer email.

                        </p>

                    </div>

                <?php endif; ?>


            </div>

        </div>


    </div>

</section>


</body>

</html>