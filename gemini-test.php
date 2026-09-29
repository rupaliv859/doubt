<?php

require_once "includes/gemini.php";

$prompt = "Hello Gemini! This is a test from the OUTLIERS project. Reply with a short welcome message.";

$response = askGemini($prompt);

?>

<!DOCTYPE html>
<html>
<head>
    <title>OUTLIERS - Gemini Test</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f7fb;
            padding: 40px;
        }

        .box {
            max-width: 700px;
            margin: auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        h1 {
            color: #1e3a8a;
        }

        .response {
            margin-top: 20px;
            padding: 20px;
            background: #eef6ff;
            border-radius: 8px;
            white-space: pre-wrap;
        }
    </style>
</head>

<body>

<div class="box">

    <h1>OUTLIERS - Gemini AI Test</h1>

    <p>
        Testing connection between PHP and Gemini API...
    </p>

    <div class="response">

        <?php echo htmlspecialchars($response); ?>

    </div>

</div>

</body>
</html>