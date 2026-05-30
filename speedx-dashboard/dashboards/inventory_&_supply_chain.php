<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.html');
    exit();
}

$user_name = $_SESSION['full_name'];

// DATE FILTER
$date_from = isset($_GET['date_from']) && $_GET['date_from'] != '' ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) && $_GET['date_to'] != '' ? $_GET['date_to'] : date('Y-m-d');

$date_from_dt = $date_from . ' 00:00:00';
$date_to_dt = $date_to . ' 23:59:59';

try {
     $pdo = new PDO("mysql:host=sql201.infinityfree.com;dbname=if0_42049613_speedx_dashboard", "if0_42049613", "9846294820Amma");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) { die("DB Connection failed"); }

function getVal($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchColumn(); }
    catch(Exception $e) { return 0; }
}
function getRows($pdo, $sql, $params = []) {
    try { $stmt = $pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(); }
    catch(Exception $e) { return []; }
}

// ============ ALL KPIs NOW FILTERED BY DATE ============

// Inventory Value - Based on movements in period
$period_inventory_value = getVal($pdo, 
    "SELECT COALESCE(SUM(i.inventory_value),0) FROM inventory i 
     WHERE i.last_updated >= ? AND i.last_updated <= ?",
    [$date_from_dt, $date_to_dt]
);
// If no movement data, show current inventory
if ($period_inventory_value == 0) {
    $period_inventory_value = getVal($pdo, "SELECT COALESCE(SUM(inventory_value),0) FROM inventory");
}

// Total Stock affected in period (INWARD - OUTWARD)
$period_inward = getVal($pdo, 
    "SELECT COALESCE(SUM(quantity),0) FROM inventory_movements WHERE movement_type = 'INWARD' AND movement_date >= ? AND movement_date <= ?",
    [$date_from_dt, $date_to_dt]
);
$period_outward = getVal($pdo, 
    "SELECT COALESCE(SUM(quantity),0) FROM inventory_movements WHERE movement_type = 'OUTWARD' AND movement_date >= ? AND movement_date <= ?",
    [$date_from_dt, $date_to_dt]
);
$period_transfer = getVal($pdo, 
    "SELECT COALESCE(SUM(quantity),0) FROM inventory_movements WHERE movement_type = 'TRANSFER' AND movement_date >= ? AND movement_date <= ?",
    [$date_from_dt, $date_to_dt]
);
$period_damage = getVal($pdo, 
    "SELECT COALESCE(SUM(quantity),0) FROM inventory_movements WHERE movement_type = 'DAMAGE' AND movement_date >= ? AND movement_date <= ?",
    [$date_from_dt, $date_to_dt]
);
$period_return = getVal($pdo, 
    "SELECT COALESCE(SUM(quantity),0) FROM inventory_movements WHERE movement_type = 'RETURN' AND movement_date >= ? AND movement_date <= ?",
    [$date_from_dt, $date_to_dt]
);

// Total Movements in period
$total_period_movements = $period_inward + $period_outward + $period_transfer + $period_damage + $period_return;

// SKUs affected in period
$period_skus = getVal($pdo, 
    "SELECT COUNT(DISTINCT product_id) FROM inventory_movements WHERE movement_date >= ? AND movement_date <= ?",
    [$date_from_dt, $date_to_dt]
);

// Low Stock (still current snapshot - but shows items that need attention NOW)
$low_stock_count = getVal($pdo, "SELECT COUNT(*) FROM inventory WHERE current_stock <= reorder_level AND current_stock > 0");
$out_of_stock_count = getVal($pdo, "SELECT COUNT(*) FROM inventory WHERE current_stock = 0");

// Active Suppliers in period
$period_suppliers = getVal($pdo, 
    "SELECT COUNT(DISTINCT s.supplier_id) FROM suppliers s 
     JOIN purchase_orders po ON s.supplier_id = po.supplier_id 
     WHERE po.po_date >= ? AND po.po_date <= ? AND s.supplier_status = 'ACTIVE'",
    [$date_from, $date_to]
);
$total_suppliers = getVal($pdo, "SELECT COUNT(*) FROM suppliers WHERE supplier_status = 'ACTIVE'");

// Reserved & Damaged
$reserved_units = getVal($pdo, "SELECT COALESCE(SUM(reserved_stock),0) FROM inventory");
$damaged_units = getVal($pdo, "SELECT COALESCE(SUM(damaged_stock),0) FROM inventory");
$total_units = getVal($pdo, "SELECT COALESCE(SUM(current_stock),0) FROM inventory");
$available_units = $total_units - $reserved_units - $damaged_units;
$in_stock_items = getVal($pdo, "SELECT COUNT(*) FROM inventory WHERE current_stock > reorder_level");

// ============ DATE FILTERED DATA ============

// Movements Summary
$movements_data = getRows($pdo, 
    "SELECT movement_type, COUNT(*) as total_count, SUM(quantity) as total_qty 
     FROM inventory_movements 
     WHERE movement_date >= ? AND movement_date <= ?
     GROUP BY movement_type 
     ORDER BY total_qty DESC",
    [$date_from_dt, $date_to_dt]
);
$total_movements = array_sum(array_column($movements_data, 'total_count'));

// Purchase Orders Summary
$po_data = getRows($pdo,
    "SELECT po_status, COUNT(*) as total_count, COALESCE(SUM(total_amount),0) as total_amount 
     FROM purchase_orders 
     WHERE po_date >= ? AND po_date <= ?
     GROUP BY po_status 
     ORDER BY total_count DESC",
    [$date_from, $date_to]
);
$total_pos = array_sum(array_column($po_data, 'total_count'));

// PO Total Value in period
$period_po_value = getVal($pdo,
    "SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE po_date >= ? AND po_date <= ?",
    [$date_from, $date_to]
);

// Recent POs
$recent_pos = getRows($pdo,
    "SELECT p.*, s.supplier_name 
     FROM purchase_orders p 
     JOIN suppliers s ON p.supplier_id = s.supplier_id 
     WHERE p.po_date >= ? AND p.po_date <= ?
     ORDER BY p.po_date DESC LIMIT 10",
    [$date_from, $date_to]
);

// Pending Replenishments
$pending_replen = getRows($pdo,
    "SELECT * FROM stock_replenishment 
     WHERE replenishment_status = 'PENDING' 
     AND request_date >= ? AND request_date <= ?
     ORDER BY request_date DESC LIMIT 10",
    [$date_from, $date_to]
);

// Completed Replenishments in period
$period_replen = getVal($pdo,
    "SELECT COUNT(*) FROM stock_replenishment WHERE replenishment_status = 'COMPLETED' AND request_date >= ? AND request_date <= ?",
    [$date_from, $date_to]
);

// ============ CHART DATA ============

// Inventory by Store (current snapshot)
$store_data = getRows($pdo,
    "SELECT store_id, COUNT(DISTINCT product_id) as products, 
     SUM(current_stock) as stock, COALESCE(SUM(inventory_value),0) as value 
     FROM inventory GROUP BY store_id ORDER BY value DESC"
);

// Stock Status
$stock_status = [
    ['label' => 'In Stock', 'count' => (int)$in_stock_items, 'color' => '#48bb78'],
    ['label' => 'Low Stock', 'count' => (int)$low_stock_count, 'color' => '#f6ad55'],
    ['label' => 'Out of Stock', 'count' => (int)$out_of_stock_count, 'color' => '#e53e3e'],
];

// PO Trend 6 months
$po_trend = getRows($pdo,
    "SELECT DATE_FORMAT(po_date, '%Y-%m') as month, 
     COUNT(*) as count, COALESCE(SUM(total_amount),0) as amount 
     FROM purchase_orders 
     WHERE po_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
     GROUP BY DATE_FORMAT(po_date, '%Y-%m') ORDER BY month"
);

// Inventory Exceptions
$exceptions = getRows($pdo,
    "SELECT *, 
     CASE WHEN current_stock = 0 THEN 'Out of Stock' 
          WHEN current_stock <= reorder_level THEN 'Low Stock' 
     END as status_label 
     FROM inventory WHERE current_stock <= reorder_level 
     ORDER BY current_stock ASC LIMIT 20"
);

// Supplier Performance
$supplier_list = getRows($pdo, "SELECT supplier_id, supplier_name, city FROM suppliers WHERE supplier_status = 'ACTIVE'");
$supplier_performance = [];
foreach ($supplier_list as $sup) {
    $sid = $sup['supplier_id'];
    $orders = getVal($pdo, "SELECT COALESCE(SUM(orders_supplied),0) FROM supplier_performance WHERE supplier_id=? AND report_month BETWEEN ? AND ?", [$sid, date('Y-m-01', strtotime($date_from)), $date_to]);
    $ontime = getVal($pdo, "SELECT COALESCE(SUM(on_time_deliveries),0) FROM supplier_performance WHERE supplier_id=? AND report_month BETWEEN ? AND ?", [$sid, date('Y-m-01', strtotime($date_from)), $date_to]);
    $delayed = getVal($pdo, "SELECT COALESCE(SUM(delayed_deliveries),0) FROM supplier_performance WHERE supplier_id=? AND report_month BETWEEN ? AND ?", [$sid, date('Y-m-01', strtotime($date_from)), $date_to]);
    $rating = getVal($pdo, "SELECT ROUND(AVG(supplier_rating),2) FROM supplier_performance WHERE supplier_id=?", [$sid]);
    $pct = $orders > 0 ? round(($ontime/$orders)*100, 1) : 0;
    $supplier_performance[] = [
        'name' => $sup['supplier_name'], 'city' => $sup['city'],
        'orders' => (int)$orders, 'ontime' => (int)$ontime, 'delayed' => (int)$delayed,
        'rating' => (float)($rating ?: 0), 'pct' => $pct
    ];
}
usort($supplier_performance, function($a, $b) { return $b['rating'] <=> $a['rating']; });

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpeedX - Inventory Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',Tahoma,sans-serif;background:#f0f2f5;color:#2d3748}
        .header{background:linear-gradient(135deg,#c05621,#9c4221);color:white;padding:12px 25px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
        .header h1{font-size:20px}.header a{color:white;text-decoration:none}
        .btn{background:rgba(255,255,255,0.15);padding:7px 14px;border-radius:6px;font-size:12px;font-weight:600;display:inline-block}.btn:hover{background:rgba(255,255,255,0.25)}
        .date-form{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.1);padding:8px 14px;border-radius:8px;flex-wrap:wrap}
        .date-form label{font-size:11px;color:rgba(255,255,255,0.8)}
        .date-form input{padding:6px 10px;border:1px solid rgba(255,255,255,0.3);background:rgba(255,255,255,0.1);color:white;border-radius:4px;font-size:11px;width:130px}
        .date-form input:focus{outline:none;border-color:white}
        .date-form button{padding:6px 14px;background:white;color:#c05621;border:none;border-radius:4px;cursor:pointer;font-weight:600;font-size:11px}.date-form button:hover{background:#f0f0f0}
        .reset-link{color:white;font-size:10px;text-decoration:underline;margin-left:4px}
        .container{max-width:1500px;margin:15px auto;padding:0 15px}
        .info-bar{background:white;padding:12px 18px;border-radius:8px;margin-bottom:15px;display:flex;justify-content:space-between;align-items:center;font-size:13px;box-shadow:0 1px 4px rgba(0,0,0,0.05);flex-wrap:wrap;gap:8px}
        .info-bar .period{color:#c05621;font-weight:700}.info-bar .stats{color:#718096;font-size:12px}.info-bar .stats strong{color:#2d3748}
        .kpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:15px}
        .kpi-card{background:white;padding:18px;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,0.05);border-left:4px solid #c05621;text-align:center;transition:transform 0.2s}
        .kpi-card:hover{transform:translateY(-2px)}.kpi-card.green{border-left-color:#48bb78}.kpi-card.red{border-left-color:#e53e3e}.kpi-card.orange{border-left-color:#f6ad55}.kpi-card.blue{border-left-color:#4299e1}
        .kpi-icon{font-size:24px;margin-bottom:8px}.kpi-value{font-size:26px;font-weight:bold;color:#1a202c}.kpi-label{font-size:10px;color:#718096;text-transform:uppercase;letter-spacing:0.5px;margin-top:5px}.kpi-sub{font-size:9px;color:#a0aec0;margin-top:3px}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px}.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:15px}
        .card{background:white;padding:20px;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,0.05)}.card h3{font-size:14px;color:#2d3748;margin-bottom:15px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;display:flex;align-items:center;gap:8px}.card canvas{max-height:250px}
        table{width:100%;border-collapse:collapse;font-size:12px}th{background:#f7fafc;padding:8px 10px;text-align:left;font-size:10px;text-transform:uppercase;color:#4a5568;letter-spacing:0.5px;font-weight:600}td{padding:8px 10px;border-bottom:1px solid #e2e8f0}tr:hover{background:#f7fafc}
        .badge{padding:3px 10px;border-radius:10px;font-size:10px;font-weight:600;display:inline-block}.badge-green{background:#c6f6d5;color:#276749}.badge-red{background:#fed7d7;color:#9b2c2c}.badge-yellow{background:#fefcbf;color:#975a16}.badge-blue{background:#bee3f8;color:#2a4365}
        .progress-wrap{display:flex;align-items:center;gap:8px}.progress-bar{height:6px;background:#e2e8f0;border-radius:3px;flex:1;min-width:60px}.progress-fill{height:100%;border-radius:3px}
        .empty-state{text-align:center;padding:25px;color:#a0aec0}.empty-state .icon{font-size:36px;margin-bottom:10px}
        @media(max-width:1200px){.kpi-row{grid-template-columns:repeat(3,1fr)}.grid-3{grid-template-columns:1fr 1fr}}
        @media(max-width:768px){.kpi-row{grid-template-columns:repeat(2,1fr)}.grid-2,.grid-3{grid-template-columns:1fr}.date-form{width:100%;justify-content:center}}
    </style>
</head>
<body>
    <div class="header">
        <div><h1>📦 Inventory & Supply Chain</h1><small style="opacity:0.7;font-size:12px;">SpeedX Quick Commerce</small></div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <form class="date-form" method="GET" action="">
                <label>📅 From:</label><input type="date" name="date_from" value="<?php echo $date_from; ?>">
                <label>To:</label><input type="date" name="date_to" value="<?php echo $date_to; ?>">
                <button type="submit">🔍 Apply</button>
                <a href="?date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="reset-link">↺ Reset</a>
            </form>
            <a href="../dashboard.php" class="btn">🏠 Menu</a>
            <a href="../logout.php" class="btn">🚪 Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="info-bar">
            <div><span class="period">📊 Period:</span> <?php echo date('d M Y', strtotime($date_from)); ?> - <?php echo date('d M Y', strtotime($date_to)); ?></div>
            <div class="stats">Movements: <strong><?php echo number_format($total_movements); ?></strong> | POs: <strong><?php echo number_format($total_pos); ?></strong> | PO Value: <strong>₹<?php echo number_format($period_po_value, 0); ?></strong></div>
        </div>
        
        <!-- KPI ROW 1 - ALL FILTERED -->
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon">🔄</div>
                <div class="kpi-value"><?php echo number_format($total_period_movements); ?></div>
                <div class="kpi-label">Stock Movements</div>
                <div class="kpi-sub">In: <?php echo number_format($period_inward); ?> | Out: <?php echo number_format($period_outward); ?></div>
            </div>
            <div class="kpi-card blue">
                <div class="kpi-icon">📦</div>
                <div class="kpi-value"><?php echo number_format($period_skus); ?></div>
                <div class="kpi-label">SKUs Moved</div>
                <div class="kpi-sub">Products with activity</div>
            </div>
            <div class="kpi-card orange">
                <div class="kpi-icon">⚠️</div>
                <div class="kpi-value"><?php echo number_format($low_stock_count); ?></div>
                <div class="kpi-label">Low Stock Now</div>
                <div class="kpi-sub">Below reorder level</div>
            </div>
            <div class="kpi-card red">
                <div class="kpi-icon">❌</div>
                <div class="kpi-value"><?php echo number_format($out_of_stock_count); ?></div>
                <div class="kpi-label">Out of Stock</div>
                <div class="kpi-sub">Zero inventory</div>
            </div>
            <div class="kpi-card green">
                <div class="kpi-icon">🤝</div>
                <div class="kpi-value"><?php echo $period_suppliers; ?>/<?php echo $total_suppliers; ?></div>
                <div class="kpi-label">Active Suppliers</div>
                <div class="kpi-sub">In period</div>
            </div>
        </div>
        
        <!-- KPI ROW 2 -->
        <div class="kpi-row" style="grid-template-columns: repeat(4, 1fr);">
            <div class="kpi-card"><div class="kpi-icon">📋</div><div class="kpi-value">₹<?php echo number_format($period_po_value, 0); ?></div><div class="kpi-label">PO Value</div><div class="kpi-sub">In period</div></div>
            <div class="kpi-card red"><div class="kpi-icon">🗑️</div><div class="kpi-value"><?php echo number_format($period_damage); ?></div><div class="kpi-label">Damaged</div><div class="kpi-sub">In period</div></div>
            <div class="kpi-card"><div class="kpi-icon">🔄</div><div class="kpi-value"><?php echo number_format($period_return); ?></div><div class="kpi-label">Returns</div><div class="kpi-sub">In period</div></div>
            <div class="kpi-card green"><div class="kpi-icon">✅</div><div class="kpi-value"><?php echo number_format($period_replen); ?></div><div class="kpi-label">Replenished</div><div class="kpi-sub">In period</div></div>
        </div>
        
        <div class="grid-2">
            <div class="card"><h3>📈 Stock Movements by Type (Filtered)</h3><canvas id="chartMovements"></canvas></div>
            <div class="card"><h3>🥧 Stock Status Distribution (Current)</h3><canvas id="chartStockStatus"></canvas></div>
        </div>
        
        <div class="grid-2">
            <div class="card"><h3>📊 Purchase Orders (Filtered Period)</h3><canvas id="chartPOStatus"></canvas></div>
            <div class="card"><h3>⭐ Supplier On-Time Delivery %</h3><canvas id="chartSupplier"></canvas></div>
        </div>
        
        <div class="grid-2">
            <div class="card"><h3>📋 Recent Purchase Orders</h3><div style="max-height:250px;overflow-y:auto;"><?php if($recent_pos): ?><table><thead><tr><th>PO</th><th>Supplier</th><th>Date</th><th>Amount</th><th>Status</th></tr></thead><tbody><?php foreach($recent_pos as $po): $b=$po['po_status']=='DELIVERED'?'badge-green':($po['po_status']=='CANCELLED'?'badge-red':($po['po_status']=='APPROVED'?'badge-blue':'badge-yellow')); ?><tr><td><strong>#PO-<?php echo $po['po_id']; ?></strong></td><td><?php echo htmlspecialchars($po['supplier_name']); ?></td><td><?php echo date('d M', strtotime($po['po_date'])); ?></td><td><strong>₹<?php echo number_format($po['total_amount'],0); ?></strong></td><td><span class="badge <?php echo $b; ?>"><?php echo $po['po_status']; ?></span></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="empty-state"><div class="icon">📋</div><p>No POs in this period</p></div><?php endif; ?></div></div>
            <div class="card"><h3>📈 PO Value Trend (6M)</h3><canvas id="chartPOTrend"></canvas></div>
        </div>
        
        <div class="grid-3">
            <div class="card"><h3>⚠️ Inventory Exceptions</h3><div style="max-height:280px;overflow-y:auto;"><?php if($exceptions): ?><table><thead><tr><th>Product</th><th>Store</th><th>Stock</th><th>Status</th></tr></thead><tbody><?php foreach($exceptions as $e): $b=$e['current_stock']==0?'badge-red':'badge-yellow'; ?><tr><td><strong>#<?php echo $e['product_id']; ?></strong></td><td>S<?php echo $e['store_id']; ?></td><td style="font-weight:600;color:<?php echo $e['current_stock']==0?'#e53e3e':'#ed8936'; ?>"><?php echo $e['current_stock']; ?></td><td><span class="badge <?php echo $b; ?>"><?php echo $e['status_label']; ?></span></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="empty-state"><div class="icon">✅</div><p>All stock healthy</p></div><?php endif; ?></div></div>
            
            <div class="card"><h3>🔄 Replenishment Requests</h3><div style="max-height:280px;overflow-y:auto;"><?php if($pending_replen): ?><table><thead><tr><th>Req</th><th>Product</th><th>Store</th><th>Qty</th></tr></thead><tbody><?php foreach($pending_replen as $r): ?><tr><td><strong>#<?php echo $r['replenishment_id']; ?></strong></td><td>P#<?php echo $r['product_id']; ?></td><td>S<?php echo $r['store_id']; ?></td><td style="font-weight:600;"><?php echo $r['requested_qty']; ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="empty-state"><div class="icon">✅</div><p>No pending requests</p></div><?php endif; ?></div></div>
            
            <div class="card"><h3>🏪 Store Inventory Value</h3><div style="max-height:280px;overflow-y:auto;"><?php if($store_data): ?><table><thead><tr><th>Store</th><th>Products</th><th>Stock</th><th>Value</th></tr></thead><tbody><?php foreach($store_data as $s): ?><tr><td><strong>Store <?php echo $s['store_id']; ?></strong></td><td><?php echo $s['products']; ?></td><td><?php echo number_format($s['stock']); ?></td><td><strong>₹<?php echo number_format($s['value'],0); ?></strong></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div></div>
        </div>
        
        <div class="card"><h3>🤝 Supplier Performance (Filtered Period)</h3><?php if($supplier_performance): ?><div style="max-height:350px;overflow-y:auto;"><table><thead><tr><th>#</th><th>Supplier</th><th>City</th><th>Orders</th><th>On-Time</th><th>Delayed</th><th>On-Time %</th><th>Rating</th></tr></thead><tbody><?php $rk=1; foreach($supplier_performance as $sp): $oc=$sp['pct']>=90?'#48bb78':($sp['pct']>=75?'#f6ad55':'#e53e3e'); $rc=$sp['rating']>=4.5?'#48bb78':($sp['rating']>=3.5?'#f6ad55':'#e53e3e'); ?><tr><td><strong>#<?php echo $rk++; ?></strong></td><td><strong><?php echo htmlspecialchars($sp['name']); ?></strong></td><td><?php echo htmlspecialchars($sp['city']); ?></td><td><?php echo number_format($sp['orders']); ?></td><td style="color:#48bb78"><?php echo number_format($sp['ontime']); ?></td><td style="color:<?php echo $sp['delayed']>0?'#e53e3e':'#718096'; ?>"><?php echo number_format($sp['delayed']); ?></td><td><div class="progress-wrap"><div class="progress-bar"><div class="progress-fill" style="width:<?php echo $sp['pct']; ?>%;background:<?php echo $oc; ?>;"></div></div><strong style="color:<?php echo $oc; ?>"><?php echo $sp['pct']; ?>%</strong></div></td><td style="color:<?php echo $rc; ?>"><?php echo str_repeat('⭐',min(floor($sp['rating']),5)); ?> <?php echo number_format($sp['rating'],1); ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><p>No supplier data</p></div><?php endif; ?></div>
    </div>
    
 <script>
// Wait for page to fully load
document.addEventListener('DOMContentLoaded', function() {
    
    // ============ CHART 1: Stock Movements ============
    var movementsData = <?php echo json_encode($movements_data); ?>;
    var movementsCanvas = document.getElementById('chartMovements');
    if (movementsCanvas && movementsData && movementsData.length > 0) {
        var ctx1 = movementsCanvas.getContext('2d');
        new Chart(ctx1, {
            type: 'bar',
            data: {
                labels: movementsData.map(function(d) { return d.movement_type; }),
                datasets: [{
                    label: 'Quantity',
                    data: movementsData.map(function(d) { return d.total_qty; }),
                    backgroundColor: '#c05621',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    } else if (movementsCanvas) {
        var ctx1 = movementsCanvas.getContext('2d');
        ctx1.font = '14px Arial';
        ctx1.fillStyle = '#a0aec0';
        ctx1.textAlign = 'center';
        ctx1.fillText('No movement data for this period', movementsCanvas.width/2, movementsCanvas.height/2);
    }

    // ============ CHART 2: Stock Status ============
    var stockData = <?php echo json_encode($stock_status); ?>;
    var stockCanvas = document.getElementById('chartStockStatus');
    if (stockCanvas) {
        var ctx2 = stockCanvas.getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: stockData.map(function(d) { return d.label + ' (' + d.count + ')'; }),
                datasets: [{
                    data: stockData.map(function(d) { return d.count; }),
                    backgroundColor: stockData.map(function(d) { return d.color; }),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, padding: 10, font: { size: 10 } }
                    }
                }
            }
        });
    }

    // ============ CHART 3: Purchase Order Status ============
    var poData = <?php echo json_encode($po_data); ?>;
    var poCanvas = document.getElementById('chartPOStatus');
    if (poCanvas && poData && poData.length > 0) {
        var ctx3 = poCanvas.getContext('2d');
        var colorMap = {
            'PENDING': '#f6ad55',
            'APPROVED': '#4299e1',
            'DELIVERED': '#48bb78',
            'CANCELLED': '#e53e3e'
        };
        new Chart(ctx3, {
            type: 'bar',
            data: {
                labels: poData.map(function(d) { return d.po_status; }),
                datasets: [{
                    label: 'Number of POs',
                    data: poData.map(function(d) { return d.total_count; }),
                    backgroundColor: poData.map(function(d) { return colorMap[d.po_status] || '#a0aec0'; }),
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    } else if (poCanvas) {
        var ctx3 = poCanvas.getContext('2d');
        ctx3.font = '14px Arial';
        ctx3.fillStyle = '#a0aec0';
        ctx3.textAlign = 'center';
        ctx3.fillText('No purchase orders for this period', poCanvas.width/2, poCanvas.height/2);
    }

    // ============ CHART 4: Supplier Performance ============
    var supplierData = <?php echo json_encode(array_slice($supplier_performance, 0, 8)); ?>;
    var supplierCanvas = document.getElementById('chartSupplier');
    if (supplierCanvas && supplierData && supplierData.length > 0) {
        var ctx4 = supplierCanvas.getContext('2d');
        new Chart(ctx4, {
            type: 'bar',
            data: {
                labels: supplierData.map(function(d) { 
                    return d.name.length > 15 ? d.name.substring(0, 15) + '...' : d.name; 
                }),
                datasets: [{
                    label: 'On-Time Delivery %',
                    data: supplierData.map(function(d) { return d.pct; }),
                    backgroundColor: supplierData.map(function(d) { 
                        return d.pct >= 90 ? '#48bb78' : (d.pct >= 75 ? '#f6ad55' : '#e53e3e'); 
                    }),
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: { 
                    x: { 
                        max: 100, 
                        ticks: { callback: function(v) { return v + '%'; } } 
                    } 
                }
            }
        });
    } else if (supplierCanvas) {
        var ctx4 = supplierCanvas.getContext('2d');
        ctx4.font = '14px Arial';
        ctx4.fillStyle = '#a0aec0';
        ctx4.textAlign = 'center';
        ctx4.fillText('No supplier data available', supplierCanvas.width/2, supplierCanvas.height/2);
    }

    // ============ CHART 5: PO Trend ============
    var poTrendData = <?php echo json_encode($po_trend); ?>;
    var poTrendCanvas = document.getElementById('chartPOTrend');
    if (poTrendCanvas && poTrendData && poTrendData.length > 0) {
        var ctx5 = poTrendCanvas.getContext('2d');
        new Chart(ctx5, {
            type: 'line',
            data: {
                labels: poTrendData.map(function(d) { return d.month; }),
                datasets: [{
                    label: 'PO Value (₹)',
                    data: poTrendData.map(function(d) { return d.amount; }),
                    borderColor: '#c05621',
                    backgroundColor: 'rgba(192, 86, 33, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#c05621'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12, padding: 15, font: { size: 10 } }
                    }
                },
                scales: { y: { beginAtZero: true } }
            }
        });
    } else if (poTrendCanvas) {
        var ctx5 = poTrendCanvas.getContext('2d');
        ctx5.font = '14px Arial';
        ctx5.fillStyle = '#a0aec0';
        ctx5.textAlign = 'center';
        ctx5.fillText('No trend data available', poTrendCanvas.width/2, poTrendCanvas.height/2);
    }

});
</script>
</body>
</html>