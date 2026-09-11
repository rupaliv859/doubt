<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../employee-login.php");
    exit();
}

require_once "../includes/db.php";

$user_id = $_SESSION['user_id'];

/* Get employee */
$stmt = $conn->prepare("
    SELECT employee_id, full_name
    FROM employees
    WHERE user_id = ?
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$employee = $stmt->get_result()->fetch_assoc();

if (!$employee) {
    die("Employee profile not found.");
}

$employee_id = $employee['employee_id'];

/* Get course ID */
$course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;

if ($course_id <= 0) {
    header("Location: courses.php");
    exit();
}

/* Get course */
$stmt = $conn->prepare("
    SELECT 
        c.course_id,
        c.course_name,
        c.description,
        c.difficulty,
        s.skill_name
    FROM courses c
    LEFT JOIN skills s ON c.skill_id = s.skill_id
    WHERE c.course_id = ?
");

$stmt->bind_param("i", $course_id);
$stmt->execute();

$course = $stmt->get_result()->fetch_assoc();

if (!$course) {
    die("Course not found.");
}

/* Check enrollment */
$stmt = $conn->prepare("
    SELECT 
        e.enrollment_id,
        e.status,
        p.progress_percentage
    FROM enrollments e
    LEFT JOIN progress p 
        ON e.enrollment_id = p.enrollment_id
    WHERE e.employee_id = ?
    AND e.course_id = ?
");

$stmt->bind_param("ii", $employee_id, $course_id);
$stmt->execute();

$enrollment = $stmt->get_result()->fetch_assoc();

/* If not enrolled, enroll employee */
if (!$enrollment) {

    $stmt = $conn->prepare("
        INSERT INTO enrollments
        (employee_id, course_id, status)
        VALUES (?, ?, 'enrolled')
    ");

    $stmt->bind_param("ii", $employee_id, $course_id);
    $stmt->execute();

    $enrollment_id = $conn->insert_id;

    /* Create progress record */
    $stmt = $conn->prepare("
        INSERT INTO progress
        (enrollment_id, progress_percentage, last_accessed)
        VALUES (?, 0, NOW())
    ");

    $stmt->bind_param("i", $enrollment_id);
    $stmt->execute();

    $progress_percentage = 0;

} else {

    $enrollment_id = $enrollment['enrollment_id'];
    $progress_percentage = $enrollment['progress_percentage'] ?? 0;
}

/* Update progress */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $new_progress = isset($_POST['progress']) 
        ? intval($_POST['progress']) 
        : 0;

    if ($new_progress < 0) {
        $new_progress = 0;
    }

    if ($new_progress > 100) {
        $new_progress = 100;
    }

    if ($new_progress == 100) {

        $stmt = $conn->prepare("
            UPDATE progress
            SET progress_percentage = 100,
                last_accessed = NOW(),
                completed_at = NOW()
            WHERE enrollment_id = ?
        ");

        $stmt->bind_param("i", $enrollment_id);
        $stmt->execute();

        $stmt = $conn->prepare("
            UPDATE enrollments
            SET status = 'completed'
            WHERE enrollment_id = ?
        ");

        $stmt->bind_param("i", $enrollment_id);
        $stmt->execute();

        $progress_percentage = 100;

        $message = "Congratulations! Course completed successfully.";

    } else {

        $stmt = $conn->prepare("
            UPDATE progress
            SET progress_percentage = ?,
                last_accessed = NOW()
            WHERE enrollment_id = ?
        ");

        $stmt->bind_param("di", $new_progress, $enrollment_id);
        $stmt->execute();

        $progress_percentage = $new_progress;

        $message = "Your learning progress has been updated.";
    }
}

/* Get learning materials */
$stmt = $conn->prepare("
    SELECT 
        material_id,
        title,
        file_path,
        material_type
    FROM learning_materials
    WHERE course_id = ?
    ORDER BY uploaded_at ASC
");

$stmt->bind_param("i", $course_id);
$stmt->execute();

$materials = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?php echo htmlspecialchars($course['course_name']); ?> - OUTLIERS
    </title>

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

        <a href="courses.php" class="btn btn-outline-light">
            Back to Courses
        </a>

    </div>

</nav>


<div class="container py-5">

    <!-- Course Header -->

    <div class="card shadow-sm border-0 mb-4">

        <div class="card-body p-4">

            <h2 class="fw-bold">
                <?php echo htmlspecialchars($course['course_name']); ?>
            </h2>

            <p class="text-muted mb-3">
                <?php echo htmlspecialchars($course['description']); ?>
            </p>

            <div class="mb-3">

                <span class="badge bg-primary me-2">
                    <?php echo htmlspecialchars($course['difficulty']); ?>
                </span>

                <?php if ($course['skill_name']) { ?>

                    <span class="badge bg-secondary">
                        <?php echo htmlspecialchars($course['skill_name']); ?>
                    </span>

                <?php } ?>

            </div>

        </div>

    </div>


    <!-- Progress -->

    <div class="card shadow-sm border-0 mb-4">

        <div class="card-body p-4">

            <h5 class="fw-bold">
                Your Progress
            </h5>

            <div class="progress mb-3" style="height: 25px;">

                <div
                    class="progress-bar"
                    role="progressbar"
                    style="width: <?php echo $progress_percentage; ?>%;"
                >

                    <?php echo $progress_percentage; ?>%

                </div>

            </div>

            <?php if ($progress_percentage == 100) { ?>

                <div class="alert alert-success">
                    🎉 You have completed this course!
                </div>

            <?php } ?>

        </div>

    </div>


    <?php if (isset($message)) { ?>

        <div class="alert alert-success">
            <?php echo htmlspecialchars($message); ?>
        </div>

    <?php } ?>


    <!-- Learning Materials -->

    <div class="card shadow-sm border-0 mb-4">

        <div class="card-body p-4">

            <h4 class="fw-bold mb-3">
                Learning Materials
            </h4>

            <?php if ($materials->num_rows > 0) { ?>

                <?php while ($material = $materials->fetch_assoc()) { ?>

                    <div class="border rounded p-3 mb-3">

                        <h6 class="fw-bold">
                            <?php echo htmlspecialchars($material['title']); ?>
                        </h6>

                        <p class="text-muted mb-2">

                            Type:
                            <?php echo htmlspecialchars($material['material_type']); ?>

                        </p>

                        <a
                            href="../<?php echo htmlspecialchars($material['file_path']); ?>"
                            target="_blank"
                            class="btn btn-primary btn-sm"
                        >
                            Open Material
                        </a>

                    </div>

                <?php } ?>

            <?php } else { ?>

                <div class="alert alert-info">

                    No learning materials have been uploaded for this
                    course yet.

                </div>

            <?php } ?>

        </div>

    </div>


    <!-- Update Progress -->

    <div class="card shadow-sm border-0">

        <div class="card-body p-4">

            <h4 class="fw-bold mb-3">
                Update Learning Progress
            </h4>

            <form method="POST">

                <label class="form-label">
                    Select your completed percentage
                </label>

                <select
                    name="progress"
                    class="form-select mb-3"
                    required
                >

                    <option value="">Select Progress</option>

                    <option value="25">25%</option>

                    <option value="50">50%</option>

                    <option value="75">75%</option>

                    <option value="100">100% - Completed</option>

                </select>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Update Progress
                </button>

            </form>

        </div>

    </div>

</div>

</body>

</html>