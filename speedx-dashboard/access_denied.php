<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access denied - SpeedX</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Arial, sans-serif; background: #f7fafc; color: #2d3748; }
        .panel { max-width: 440px; padding: 32px; background: white; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); text-align: center; }
        h1 { margin-bottom: 12px; color: #c53030; }
        p { color: #4a5568; line-height: 1.5; }
        a { display: inline-block; margin-top: 20px; padding: 10px 16px; border-radius: 6px; background: #667eea; color: white; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <main class="panel">
        <h1>Access denied</h1>
        <p>You do not have permission to view that department dashboard. Ask an admin to grant department access.</p>
        <a href="dashboard.php">Back to dashboard</a>
    </main>
</body>
</html>
