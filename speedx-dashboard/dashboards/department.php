<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.html');
    exit();
}

$dept_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

try {
     $pdo = new PDO("mysql:host=sql201.infinityfree.com;dbname=if0_42049613_speedx_dashboard", "if0_42049613", "9846294820Amma");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed");
}

// Get department info
$stmt = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
$stmt->execute([$dept_id]);
$department = $stmt->fetch();

if (!$department) {
    header('Location: ../dashboard.php');
    exit();
}

// Access control
$user_role = $_SESSION['role'];
$user_dept = $_SESSION['department'] ?? '';
$admin_roles = ['ceo', 'data_analyst_manager', 'audit_manager', 'mis_manager'];
$has_access = false;

if (in_array($user_role, $admin_roles)) {
    $has_access = true;
}
if ($user_dept == $department['name']) {
    $has_access = true;
}

if (!$has_access) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM department_access WHERE user_id = ? AND department_id = ?");
    $stmt->execute([$_SESSION['user_id'], $dept_id]);
    if ($stmt->fetchColumn() > 0) {
        $has_access = true;
    }
}

if (!$has_access) {
    header('Location: ../dashboard.php?error=access_denied');
    exit();
}

$user_name = $_SESSION['full_name'];
$dept_name = $department['name'];

// ==========================================
// SMART ROUTING: Check if specific file exists
// ==========================================
$dept_file = strtolower(str_replace(' ', '_', $dept_name)) . '.php';
$specific_file = __DIR__ . '/' . $dept_file;

// If a dedicated dashboard file exists for this department, redirect to it
if (file_exists($specific_file) && $dept_file !== 'department.php') {
    header('Location: ' . $dept_file . '?id=' . $dept_id);
    exit();
}

// ==========================================
// DEPARTMENT PROFILES (Define BEFORE using)
// ==========================================
$department_profiles = [
    'Operations' => [
        'label' => 'Operations Command',
        'color' => '#1a56db',
        'icon' => '🚚',
        'kpis' => ['Orders Processed', 'Active Riders', 'Store Performance', 'Completion Rate'],
        'focus' => ['Order fulfillment optimization', 'Rider allocation efficiency', 'Peak hour management', 'Delivery time reduction']
    ],
    'Delivery' => [
        'label' => 'Delivery Control',
        'color' => '#48bb78',
        'icon' => '🛵',
        'kpis' => ['Deliveries Today', 'On-Time Rate', 'Avg Delivery Time', 'Rider Rating'],
        'focus' => ['Last-mile optimization', 'Rider performance tracking', 'Route efficiency', 'Customer satisfaction']
    ],
    'Inventory' => [
        'label' => 'Inventory Control',
        'color' => '#ed8936',
        'icon' => '📦',
        'kpis' => ['Total SKUs', 'Low Stock Items', 'Out of Stock', 'Inventory Accuracy'],
        'focus' => ['Stock level monitoring', 'Reorder automation', 'Warehouse utilization', 'Expiry tracking']
    ],
    'Finance' => [
        'label' => 'Finance Center',
        'color' => '#38a169',
        'icon' => '💰',
        'kpis' => ['Daily Revenue', 'Net Profit', 'Avg Order Value', 'Cash Flow'],
        'focus' => ['Revenue growth tracking', 'Cost optimization', 'Profit margin analysis', 'Budget planning']
    ],
    'Marketing' => [
        'label' => 'Marketing Hub',
        'color' => '#9f7aea',
        'icon' => '📢',
        'kpis' => ['New Customers', 'CAC', 'Campaign ROI', 'Engagement Rate'],
        'focus' => ['Customer acquisition', 'Campaign performance', 'Brand awareness', 'Retention strategies']
    ],
    'Vendor' => [
        'label' => 'Vendor Portal',
        'color' => '#3182ce',
        'icon' => '🤝',
        'kpis' => ['Active Vendors', 'On-Time Delivery', 'Quality Score', 'Order Volume'],
        'focus' => ['Vendor performance', 'Procurement optimization', 'Contract management', 'Quality assurance']
    ],
    'HR' => [
        'label' => 'HR Dashboard',
        'color' => '#d53f8c',
        'icon' => '👥',
        'kpis' => ['Total Employees', 'New Hires', 'Attendance Rate', 'Satisfaction'],
        'focus' => ['Talent acquisition', 'Employee engagement', 'Training programs', 'Retention strategies']
    ],
    'Sales' => [
        'label' => 'Sales Command',
        'color' => '#dd6b20',
        'icon' => '💼',
        'kpis' => ['Total Revenue', 'Deals Closed', 'Conversion Rate', 'Avg Deal Size'],
        'focus' => ['Revenue targets', 'Pipeline management', 'Client relationships', 'Market expansion']
    ],
    'Customer Support' => [
        'label' => 'Support Center',
        'color' => '#2c5282',
        'icon' => '🎧',
        'kpis' => ['Tickets Today', 'Resolution Rate', 'Avg Response Time', 'CSAT Score'],
        'focus' => ['Ticket resolution', 'Customer satisfaction', 'Response time optimization', 'Quality monitoring']
    ],
];

// Get profile (with fallback)
$profile = $department_profiles[$dept_name] ?? [
    'label' => 'Department',
    'color' => '#4c51bf',
    'icon' => '📊',
    'kpis' => ['Performance', 'Tasks Done', 'Growth', 'Rating'],
    'focus' => ['Performance tracking', 'Task management', 'Team collaboration', 'Goal achievement']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpeedX - <?php echo htmlspecialchars($dept_name); ?> Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; color: #2d3748; }
        
        .top-header {
            background: linear-gradient(135deg, <?php echo $profile['color']; ?>, #1a202c);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .top-header h1 { font-size: 24px; margin-bottom: 4px; }
        .top-header small { opacity: 0.85; }
        .btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            margin-left: 10px;
            display: inline-block;
            transition: background 0.3s;
        }
        .btn:hover { background: rgba(255,255,255,0.3); }
        
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        
        /* KPI Cards */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .kpi-card {
            background: white;
            padding: 28px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            text-align: center;
            border-top: 4px solid <?php echo $profile['color']; ?>;
            transition: transform 0.3s;
        }
        .kpi-card:hover { transform: translateY(-5px); }
        .kpi-icon { font-size: 32px; margin-bottom: 10px; }
        .kpi-value {
            font-size: 32px;
            font-weight: bold;
            color: <?php echo $profile['color']; ?>;
        }
        .kpi-label { color: #718096; font-size: 13px; margin-top: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
        
        /* Cards */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .card h2 {
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            font-size: 18px;
        }
        
        /* Focus Grid */
        .focus-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .focus-item {
            padding: 20px;
            background: #f7fafc;
            border-left: 4px solid <?php echo $profile['color']; ?>;
            border-radius: 8px;
            font-weight: 500;
            color: #4a5568;
            transition: background 0.3s;
        }
        .focus-item:hover { background: #edf2f7; }
        
        /* Info Box */
        .info-box {
            padding: 24px;
            color: #4a5568;
            line-height: 1.6;
        }
        .info-box h3 { color: #2d3748; margin-bottom: 10px; }
        
        /* Welcome Section */
        .welcome-card {
            text-align: center;
            padding: 40px;
        }
        .welcome-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .kpi-row { grid-template-columns: repeat(2, 1fr); }
            .dashboard-grid { grid-template-columns: 1fr; }
            .focus-grid { grid-template-columns: 1fr; }
            .btn { margin: 8px 8px 0 0; }
        }
    </style>
</head>
<body>
    <div class="top-header">
        <div>
            <h1><?php echo $profile['icon']; ?> <?php echo htmlspecialchars($profile['label']); ?></h1>
            <small><?php echo htmlspecialchars($dept_name); ?> Department • <?php echo htmlspecialchars($department['description'] ?? ''); ?></small>
        </div>
        <div>
            <a href="../dashboard.php" class="btn">🏠 Main Dashboard</a>
            <a href="../logout.php" class="btn">🚪 Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- KPI Row -->
        <div class="kpi-row">
            <?php foreach ($profile['kpis'] as $index => $label): 
                $icons = ['📊', '✅', '📈', '⭐'];
            ?>
                <div class="kpi-card">
                    <div class="kpi-icon"><?php echo $icons[$index]; ?></div>
                    <div class="kpi-value">--</div>
                    <div class="kpi-label"><?php echo htmlspecialchars($label); ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Overview & Focus -->
        <div class="dashboard-grid">
            <!-- Welcome Card -->
            <div class="card">
                <h2>📋 Department Overview</h2>
                <div class="info-box welcome-card">
                    <div class="welcome-icon"><?php echo $profile['icon']; ?></div>
                    <h3><?php echo htmlspecialchars($dept_name); ?> Department</h3>
                    <p><?php echo htmlspecialchars($department['description'] ?? 'Department Dashboard'); ?></p>
                    <p style="margin-top:16px;">Welcome, <strong><?php echo htmlspecialchars($user_name); ?></strong></p>
                    <p style="font-size:13px;color:#718096;margin-top:5px;">Role: <?php echo ucfirst(str_replace('_', ' ', $user_role)); ?></p>
                </div>
            </div>
            
            <!-- Focus Areas -->
            <div class="card">
                <h2>🎯 Dashboard Focus Areas</h2>
                <div class="focus-grid">
                    <?php foreach ($profile['focus'] as $item): ?>
                        <div class="focus-item">✅ <?php echo htmlspecialchars($item); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Connect Data Notice -->
        <div class="card" style="text-align:center;">
            <h2>🔗 Connect Your Data</h2>
            <div class="info-box">
                <p style="font-size:48px;margin-bottom:15px;">📊</p>
                <p>This dashboard is ready to display live metrics.</p>
                <p style="font-size:14px;color:#718096;margin-top:8px;">
                    Connect your <?php echo htmlspecialchars($dept_name); ?> data tables or sync from Excel/Google Sheets to populate real-time values.
                </p>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-refresh every 60 seconds
        setInterval(() => location.reload(), 60000);
    </script>
</body>
</html>