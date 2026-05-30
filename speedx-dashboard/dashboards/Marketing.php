<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.html');
    exit();
}

$user_name = $_SESSION['full_name'];
$user_role = $_SESSION['role'];
$period = $_GET['period'] ?? 'mtd';

try {
     $pdo = new PDO("mysql:host=sql201.infinityfree.com;dbname=if0_42049613_speedx_dashboard", "if0_42049613", "9846294820Amma","" [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) { die("Connection failed: " . $e->getMessage()); }

// ==========================================
// DATE RANGES
// ==========================================
$today = date('Y-m-d');

switch($period) {
    case 'today':
        $start = $today; $end = $today;
        $prev_start = date('Y-m-d', strtotime('-1 day'));
        $prev_end = date('Y-m-d', strtotime('-1 day'));
        $label = 'Today';
        break;
    case 'wtd':
        $start = date('Y-m-d', strtotime('monday this week'));
        $end = $today;
        $prev_start = date('Y-m-d', strtotime('monday last week'));
        $prev_end = date('Y-m-d', strtotime('sunday last week'));
        $label = 'Week-to-Date';
        break;
    case 'ytd':
        $start = date('Y-01-01');
        $end = $today;
        $prev_start = date('Y-01-01', strtotime('-1 year'));
        $prev_end = date('Y-12-31', strtotime('-1 year'));
        $label = 'Year-to-Date';
        break;
    default:
        $period = 'mtd';
        $start = date('Y-m-01');
        $end = $today;
        $prev_start = date('Y-m-01', strtotime('first day of last month'));
        $prev_end = date('Y-m-t', strtotime('last day of last month'));
        $label = 'Month-to-Date';
}

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
// KPI METRICS
// ==========================================

// Marketing Spend (Current)
$curr_spend = q($pdo, "
    SELECT COALESCE(SUM(cp.spend), 0) 
    FROM campaign_performance cp 
    JOIN marketing_campaigns mc ON cp.campaign_id = mc.campaign_id 
    WHERE cp.report_date BETWEEN ? AND ?
", [$start, $end]);

// Marketing Spend (Previous)
$prev_spend = q($pdo, "
    SELECT COALESCE(SUM(cp.spend), 0) 
    FROM campaign_performance cp 
    JOIN marketing_campaigns mc ON cp.campaign_id = mc.campaign_id 
    WHERE cp.report_date BETWEEN ? AND ?
", [$prev_start, $prev_end]);

// Campaign Revenue (Current)
$curr_revenue = q($pdo, "
    SELECT COALESCE(SUM(cp.revenue_generated), 0) 
    FROM campaign_performance cp 
    WHERE cp.report_date BETWEEN ? AND ?
", [$start, $end]);

// Campaign Revenue (Previous)
$prev_revenue = q($pdo, "
    SELECT COALESCE(SUM(cp.revenue_generated), 0) 
    FROM campaign_performance cp 
    WHERE cp.report_date BETWEEN ? AND ?
", [$prev_start, $prev_end]);

// ROAS (Return on Ad Spend)
$curr_roas = $curr_spend > 0 ? round($curr_revenue / $curr_spend, 2) : 0;
$prev_roas = $prev_spend > 0 ? round($prev_revenue / $prev_spend, 2) : 0;

// New Customers (Current)
$curr_new_customers = q($pdo, "
    SELECT COUNT(*) FROM customer_acquisition 
    WHERE acquisition_date BETWEEN ? AND ?
", [$start, $end]);

// New Customers (Previous)
$prev_new_customers = q($pdo, "
    SELECT COUNT(*) FROM customer_acquisition 
    WHERE acquisition_date BETWEEN ? AND ?
", [$prev_start, $prev_end]);

// Customer Acquisition Cost (CAC)
$curr_cac = $curr_new_customers > 0 ? round($curr_spend / $curr_new_customers, 2) : 0;
$prev_cac = $prev_new_customers > 0 ? round($prev_spend / $prev_new_customers, 2) : 0;

// Active Campaigns
$active_campaigns = q($pdo, "SELECT COUNT(*) FROM marketing_campaigns WHERE status = 'ACTIVE'");
$total_campaigns = q($pdo, "SELECT COUNT(*) FROM marketing_campaigns");

// Total customers for retention
$total_customers = q($pdo, "SELECT COUNT(*) FROM customer");

// Repeat customers (ordered more than once)
$repeat_customers = q($pdo, "
    SELECT COUNT(DISTINCT customer_id) FROM customer_retention_metrics 
    WHERE total_orders > 1
");

// Retention Rate
$retention_rate = $total_customers > 0 ? round(($repeat_customers / $total_customers) * 100, 1) : 0;

// Overall metrics
$overall_spend = q($pdo, "SELECT COALESCE(SUM(spend),0) FROM campaign_performance");
$overall_revenue = q($pdo, "SELECT COALESCE(SUM(revenue_generated),0) FROM campaign_performance");
$overall_roas = $overall_spend > 0 ? round($overall_revenue / $overall_spend, 2) : 0;
$overall_customers = q($pdo, "SELECT COUNT(*) FROM customer_acquisition");

// ==========================================
// ACQUISITION SOURCE BREAKDOWN
// ==========================================
$acquisition_sources = qAll($pdo, "
    SELECT acquisition_channel, COUNT(*) as cnt 
    FROM customer_acquisition 
    WHERE acquisition_date BETWEEN ? AND ?
    GROUP BY acquisition_channel
    ORDER BY cnt DESC
", [$start, $end]);

// ==========================================
// DAILY TREND
// ==========================================
$daily_trend = qAll($pdo, "
    SELECT 
        cp.report_date,
        COALESCE(SUM(cp.spend), 0) as spend,
        COALESCE(SUM(cp.revenue_generated), 0) as revenue
    FROM campaign_performance cp
    WHERE cp.report_date BETWEEN ? AND ?
    GROUP BY cp.report_date
    ORDER BY cp.report_date
", [$start, $end]);

// ==========================================
// CUSTOMER ACQUISITION TREND
// ==========================================
$acquisition_trend = qAll($pdo, "
    SELECT acquisition_date, COUNT(*) as cnt
    FROM customer_acquisition
    WHERE acquisition_date BETWEEN ? AND ?
    GROUP BY acquisition_date
    ORDER BY acquisition_date
", [$start, $end]);

// ==========================================
// TOP CAMPAIGNS
// ==========================================
$top_campaigns = qAll($pdo, "
    SELECT 
        mc.campaign_name,
        mc.campaign_type,
        mc.status,
        mc.budget,
        COALESCE(SUM(cp.spend), 0) as total_spend,
        COALESCE(SUM(cp.revenue_generated), 0) as total_revenue,
        COALESCE(SUM(cp.impressions), 0) as total_impressions,
        COALESCE(SUM(cp.clicks), 0) as total_clicks,
        COALESCE(SUM(cp.conversions), 0) as total_conversions,
        CASE WHEN COALESCE(SUM(cp.spend), 0) > 0 
             THEN ROUND(COALESCE(SUM(cp.revenue_generated), 0) / COALESCE(SUM(cp.spend), 1), 2) 
             ELSE 0 END as roas
    FROM marketing_campaigns mc
    LEFT JOIN campaign_performance cp ON mc.campaign_id = cp.campaign_id AND cp.report_date BETWEEN ? AND ?
    GROUP BY mc.campaign_id
    ORDER BY total_revenue DESC
    LIMIT 10
", [$start, $end]);

// ==========================================
// ACQUISITION CHANNEL PERFORMANCE
// ==========================================
$channel_performance = qAll($pdo, "
    SELECT 
        ca.acquisition_channel,
        COUNT(DISTINCT ca.customer_id) as new_customers,
        COALESCE(SUM(ca.acquisition_cost), 0) as total_cost,
        COALESCE(AVG(ca.acquisition_cost), 0) as avg_cac,
        COALESCE(SUM(crm.total_spend), 0) as customer_lifetime_value
    FROM customer_acquisition ca
    LEFT JOIN customer_retention_metrics crm ON ca.customer_id = crm.customer_id
    WHERE ca.acquisition_date BETWEEN ? AND ?
    GROUP BY ca.acquisition_channel
    ORDER BY new_customers DESC
", [$start, $end]);

// ==========================================
// CAMPAIGN TYPE PERFORMANCE (Funnel)
// ==========================================
$campaign_funnel = qAll($pdo, "
    SELECT 
        mc.campaign_type,
        COUNT(DISTINCT mc.campaign_id) as campaigns,
        COALESCE(SUM(cp.impressions), 0) as impressions,
        COALESCE(SUM(cp.clicks), 0) as clicks,
        COALESCE(SUM(cp.conversions), 0) as conversions,
        COALESCE(SUM(cp.spend), 0) as spend,
        COALESCE(SUM(cp.revenue_generated), 0) as revenue
    FROM marketing_campaigns mc
    LEFT JOIN campaign_performance cp ON mc.campaign_id = cp.campaign_id AND cp.report_date BETWEEN ? AND ?
    GROUP BY mc.campaign_type
    ORDER BY revenue DESC
", [$start, $end]);

// ==========================================
// COUPON USAGE
// ==========================================
$coupon_usage = qAll($pdo, "
    SELECT 
        cc.coupon_code,
        cc.discount_type,
        cc.discount_value,
        COUNT(cu.usage_id) as times_used,
        COALESCE(SUM(cu.discount_amount), 0) as total_discount
    FROM coupon_codes cc
    LEFT JOIN coupon_usage cu ON cc.coupon_id = cu.coupon_id AND DATE(cu.used_at) BETWEEN ? AND ?
    GROUP BY cc.coupon_id
    ORDER BY times_used DESC
    LIMIT 5
", [$start, $end]);

// ==========================================
// REFERRAL PERFORMANCE
// ==========================================
$referral_stats = qAll($pdo, "
    SELECT 
        referral_status,
        COUNT(*) as cnt,
        COALESCE(SUM(reward_amount), 0) as total_rewards
    FROM referral_program
    WHERE referral_date BETWEEN ? AND ?
    GROUP BY referral_status
", [$start, $end]);

$completed_referrals = q($pdo, "SELECT COUNT(*) FROM referral_program WHERE referral_status = 'COMPLETED' AND referral_date BETWEEN ? AND ?", [$start, $end]);
$total_referrals = q($pdo, "SELECT COUNT(*) FROM referral_program WHERE referral_date BETWEEN ? AND ?", [$start, $end]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpeedX - Marketing Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; }
        
        .top-header {
            background: linear-gradient(135deg, #6b46c1, #553c9a);
            color: white;
            padding: 12px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .top-header h1 { font-size: 20px; }
        
        .period-tabs { display: flex; gap: 2px; background: rgba(255,255,255,0.1); padding: 3px; border-radius: 8px; }
        .period-tabs a {
            padding: 6px 14px; border-radius: 6px; color: white; text-decoration: none;
            font-weight: 600; font-size: 11px; transition: all 0.2s;
        }
        .period-tabs a.active { background: white; color: #553c9a; }
        .period-tabs a:hover:not(.active) { background: rgba(255,255,255,0.15); }
        
        .btn { background: rgba(255,255,255,0.15); color: white; padding: 7px 14px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; }
        
        .container { max-width: 1600px; margin: 12px auto; padding: 0 15px; }
        
        .period-info {
            background: white; padding: 10px 18px; border-radius: 8px; margin-bottom: 12px;
            display: flex; justify-content: space-between; align-items: center; font-size: 13px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .period-info strong { color: #6b46c1; }
        
        /* KPI Cards */
        .kpi-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 12px; }
        .kpi-card {
            background: white; padding: 15px 18px; border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04); border-left: 3px solid #6b46c1;
        }
        .kpi-card.green { border-left-color: #48bb78; }
        .kpi-card.red { border-left-color: #e53e3e; }
        .kpi-card.orange { border-left-color: #ed8936; }
        .kpi-card.blue { border-left-color: #4299e1; }
        
        .kpi-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; }
        .kpi-icon { font-size: 20px; }
        .kpi-change { font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 8px; }
        .change-up { background: #c6f6d5; color: #276749; }
        .change-down { background: #fed7d7; color: #9b2c2c; }
        .kpi-value { font-size: 24px; font-weight: bold; color: #1a202c; }
        .kpi-label { font-size: 9px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
        .kpi-prev { font-size: 9px; color: #a0aec0; }
        
        /* Charts Grid */
        .charts-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .charts-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 12px; }
        .card {
            background: white; padding: 15px; border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .card h2 { font-size: 13px; color: #2d3748; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
        .card canvas { max-height: 250px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th { background: #f7fafc; padding: 7px 8px; text-align: left; font-size: 9px; text-transform: uppercase; color: #4a5568; }
        td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
        tr:hover { background: #f7fafc; }
        
        .badge { padding: 1px 7px; border-radius: 6px; font-size: 9px; font-weight: 600; }
        .badge-green { background: #c6f6d5; color: #276749; }
        .badge-yellow { background: #fefcbf; color: #975a16; }
        .badge-purple { background: #e9d8fd; color: #553c9a; }
        
        @media (max-width: 1200px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } .charts-2, .charts-3 { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .kpi-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="top-header">
        <div>
            <h1>📢 Marketing Dashboard</h1>
            <small style="opacity:0.7;">SpeedX Quick Commerce</small>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <div class="period-tabs">
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
                <strong>📊 <?php echo $label; ?></strong>: <?php echo date('d M Y', strtotime($start)); ?> - <?php echo date('d M Y', strtotime($end)); ?>
                | <span style="color:#718096;">vs Previous: <?php echo date('d M', strtotime($prev_start)); ?> - <?php echo date('d M', strtotime($prev_end)); ?></span>
            </div>
            <div style="color:#718096;font-size:11px;">
                Overall: ₹<?php echo number_format($overall_spend, 0); ?> spend | ₹<?php echo number_format($overall_revenue, 0); ?> revenue | ROAS: <?php echo $overall_roas; ?>x
            </div>
        </div>
        
        <!-- KPI Row 1 -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-top">
                    <span class="kpi-icon">💳</span>
                    <span class="kpi-change <?php echo pct($curr_spend,$prev_spend)<=0?'change-up':'change-down'; ?>">
                        <?php echo pct($curr_spend,$prev_spend)>=0?'▲':'▼'; ?> <?php echo abs(pct($curr_spend,$prev_spend)); ?>%
                    </span>
                </div>
                <div class="kpi-value">₹<?php echo number_format($curr_spend, 0); ?></div>
                <div class="kpi-label">Marketing Spend</div>
                <div class="kpi-prev">Prev: ₹<?php echo number_format($prev_spend, 0); ?></div>
            </div>
            
            <div class="kpi-card green">
                <div class="kpi-top">
                    <span class="kpi-icon">💰</span>
                    <span class="kpi-change <?php echo pct($curr_revenue,$prev_revenue)>=0?'change-up':'change-down'; ?>">
                        <?php echo pct($curr_revenue,$prev_revenue)>=0?'▲':'▼'; ?> <?php echo abs(pct($curr_revenue,$prev_revenue)); ?>%
                    </span>
                </div>
                <div class="kpi-value">₹<?php echo number_format($curr_revenue, 0); ?></div>
                <div class="kpi-label">Campaign Revenue</div>
                <div class="kpi-prev">Prev: ₹<?php echo number_format($prev_revenue, 0); ?></div>
            </div>
            
            <div class="kpi-card <?php echo $curr_roas >= 3 ? 'green' : ($curr_roas >= 1 ? 'orange' : 'red'); ?>">
                <div class="kpi-top">
                    <span class="kpi-icon">🎯</span>
                    <span class="kpi-change <?php echo pct($curr_roas,$prev_roas)>=0?'change-up':'change-down'; ?>">
                        <?php echo pct($curr_roas,$prev_roas)>=0?'▲':'▼'; ?> <?php echo abs(pct($curr_roas,$prev_roas)); ?>%
                    </span>
                </div>
                <div class="kpi-value"><?php echo $curr_roas; ?>x</div>
                <div class="kpi-label">ROAS (Return on Ad Spend)</div>
                <div class="kpi-prev">Prev: <?php echo $prev_roas; ?>x</div>
            </div>
        </div>
        
        <!-- KPI Row 2 -->
        <div class="kpi-row">
            <div class="kpi-card blue">
                <div class="kpi-top">
                    <span class="kpi-icon">👥</span>
                    <span class="kpi-change <?php echo pct($curr_new_customers,$prev_new_customers)>=0?'change-up':'change-down'; ?>">
                        <?php echo pct($curr_new_customers,$prev_new_customers)>=0?'▲':'▼'; ?> <?php echo abs(pct($curr_new_customers,$prev_new_customers)); ?>%
                    </span>
                </div>
                <div class="kpi-value"><?php echo number_format($curr_new_customers); ?></div>
                <div class="kpi-label">New Customers Acquired</div>
                <div class="kpi-prev">Prev: <?php echo number_format($prev_new_customers); ?></div>
            </div>
            
            <div class="kpi-card <?php echo $curr_cac < 500 ? 'green' : 'red'; ?>">
                <div class="kpi-top">
                    <span class="kpi-icon">💸</span>
                    <span class="kpi-change <?php echo pct($curr_cac,$prev_cac)<=0?'change-up':'change-down'; ?>">
                        <?php echo pct($curr_cac,$prev_cac)>=0?'▲':'▼'; ?> <?php echo abs(pct($curr_cac,$prev_cac)); ?>%
                    </span>
                </div>
                <div class="kpi-value">₹<?php echo number_format($curr_cac, 0); ?></div>
                <div class="kpi-label">CAC (Customer Acquisition Cost)</div>
                <div class="kpi-prev">Prev: ₹<?php echo number_format($prev_cac, 0); ?></div>
            </div>
            
            <div class="kpi-card purple">
                <div class="kpi-top"><span class="kpi-icon">🔄</span></div>
                <div class="kpi-value"><?php echo $retention_rate; ?>%</div>
                <div class="kpi-label">Customer Retention Rate</div>
                <div class="kpi-prev"><?php echo number_format($repeat_customers); ?> repeat customers</div>
            </div>
        </div>
        
        <!-- Charts Row 1 -->
        <div class="charts-2">
            <div class="card">
                <h2>📈 Revenue vs Spend Trend</h2>
                <canvas id="trendChart"></canvas>
            </div>
            <div class="card">
                <h2>📊 Acquisition Source Distribution</h2>
                <canvas id="sourceChart"></canvas>
            </div>
        </div>
        
        <!-- Charts Row 2 -->
        <div class="charts-2">
            <div class="card">
                <h2>👥 Customer Acquisition Trend</h2>
                <canvas id="acquisitionChart"></canvas>
            </div>
            <div class="card">
                <h2>📊 Campaign Funnel (by Type)</h2>
                <canvas id="funnelChart"></canvas>
            </div>
        </div>
        
        <!-- Tables Row -->
        <div class="charts-2">
            <div class="card">
                <h2>🏆 Top Campaigns</h2>
                <div style="max-height:300px;overflow-y:auto;">
                    <table>
                        <thead><tr><th>Campaign</th><th>Type</th><th>Spend</th><th>Revenue</th><th>ROAS</th><th>Impressions</th><th>Clicks</th><th>Conv.</th></tr></thead>
                        <tbody>
                            <?php foreach ($top_campaigns as $c): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($c['campaign_name']); ?></strong></td>
                                <td><span class="badge badge-purple"><?php echo $c['campaign_type']; ?></span></td>
                                <td>₹<?php echo number_format($c['total_spend'], 0); ?></td>
                                <td>₹<?php echo number_format($c['total_revenue'], 0); ?></td>
                                <td style="color:<?php echo $c['roas']>=3?'#48bb78':($c['roas']>=1?'#ed8936':'#e53e3e'); ?>"><?php echo $c['roas']; ?>x</td>
                                <td><?php echo number_format($c['total_impressions']); ?></td>
                                <td><?php echo number_format($c['total_clicks']); ?></td>
                                <td><?php echo number_format($c['total_conversions']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <h2>📋 Acquisition Channel Performance</h2>
                <div style="max-height:300px;overflow-y:auto;">
                    <table>
                        <thead><tr><th>Channel</th><th>New Customers</th><th>Total Cost</th><th>Avg CAC</th><th>CLV</th></tr></thead>
                        <tbody>
                            <?php foreach ($channel_performance as $ch): ?>
                            <tr>
                                <td><strong><?php echo $ch['acquisition_channel']; ?></strong></td>
                                <td><?php echo number_format($ch['new_customers']); ?></td>
                                <td>₹<?php echo number_format($ch['total_cost'], 0); ?></td>
                                <td>₹<?php echo number_format($ch['avg_cac'], 0); ?></td>
                                <td>₹<?php echo number_format($ch['customer_lifetime_value'], 0); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Coupon & Referral -->
        <div class="charts-2">
            <div class="card">
                <h2>🎫 Top Coupon Codes</h2>
                <table>
                    <thead><tr><th>Coupon</th><th>Type</th><th>Value</th><th>Used</th><th>Total Discount</th></tr></thead>
                    <tbody>
                        <?php foreach ($coupon_usage as $cu): ?>
                        <tr>
                            <td><strong><?php echo $cu['coupon_code']; ?></strong></td>
                            <td><?php echo $cu['discount_type']; ?></td>
                            <td><?php echo $cu['discount_value']; ?><?php echo $cu['discount_type']=='PERCENTAGE'?'%':'₹'; ?></td>
                            <td><?php echo $cu['times_used']; ?>x</td>
                            <td>₹<?php echo number_format($cu['total_discount'], 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card">
                <h2>👥 Referral Program</h2>
                <div style="text-align:center;padding:20px;">
                    <div style="font-size:48px;font-weight:bold;color:#6b46c1;"><?php echo $completed_referrals; ?></div>
                    <div style="color:#718096;">Completed Referrals</div>
                    <div style="margin-top:10px;color:#a0aec0;">Total: <?php echo $total_referrals; ?> referrals</div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // ============ REVENUE VS SPEND TREND ============
    const trend = <?php echo json_encode($daily_trend); ?>;
    if (trend.length > 0) {
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: trend.map(d => d.report_date),
                datasets: [{
                    label: 'Revenue (₹)',
                    data: trend.map(d => d.revenue),
                    borderColor: '#48bb78',
                    backgroundColor: 'rgba(72,187,120,0.1)',
                    tension: 0.3,
                    fill: true
                }, {
                    label: 'Spend (₹)',
                    data: trend.map(d => d.spend),
                    borderColor: '#e53e3e',
                    backgroundColor: 'rgba(229,62,62,0.1)',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
            }
        });
    }
    
    // ============ ACQUISITION SOURCE DOUGHNUT ============
    const sources = <?php echo json_encode($acquisition_sources); ?>;
    if (sources.length > 0) {
        new Chart(document.getElementById('sourceChart'), {
            type: 'doughnut',
            data: {
                labels: sources.map(s => s.acquisition_channel + ' (' + s.cnt + ')'),
                datasets: [{
                    data: sources.map(s => s.cnt),
                    backgroundColor: ['#6b46c1', '#9f7aea', '#48bb78', '#4299e1', '#ed8936', '#e53e3e']
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } } }
            }
        });
    }
    
    // ============ ACQUISITION TREND ============
    const acqTrend = <?php echo json_encode($acquisition_trend); ?>;
    if (acqTrend.length > 0) {
        new Chart(document.getElementById('acquisitionChart'), {
            type: 'bar',
            data: {
                labels: acqTrend.map(d => d.acquisition_date),
                datasets: [{
                    label: 'New Customers',
                    data: acqTrend.map(d => d.cnt),
                    backgroundColor: '#6b46c1',
                    borderRadius: 3
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } }
            }
        });
    }
    
    // ============ CAMPAIGN FUNNEL ============
    const funnel = <?php echo json_encode($campaign_funnel); ?>;
    if (funnel.length > 0) {
        new Chart(document.getElementById('funnelChart'), {
            type: 'bar',
            data: {
                labels: funnel.map(f => f.campaign_type),
                datasets: [{
                    label: 'Impressions',
                    data: funnel.map(f => f.impressions),
                    backgroundColor: '#a0aec0'
                }, {
                    label: 'Clicks',
                    data: funnel.map(f => f.clicks),
                    backgroundColor: '#4299e1'
                }, {
                    label: 'Conversions',
                    data: funnel.map(f => f.conversions),
                    backgroundColor: '#48bb78'
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
            }
        });
    }
    </script>
</body>
</html>