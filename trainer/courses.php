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

/* =========================================================
   CREATE NEW COURSE
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_course"])) {

    $course_name = trim($_POST["course_name"]);
    $description = trim($_POST["description"]);
    $skill_id = intval($_POST["skill_id"]);
    $difficulty = trim($_POST["difficulty"]);

    if ($course_name === "" || $skill_id <= 0 || $difficulty === "") {

        $error = "Please fill all required fields.";

    } else {

        $stmt = $conn->prepare("
            INSERT INTO courses
            (course_name, description, skill_id, difficulty, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssisi",
            $course_name,
            $description,
            $skill_id,
            $difficulty,
            $trainer_id
        );

        if ($stmt->execute()) {
            $message = "Course created successfully.";
        } else {
            $error = "Failed to create course.";
        }

        $stmt->close();
    }
}


/* =========================================================
   GET SKILLS
   ========================================================= */

$skills = [];

$result = $conn->query("
    SELECT skill_id, skill_name
    FROM skills
    ORDER BY skill_name ASC
");

while ($row = $result->fetch_assoc()) {
    $skills[] = $row;
}


/* =========================================================
   GET TRAINER COURSES
   ========================================================= */

$courses = [];

$stmt = $conn->prepare("
    SELECT
        c.course_id,
        c.course_name,
        c.description,
        c.difficulty,
        c.created_at,
        s.skill_name,

        COUNT(DISTINCT en.employee_id) AS student_count,

        COALESCE(
            ROUND(AVG(p.progress_percentage), 1),
            0
        ) AS avg_progress,

        COUNT(DISTINCT lm.material_id) AS material_count

    FROM courses c

    LEFT JOIN skills s
        ON c.skill_id = s.skill_id

    LEFT JOIN enrollments en
        ON c.course_id = en.course_id

    LEFT JOIN progress p
        ON en.enrollment_id = p.enrollment_id

    LEFT JOIN learning_materials lm
        ON c.course_id = lm.course_id

    WHERE c.created_by = ?

    GROUP BY
        c.course_id,
        c.course_name,
        c.description,
        c.difficulty,
        c.created_at,
        s.skill_name

    ORDER BY c.created_at DESC
");

$stmt->bind_param("i", $trainer_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $courses[] = $row;
}

$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>My Courses - OUTLIERS</title>

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


    <!-- PAGE HEADING -->

    <div class="mb-4">

        <h2 class="fw-bold">
            My Courses
        </h2>

        <p class="text-muted">
            Create courses, manage learning content and assign courses
            to your verified employees.
        </p>

    </div>


    <!-- =====================================================
         SUCCESS MESSAGE
         ===================================================== -->

    <?php if ($message): ?>

        <div class="alert alert-success">
            <?php echo htmlspecialchars($message); ?>
        </div>

    <?php endif; ?>


    <!-- =====================================================
         ERROR MESSAGE
         ===================================================== -->

    <?php if ($error): ?>

        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error); ?>
        </div>

    <?php endif; ?>


    <!-- =====================================================
         CREATE COURSE
         ===================================================== -->

    <div class="card shadow-sm mb-5">

        <div class="card-body p-4">

            <h4 class="fw-bold mb-3">
                Create New Course
            </h4>

            <form method="POST">

                <div class="row g-3">


                    <!-- COURSE NAME -->

                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Course Name
                        </label>

                        <input
                            type="text"
                            name="course_name"
                            class="form-control"
                            placeholder="Enter course name"
                            required
                        >

                    </div>


                    <!-- SKILL -->

                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Related Skill
                        </label>

                        <select
                            name="skill_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select Skill
                            </option>

                            <?php foreach ($skills as $skill): ?>

                                <option
                                    value="<?php echo $skill["skill_id"]; ?>"
                                >
                                    <?php
                                    echo htmlspecialchars(
                                        $skill["skill_name"]
                                    );
                                    ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- DESCRIPTION -->

                    <div class="col-12">

                        <label class="form-label fw-semibold">
                            Description
                        </label>

                        <textarea
                            name="description"
                            class="form-control"
                            rows="3"
                            placeholder="Describe what employees will learn..."
                        ></textarea>

                    </div>


                    <!-- DIFFICULTY -->

                    <div class="col-md-4">

                        <label class="form-label fw-semibold">
                            Difficulty
                        </label>

                        <select
                            name="difficulty"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select Difficulty
                            </option>

                            <option value="Beginner">
                                Beginner
                            </option>

                            <option value="Intermediate">
                                Intermediate
                            </option>

                            <option value="Advanced">
                                Advanced
                            </option>

                        </select>

                    </div>


                    <!-- SUBMIT -->

                    <div class="col-md-8 d-flex align-items-end">

                        <button
                            type="submit"
                            name="create_course"
                            class="btn btn-primary"
                        >
                            Create Course
                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>


    <!-- =====================================================
         COURSE LIST
         ===================================================== -->

    <div class="d-flex justify-content-between align-items-center mb-3">

        <div>

            <h4 class="fw-bold mb-1">
                Your Courses
            </h4>

            <p class="text-muted mb-0">
                Manage your courses and learners.
            </p>

        </div>

        <span class="badge bg-primary">
            <?php echo count($courses); ?> Courses
        </span>

    </div>


    <?php if (count($courses) > 0): ?>

        <div class="row g-4">

            <?php foreach ($courses as $course): ?>

                <div class="col-md-6 col-lg-4">

                    <div class="card h-100 shadow-sm">

                        <div class="card-body p-4">


                            <!-- COURSE NAME -->

                            <h5 class="fw-bold">

                                <?php
                                echo htmlspecialchars(
                                    $course["course_name"]
                                );
                                ?>

                            </h5>


                            <!-- SKILL -->

                            <p class="mb-1">

                                <strong>
                                    Skill:
                                </strong>

                                <?php
                                echo htmlspecialchars(
                                    $course["skill_name"] ?? "N/A"
                                );
                                ?>

                            </p>


                            <!-- DIFFICULTY -->

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


                            <!-- DESCRIPTION -->

                            <p class="text-muted">

                                <?php
                                echo htmlspecialchars(
                                    $course["description"]
                                );
                                ?>

                            </p>


                            <hr>


                            <!-- COURSE STATISTICS -->

                            <div class="row text-center mb-3">

                                <div class="col-4">

                                    <h5 class="fw-bold mb-0">

                                        <?php
                                        echo $course["student_count"];
                                        ?>

                                    </h5>

                                    <small class="text-muted">
                                        Learners
                                    </small>

                                </div>


                                <div class="col-4">

                                    <h5 class="fw-bold mb-0">

                                        <?php
                                        echo $course["avg_progress"];
                                        ?>%

                                    </h5>

                                    <small class="text-muted">
                                        Progress
                                    </small>

                                </div>


                                <div class="col-4">

                                    <h5 class="fw-bold mb-0">

                                        <?php
                                        echo $course["material_count"];
                                        ?>

                                    </h5>

                                    <small class="text-muted">
                                        Materials
                                    </small>

                                </div>

                            </div>


                            <!-- =================================================
                                 ACTION BUTTONS
                                 ================================================= -->

                            <div class="d-grid gap-2">


                                <!-- ASSIGN COURSE -->

                                <a
                                    href="assign-course.php?course_id=<?php echo $course["course_id"]; ?>"
                                    class="btn btn-primary"
                                >
                                    Assign Course
                                </a>


                                <!-- MATERIALS -->

                                <a
                                    href="materials.php?course_id=<?php echo $course["course_id"]; ?>"
                                    class="btn btn-outline-primary"
                                >
                                    Manage Materials
                                </a>


                                <!-- LEARNERS -->

                                <a
                                    href="learners.php?course_id=<?php echo $course["course_id"]; ?>"
                                    class="btn btn-outline-secondary"
                                >
                                    View Learners
                                </a>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="alert alert-info">

            <h5 class="fw-bold">
                No courses yet
            </h5>

            <p class="mb-0">
                Create your first course using the form above.
            </p>

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


</body>
</html>