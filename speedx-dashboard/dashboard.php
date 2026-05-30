<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.html');
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
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Get departments based on user role
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
    $department_ids = [];
    foreach ($departments as $dept) {
        $department_ids[$dept['name']] = $dept['id'];
    }
    
} catch(PDOException $e) {
    $departments = [];
    $department_ids = [];
}

function q1($pdo, $sql, $params = []) {
    try { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
    catch(Exception $e) { return 0; }
}
function qAll($pdo, $sql, $params = []) {
    try { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    catch(Exception $e) { return []; }
}

$today = date('Y-m-d');
$month_start = date('Y-m-01');

// ============ SALES METRICS ============
$today_orders = q1($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE STR_TO_DATE(order_date, '%d-%m-%Y %H:%i') >= ?", [$today.' 00:00:00']);
$today_revenue = q1($pdo, "SELECT COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) FROM speedx_orders WHERE STR_TO_DATE(order_date, '%d-%m-%Y %H:%i') >= ?", [$today.' 00:00:00']);
$month_revenue = q1($pdo, "SELECT COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) FROM speedx_orders WHERE STR_TO_DATE(order_date, '%d-%m-%Y %H:%i') >= ?", [$month_start.' 00:00:00']);
$total_customers = q1($pdo, "SELECT COUNT(DISTINCT customer_id) FROM speedx_orders");
$today_delivered = q1(
    $pdo,
    "SELECT COUNT(*) 
     FROM speedx_orders 
     WHERE actual_delivery_time IS NOT NULL
     AND DATE(STR_TO_DATE(actual_delivery_time, '%d-%m-%Y %H:%i')) = ?",
    [$today]
);$today_delayed = q1($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE (delivery_status LIKE '%Delayed%' OR delivery_status LIKE '%delayed%') AND STR_TO_DATE(order_date, '%d-%m-%Y %H:%i') >= ?", [$today.' 00:00:00']);

// ============ INVENTORY METRICS ============
$inventory_value = q1($pdo, "SELECT COALESCE(SUM(inventory_value),0) FROM inventory");
$total_stock = q1($pdo, "SELECT COALESCE(SUM(current_stock),0) FROM inventory");
$low_stock = q1($pdo, "SELECT COUNT(*) FROM inventory WHERE current_stock <= reorder_level AND current_stock > 0");
$out_of_stock = q1($pdo, "SELECT COUNT(*) FROM inventory WHERE current_stock = 0");
$active_suppliers = q1($pdo, "SELECT COUNT(*) FROM suppliers WHERE supplier_status = 'ACTIVE'");
$pending_pos = q1($pdo, "SELECT COUNT(*) FROM purchase_orders WHERE po_status IN ('PENDING','APPROVED')");

// ============ FINANCE METRICS ============
$month_income = q1($pdo, "SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE transaction_type = 'CREDIT' AND transaction_date >= ?", [$month_start]);
$month_expenses = q1($pdo, "SELECT COALESCE(SUM(amount),0) FROM finance_transactions WHERE transaction_type = 'DEBIT' AND transaction_date >= ?", [$month_start]);
$cash_balance = q1($pdo, "SELECT closing_balance FROM finance_cashflow ORDER BY cashflow_date DESC LIMIT 1");
$pending_receivables = q1($pdo, "SELECT COALESCE(SUM(amount - paid_amount),0) FROM finance_invoices WHERE status IN ('PENDING','PARTIALLY_PAID','OVERDUE')");

// ============ HR METRICS ============
$total_employees = q1($pdo, "SELECT COUNT(*) FROM employees WHERE employee_status != 'TERMINATED'");
$active_employees = q1($pdo, "SELECT COUNT(*) FROM employees WHERE employee_status = 'ACTIVE'");
$new_hires_month = q1($pdo, "SELECT COUNT(*) FROM employees WHERE joining_date >= ?", [$month_start]);
$attrition_month = q1($pdo, "SELECT COUNT(*) FROM attrition WHERE resignation_date >= ?", [$month_start]);
$attendance_today = q1($pdo, "SELECT COUNT(*) FROM attendance WHERE attendance_date = ? AND attendance_status = 'PRESENT'", [$today]);
$total_attendance_today = q1($pdo, "SELECT COUNT(*) FROM attendance WHERE attendance_date = ?", [$today]);
$attendance_rate = $total_attendance_today > 0 ? round(($attendance_today/$total_attendance_today)*100,1) : 0;

// ============ MARKETING METRICS ============
$active_campaigns = q1($pdo, "SELECT COUNT(*) FROM marketing_campaigns WHERE status = 'ACTIVE'");
$month_marketing_spend = q1($pdo, "SELECT COALESCE(SUM(spend),0) FROM campaign_performance WHERE report_date >= ?", [$month_start]);
$month_marketing_revenue = q1($pdo, "SELECT COALESCE(SUM(revenue_generated),0) FROM campaign_performance WHERE report_date >= ?", [$month_start]);
$new_customers_month = q1($pdo, "SELECT COUNT(*) FROM customer_acquisition WHERE acquisition_date >= ?", [$month_start]);

// ============ TREND DATA ============
$daily_revenue_7 = qAll($pdo, "
    SELECT DATE(STR_TO_DATE(order_date, '%d-%m-%Y %H:%i')) as dt,
        COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) as revenue
    FROM speedx_orders 
    WHERE STR_TO_DATE(order_date, '%d-%m-%Y %H:%i') >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(STR_TO_DATE(order_date, '%d-%m-%Y %H:%i'))
    ORDER BY dt
");

$monthly_revenue_6 = qAll($pdo, "
    SELECT DATE_FORMAT(STR_TO_DATE(order_date, '%d-%m-%Y %H:%i'), '%b %Y') as month,
        COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) as revenue
    FROM speedx_orders 
    WHERE STR_TO_DATE(order_date, '%d-%m-%Y %H:%i') >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(STR_TO_DATE(order_date, '%d-%m-%Y %H:%i'), '%Y-%m')
    ORDER BY month
");

$dept_employees = qAll($pdo, "
    SELECT department, COUNT(*) as cnt FROM employees WHERE employee_status = 'ACTIVE' GROUP BY department ORDER BY cnt DESC LIMIT 6
");

$stock_summary = [
    ['label' => 'In Stock', 'count' => (int)q1($pdo, "SELECT COUNT(*) FROM inventory WHERE current_stock > reorder_level"), 'color' => '#48bb78'],
    ['label' => 'Low Stock', 'count' => (int)$low_stock, 'color' => '#f6ad55'],
    ['label' => 'Out of Stock', 'count' => (int)$out_of_stock, 'color' => '#e53e3e'],
];



$recent_orders = qAll($pdo, "
    SELECT order_id, customer_id, order_date, order_total, delivery_status
    FROM speedx_orders ORDER BY STR_TO_DATE(order_date, '%d-%m-%Y %H:%i') DESC LIMIT 8
");

function getDepartmentConfig($dept_name) {
    $defaults = [
        'Operations' => ['icon' => '🏭', 'color' => '#3b82f6'],
        'Delivery' => ['icon' => '🚚', 'color' => '#10b981'],
        'Inventory' => ['icon' => '📦', 'color' => '#f59e0b'],
        'Finance' => ['icon' => '💰', 'color' => '#8b5cf6'],
        'Marketing' => ['icon' => '📈', 'color' => '#ec4899'],
        'Vendor' => ['icon' => '🤝', 'color' => '#06b6d4'],
        'HR' => ['icon' => '👥', 'color' => '#ef4444'],
        'Sales' => ['icon' => '📊', 'color' => '#f97316'],
        'Customer Support' => ['icon' => '🎧', 'color' => '#14b8a6'],
        'IT' => ['icon' => '💻', 'color' => '#6366f1'],
    ];
    return $defaults[$dept_name] ?? ['icon' => '📊', 'color' => '#667eea'];
}

$user_initials = '';
$name_parts = explode(' ', $user_name);
foreach ($name_parts as $part) {
    $user_initials .= strtoupper(substr($part, 0, 1));
}
$user_initials = substr($user_initials, 0, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpeedX - Company Overview</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f7f9fc;
            display: flex;
            overflow-x: hidden;
        }

        /* ============ SIDEBAR ============ */
        .sidebar {
            width: 280px;
            height: 100vh;
            background: #0f172a;
            color: #e2e8f0;
            position: fixed;
            left: 0;
            top: 0;
            overflow-y: auto;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
        }

        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: #1e293b; }
        .sidebar::-webkit-scrollbar-thumb { background: #475569; border-radius: 5px; }

        .sidebar-header {
            padding: 28px 24px;
            background: linear-gradient(135deg, #1e293b, #0f172a);
            text-align: left;
            border-bottom: 1px solid #1e293b;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-icon {
            font-size: 32px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .sidebar-header .logo {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .sidebar-header .company-name {
            font-size: 10px;
            opacity: 0.6;
            margin-top: 6px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            font-weight: 500;
        }

        .user-profile {
            padding: 24px;
            background: rgba(30, 41, 59, 0.5);
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 1px solid #1e293b;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3);
        }

        .user-info .name {
            font-weight: 700;
            font-size: 15px;
            color: white;
            margin-bottom: 4px;
        }

        .user-info .role-text {
            font-size: 11px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 500;
        }

        .sidebar-nav {
            flex: 1;
            padding: 20px 0;
        }

        .nav-section {
            margin-bottom: 28px;
        }

        .section-label {
            padding: 8px 24px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 24px;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.25s;
            cursor: pointer;
            border-left: 3px solid transparent;
            font-size: 14px;
            font-weight: 500;
            margin: 2px 0;
        }

        .nav-item:hover {
            background: rgba(59, 130, 246, 0.1);
            color: white;
            border-left-color: #3b82f6;
        }

        .nav-item.active {
            background: rgba(59, 130, 246, 0.15);
            color: white;
            border-left-color: #3b82f6;
            font-weight: 600;
        }

        .nav-item .nav-icon {
            font-size: 18px;
            width: 24px;
            text-align: center;
        }

        .sidebar-footer {
            padding: 20px 24px;
            border-top: 1px solid #1e293b;
            background: #0f172a;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 12px 16px;
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }

        .logout-btn:hover {
            background: #ef4444;
            color: white;
            border-color: #ef4444;
        }

        /* ============ MAIN CONTENT ============ */
        .main-content {
            margin-left: 280px;
            flex: 1;
            min-height: 100vh;
            width: calc(100% - 280px);
        }

        .top-bar {
            background: white;
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }

        .page-title {
            font-size: 24px;
            color: #0f172a;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title i {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .live-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #10b981;
            font-weight: 600;
            background: rgba(16, 185, 129, 0.1);
            padding: 8px 16px;
            border-radius: 30px;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .content-area {
            padding: 28px 32px;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-radius: 20px;
            padding: 28px 32px;
            margin-bottom: 28px;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.2), transparent);
            border-radius: 50%;
        }

        .welcome-banner h2 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .welcome-banner p {
            color: #94a3b8;
            font-size: 14px;
        }

        /* Section Title */
        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 28px 0 16px 0;
            padding-bottom: 8px;
            border-bottom: 3px solid #3b82f6;
            display: inline-block;
        }

        /* KPI Cards */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .kpi-card {
            background: white;
            padding: 18px 16px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #667eea;
            text-align: center;
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
        }

        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .kpi-card.green { border-left-color: #10b981; }
        .kpi-card.red { border-left-color: #ef4444; }
        .kpi-card.blue { border-left-color: #3b82f6; }
        .kpi-card.orange { border-left-color: #f59e0b; }
        .kpi-card.purple { border-left-color: #8b5cf6; }

        .kpi-icon { font-size: 28px; margin-bottom: 8px; }
        .kpi-value { font-size: 28px; font-weight: 800; color: #0f172a; }
        .kpi-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; font-weight: 600; }

        /* Grids */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
        }

        .card h3 {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card canvas {
            max-height: 220px;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        th {
            background: #f8fafc;
            padding: 10px 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 700;
            color: #475569;
        }

        td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
        }

        tr:hover { background: #f8fafc; }

        .badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .badge-yellow { background: #fef3c7; color: #92400e; }

        .text-green { color: #10b981; font-weight: 700; }

        .scrollable {
            max-height: 240px;
            overflow-y: auto;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .kpi-row { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { width: 100%; height: auto; position: relative; transform: translateX(-100%); position: fixed; }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; width: 100%; }
            .grid-2 { grid-template-columns: 1fr; }
            .kpi-row { grid-template-columns: repeat(2, 1fr); }
            .content-area { padding: 20px; }
        }
        @media (max-width: 480px) {
            .kpi-row { grid-template-columns: 1fr; }
            .page-title { font-size: 18px; }
        }
    </style>
</head>
<body>
    <!-- ============ SIDEBAR ============ -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <i class="fas fa-bolt logo-icon"></i>
                <div class="logo">SpeedX</div>
            </div>
            <div class="company-name">Quick Commerce Platform</div>
        </div>
        
        <div class="user-profile">
            <div class="user-avatar"><?php echo $user_initials; ?></div>
            <div class="user-info">
                <div class="name"><?php echo htmlspecialchars($user_name); ?></div>
                <div class="role-text"><i class="fas fa-shield-alt"></i> <?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></div>
            </div>
        </div>
        
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="section-label"><i class="fas fa-chart-line"></i> Main</div>
                <a href="company_overview.php" class="nav-item active">
                    <span class="nav-icon"><i class="fas fa-home"></i></span> Company Overview
                </a>
            </div>
            
            <div class="nav-section">
                <div class="section-label"><i class="fas fa-building"></i> Departments</div>
                <?php foreach ($departments as $dept): 
                    $config = getDepartmentConfig($dept['name']);
                ?>
                <a href="dashboards/department.php?id=<?php echo $dept['id']; ?>" class="nav-item">
                    <span class="nav-icon"><?php echo $config['icon']; ?></span>
                    <?php echo htmlspecialchars($dept['name']); ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (in_array($user_role, ['ceo', 'mis_manager'])): ?>
            <div class="nav-section">
                <div class="section-label"><i class="fas fa-cog"></i> Admin</div>
                <a href="../admin.php" class="nav-item"><span class="nav-icon"><i class="fas fa-users"></i></span> User Management</a>
                <a href="../manage_departments.php" class="nav-item"><span class="nav-icon"><i class="fas fa-building"></i></span> Manage Departments</a>
            </div>
            <?php endif; ?>
        </nav>
        
        <div class="sidebar-footer">
            <button class="logout-btn" onclick="logout()"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>
    </aside>
    
    <!-- ============ MAIN CONTENT ============ -->
    <main class="main-content">
        <div class="top-bar">
            <h1 class="page-title"><i class="fas fa-chart-pie"></i> Company Overview</h1>
            <div class="live-indicator"><div class="live-dot"></div><span>Live • <?php echo date('d M Y, h:i A'); ?></span></div>
        </div>
        
        <div class="content-area">
            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <h2>Welcome back, <?php echo htmlspecialchars(explode(' ', $user_name)[0]); ?>! 👋</h2>
                <p>Here's your real-time business performance snapshot across all departments.</p>
            </div>
            
            <!-- Sales Section -->
            <div class="section-title">📊 Sales & Orders</div>
            <div class="kpi-row">
                <a href="sales_dashboard.php" class="kpi-card green"><div class="kpi-icon">💰</div><div class="kpi-value">₹<?php echo number_format($today_revenue,0); ?></div><div class="kpi-label">Today's Revenue</div></a>
                <a href="sales_dashboard.php" class="kpi-card blue"><div class="kpi-icon">📦</div><div class="kpi-value"><?php echo number_format($today_orders); ?></div><div class="kpi-label">Today's Orders</div></a>
                <a href="sales_dashboard.php" class="kpi-card"><div class="kpi-icon">📈</div><div class="kpi-value">₹<?php echo number_format($month_revenue,0); ?></div><div class="kpi-label">Month Revenue</div></a>
                <a href="sales_dashboard.php" class="kpi-card"><div class="kpi-icon">👥</div><div class="kpi-value"><?php echo number_format($total_customers); ?></div><div class="kpi-label">Total Customers</div></a>
                <a href="sales_dashboard.php" class="kpi-card green"><div class="kpi-icon">✅</div><div class="kpi-value"><?php echo $today_delivered; ?></div><div class="kpi-label">Delivered Today</div></a>
                <a href="sales_dashboard.php" class="kpi-card <?php echo $today_delayed>0?'red':'green'; ?>"><div class="kpi-icon">⚠️</div><div class="kpi-value"><?php echo $today_delayed; ?></div><div class="kpi-label">Delayed Today</div></a>
            </div>
            
            <!-- Charts -->
            <div class="grid-2">
                <div class="card"><h3><i class="fas fa-chart-line"></i> Revenue Trend (7 Days)</h3><canvas id="chartRevenue7"></canvas></div>
                <div class="card"><h3><i class="fas fa-chart-bar"></i> Monthly Revenue (6 Months)</h3><canvas id="chartMonthly"></canvas></div>
            </div>
            
            <!-- Inventory Section -->
            <div class="section-title">📦 Inventory</div>
            <div class="kpi-row">
                <a href="inventory_dashboard.php" class="kpi-card"><div class="kpi-icon">💰</div><div class="kpi-value">₹<?php echo number_format($inventory_value,0); ?></div><div class="kpi-label">Inventory Value</div></a>
                <a href="inventory_dashboard.php" class="kpi-card blue"><div class="kpi-icon">📦</div><div class="kpi-value"><?php echo number_format($total_stock); ?></div><div class="kpi-label">Total Stock</div></a>
                <a href="inventory_dashboard.php" class="kpi-card orange"><div class="kpi-icon">⚠️</div><div class="kpi-value"><?php echo $low_stock; ?></div><div class="kpi-label">Low Stock</div></a>
                <a href="inventory_dashboard.php" class="kpi-card red"><div class="kpi-icon">❌</div><div class="kpi-value"><?php echo $out_of_stock; ?></div><div class="kpi-label">Out of Stock</div></a>
                <a href="inventory_dashboard.php" class="kpi-card green"><div class="kpi-icon">🤝</div><div class="kpi-value"><?php echo $active_suppliers; ?></div><div class="kpi-label">Active Suppliers</div></a>
                <a href="inventory_dashboard.php" class="kpi-card"><div class="kpi-icon">📋</div><div class="kpi-value"><?php echo $pending_pos; ?></div><div class="kpi-label">Pending POs</div></a>
            </div>
            
            <div class="grid-2">
                <div class="card"><h3><i class="fas fa-chart-pie"></i> Stock Status</h3><canvas id="chartStock"></canvas></div>
            </div>
            
            <!-- Finance Section -->
            <div class="section-title">💰 Finance</div>
            <div class="kpi-row">
                <a href="finance_dashboard.php" class="kpi-card green"><div class="kpi-icon">📈</div><div class="kpi-value">₹<?php echo number_format($month_income,0); ?></div><div class="kpi-label">Month Income</div></a>
                <a href="finance_dashboard.php" class="kpi-card red"><div class="kpi-icon">📉</div><div class="kpi-value">₹<?php echo number_format($month_expenses,0); ?></div><div class="kpi-label">Month Expenses</div></a>
                <a href="finance_dashboard.php" class="kpi-card blue"><div class="kpi-icon">🏦</div><div class="kpi-value">₹<?php echo number_format($cash_balance,0); ?></div><div class="kpi-label">Cash Balance</div></a>
                <a href="finance_dashboard.php" class="kpi-card orange"><div class="kpi-icon">📋</div><div class="kpi-value">₹<?php echo number_format($pending_receivables,0); ?></div><div class="kpi-label">Receivables</div></a>
                <a href="finance_dashboard.php" class="kpi-card green"><div class="kpi-icon">💵</div><div class="kpi-value">₹<?php echo number_format($month_income - $month_expenses,0); ?></div><div class="kpi-label">Net Profit</div></a>
                <a href="finance_dashboard.php" class="kpi-card"><div class="kpi-icon">📊</div><div class="kpi-value"><?php echo $month_expenses>0?round(($month_income/$month_expenses),1):0; ?>x</div><div class="kpi-label">Profit Ratio</div></a>
            </div>
            
            <!-- HR Section -->
            <div class="section-title">👥 Human Resources</div>
            <div class="kpi-row">
                <a href="hr_dashboard.php" class="kpi-card"><div class="kpi-icon">👥</div><div class="kpi-value"><?php echo $total_employees; ?></div><div class="kpi-label">Total Employees</div></a>
                <a href="hr_dashboard.php" class="kpi-card green"><div class="kpi-icon">✅</div><div class="kpi-value"><?php echo $active_employees; ?></div><div class="kpi-label">Active</div></a>
                <a href="hr_dashboard.php" class="kpi-card blue"><div class="kpi-icon">🆕</div><div class="kpi-value"><?php echo $new_hires_month; ?></div><div class="kpi-label">New Hires</div></a>
                <a href="hr_dashboard.php" class="kpi-card orange"><div class="kpi-icon">🚪</div><div class="kpi-value"><?php echo $attrition_month; ?></div><div class="kpi-label">Attrition</div></a>
                <a href="hr_dashboard.php" class="kpi-card green"><div class="kpi-icon">📋</div><div class="kpi-value"><?php echo $attendance_rate; ?>%</div><div class="kpi-label">Attendance</div></a>
                <a href="hr_dashboard.php" class="kpi-card purple"><div class="kpi-icon">🏢</div><div class="kpi-value"><?php echo count($dept_employees); ?></div><div class="kpi-label">Departments</div></a>
            </div>
            
            <div class="grid-2">
                <div class="card"><h3><i class="fas fa-chart-bar"></i> Employees by Department</h3><canvas id="chartDept"></canvas></div>
                <div class="card"><h3><i class="fas fa-clock"></i> Recent Orders</h3><div class="scrollable"><table><thead><tr><th>Order ID</th><th>Customer</th><th>Amount</th><th>Status</th></tr></thead><tbody><?php foreach($recent_orders as $o): $badge='badge-yellow'; if($o['delivery_status']=='Delivered')$badge='badge-green'; if(stripos($o['delivery_status'],'Delayed')!==false)$badge='badge-red'; ?><tr><td>#<?php echo htmlspecialchars($o['order_id']); ?></td><td><?php echo htmlspecialchars($o['customer_id']); ?></td><td>₹<?php echo htmlspecialchars($o['order_total']); ?></td><td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($o['delivery_status']); ?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
            </div>
            
            <!-- Marketing Section -->
            <div class="section-title">📢 Marketing</div>
            <div class="kpi-row">
                <a href="marketing_dashboard.php" class="kpi-card green"><div class="kpi-icon">📢</div><div class="kpi-value"><?php echo $active_campaigns; ?></div><div class="kpi-label">Active Campaigns</div></a>
                <a href="marketing_dashboard.php" class="kpi-card red"><div class="kpi-icon">💸</div><div class="kpi-value">₹<?php echo number_format($month_marketing_spend,0); ?></div><div class="kpi-label">Marketing Spend</div></a>
                <a href="marketing_dashboard.php" class="kpi-card green"><div class="kpi-icon">💰</div><div class="kpi-value">₹<?php echo number_format($month_marketing_revenue,0); ?></div><div class="kpi-label">Campaign Revenue</div></a>
                <a href="marketing_dashboard.php" class="kpi-card blue"><div class="kpi-icon">👥</div><div class="kpi-value"><?php echo $new_customers_month; ?></div><div class="kpi-label">New Customers</div></a>
                <a href="marketing_dashboard.php" class="kpi-card"><div class="kpi-icon">🎯</div><div class="kpi-value"><?php echo $month_marketing_spend>0?round($month_marketing_revenue/$month_marketing_spend,1):0; ?>x</div><div class="kpi-label">ROAS</div></a>

            </div>
        </div>
    </main>
    
    <script>
        // Logout function
        function logout() {
            fetch('logout.php')
                .then(() => window.location.href = 'index.html');
        }
        
    
    document.addEventListener('DOMContentLoaded', function() {
        var d1 = <?php echo json_encode($daily_revenue_7); ?>;
        if(d1.length > 0) {
            new Chart(document.getElementById('chartRevenue7'), {
                type: 'bar',
                data: { labels: d1.map(x => x.dt), datasets: [{ label: 'Revenue', data: d1.map(x => x.revenue), backgroundColor: '#3b82f6', borderRadius: 6 }] },
                options: { responsive: true, plugins: { legend: { display: false } } }
            });
        }
        
        var d2 = <?php echo json_encode($monthly_revenue_6); ?>;
        if(d2.length > 0) {
            new Chart(document.getElementById('chartMonthly'), {
                type: 'line',
                data: { labels: d2.map(x => x.month), datasets: [{ label: 'Revenue', data: d2.map(x => x.revenue), borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.1)', tension: 0.3, fill: true }] },
                options: { responsive: true, plugins: { legend: { display: false } } }
            });
        }
        
        var d3 = <?php echo json_encode($stock_summary); ?>;
        new Chart(document.getElementById('chartStock'), {
            type: 'doughnut',
            data: { labels: d3.map(x => x.label + ' (' + x.count + ')'), datasets: [{ data: d3.map(x => x.count), backgroundColor: d3.map(x => x.color), borderWidth: 0 }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } } }
        });
        
        var d4 = <?php echo json_encode($dept_employees); ?>;
        if(d4.length > 0) {
            new Chart(document.getElementById('chartDept'), {
                type: 'bar',
                data: { labels: d4.map(x => x.department), datasets: [{ label: 'Employees', data: d4.map(x => x.cnt), backgroundColor: '#8b5cf6', borderRadius: 6 }] },
                options: { responsive: true, plugins: { legend: { display: false } } }
            });
        }
    });
    </script>
</body>
</html>