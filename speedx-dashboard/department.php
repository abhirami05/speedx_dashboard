<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.html');
    exit();
}

$user_name = $_SESSION['full_name'];
$user_role = $_SESSION['role'];
$user_dept = $_SESSION['department'] ?? '';

$admin_roles = ['ceo', 'data_analyst_manager', 'audit_manager', 'mis_manager'];
$can_see_all = in_array($user_role, $admin_roles);

try {
    $pdo = new PDO("mysql:host=sql201.infinityfree.com;dbname=if0_42049613_speedx_dashboard", "if0_42049613", "9846294820Amma");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all departments
    if ($can_see_all) {
        $dept_stmt = $pdo->query("SELECT * FROM departments ORDER BY name");
    } else {
        $dept_stmt = $pdo->prepare("
            SELECT DISTINCT d.* 
            FROM departments d
            LEFT JOIN department_access da ON d.id = da.department_id AND da.user_id = ?
            WHERE d.name = ? OR da.user_id = ?
            ORDER BY d.name
        ");
        $dept_stmt->execute([$_SESSION['user_id'], $user_dept, $_SESSION['user_id']]);
    }
    $departments = $dept_stmt->fetchAll();
    
    // Get overview stats
    $total_orders = $pdo->query("SELECT COUNT(*) FROM operations_orders WHERE DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;
    $active_riders = $pdo->query("SELECT COUNT(*) FROM operations_fleet WHERE current_status IN ('online', 'busy')")->fetchColumn() ?: 0;
    $active_stores = $pdo->query("SELECT COUNT(*) FROM operations_stores WHERE status = 'active'")->fetchColumn() ?: 0;
    $today_revenue = $pdo->query("SELECT COALESCE(SUM(total_revenue),0) FROM operations_finance WHERE transaction_date = CURDATE()")->fetchColumn() ?: 0;
    
} catch(PDOException $e) {
    $total_orders = 0; $active_riders = 0; $active_stores = 0; $today_revenue = 0;
    $departments = [];
}

/**
 * AUTO-GENERATE department config from database
 * Only need to manually add icon and color for new departments
 */
function getDepartmentConfig($dept_name) {
    // Default icons and colors for known departments
    $defaults = [
        'Operations' => ['icon' => '🚚', 'color' => '#1a56db'],
        'Delivery' => ['icon' => '🛵', 'color' => '#48bb78'],
        'Inventory' => ['icon' => '📦', 'color' => '#ed8936'],
        'Finance' => ['icon' => '💰', 'color' => '#38a169'],
        'Marketing' => ['icon' => '📢', 'color' => '#9f7aea'],
        'Vendor' => ['icon' => '🤝', 'color' => '#3182ce'],
        'HR' => ['icon' => '👥', 'color' => '#d53f8c'],
        'Customer Support' => ['icon' => '🎧', 'color' => '#dd6b20'],
        'IT' => ['icon' => '💻', 'color' => '#2c5282'],
        'Legal' => ['icon' => '⚖️', 'color' => '#744210'],
        'R&D' => ['icon' => '🔬', 'color' => '#6b46c1'],
    ];
    
    if (isset($defaults[$dept_name])) {
        return $defaults[$dept_name];
    }
    
    // Auto-generate config for new departments
    return [
        'icon' => '📊',  // Default icon
        'color' => '#667eea',  // Default color
        'url' => 'dashboards/' . strtolower(str_replace(' ', '_', $dept_name)) . '.php'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpeedX - Company Dashboard</title>
    <style>
        /* ... your existing styles ... */
    </style>
</head>
<body>
    <aside class="sidebar">
        <!-- ... sidebar header and user profile ... -->
        
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="section-label">📊 Main</div>
                <a href="dashboard.php" class="nav-item active">
                    <span class="nav-icon">🏠</span> Overview Dashboard
                </a>
            </div>
            
            <div class="nav-section">
                <div class="section-label">📋 Departments</div>
                <?php foreach ($departments as $dept): 
                    $config = getDepartmentConfig($dept['name']);
                    // Auto-generate URL from department name
                    $url = 'dashboards/' . strtolower(str_replace(' ', '_', $dept['name'])) . '.php';
                ?>
                <a href="<?php echo $url; ?>" class="nav-item">
                    <span class="nav-icon"><?php echo $config['icon']; ?></span>
                    <?php echo htmlspecialchars($dept['name']); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </nav>
    </aside>
    
    <main class="main-content">
        <!-- ... overview cards ... -->
        
        <h2 class="section-title">📋 Department Dashboards</h2>
        <div class="dept-cards">
            <?php foreach ($departments as $dept): 
                $config = getDepartmentConfig($dept['name']);
                $url = 'dashboards/' . strtolower(str_replace(' ', '_', $dept['name'])) . '.php';
            ?>
            <a href="<?php echo $url; ?>" class="dept-card" style="border-left-color: <?php echo $config['color']; ?>;">
                <div class="dept-icon"><?php echo $config['icon']; ?></div>
                <h3><?php echo htmlspecialchars($dept['name']); ?></h3>
                <p><?php echo htmlspecialchars($dept['description'] ?? 'Department Dashboard'); ?></p>
            </a>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>