<?php

session_start();

require_once "../includes/db.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "trainer") {
    header("Location: ../employee-login.php");
    exit;
}

$trainer_id = (int) $_SESSION["user_id"];

/* ---------------------------------------------------
   GET TRAINER EMPLOYEES + SKILL ANALYSIS
--------------------------------------------------- */

$query = $conn->prepare(
    "SELECT
        e.employee_id,
        e.employee_code,
        e.full_name,
        e.department,
        e.designation,
        s.skill_name,
        s.category,
        rs.required_level,
        COALESCE(es.current_level, 0) AS current_level,

        (
            rs.required_level -
            COALESCE(es.current_level, 0)
        ) AS skill_gap

     FROM trainer_employee te

     INNER JOIN employees e
        ON te.employee_id = e.employee_id

     INNER JOIN roles r
        ON LOWER(TRIM(r.role_name)) =
           LOWER(TRIM(e.designation))

     INNER JOIN role_skills rs
        ON r.role_id = rs.role_id

     INNER JOIN skills s
        ON rs.skill_id = s.skill_id

     LEFT JOIN employee_skills es
        ON es.employee_id = e.employee_id
        AND es.skill_id = s.skill_id

     WHERE te.trainer_id = ?
       AND te.status = 'approved'

     ORDER BY skill_gap DESC, e.full_name, s.skill_name"
);

$query->bind_param("i", $trainer_id);
$query->execute();

$result = $query->get_result();

$analysis = [];

while ($row = $result->fetch_assoc()) {

    $gap = (int) $row["skill_gap"];

    if ($gap < 0) {
        $gap = 0;
    }

    $row["skill_gap"] = $gap;

    $analysis[] = $row;
}

$query->close();


/* ---------------------------------------------------
   SUMMARY
--------------------------------------------------- */

$total_records = count($analysis);

$total_gaps = 0;
$critical_gaps = 0;
$employees = [];

foreach ($analysis as $row) {

    if ($row["skill_gap"] > 0) {
        $total_gaps++;
    }

    if ($row["skill_gap"] >= 2) {
        $critical_gaps++;
    }

    $employees[$row["employee_id"]] = true;
}

$total_employees = count($employees);


/* ---------------------------------------------------
   GROUP BY EMPLOYEE
--------------------------------------------------- */

$employee_analysis = [];

foreach ($analysis as $row) {

    $id = $row["employee_id"];

    if (!isset($employee_analysis[$id])) {

        $employee_analysis[$id] = [
            "employee_code" => $row["employee_code"],
            "full_name" => $row["full_name"],
            "department" => $row["department"],
            "designation" => $row["designation"],
            "skills" => []
        ];
    }

    $employee_analysis[$id]["skills"][] = [
        "skill_name" => $row["skill_name"],
        "category" => $row["category"],
        "required_level" => (int) $row["required_level"],
        "current_level" => (int) $row["current_level"],
        "skill_gap" => (int) $row["skill_gap"]
    ];
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

    <title>AI Skill Analysis - OUTLIERS</title>

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

    <!-- HEADER -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="fw-bold mb-1">
                AI Skill Analysis
            </h2>

            <p class="text-muted mb-0">
                Identify employee competency gaps and training priorities.
            </p>

        </div>

        <a
            href="dashboard.php"
            class="btn btn-outline-secondary"
        >
            Back to Dashboard
        </a>

    </div>


    <!-- SUMMARY CARDS -->

    <div class="row g-4 mb-4">

        <div class="col-md-4">

            <div class="card shadow-sm h-100">

                <div class="card-body">

                    <small class="text-muted">
                        Employees Analyzed
                    </small>

                    <h2 class="fw-bold mt-2 mb-0">
                        <?php echo $total_employees; ?>
                    </h2>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card shadow-sm h-100">

                <div class="card-body">

                    <small class="text-muted">
                        Skill Gaps
                    </small>

                    <h2 class="fw-bold mt-2 mb-0">
                        <?php echo $total_gaps; ?>
                    </h2>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card shadow-sm h-100">

                <div class="card-body">

                    <small class="text-muted">
                        High Priority Gaps
                    </small>

                    <h2 class="fw-bold mt-2 mb-0">
                        <?php echo $critical_gaps; ?>
                    </h2>

                </div>

            </div>

        </div>

    </div>


    <!-- EXPLANATION -->

    <div class="alert alert-info mb-4">

        <strong>How this analysis works:</strong>

        The system compares each employee's current competency level
        with the required competency level for their designation.

        A larger gap indicates a higher training priority.

    </div>


    <?php if (count($employee_analysis) > 0): ?>


        <!-- EMPLOYEE ANALYSIS -->

        <?php foreach ($employee_analysis as $employee): ?>

            <div class="card shadow-sm mb-4">

                <div class="card-body">

                    <!-- EMPLOYEE HEADER -->

                    <div class="d-flex justify-content-between align-items-start mb-4">

                        <div>

                            <h4 class="fw-bold mb-1">

                                <?php
                                    echo htmlspecialchars(
                                        $employee["full_name"]
                                    );
                                ?>

                            </h4>

                            <p class="text-muted mb-1">

                                Employee Code:
                                <?php
                                    echo htmlspecialchars(
                                        $employee["employee_code"]
                                    );
                                ?>

                            </p>

                            <p class="text-muted mb-0">

                                Designation:
                                <?php
                                    echo htmlspecialchars(
                                        $employee["designation"]
                                    );
                                ?>

                            </p>

                        </div>

                        <span class="badge bg-primary">

                            <?php
                                echo count($employee["skills"]);
                            ?>

                            Skills

                        </span>

                    </div>


                    <!-- SKILLS -->

                    <div class="table-responsive">

                        <table class="table table-bordered align-middle">

                            <thead>

                                <tr>

                                    <th>
                                        Skill
                                    </th>

                                    <th>
                                        Category
                                    </th>

                                    <th>
                                        Required
                                    </th>

                                    <th>
                                        Current
                                    </th>

                                    <th>
                                        Gap
                                    </th>

                                    <th>
                                        Priority
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php foreach ($employee["skills"] as $skill): ?>

                                <tr>

                                    <td class="fw-semibold">

                                        <?php
                                            echo htmlspecialchars(
                                                $skill["skill_name"]
                                            );
                                        ?>

                                    </td>

                                    <td>

                                        <?php
                                            echo htmlspecialchars(
                                                $skill["category"]
                                            );
                                        ?>

                                    </td>

                                    <td>

                                        <?php
                                            echo $skill["required_level"];
                                        ?>

                                    </td>

                                    <td>

                                        <?php
                                            echo $skill["current_level"];
                                        ?>

                                    </td>

                                    <td>

                                        <?php if ($skill["skill_gap"] > 0): ?>

                                            <span class="badge bg-warning text-dark">

                                                <?php
                                                    echo $skill["skill_gap"];
                                                ?>

                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-success">
                                                0
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <?php if ($skill["skill_gap"] >= 2): ?>

                                            <span class="badge bg-danger">
                                                High
                                            </span>

                                        <?php elseif ($skill["skill_gap"] == 1): ?>

                                            <span class="badge bg-warning text-dark">
                                                Medium
                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-success">
                                                Low
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>


    <?php else: ?>

        <div class="card shadow-sm">

            <div class="card-body text-center py-5">

                <h4 class="fw-bold">
                    No Skill Analysis Available
                </h4>

                <p class="text-muted mb-0">

                    No approved employees with matching
                    role-skill mappings were found.

                </p>

            </div>

        </div>

    <?php endif; ?>


    <!-- ACTIONS -->

    <div class="text-center mt-4">

        <a
            href="course-progress.php"
            class="btn btn-outline-primary me-2"
        >
            Course Progress
        </a>

        <a
            href="quiz-results.php"
            class="btn btn-outline-success me-2"
        >
            Quiz Results
        </a>

        <a
            href="dashboard.php"
            class="btn btn-outline-secondary"
        >
            Dashboard
        </a>

    </div>

</div>


<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>