<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

try {
     $pdo = new PDO("mysql:host=sql201.infinityfree.com;dbname=if0_42049613_speedx_dashboard", "if0_42049613", "9846294820Amma");
    
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'];
    $user_dept = $_SESSION['department'] ?? '';
    
    // Admin roles can see everything
    $admin_roles = ['ceo', 'data_analyst_manager', 'audit_manager', 'mis_manager'];
    
    if (in_array($user_role, $admin_roles)) {
        // Show ALL departments
        $stmt = $pdo->query("SELECT * FROM departments ORDER BY name");
    } else {
        // Show user's primary department AND any granted departments
        $stmt = $pdo->prepare("
            SELECT DISTINCT d.* 
            FROM departments d
            LEFT JOIN department_access da ON d.id = da.department_id AND da.user_id = ?
            WHERE d.name = ? OR da.user_id = ?
            ORDER BY d.name
        ");
        $stmt->execute([$user_id, $user_dept, $user_id]);
    }
    
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($departments);
    
} catch(PDOException $e) {
    echo json_encode([]);
}
?>