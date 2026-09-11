<?php
session_start();

require_once "../includes/db.php";

/*
|--------------------------------------------------------------------------
| Trainer Authentication
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "trainer") {
    header("Location: ../login.php");
    exit;
}

$trainer_id = (int) $_SESSION["user_id"];

/*
|--------------------------------------------------------------------------
| Get Parameters
|--------------------------------------------------------------------------
*/
$employee_id = isset($_GET["employee_id"])
    ? (int) $_GET["employee_id"]
    : 0;

$skill_name = isset($_GET["skill"])
    ? trim($_GET["skill"])
    : "";

$recommended_difficulty = isset($_GET["difficulty"])
    ? trim($_GET["difficulty"])
    : "";

/*
|--------------------------------------------------------------------------
| Validate Difficulty
|--------------------------------------------------------------------------
*/
$allowed_difficulties = [
    "Beginner",
    "Intermediate",
    "Advanced"
];

if (!in_array($recommended_difficulty, $allowed_difficulties, true)) {
    $recommended_difficulty = "";
}

/*
|--------------------------------------------------------------------------
| Validate Employee
|--------------------------------------------------------------------------
*/
$employee = null;

$stmt = $conn->prepare("
    SELECT
        e.employee_id,
        e.employee_code,
        e.full_name,
        e.designation,
        e.department
    FROM employees e
    INNER JOIN trainer_employee te
        ON te.employee_id = e.employee_id
    WHERE e.employee_id = ?
      AND te.trainer_id = ?
      AND te.status = 'approved'
    LIMIT 1
");

$stmt->bind_param(
    "ii",
    $employee_id,
    $trainer_id
);

$stmt->execute();

$result = $stmt->get_result();
$employee = $result->fetch_assoc();

$stmt->close();

if (!$employee) {
    die("Employee is not assigned to you or the trainer relationship is not approved.");
}

/*
|--------------------------------------------------------------------------
| Find Skill
|--------------------------------------------------------------------------
*/
$skill = null;

if ($skill_name !== "") {

    $stmt = $conn->prepare("
        SELECT
            skill_id,
            skill_name,
            category,
            description
        FROM skills
        WHERE LOWER(TRIM(skill_name)) = LOWER(TRIM(?))
        LIMIT 1
    ");

    $stmt->bind_param(
        "s",
        $skill_name
    );

    $stmt->execute();

    $result = $stmt->get_result();
    $skill = $result->fetch_assoc();

    $stmt->close();
}

if (!$skill) {
    die("The recommended skill could not be found.");
}

$skill_id = (int) $skill["skill_id"];

/*
|--------------------------------------------------------------------------
| Handle Course Assignment
|--------------------------------------------------------------------------
*/
$success_message = "";
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $course_id = isset($_POST["course_id"])
        ? (int) $_POST["course_id"]
        : 0;

    /*
    |--------------------------------------------------------------------------
    | Verify Course
    |--------------------------------------------------------------------------
    */
    $stmt = $conn->prepare("
        SELECT
            course_id,
            course_name,
            difficulty,
            skill_id
        FROM courses
        WHERE course_id = ?
          AND skill_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "ii",
        $course_id,
        $skill_id
    );

    $stmt->execute();

    $result = $stmt->get_result();
    $selected_course = $result->fetch_assoc();

    $stmt->close();

    if (!$selected_course) {

        $error_message = "Invalid course selected for this skill.";

    } else {

        /*
        |--------------------------------------------------------------------------
        | Check Existing Assignment
        |--------------------------------------------------------------------------
        */
        $stmt = $conn->prepare("
            SELECT assignment_id
            FROM course_assignments
            WHERE course_id = ?
              AND employee_id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            "ii",
            $course_id,
            $employee_id
        );

        $stmt->execute();

        $result = $stmt->get_result();
        $existing_assignment = $result->fetch_assoc();

        $stmt->close();

        if ($existing_assignment) {

            $error_message = "This course is already assigned to this employee.";

        } else {

            /*
            |--------------------------------------------------------------------------
            | Assign Course
            |--------------------------------------------------------------------------
            */
            $status = "assigned";

            $stmt = $conn->prepare("
                INSERT INTO course_assignments
                (
                    course_id,
                    employee_id,
                    assigned_by,
                    status
                )
                VALUES (?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "iiis",
                $course_id,
                $employee_id,
                $trainer_id,
                $status
            );

            if ($stmt->execute()) {

                $success_message =
                    "Course successfully assigned to " .
                    $employee["full_name"] .
                    ".";

            } else {

                $error_message =
                    "Unable to assign the course. Please try again.";
            }

            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load Matching Courses
|--------------------------------------------------------------------------
|
| Exact AI-recommended difficulty comes first.
| Other difficulties are still shown as alternatives.
|--------------------------------------------------------------------------
*/

$courses = [];

$stmt = $conn->prepare("
    SELECT
        c.course_id,
        c.course_name,
        c.description,
        c.difficulty,
        c.created_at,

        CASE
            WHEN ca.assignment_id IS NOT NULL THEN 1
            ELSE 0
        END AS is_assigned,

        CASE
            WHEN c.difficulty = ? THEN 1
            ELSE 0
        END AS is_ai_recommended

    FROM courses c

    LEFT JOIN course_assignments ca
        ON ca.course_id = c.course_id
       AND ca.employee_id = ?

    WHERE c.skill_id = ?

    ORDER BY
        is_ai_recommended DESC,
        is_assigned ASC,
        CASE c.difficulty
            WHEN 'Beginner' THEN 1
            WHEN 'Intermediate' THEN 2
            WHEN 'Advanced' THEN 3
            ELSE 4
        END,
        c.course_name ASC
");

$stmt->bind_param(
    "sii",
    $recommended_difficulty,
    $employee_id,
    $skill_id
);

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
          content="width=device-width, initial-scale=1">

    <title>Assign Recommended Course - OUTLIERS</title>

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

<div class="container py-5">

    <!-- Header -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="fw-bold mb-1">
                Assign Recommended Course
            </h2>

            <p class="text-muted mb-0">
                Select the most suitable course for the employee.
            </p>

        </div>

        <a
            href="ai-training-recommendations.php"
            class="btn btn-outline-secondary"
        >
            Back to Recommendations
        </a>

    </div>


    <!-- Success Message -->

    <?php if ($success_message !== ""): ?>

        <div class="alert alert-success">

            <strong>Success!</strong>
            <?php echo htmlspecialchars($success_message); ?>

        </div>

    <?php endif; ?>


    <!-- Error Message -->

    <?php if ($error_message !== ""): ?>

        <div class="alert alert-danger">

            <strong>Error:</strong>
            <?php echo htmlspecialchars($error_message); ?>

        </div>

    <?php endif; ?>


    <!-- Employee Information -->

    <div class="card shadow-sm border-0 mb-4">

        <div class="card-body p-4">

            <div class="row">

                <div class="col-md-6">

                    <h5 class="fw-bold mb-3">
                        Employee
                    </h5>

                    <p class="mb-1">
                        <strong>Name:</strong>
                        <?php
                        echo htmlspecialchars(
                            $employee["full_name"]
                        );
                        ?>
                    </p>

                    <p class="mb-1">

                        <strong>Employee Code:</strong>

                        <?php
                        echo htmlspecialchars(
                            $employee["employee_code"]
                        );
                        ?>

                    </p>

                    <p class="mb-0">

                        <strong>Designation:</strong>

                        <?php
                        echo htmlspecialchars(
                            $employee["designation"]
                        );
                        ?>

                    </p>

                </div>


                <div class="col-md-6">

                    <h5 class="fw-bold mb-3">
                        AI Recommendation
                    </h5>

                    <p class="mb-2">

                        <strong>Skill:</strong>

                        <span class="badge bg-primary">

                            <?php
                            echo htmlspecialchars(
                                $skill["skill_name"]
                            );
                            ?>

                        </span>

                    </p>


                    <?php if ($recommended_difficulty !== ""): ?>

                        <p class="mb-0">

                            <strong>Recommended Difficulty:</strong>

                            <span class="badge bg-success">

                                <?php
                                echo htmlspecialchars(
                                    $recommended_difficulty
                                );
                                ?>

                            </span>

                        </p>

                    <?php else: ?>

                        <p class="text-muted mb-0">

                            No specific difficulty was provided
                            by the AI recommendation.

                        </p>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>


    <!-- Course List -->

    <div class="card shadow-sm border-0">

        <div class="card-body p-4">

            <div class="d-flex justify-content-between align-items-center mb-4">

                <div>

                    <h4 class="fw-bold mb-1">
                        Matching Courses
                    </h4>

                    <p class="text-muted mb-0">

                        Courses for skill:
                        <strong>
                            <?php
                            echo htmlspecialchars(
                                $skill["skill_name"]
                            );
                            ?>
                        </strong>

                    </p>

                </div>

            </div>


            <?php if (empty($courses)): ?>

                <div class="text-center py-5">

                    <h5 class="fw-bold">
                        No matching courses found
                    </h5>

                    <p class="text-muted mb-0">

                        There are currently no courses
                        available for this skill.

                    </p>

                </div>

            <?php else: ?>

                <div class="row g-4">

                    <?php foreach ($courses as $course): ?>

                        <div class="col-md-6">

                            <div
                                class="card h-100 border
                                <?php
                                echo $course["is_ai_recommended"]
                                    ? "border-success"
                                    : "";
                                ?>"
                            >

                                <div class="card-body p-4">

                                    <!-- Course title -->

                                    <div class="d-flex
                                                justify-content-between
                                                align-items-start
                                                gap-2">

                                        <h5 class="fw-bold mb-2">

                                            <?php
                                            echo htmlspecialchars(
                                                $course["course_name"]
                                            );
                                            ?>

                                        </h5>


                                        <?php if (
                                            $course["is_ai_recommended"]
                                        ): ?>

                                            <span
                                                class="badge bg-success"
                                            >
                                                AI Recommended
                                            </span>

                                        <?php endif; ?>

                                    </div>


                                    <!-- Difficulty -->

                                    <div class="mb-3">

                                        <?php

                                        $difficulty_class =
                                            "bg-secondary";

                                        if (
                                            $course["difficulty"]
                                            === "Beginner"
                                        ) {
                                            $difficulty_class =
                                                "bg-info text-dark";
                                        }

                                        if (
                                            $course["difficulty"]
                                            === "Intermediate"
                                        ) {
                                            $difficulty_class =
                                                "bg-warning text-dark";
                                        }

                                        if (
                                            $course["difficulty"]
                                            === "Advanced"
                                        ) {
                                            $difficulty_class =
                                                "bg-danger";
                                        }

                                        ?>

                                        <span
                                            class="badge
                                            <?php
                                            echo $difficulty_class;
                                            ?>"
                                        >

                                            <?php
                                            echo htmlspecialchars(
                                                $course["difficulty"]
                                            );
                                            ?>

                                        </span>

                                    </div>


                                    <!-- Description -->

                                    <p class="text-muted">

                                        <?php
                                        echo htmlspecialchars(
                                            $course["description"]
                                        );
                                        ?>

                                    </p>


                                    <!-- Assignment status -->

                                    <?php if (
                                        $course["is_assigned"]
                                    ): ?>

                                        <div
                                            class="alert alert-secondary
                                                   py-2 mb-0"
                                        >

                                            <strong>
                                                Already Assigned
                                            </strong>

                                            <br>

                                            This course has already
                                            been assigned to this
                                            employee.

                                        </div>

                                    <?php else: ?>

                                        <form method="POST">

                                            <input
                                                type="hidden"
                                                name="course_id"
                                                value="<?php
                                                echo (int)
                                                    $course["course_id"];
                                                ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn
                                                       <?php
                                                       echo
                                                       $course[
                                                           "is_ai_recommended"
                                                       ]
                                                       ? "btn-success"
                                                       : "btn-primary";
                                                       ?>
                                                       w-100"
                                            >

                                                <?php if (
                                                    $course[
                                                        "is_ai_recommended"
                                                    ]
                                                ): ?>

                                                    Assign
                                                    AI Recommended
                                                    Course

                                                <?php else: ?>

                                                    Assign This Course

                                                <?php endif; ?>

                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- Navigation -->

    <div class="mt-4 text-center">

        <a
            href="ai-training-recommendations.php"
            class="btn btn-outline-primary me-2"
        >
            AI Training Recommendations
        </a>

        <a
            href="course-progress.php"
            class="btn btn-outline-primary me-2"
        >
            Course Progress
        </a>

        <a
            href="dashboard.php"
            class="btn btn-outline-secondary"
        >
            Trainer Dashboard
        </a>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js">
</script>

</body>

</html>