<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.html');
    exit();
}

$user_name = $_SESSION['full_name'];
$user_role = $_SESSION['role'];
$period = $_GET['period'] ?? 'ytd';

try {
     $pdo = new PDO("mysql:host=sql201.infinityfree.com;dbname=if0_42049613_speedx_dashboard", "if0_42049613", "9846294820Amma", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) { die("Connection failed"); }

// ==========================================
// DATE RANGES
// ==========================================
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

switch($period) {
    case 'today':
        $start = $today . ' 00:00:00';
        $end = $today . ' 23:59:59';
        $prev_start = date('Y-m-d', strtotime('-1 day')) . ' 00:00:00';
        $prev_end = date('Y-m-d', strtotime('-1 day')) . ' 23:59:59';
        $label = 'Today';
        break;
    case 'wtd':
        $start = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        $end = $today . ' 23:59:59';
        $prev_start = date('Y-m-d', strtotime('monday last week')) . ' 00:00:00';
        $prev_end = date('Y-m-d', strtotime('sunday last week')) . ' 23:59:59';
        $label = 'Week-to-Date';
        break;
    case 'mtd':
        $start = date('Y-m-01') . ' 00:00:00';
        $end = $today . ' 23:59:59';
        $prev_start = date('Y-m-01', strtotime('first day of last month')) . ' 00:00:00';
        $prev_end = date('Y-m-t', strtotime('last day of last month')) . ' 23:59:59';
        $label = 'Month-to-Date';
        break;
    default:
        $period = 'ytd';
        $start = date('Y-01-01') . ' 00:00:00';
        $end = $today . ' 23:59:59';
        $prev_start = date('Y-01-01', strtotime('-1 year')) . ' 00:00:00';
        $prev_end = date('Y-12-31', strtotime('-1 year')) . ' 23:59:59';
        $label = 'Year-to-Date';
}

// Overall (all time)
$overall_start = '2000-01-01 00:00:00';

// ==========================================
// HELPER FUNCTIONS
// ==========================================
function q($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn();
    } catch(Exception $e) { return 0; }
}
function qAll($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
    } catch(Exception $e) { return []; }
}
function pct($cur, $prev) {
    if ($prev == 0) return $cur > 0 ? 100 : 0;
    return round((($cur - $prev) / $prev) * 100, 1);
}

// ==========================================
// DATE FORMAT: DD-MM-YYYY HH:MM
// ==========================================
$dateCondition = "STR_TO_DATE(order_date, '%d-%m-%Y %H:%i')";

// ==========================================
// CURRENT PERIOD METRICS
// ==========================================
$curr_orders = q($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$start, $end]);
$curr_revenue = q($pdo, "SELECT COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(12,2))),0) FROM speedx_orders WHERE $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$start, $end]);
$curr_customers = q($pdo, "SELECT COUNT(DISTINCT customer_id) FROM speedx_orders WHERE $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$start, $end]);

// Delivery status grouping
$curr_on_time = q($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE delivery_status = 'On Time' AND $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$start, $end]);
$curr_slightly = q($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE delivery_status = 'Slightly Delayed' AND $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$start, $end]);
$curr_highly = q($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE delivery_status = 'Highly Delayed' AND $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$start, $end]);
$curr_significantly = q($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE delivery_status = 'Significantly Delayed' AND $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$start, $end]);
$curr_null_status = q($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE (delivery_status IS NULL OR delivery_status = '') AND $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$start, $end]);

// Total delayed
$curr_delayed_all = $curr_slightly + $curr_highly + $curr_significantly;

// ==========================================
// PREVIOUS PERIOD METRICS
// ==========================================
$prev_orders = q($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$prev_start, $prev_end]);
$prev_revenue = q($pdo, "SELECT COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(12,2))),0) FROM speedx_orders WHERE $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$prev_start, $prev_end]);
$prev_customers = q($pdo, "SELECT COUNT(DISTINCT customer_id) FROM speedx_orders WHERE $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$prev_start, $prev_end]);
$prev_on_time = q($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE delivery_status = 'On Time' AND $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$prev_start, $prev_end]);

// ==========================================
// OVERALL METRICS
// ==========================================
$overall_orders = q($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE $dateCondition >= STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$overall_start]);
$overall_revenue = q($pdo, "SELECT COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(12,2))),0) FROM speedx_orders WHERE $dateCondition >= STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')", [$overall_start]);

// ==========================================
// CALCULATED VALUES
// ==========================================
$avg_order = $curr_orders > 0 ? round($curr_revenue / $curr_orders, 2) : 0;
$prev_avg = $prev_orders > 0 ? round($prev_revenue / $prev_orders, 2) : 0;
$success_rate = $curr_orders > 0 ? round(($curr_on_time / $curr_orders) * 100, 1) : 0;
$prev_success = $prev_orders > 0 ? round(($prev_on_time / $prev_orders) * 100, 1) : 0;
$overall_avg = $overall_orders > 0 ? round($overall_revenue / $overall_orders, 2) : 0;

// ==========================================
// DELIVERY TIME CALCULATION
// Using actual_delivery_time - order_date in minutes
// ==========================================
$avg_delivery_time = q($pdo, "
    SELECT ROUND(AVG(
        TIMESTAMPDIFF(MINUTE, 
            STR_TO_DATE(order_date, '%d-%m-%Y %H:%i'), 
            STR_TO_DATE(actual_delivery_time, '%d-%m-%Y %H:%i')
        )
    ), 1) 
    FROM speedx_orders 
    WHERE $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')
    AND actual_delivery_time IS NOT NULL AND actual_delivery_time != ''
", [$start, $end]);

// Overall avg delivery time
$overall_avg_delivery = q($pdo, "
    SELECT ROUND(AVG(
        TIMESTAMPDIFF(MINUTE, 
            STR_TO_DATE(order_date, '%d-%m-%Y %H:%i'), 
            STR_TO_DATE(actual_delivery_time, '%d-%m-%Y %H:%i')
        )
    ), 1) 
    FROM speedx_orders 
    WHERE actual_delivery_time IS NOT NULL AND actual_delivery_time != ''
");

// ==========================================
// DELIVERY PARTNERS
// ==========================================
$total_partners = q($pdo, "SELECT COUNT(DISTINCT delivery_partner_id) FROM delivary_perfomance");
$curr_partners = q($pdo, "SELECT COUNT(DISTINCT delivery_partner_id) FROM speedx_orders WHERE $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND delivery_partner_id IS NOT NULL", [$start, $end]);

// ==========================================
// HOURLY DISTRIBUTION
// ==========================================
$hourly = qAll($pdo, "
    SELECT HOUR(STR_TO_DATE(order_date, '%d-%m-%Y %H:%i')) as hr, COUNT(*) as cnt
    FROM speedx_orders 
    WHERE $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')
    GROUP BY HOUR(STR_TO_DATE(order_date, '%d-%m-%Y %H:%i'))
    ORDER BY hr
", [$start, $end]);

// ==========================================
// TOP 10 SELLING PRODUCTS
// ==========================================
$top_products = qAll($pdo, "
    SELECT 
        soi.product_id,
        p.product_name,
        p.category,
        p.brand,
        p.price,
        SUM(CAST(REPLACE(IFNULL(soi.quantity,'0'),',','') AS UNSIGNED)) as total_qty,
        SUM(CAST(REPLACE(IFNULL(soi.unit_price,'0'),',','') AS DECIMAL(10,2)) * CAST(REPLACE(IFNULL(soi.quantity,'0'),',','') AS UNSIGNED)) as total_revenue
    FROM speedx_order_items soi
    JOIN speedx_orders so ON soi.order_id = so.order_id
    LEFT JOIN products p ON soi.product_id = p.product_id
    WHERE STR_TO_DATE(so.order_date, '%d-%m-%Y %H:%i') BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')
    GROUP BY soi.product_id, p.product_name, p.category, p.brand, p.price
    ORDER BY total_revenue DESC
    LIMIT 10
", [$start, $end]);

// ==========================================
// DELIVERY STATUS BREAKDOWN
// ==========================================
$status_breakdown = [
    ['status' => 'On Time', 'count' => (int)$curr_on_time, 'color' => '#48bb78'],
    ['status' => 'Slightly Delayed', 'count' => (int)$curr_slightly, 'color' => '#f6ad55'],
    ['status' => 'Highly Delayed', 'count' => (int)$curr_highly, 'color' => '#e53e3e'],
    ['status' => 'Significantly Delayed', 'count' => (int)$curr_significantly, 'color' => '#c53030'],
    ['status' => 'Unknown', 'count' => (int)$curr_null_status, 'color' => '#a0aec0'],
];

// ==========================================
// DAILY TREND
// ==========================================
$daily = qAll($pdo, "
    SELECT 
        STR_TO_DATE(order_date, '%d-%m-%Y %H:%i') as dt,
        COUNT(*) as orders,
        COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(12,2))),0) as revenue
    FROM speedx_orders 
    WHERE $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s')
    GROUP BY DATE(STR_TO_DATE(order_date, '%d-%m-%Y %H:%i'))
    ORDER BY dt
", [$start, $end]);

// Store performance
$stores = qAll($pdo, "
    SELECT store_id, COUNT(*) as orders, COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(12,2))),0) as revenue 
    FROM speedx_orders 
    WHERE $dateCondition BETWEEN STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s') AND store_id IS NOT NULL 
    GROUP BY store_id ORDER BY orders DESC LIMIT 5
", [$start, $end]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpeedX - Operations Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; }
        
        .top-header {
            background: linear-gradient(135deg, #1a202c, #2d3748);
            color: white;
            padding: 12px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .top-header h1 { font-size: 20px; }
        
        .period-tabs {
            display: flex;
            gap: 2px;
            background: rgba(255,255,255,0.1);
            padding: 3px;
            border-radius: 8px;
        }
        .period-tabs a {
            padding: 6px 14px;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 11px;
            transition: all 0.2s;
        }
        .period-tabs a.active { background: white; color: #1a202c; }
        .period-tabs a:hover:not(.active) { background: rgba(255,255,255,0.15); }
        
        .btn {
            background: rgba(255,255,255,0.15);
            color: white;
            padding: 7px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }
        
        .container { max-width: 1600px; margin: 12px auto; padding: 0 15px; }
        
        .period-info {
            background: white;
            padding: 10px 18px;
            border-radius: 8px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .period-info strong { color: #1a56db; }
        
        /* KPI */
        .kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 12px; }
        .kpi-card {
            background: white;
            padding: 15px 18px;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border-left: 3px solid #1a56db;
        }
        .kpi-card.green { border-left-color: #48bb78; }
        .kpi-card.red { border-left-color: #e53e3e; }
        .kpi-card.orange { border-left-color: #ed8936; }
        .kpi-card.purple { border-left-color: #9f7aea; }
        
        .kpi-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
        .kpi-icon { font-size: 20px; }
        .kpi-change {
            font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 8px; white-space: nowrap;
        }
        .change-up { background: #c6f6d5; color: #276749; }
        .change-down { background: #fed7d7; color: #9b2c2c; }
        .kpi-value { font-size: 24px; font-weight: bold; color: #1a202c; }
        .kpi-label { font-size: 9px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
        .kpi-prev { font-size: 9px; color: #a0aec0; }
        
        /* Grids */
        .charts-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 12px; }
        .charts-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .card h2 { font-size: 13px; color: #2d3748; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
        .card canvas { max-height: 250px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th { background: #f7fafc; padding: 7px 8px; text-align: left; font-size: 9px; text-transform: uppercase; color: #4a5568; letter-spacing: 0.5px; }
        td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
        tr:hover { background: #f7fafc; }
        
        .badge { padding: 1px 7px; border-radius: 6px; font-size: 9px; font-weight: 600; }
        .badge-green { background: #c6f6d5; color: #276749; }
        .badge-red { background: #fed7d7; color: #9b2c2c; }
        .badge-orange { background: #feebc8; color: #9c4221; }
        
        @media (max-width: 1200px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } .charts-3 { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 768px) { .kpi-row { grid-template-columns: 1fr; } .charts-3, .charts-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="top-header">
        <div>
            <h1>🚚 Operations Command Center</h1>
            <small style="opacity:0.7;">SpeedX Quick Commerce</small>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <div class="period-tabs">
                <a href="?period=overall">📊 Overall</a>
                <a href="?period=ytd" class="<?php echo $period=='ytd'?'active':''; ?>">📆 YTD</a>
                <a href="?period=mtd" class="<?php echo $period=='mtd'?'active':''; ?>">📅 MTD</a>
                <a href="?period=wtd" class="<?php echo $period=='wtd'?'active':''; ?>">📋 WTD</a>
                <a href="?period=today" class="<?php echo $period=='today'?'active':''; ?>">📍 Today</a>
            </div>
            <a href="../dashboard.php" class="btn">🏠 Menu</a>
            <a href="../logout.php" class="btn">🚪 Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="period-info">
            <div>
                <strong><?php echo $label; ?></strong>: 
                <?php echo $period == 'overall' ? 'All Time' : date('d M Y', strtotime($start)) . ' - ' . date('d M Y'); ?>
                <?php if ($period != 'overall'): ?>
                | <span style="color:#718096;">vs Previous: <?php echo date('d M', strtotime($prev_start)); ?> - <?php echo date('d M', strtotime($prev_end)); ?></span>
                <?php endif; ?>
            </div>
            <div style="color:#718096;font-size:11px;">
                Overall: <?php echo number_format($overall_orders); ?> orders | ₹<?php echo number_format($overall_revenue, 0); ?>
            </div>
        </div>
        
        <!-- KPI Row -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-top">
                    <span class="kpi-icon">📦</span>
                    <?php if ($period != 'overall'): ?>
                    <span class="kpi-change <?php echo pct($curr_orders,$prev_orders)>=0?'change-up':'change-down'; ?>">
                        <?php echo pct($curr_orders,$prev_orders)>=0?'▲':'▼'; ?> <?php echo abs(pct($curr_orders,$prev_orders)); ?>%
                    </span>
                    <?php endif; ?>
                </div>
                <div class="kpi-value"><?php echo number_format($curr_orders); ?></div>
                <div class="kpi-label">Total Orders</div>
                <?php if ($period != 'overall'): ?><div class="kpi-prev">Prev: <?php echo number_format($prev_orders); ?></div><?php endif; ?>
            </div>
            
            <div class="kpi-card green">
                <div class="kpi-top">
                    <span class="kpi-icon">💰</span>
                    <?php if ($period != 'overall'): ?>
                    <span class="kpi-change <?php echo pct($curr_revenue,$prev_revenue)>=0?'change-up':'change-down'; ?>">
                        <?php echo pct($curr_revenue,$prev_revenue)>=0?'▲':'▼'; ?> <?php echo abs(pct($curr_revenue,$prev_revenue)); ?>%
                    </span>
                    <?php endif; ?>
                </div>
                <div class="kpi-value">₹<?php echo number_format($curr_revenue, 0); ?></div>
                <div class="kpi-label">Total Revenue</div>
                <?php if ($period != 'overall'): ?><div class="kpi-prev">Prev: ₹<?php echo number_format($prev_revenue, 0); ?></div><?php endif; ?>
            </div>
            
            <div class="kpi-card green">
                <div class="kpi-top">
                    <span class="kpi-icon">✅</span>
                    <?php if ($period != 'overall'): ?>
                    <span class="kpi-change <?php echo pct($success_rate,$prev_success)>=0?'change-up':'change-down'; ?>">
                        <?php echo pct($success_rate,$prev_success)>=0?'▲':'▼'; ?> <?php echo abs(pct($success_rate,$prev_success)); ?>%
                    </span>
                    <?php endif; ?>
                </div>
                <div class="kpi-value"><?php echo $success_rate; ?>%</div>
                <div class="kpi-label">On-Time Delivery</div>
                <?php if ($period != 'overall'): ?><div class="kpi-prev">Prev: <?php echo $prev_success; ?>%</div><?php endif; ?>
            </div>
            
            <div class="kpi-card orange">
                <div class="kpi-top"><span class="kpi-icon">⏱️</span></div>
                <div class="kpi-value"><?php echo $avg_delivery_time; ?> min</div>
                <div class="kpi-label">Avg Delivery Time</div>
                <div class="kpi-prev">Overall Avg: <?php echo $overall_avg_delivery; ?> min</div>
            </div>
        </div>
        
        <!-- KPI Row 2 -->
        <div class="kpi-row">
            <div class="kpi-card purple">
                <div class="kpi-top"><span class="kpi-icon">👥</span></div>
                <div class="kpi-value"><?php echo number_format($curr_customers); ?></div>
                <div class="kpi-label">Unique Customers</div>
            </div>
            <div class="kpi-card <?php echo $curr_delayed_all > 0 ? 'red' : 'green'; ?>">
                <div class="kpi-top"><span class="kpi-icon">⚠️</span></div>
                <div class="kpi-value"><?php echo $curr_delayed_all; ?></div>
                <div class="kpi-label">Total Delayed Orders</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-top"><span class="kpi-icon">🛵</span></div>
                <div class="kpi-value"><?php echo $total_partners; ?></div>
                <div class="kpi-label">Delivery Partners</div>
            </div>
            <div class="kpi-card orange">
                <div class="kpi-top"><span class="kpi-icon">📊</span></div>
                <div class="kpi-value">₹<?php echo number_format($avg_order, 0); ?></div>
                <div class="kpi-label">Avg Order Value</div>
            </div>
        </div>
        
        <!-- Charts Row 1 -->
        <div class="charts-3">
            <div class="card">
                <h2>📈 Orders & Revenue Trend</h2>
                <canvas id="trendChart"></canvas>
            </div>
            <div class="card">
                <h2>🥧 Delivery Status Breakdown</h2>
                <canvas id="statusChart"></canvas>
            </div>
            <div class="card">
                <h2>⏰ Hourly Order Distribution</h2>
                <canvas id="hourlyChart"></canvas>
            </div>
        </div>
        
        <!-- Charts Row 2 -->
        <div class="charts-2">
            <div class="card">
                <h2>🏆 Top 10 Best Selling Products</h2>
                <div style="max-height:280px;overflow-y:auto;">
                    <table>
                        <thead><tr><th>#</th><th>Product</th><th>Category</th><th>Brand</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
                        <tbody>
                            <?php $rank=1; foreach ($top_products as $p): ?>
                            <tr>
                                <td><strong><?php echo $rank++; ?></strong></td>
                                <td><?php echo htmlspecialchars($p['product_name'] ?? $p['product_id']); ?></td>
                                <td><?php echo htmlspecialchars($p['category'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($p['brand'] ?? '-'); ?></td>
                                <td><?php echo number_format($p['total_qty']); ?></td>
                                <td>₹<?php echo number_format($p['total_revenue'], 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($top_products)): ?>
                            <tr><td colspan="6" style="text-align:center;padding:20px;">No product data available</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <h2>🏪 Store Performance</h2>
                <canvas id="storeChart"></canvas>
            </div>
        </div>
    </div>
    
    <script>
    // ============ TREND CHART (Bar + Line) ============
    const daily = <?php echo json_encode($daily); ?>;
    if (daily.length > 0) {
        new Chart(document.getElementById('trendChart'), {
            type: 'bar',
            data: {
                labels: daily.map(d => new Date(d.dt).toLocaleDateString('en-IN', {day:'numeric', month:'short'})),
                datasets: [{
                    label: 'Revenue (₹)',
                    data: daily.map(d => d.revenue),
                    backgroundColor: '#48bb78',
                    borderRadius: 3,
                    yAxisID: 'y'
                }, {
                    label: 'Orders',
                    data: daily.map(d => d.orders),
                    type: 'line',
                    borderColor: '#1a56db',
                    backgroundColor: 'transparent',
                    tension: 0.3,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                plugins: { 
                    legend: { display: true, position: 'bottom', labels: { boxWidth: 10, font: { size: 10 }, padding: 8 } } 
                },
                scales: {
                    y: { position: 'left', title: { display: true, text: '₹' } },
                    y1: { position: 'right', title: { display: true, text: 'Orders' }, grid: { drawOnChartArea: false } }
                }
            }
        });
    }
    
    // ============ STATUS DOUGHNUT ============
    const statusData = <?php echo json_encode($status_breakdown); ?>;
    const filtered = statusData.filter(s => s.count > 0);
    if (filtered.length > 0) {
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: filtered.map(s => s.status + ' (' + s.count + ')'),
                datasets: [{
                    data: filtered.map(s => s.count),
                    backgroundColor: filtered.map(s => s.color)
                }]
            },
            options: {
                responsive: true,
                plugins: { 
                    legend: { display: true, position: 'bottom', labels: { boxWidth: 10, font: { size: 9 }, padding: 6 } } 
                }
            }
        });
    }
    
    // ============ HOURLY CHART ============
    const hourly = <?php echo json_encode($hourly); ?>;
    if (hourly.length > 0) {
        const allHours = Array.from({length: 24}, (_, i) => {
            const found = hourly.find(h => parseInt(h.hr) === i);
            return found ? parseInt(found.cnt) : 0;
        });
        const hourLabels = Array.from({length: 24}, (_, i) => String(i).padStart(2,'0') + ':00');
        
        new Chart(document.getElementById('hourlyChart'), {
            type: 'bar',
            data: {
                labels: hourLabels,
                datasets: [{
                    label: 'Orders',
                    data: allHours,
                    backgroundColor: allHours.map(v => v > 50 ? '#1a56db' : '#a0c4ff'),
                    borderRadius: 2
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { x: { ticks: { font: { size: 8 }, maxTicksLimit: 12 } } }
            }
        });
    }
    
    // ============ STORE CHART ============
    const stores = <?php echo json_encode($stores); ?>;
    if (stores.length > 0) {
        new Chart(document.getElementById('storeChart'), {
            type: 'bar',
            data: {
                labels: stores.map(s => 'Store ' + s.store_id),
                datasets: [{
                    label: 'Orders',
                    data: stores.map(s => s.orders),
                    backgroundColor: '#1a56db',
                    borderRadius: 3
                }]
            },
            options: {
                responsive: true,
                indexAxis: 'y',
                plugins: { legend: { display: false } }
            }
        });
    }
    </script>
</body>
</html>