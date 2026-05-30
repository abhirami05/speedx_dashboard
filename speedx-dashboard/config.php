<?php
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'speedx_dashboard';
$username = 'root';
$password = '';

try {
     $pdo = new PDO("mysql:host=sql201.infinityfree.com;dbname=if0_42049613_speedx_dashboard", "if0_42049613", "9846294820Amma");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Function to check if user is logged in
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.html');
        exit();
    }
}

// Function to check user role access
function checkAccess($required_role) {
    if ($_SESSION['role'] != $required_role && 
        !in_array($_SESSION['role'], ['ceo', 'data_analyst_manager', 'audit_manager', 'mis_manager'])) {
        header('Location: dashboard.php');
        exit();
    }
}
?>
