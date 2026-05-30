<?php
session_start();

// Database connection
$host = 'localhost';
$dbname = 'speedx_dashboard';
$dbuser = 'root';
$dbpass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die('Database connection failed');
}

// Get login data
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

// Find user
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND password = MD5(?)");
$stmt->execute([$username, $password]);
$user = $stmt->fetch();

if ($user) {
    // Login successful - SET SESSION
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['department'] = $user['department'];
    
    // Redirect to dashboard
    header('Location: dashboard.php');
    exit();
} else {
    // Failed - go back to login
    header('Location: index.html?error=1');
    exit();
}
?>
