<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.html');
    exit();
}

$user_name = $_SESSION['full_name'];
$user_role = $_SESSION['role'];

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

try {
     $pdo = new PDO("mysql:host=sql201.infinityfree.com;dbname=if0_42049613_speedx_dashboard", "if0_42049613", "9846294820Amma","", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) { die("Connection failed: " . $e->getMessage()); }

function q($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn();
    } catch(Exception $e) { return 0; }
}
function qAll($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
    } catch(Exception $e) { return []; }
}

$dateCond = "STR_TO_DATE(order_date, '%d-%m-%Y %H:%i')";
$startDt = $date_from . ' 00:00:00';
$endDt = $date_to . ' 23:59:59';
$prev_month_start = date('Y-m-01', strtotime('-1 month', strtotime($date_from)));
$prev_month_end = date('Y-m-t', strtotime('-1 month', strtotime($date_from)));

// ==========================================
// SALES METRICS
// ==========================================
// Current Period
$total_revenue = q($pdo, "SELECT COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) FROM speedx_orders WHERE $dateCond BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')", [$startDt, $endDt]);
$total_orders = q($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE $dateCond BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')", [$startDt, $endDt]);
$total_customers = q($pdo, "SELECT COUNT(DISTINCT customer_id) FROM speedx_orders WHERE $dateCond BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')", [$startDt, $endDt]);
$avg_order_value = $total_orders > 0 ? round($total_revenue / $total_orders, 2) : 0;

// Previous Month
$prev_revenue = q($pdo, "SELECT COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) FROM speedx_orders WHERE $dateCond BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')", [$prev_month_start.' 00:00:00', $prev_month_end.' 23:59:59']);
$prev_orders = q($pdo, "SELECT COUNT(*) FROM speedx_orders WHERE $dateCond BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')", [$prev_month_start.' 00:00:00', $prev_month_end.' 23:59:59']);

function pct($cur, $prev) { if ($prev == 0) return $cur > 0 ? 100 : 0; return round((($cur - $prev) / $prev) * 100, 1); }

// Overall (All time)
$overall_revenue = q($pdo, "SELECT COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) FROM speedx_orders");
$overall_orders = q($pdo, "SELECT COUNT(*) FROM speedx_orders");
$overall_customers = q($pdo, "SELECT COUNT(DISTINCT customer_id) FROM speedx_orders");

// ==========================================
// SALES TARGETS
// ==========================================
$target_month = date('Y-m-01', strtotime($date_from));
$target = qAll($pdo, "SELECT * FROM sales_targets WHERE target_month = ?", [$target_month]);
$target_revenue = $target[0]['target_revenue'] ?? 0;
$target_orders = $target[0]['target_orders'] ?? 0;
$revenue_achievement = $target_revenue > 0 ? round(($total_revenue / $target_revenue) * 100, 1) : 0;

// ==========================================
// DAILY SALES TREND
// ==========================================
$daily_trend = qAll($pdo, "
    SELECT DATE(STR_TO_DATE(order_date, '%d-%m-%Y %H:%i')) as dt,
        COUNT(*) as orders,
        COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) as revenue
    FROM speedx_orders 
    WHERE $dateCond BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')
    GROUP BY DATE(STR_TO_DATE(order_date, '%d-%m-%Y %H:%i'))
    ORDER BY dt
", [$startDt, $endDt]);

// ==========================================
// TOP SELLING PRODUCTS
// ==========================================
$top_products = qAll($pdo, "
    SELECT p.product_id, p.product_name, p.category, p.brand, p.price, p.mrp, p.margin_percentage,
        COALESCE(SUM(CAST(REPLACE(IFNULL(soi.quantity,'0'),',','') AS UNSIGNED)),0) as units_sold,
        COALESCE(SUM(CAST(REPLACE(IFNULL(soi.unit_price,'0'),',','') AS DECIMAL(15,2)) * CAST(REPLACE(IFNULL(soi.quantity,'0'),',','') AS UNSIGNED)),0) as total_revenue,
        ROUND(COALESCE(SUM(CAST(REPLACE(IFNULL(soi.unit_price,'0'),',','') AS DECIMAL(15,2)) * CAST(REPLACE(IFNULL(soi.quantity,'0'),',','') AS UNSIGNED)),0) - (p.price * COALESCE(SUM(CAST(REPLACE(IFNULL(soi.quantity,'0'),',','') AS UNSIGNED)),0)), 2) as estimated_profit
    FROM speedx_order_items soi
    JOIN speedx_orders so ON soi.order_id = so.order_id
    JOIN products p ON CAST(soi.product_id AS UNSIGNED) = p.product_id
    WHERE STR_TO_DATE(so.order_date, '%d-%m-%Y %H:%i') BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')
    GROUP BY p.product_id, p.product_name, p.category, p.brand, p.price, p.mrp, p.margin_percentage
    ORDER BY total_revenue DESC
    LIMIT 10
", [$startDt, $endDt]);

// ==========================================
// CATEGORY PERFORMANCE
// ==========================================
$category_performance = qAll($pdo, "
    SELECT p.category as category_name,
        COUNT(DISTINCT soi.order_id) as order_count,
        COALESCE(SUM(CAST(REPLACE(IFNULL(soi.quantity,'0'),',','') AS UNSIGNED)),0) as units_sold,
        COALESCE(SUM(CAST(REPLACE(IFNULL(soi.unit_price,'0'),',','') AS DECIMAL(15,2)) * CAST(REPLACE(IFNULL(soi.quantity,'0'),',','') AS UNSIGNED)),0) as revenue,
        ROUND(AVG(p.margin_percentage), 1) as avg_margin
    FROM speedx_order_items soi
    JOIN speedx_orders so ON soi.order_id = so.order_id
    JOIN products p ON CAST(soi.product_id AS UNSIGNED) = p.product_id
    WHERE STR_TO_DATE(so.order_date, '%d-%m-%Y %H:%i') BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')
    GROUP BY p.category
    ORDER BY revenue DESC
", [$startDt, $endDt]);

// ==========================================
// STORE SALES PERFORMANCE
// ==========================================
$store_performance = qAll($pdo, "
    SELECT store_id,
        COUNT(*) as total_orders,
        COUNT(DISTINCT customer_id) as unique_customers,
        COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) as total_revenue,
        ROUND(AVG(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),2) as avg_order_value,
        ROUND(COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) / NULLIF(COUNT(DISTINCT customer_id), 0), 2) as revenue_per_customer
    FROM speedx_orders
    WHERE $dateCond BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')
    AND store_id IS NOT NULL AND store_id != ''
    GROUP BY store_id
    ORDER BY total_revenue DESC
", [$startDt, $endDt]);

// ==========================================
// TOP CUSTOMERS BY SPEND
// ==========================================
$top_customers = qAll($pdo, "
    SELECT c.customer_id, c.customer_name, c.city, c.customer_segment,
        COUNT(so.order_id) as order_count,
        COALESCE(SUM(CAST(REPLACE(IFNULL(so.order_total,'0'),',','') AS DECIMAL(15,2))),0) as total_spend,
        ROUND(AVG(CAST(REPLACE(IFNULL(so.order_total,'0'),',','') AS DECIMAL(15,2))),2) as avg_spend_per_order,
        DATEDIFF(CURDATE(), MAX(STR_TO_DATE(so.order_date, '%d-%m-%Y %H:%i'))) as days_since_last_order
    FROM speedx_orders so
    JOIN customer c ON so.customer_id = c.customer_id
    WHERE STR_TO_DATE(so.order_date, '%d-%m-%Y %H:%i') BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')
    GROUP BY c.customer_id, c.customer_name, c.city, c.customer_segment
    ORDER BY total_spend DESC
    LIMIT 10
", [$startDt, $endDt]);

// ==========================================
// CUSTOMER SEGMENT PERFORMANCE
// ==========================================
$segment_performance = qAll($pdo, "
    SELECT c.customer_segment,
        COUNT(DISTINCT c.customer_id) as customer_count,
        COUNT(so.order_id) as order_count,
        COALESCE(SUM(CAST(REPLACE(IFNULL(so.order_total,'0'),',','') AS DECIMAL(15,2))),0) as total_revenue
    FROM speedx_orders so
    JOIN customer c ON so.customer_id = c.customer_id
    WHERE STR_TO_DATE(so.order_date, '%d-%m-%Y %H:%i') BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')
    GROUP BY c.customer_segment
    ORDER BY total_revenue DESC
", [$startDt, $endDt]);

// ==========================================
// PAYMENT METHOD REVENUE
// ==========================================
$payment_revenue = qAll($pdo, "
    SELECT payment_method,
        COUNT(*) as order_count,
        COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) as total_revenue,
        ROUND(AVG(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),2) as avg_revenue
    FROM speedx_orders
    WHERE $dateCond BETWEEN STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s') AND STR_TO_DATE(?,'%Y-%m-%d %H:%i:%s')
    AND payment_method IS NOT NULL AND payment_method != ''
    GROUP BY payment_method
    ORDER BY total_revenue DESC
", [$startDt, $endDt]);

// ==========================================
// MONTHLY COMPARISON (Last 12 months)
// ==========================================
$monthly_comparison = qAll($pdo, "
    SELECT DATE_FORMAT(STR_TO_DATE(order_date, '%d-%m-%Y %H:%i'), '%Y-%m') as month,
        COUNT(*) as orders,
        COALESCE(SUM(CAST(REPLACE(IFNULL(order_total,'0'),',','') AS DECIMAL(15,2))),0) as revenue,
        COUNT(DISTINCT customer_id) as customers
    FROM speedx_orders
    WHERE STR_TO_DATE(order_date, '%d-%m-%Y %H:%i') >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(STR_TO_DATE(order_date, '%d-%m-%Y %H:%i'), '%Y-%m')
    ORDER BY month
");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpeedX - Sales Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; }
        
        .top-header {
            background: linear-gradient(135deg, #6b46c1, #553c9a);
            color: white; padding: 12px 25px;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
        }
        .top-header h1 { font-size: 20px; }
        
        .date-filter {
            display: flex; align-items: center; gap: 8px;
            background: rgba(255,255,255,0.1); padding: 8px 15px; border-radius: 8px;
        }
        .date-filter input {
            padding: 6px 10px; border: 1px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.1); color: white; border-radius: 5px; font-size: 12px;
        }
        .date-filter button {
            padding: 6px 12px; background: white; color: #553c9a;
            border: none; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 12px;
        }
        .date-filter label { font-size: 12px; }
        .btn { background: rgba(255,255,255,0.15); color: white; padding: 7px 14px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; }
        
        .container { max-width: 1500px; margin: 12px auto; padding: 0 15px; }
        
        .info-bar {
            background: white; padding: 10px 18px; border-radius: 8px; margin-bottom: 12px;
            display: flex; justify-content: space-between; align-items: center; font-size: 13px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04); flex-wrap: wrap; gap: 8px;
        }
        
        .kpi-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 15px; }
        .kpi-card {
            background: white; padding: 16px; border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.05); border-left: 4px solid #6b46c1; text-align: center;
        }
        .kpi-card.green { border-left-color: #48bb78; }
        .kpi-card.blue { border-left-color: #4299e1; }
        .kpi-card.orange { border-left-color: #ed8936; }
        .kpi-card.red { border-left-color: #e53e3e; }
        
        .kpi-icon { font-size: 22px; margin-bottom: 6px; }
        .kpi-value { font-size: 24px; font-weight: bold; color: #1a202c; }
        .kpi-label { font-size: 10px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
        .kpi-change { font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 8px; display: inline-block; margin-top: 4px; }
        .change-up { background: #c6f6d5; color: #276749; }
        .change-down { background: #fed7d7; color: #9b2c2c; }
        .kpi-sub { font-size: 9px; color: #a0aec0; margin-top: 3px; }
        
        .progress-bar { height: 6px; background: #e2e8f0; border-radius: 3px; margin-top: 8px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 3px; }
        
        .charts-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        .charts-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 15px; }
        .card {
            background: white; padding: 18px; border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.05);
        }
        .card h3 { font-size: 14px; color: #2d3748; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }
        .card canvas { max-height: 240px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { background: #f7fafc; padding: 8px 10px; text-align: left; font-size: 10px; text-transform: uppercase; color: #4a5568; }
        td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
        tr:hover { background: #f7fafc; }
        
        @media (max-width: 1200px) { .kpi-row { grid-template-columns: repeat(3, 1fr); } .charts-3 { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 768px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } .charts-2, .charts-3 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="top-header">
        <div>
            <h1>💼 Sales Dashboard</h1>
            <small style="opacity:0.7;">SpeedX Quick Commerce • Revenue & Performance Analytics</small>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <form class="date-filter" method="GET">
                <label>📅</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                <label>→</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                <button type="submit">🔍 Filter</button>
                <a href="sales_dashboard.php" style="color:white;font-size:11px;text-decoration:underline;">Reset</a>
            </form>
            <a href="../dashboard.php" class="btn">🏠 Menu</a>
            <a href="../logout.php" class="btn">🚪 Logout</a>
        </div>
    </div>
    
    <div class="container">
        <!-- Info Bar -->
        <div class="info-bar">
            <div>
                <strong style="color:#6b46c1;">📊 Sales Period:</strong> <?php echo date('d M Y', strtotime($date_from)); ?> - <?php echo date('d M Y', strtotime($date_to)); ?>
            </div>
            <div style="color:#718096;font-size:12px;">
                All-Time: ₹<?php echo number_format($overall_revenue, 0); ?> | <?php echo number_format($overall_orders); ?> orders | <?php echo number_format($overall_customers); ?> customers
            </div>
        </div>
        
        <!-- KPI Cards -->
        <div class="kpi-row">
            <div class="kpi-card green">
                <div class="kpi-icon">💰</div>
                <div class="kpi-value">₹<?php echo number_format($total_revenue, 0); ?></div>
                <div class="kpi-label">Total Sales Revenue</div>
                <span class="kpi-change <?php echo pct($total_revenue,$prev_revenue)>=0?'change-up':'change-down'; ?>">
                    vs Prev Month: <?php echo pct($total_revenue,$prev_revenue)>=0?'+':''; ?><?php echo pct($total_revenue,$prev_revenue); ?>%
                </span>
            </div>
            
            <div class="kpi-card blue">
                <div class="kpi-icon">📦</div>
                <div class="kpi-value"><?php echo number_format($total_orders); ?></div>
                <div class="kpi-label">Total Orders</div>
                <span class="kpi-change <?php echo pct($total_orders,$prev_orders)>=0?'change-up':'change-down'; ?>">
                    vs Prev Month: <?php echo pct($total_orders,$prev_orders)>=0?'+':''; ?><?php echo pct($total_orders,$prev_orders); ?>%
                </span>
            </div>
            
            <div class="kpi-card orange">
                <div class="kpi-icon">🛒</div>
                <div class="kpi-value">₹<?php echo number_format($avg_order_value, 0); ?></div>
                <div class="kpi-label">Avg Order Value</div>
                <div class="kpi-sub">Basket Size</div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-icon">👥</div>
                <div class="kpi-value"><?php echo number_format($total_customers); ?></div>
                <div class="kpi-label">Paying Customers</div>
                <div class="kpi-sub"><?php echo $total_orders > 0 ? round($total_orders/$total_customers, 1) : 0; ?> orders/customer</div>
            </div>
            
            <div class="kpi-card <?php echo $revenue_achievement >= 100 ? 'green' : ($revenue_achievement >= 75 ? 'orange' : 'red'); ?>">
                <div class="kpi-icon">🎯</div>
                <div class="kpi-value"><?php echo $revenue_achievement; ?>%</div>
                <div class="kpi-label">Target Achievement</div>
                <div class="progress-bar"><div class="progress-fill" style="width:<?php echo min($revenue_achievement,100); ?>%;background:#6b46c1;"></div></div>
            </div>
        </div>
        
        <!-- Charts Row 1 -->
        <div class="charts-2">
            <div class="card">
                <h3>📈 Daily Sales Revenue & Orders</h3>
                <canvas id="trendChart"></canvas>
            </div>
            <div class="card">
                <h3>📊 12-Month Sales Trend</h3>
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>
        
        <!-- Charts Row 2 -->
        <div class="charts-2">
            <div class="card">
                <h3>🏷️ Revenue by Product Category</h3>
                <canvas id="categoryChart"></canvas>
            </div>
            <div class="card">
                <h3>🏆 Top 10 Products by Revenue</h3>
                <canvas id="productChart"></canvas>
            </div>
        </div>
        
        <!-- Charts Row 3 -->
        <div class="charts-2">
            <div class="card">
                <h3>💳 Revenue by Payment Method</h3>
                <canvas id="paymentChart"></canvas>
            </div>
            <div class="card">
                <h3>👥 Customer Segment Performance</h3>
                <canvas id="segmentChart"></canvas>
            </div>
        </div>
        
        <!-- Tables Row -->
        <div class="charts-3">
            <div class="card">
                <h3>🏆 Top Selling Products</h3>
                <div style="max-height:300px;overflow-y:auto;">
                    <table>
                        <thead><tr><th>#</th><th>Product</th><th>Category</th><th>Units</th><th>Revenue</th><th>Margin%</th></tr></thead>
                        <tbody>
                            <?php if (!empty($top_products)): $r=1; foreach ($top_products as $p): ?>
                            <tr>
                                <td><strong><?php echo $r++; ?></strong></td>
                                <td><?php echo htmlspecialchars($p['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($p['category'] ?? '-'); ?></td>
                                <td><?php echo number_format($p['units_sold']); ?></td>
                                <td><strong>₹<?php echo number_format($p['total_revenue'], 0); ?></strong></td>
                                <td><?php echo $p['margin_percentage']; ?>%</td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="6" style="text-align:center;padding:20px;color:#a0aec0;">No sales data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card">
                <h3>🏪 Store Sales Performance</h3>
                <div style="max-height:300px;overflow-y:auto;">
                    <table>
                        <thead><tr><th>Store</th><th>Orders</th><th>Customers</th><th>Revenue</th><th>Rev/Cust</th></tr></thead>
                        <tbody>
                            <?php if (!empty($store_performance)): foreach ($store_performance as $s): ?>
                            <tr>
                                <td><strong>Store <?php echo htmlspecialchars($s['store_id']); ?></strong></td>
                                <td><?php echo number_format($s['total_orders']); ?></td>
                                <td><?php echo number_format($s['unique_customers']); ?></td>
                                <td><strong>₹<?php echo number_format($s['total_revenue'], 0); ?></strong></td>
                                <td>₹<?php echo number_format($s['revenue_per_customer'], 0); ?></td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="5" style="text-align:center;padding:20px;color:#a0aec0;">No store data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card">
                <h3>⭐ Top Customers by Spend</h3>
                <div style="max-height:300px;overflow-y:auto;">
                    <table>
                        <thead><tr><th>Customer</th><th>Segment</th><th>Orders</th><th>Spend</th><th>Last Order</th></tr></thead>
                        <tbody>
                            <?php if (!empty($top_customers)): foreach ($top_customers as $c): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($c['customer_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($c['customer_segment'] ?? '-'); ?></td>
                                <td><?php echo $c['order_count']; ?></td>
                                <td><strong>₹<?php echo number_format($c['total_spend'], 0); ?></strong></td>
                                <td><?php echo $c['days_since_last_order']; ?>d ago</td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="5" style="text-align:center;padding:20px;color:#a0aec0;">No customer data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Category Details Table -->
        <div class="card">
            <h3>📊 Category Sales Breakdown</h3>
            <table>
                <thead><tr><th>Category</th><th>Orders</th><th>Units Sold</th><th>Revenue</th><th>Avg Margin</th><th>% of Total</th></tr></thead>
                <tbody>
                    <?php if (!empty($category_performance)): 
                        $total_cat_rev = array_sum(array_column($category_performance, 'revenue'));
                        foreach ($category_performance as $cat): 
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($cat['category_name']); ?></strong></td>
                        <td><?php echo number_format($cat['order_count']); ?></td>
                        <td><?php echo number_format($cat['units_sold']); ?></td>
                        <td><strong>₹<?php echo number_format($cat['revenue'], 0); ?></strong></td>
                        <td><?php echo $cat['avg_margin']; ?>%</td>
                        <td><?php echo $total_cat_rev > 0 ? round(($cat['revenue']/$total_cat_rev)*100, 1) : 0; ?>%</td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
    const trend = <?php echo json_encode($daily_trend); ?>;
    
    // Daily Trend
    if (trend.length > 0) {
        new Chart(document.getElementById('trendChart'), {
            type: 'bar',
            data: {
                labels: trend.map(d => d.dt),
                datasets: [
                    { label: 'Revenue (₹)', data: trend.map(d => d.revenue), backgroundColor: '#48bb78', borderRadius: 3, yAxisID: 'y' },
                    { label: 'Orders', data: trend.map(d => d.orders), type: 'line', borderColor: '#6b46c1', tension: 0.3, yAxisID: 'y1' }
                ]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } },
                scales: {
                    y: { position: 'left', title: { display: true, text: '₹' } },
                    y1: { position: 'right', title: { display: true, text: 'Orders' }, grid: { drawOnChartArea: false } }
                }
            }
        });
    }
    
    // Monthly Trend
    const monthly = <?php echo json_encode($monthly_comparison); ?>;
    if (monthly.length > 0) {
        new Chart(document.getElementById('monthlyChart'), {
            type: 'line',
            data: {
                labels: monthly.map(d => d.month),
                datasets: [
                    { label: 'Revenue (₹)', data: monthly.map(d => d.revenue), borderColor: '#6b46c1', backgroundColor: 'rgba(107,70,193,0.1)', tension: 0.3, fill: true }
                ]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } } }
        });
    }
    
    // Category Chart
    const catData = <?php echo json_encode($category_performance); ?>;
    if (catData.length > 0) {
        new Chart(document.getElementById('categoryChart'), {
            type: 'doughnut',
            data: {
                labels: catData.map(d => d.category_name),
                datasets: [{ data: catData.map(d => d.revenue), backgroundColor: ['#6b46c1','#48bb78','#4299e1','#ed8936','#9f7aea','#f6ad55','#e53e3e'] }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } } } }
        });
    }
    
    // Top Products
    const prodData = <?php echo json_encode(array_slice($top_products, 0, 8)); ?>;
    if (prodData.length > 0) {
        new Chart(document.getElementById('productChart'), {
            type: 'bar',
            data: {
                labels: prodData.map(d => d.product_name.length > 15 ? d.product_name.substring(0,15)+'...' : d.product_name),
                datasets: [{ data: prodData.map(d => d.total_revenue), backgroundColor: '#6b46c1', borderRadius: 3 }]
            },
            options: { responsive: true, indexAxis: 'y', plugins: { legend: { display: false } } }
        });
    }
    
    // Payment Methods
    const payData = <?php echo json_encode($payment_revenue); ?>;
    if (payData.length > 0) {
        new Chart(document.getElementById('paymentChart'), {
            type: 'pie',
            data: {
                labels: payData.map(d => d.payment_method),
                datasets: [{ data: payData.map(d => d.total_revenue), backgroundColor: ['#6b46c1','#48bb78','#4299e1','#ed8936','#9f7aea'] }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } } } }
        });
    }
    
    // Customer Segments
    const segData = <?php echo json_encode($segment_performance); ?>;
    if (segData.length > 0) {
        new Chart(document.getElementById('segmentChart'), {
            type: 'bar',
            data: {
                labels: segData.map(d => d.customer_segment),
                datasets: [{ label: 'Revenue (₹)', data: segData.map(d => d.total_revenue), backgroundColor: '#6b46c1', borderRadius: 3 }]
            },
            options: { responsive: true, plugins: { legend: { display: false } } }
        });
    }
    </script>
</body>
</html>