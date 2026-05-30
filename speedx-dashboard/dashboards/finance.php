<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.html');
    exit();
}

$user_name = $_SESSION['full_name'];
$user_role = $_SESSION['role'];

// Date Filter
$date_from = isset($_GET['date_from']) && $_GET['date_from'] != '' ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) && $_GET['date_to'] != '' ? $_GET['date_to'] : date('Y-m-d');

try {
     $pdo = new PDO("mysql:host=sql201.infinityfree.com;dbname=if0_42049613_speedx_dashboard", "if0_42049613", "9846294820Amma");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) { die("DB Connection failed"); }

function q1($pdo, $sql, $params = []) {
    try { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
    catch(Exception $e) { return 0; }
}
function qAll($pdo, $sql, $params = []) {
    try { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(); }
    catch(Exception $e) { return []; }
}

// ============ FINANCE KPIs ============

// Total Revenue (Credits)
$total_revenue = q1($pdo, 
    "SELECT COALESCE(SUM(amount),0) FROM finance_transactions 
     WHERE transaction_type = 'CREDIT' AND transaction_date BETWEEN ? AND ?",
    [$date_from, $date_to]
);

// Total Expenses (Debits)
$total_expenses = q1($pdo, 
    "SELECT COALESCE(SUM(amount),0) FROM finance_transactions 
     WHERE transaction_type = 'DEBIT' AND transaction_date BETWEEN ? AND ?",
    [$date_from, $date_to]
);

// Net Profit
$net_profit = $total_revenue - $total_expenses;

// Profit Margin
$profit_margin = $total_revenue > 0 ? round(($net_profit / $total_revenue) * 100, 1) : 0;

// Cash Balance
$current_balance = q1($pdo, 
    "SELECT closing_balance FROM finance_cashflow WHERE cashflow_date <= ? ORDER BY cashflow_date DESC LIMIT 1",
    [$date_to]
);
if (!$current_balance) {
    $current_balance = q1($pdo, "SELECT closing_balance FROM finance_cashflow ORDER BY cashflow_date DESC LIMIT 1");
}

// Pending Invoices
$pending_amount = q1($pdo, 
    "SELECT COALESCE(SUM(amount - paid_amount),0) FROM finance_invoices 
     WHERE status IN ('PENDING', 'PARTIALLY_PAID', 'OVERDUE')"
);

// Overdue Invoices
$overdue_amount = q1($pdo, 
    "SELECT COALESCE(SUM(amount - paid_amount),0) FROM finance_invoices WHERE status = 'OVERDUE'"
);

// Total Invoices
$total_invoiced = q1($pdo, 
    "SELECT COALESCE(SUM(amount),0) FROM finance_invoices WHERE invoice_date BETWEEN ? AND ?",
    [$date_from, $date_to]
);

// Previous period for comparison
$prev_month_start = date('Y-m-01', strtotime('-1 month', strtotime($date_from)));
$prev_month_end = date('Y-m-t', strtotime('-1 month', strtotime($date_from)));
$prev_revenue = q1($pdo, 
    "SELECT COALESCE(SUM(amount),0) FROM finance_transactions 
     WHERE transaction_type = 'CREDIT' AND transaction_date BETWEEN ? AND ?",
    [$prev_month_start, $prev_month_end]
);
$prev_expenses = q1($pdo, 
    "SELECT COALESCE(SUM(amount),0) FROM finance_transactions 
     WHERE transaction_type = 'DEBIT' AND transaction_date BETWEEN ? AND ?",
    [$prev_month_start, $prev_month_end]
);

function pct($c, $p) { if ($p == 0) return $c > 0 ? 100 : 0; return round((($c - $p) / $p) * 100, 1); }

// ============ CHART DATA ============

// Daily Revenue vs Expenses
$daily_trend = qAll($pdo, "
    SELECT transaction_date,
        COALESCE(SUM(CASE WHEN transaction_type = 'CREDIT' THEN amount ELSE 0 END),0) as revenue,
        COALESCE(SUM(CASE WHEN transaction_type = 'DEBIT' THEN amount ELSE 0 END),0) as expenses
    FROM finance_transactions
    WHERE transaction_date BETWEEN ? AND ?
    GROUP BY transaction_date ORDER BY transaction_date
", [$date_from, $date_to]);

// Expense Breakdown by Category
$expense_categories = qAll($pdo, "
    SELECT fc.category_name, COALESCE(SUM(ft.amount),0) as total
    FROM finance_transactions ft
    JOIN finance_categories fc ON ft.category_id = fc.category_id
    WHERE ft.transaction_type = 'DEBIT' AND ft.transaction_date BETWEEN ? AND ?
    GROUP BY fc.category_id, fc.category_name
    ORDER BY total DESC
", [$date_from, $date_to]);

// Revenue Breakdown
$revenue_categories = qAll($pdo, "
    SELECT fc.category_name, COALESCE(SUM(ft.amount),0) as total
    FROM finance_transactions ft
    JOIN finance_categories fc ON ft.category_id = fc.category_id
    WHERE ft.transaction_type = 'CREDIT' AND ft.transaction_date BETWEEN ? AND ?
    GROUP BY fc.category_id, fc.category_name
    ORDER BY total DESC
", [$date_from, $date_to]);

// Cash Flow Trend
$cashflow_trend = qAll($pdo, "
    SELECT cashflow_date, opening_balance, cash_in, cash_out, closing_balance
    FROM finance_cashflow WHERE cashflow_date BETWEEN ? AND ?
    ORDER BY cashflow_date
", [$date_from, $date_to]);

// Budget vs Actual
$budget_comparison = qAll($pdo, "
    SELECT fc.category_name, fb.budget_amount, fb.actual_amount,
           (fb.actual_amount - fb.budget_amount) as variance
    FROM finance_budgets fb
    JOIN finance_categories fc ON fb.category_id = fc.category_id
    WHERE fb.budget_month = ?
    ORDER BY fb.budget_amount DESC
", [date('Y-m-01', strtotime($date_from))]);

// P&L Monthly
$pnl_monthly = qAll($pdo, "
    SELECT pnl_month, total_revenue, total_expenses, gross_profit, profit_margin
    FROM finance_pnl ORDER BY pnl_month DESC LIMIT 12
");

// Recent Transactions
$recent_transactions = qAll($pdo, "
    SELECT ft.*, fc.category_name
    FROM finance_transactions ft
    JOIN finance_categories fc ON ft.category_id = fc.category_id
    WHERE ft.transaction_date BETWEEN ? AND ?
    ORDER BY ft.transaction_date DESC, ft.transaction_id DESC LIMIT 15
", [$date_from, $date_to]);

// Pending Invoices
$pending_invoices = qAll($pdo, "
    SELECT * FROM finance_invoices WHERE status IN ('PENDING', 'PARTIALLY_PAID', 'OVERDUE')
    ORDER BY due_date ASC
");

// Payment Method Breakdown
$payment_methods = qAll($pdo, "
    SELECT payment_method, COUNT(*) as count, COALESCE(SUM(amount),0) as total
    FROM finance_transactions WHERE transaction_date BETWEEN ? AND ?
    GROUP BY payment_method ORDER BY total DESC
", [$date_from, $date_to]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpeedX - Finance Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',Tahoma,sans-serif;background:#f0f2f5;color:#2d3748}
        .header{background:linear-gradient(135deg,#276749,#1a4732);color:white;padding:12px 25px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}
        .header h1{font-size:20px}.header a{color:white;text-decoration:none}
        .btn{background:rgba(255,255,255,0.15);padding:7px 14px;border-radius:6px;font-size:12px;font-weight:600}.btn:hover{background:rgba(255,255,255,0.25)}
        .date-form{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.1);padding:8px 14px;border-radius:8px}
        .date-form input{padding:6px 10px;border:1px solid rgba(255,255,255,0.3);background:rgba(255,255,255,0.1);color:white;border-radius:4px;font-size:11px;width:130px}
        .date-form button{padding:6px 14px;background:white;color:#276749;border:none;border-radius:4px;cursor:pointer;font-weight:600;font-size:11px}
        .reset-link{color:white;font-size:10px;text-decoration:underline;margin-left:4px}
        .container{max-width:1500px;margin:15px auto;padding:0 15px}
        .info-bar{background:white;padding:12px 18px;border-radius:8px;margin-bottom:15px;display:flex;justify-content:space-between;align-items:center;font-size:13px;box-shadow:0 1px 4px rgba(0,0,0,0.05);flex-wrap:wrap;gap:8px}
        .kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:15px}
        .kpi-card{background:white;padding:18px;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,0.05);border-left:4px solid #276749;text-align:center;transition:transform 0.2s}
        .kpi-card:hover{transform:translateY(-2px)}.kpi-card.green{border-left-color:#48bb78}.kpi-card.red{border-left-color:#e53e3e}.kpi-card.blue{border-left-color:#4299e1}.kpi-card.orange{border-left-color:#f6ad55}
        .kpi-icon{font-size:24px;margin-bottom:8px}.kpi-value{font-size:26px;font-weight:bold;color:#1a202c}.kpi-label{font-size:10px;color:#718096;text-transform:uppercase;letter-spacing:.5px;margin-top:5px}.kpi-sub{font-size:9px;color:#a0aec0;margin-top:3px}
        .kpi-change{font-size:10px;font-weight:700;padding:2px 8px;border-radius:8px;display:inline-block;margin-top:4px}.change-up{background:#c6f6d5;color:#276749}.change-down{background:#fed7d7;color:#9b2c2c}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-bottom:15px}.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin-bottom:15px}
        .card{background:white;padding:20px;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,0.05)}.card h3{font-size:14px;color:#2d3748;margin-bottom:15px;padding-bottom:10px;border-bottom:2px solid #e2e8f0}.card canvas{max-height:250px}
        table{width:100%;border-collapse:collapse;font-size:12px}th{background:#f7fafc;padding:8px 10px;text-align:left;font-size:10px;text-transform:uppercase;color:#4a5568}td{padding:8px 10px;border-bottom:1px solid #e2e8f0}tr:hover{background:#f7fafc}
        .badge{padding:3px 10px;border-radius:10px;font-size:10px;font-weight:600}.badge-green{background:#c6f6d5;color:#276749}.badge-red{background:#fed7d7;color:#9b2c2c}.badge-yellow{background:#fefcbf;color:#975a16}.badge-blue{background:#bee3f8;color:#2a4365}
        .text-green{color:#48bb78}.text-red{color:#e53e3e}.text-bold{font-weight:700}
        @media(max-width:1200px){.kpi-row{grid-template-columns:repeat(2,1fr)}.grid-3{grid-template-columns:1fr 1fr}}
        @media(max-width:768px){.kpi-row{grid-template-columns:1fr}.grid-2,.grid-3{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="header">
        <div><h1>💰 Finance Dashboard</h1><small style="opacity:0.7">SpeedX Quick Commerce</small></div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <form class="date-form" method="GET">
                <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                <span style="color:white;font-size:11px">→</span>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                <button type="submit">🔍 Filter</button>
                <a href="?date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="reset-link">↺ Reset</a>
            </form>
            <a href="../dashboard.php" class="btn">🏠 Menu</a>
            <a href="../logout.php" class="btn">🚪 Logout</a>
        </div>
    </div>
    
    <div class="container">
        <div class="info-bar">
            <div><strong style="color:#276749">📅 <?php echo date('d M Y',strtotime($date_from)); ?> - <?php echo date('d M Y',strtotime($date_to)); ?></strong></div>
            <div style="color:#718096;font-size:12px">Cash Balance: <strong>₹<?php echo number_format($current_balance,0); ?></strong> | Receivables: <strong>₹<?php echo number_format($pending_amount,0); ?></strong></div>
        </div>
        
        <!-- KPI Row 1 -->
        <div class="kpi-row">
            <div class="kpi-card green">
                <div class="kpi-icon">💰</div>
                <div class="kpi-value">₹<?php echo number_format($total_revenue,0); ?></div>
                <div class="kpi-label">Total Revenue</div>
                <span class="kpi-change <?php echo pct($total_revenue,$prev_revenue)>=0?'change-up':'change-down'; ?>"><?php echo pct($total_revenue,$prev_revenue)>=0?'▲':'▼'; ?> <?php echo abs(pct($total_revenue,$prev_revenue)); ?>% vs prev</span>
            </div>
            <div class="kpi-card red">
                <div class="kpi-icon">💸</div>
                <div class="kpi-value">₹<?php echo number_format($total_expenses,0); ?></div>
                <div class="kpi-label">Total Expenses</div>
                <span class="kpi-change <?php echo pct($total_expenses,$prev_expenses)<=0?'change-up':'change-down'; ?>"><?php echo pct($total_expenses,$prev_expenses)>=0?'▲':'▼'; ?> <?php echo abs(pct($total_expenses,$prev_expenses)); ?>% vs prev</span>
            </div>
            <div class="kpi-card <?php echo $net_profit>=0?'green':'red'; ?>">
                <div class="kpi-icon">📊</div>
                <div class="kpi-value">₹<?php echo number_format($net_profit,0); ?></div>
                <div class="kpi-label">Net Profit</div>
                <div class="kpi-sub">Margin: <?php echo $profit_margin; ?>%</div>
            </div>
            <div class="kpi-card blue">
                <div class="kpi-icon">🏦</div>
                <div class="kpi-value">₹<?php echo number_format($current_balance,0); ?></div>
                <div class="kpi-label">Cash Balance</div>
                <div class="kpi-sub">Receivables: ₹<?php echo number_format($pending_amount,0); ?></div>
            </div>
        </div>
        
        <!-- KPI Row 2 -->
        <div class="kpi-row">
            <div class="kpi-card orange">
                <div class="kpi-icon">📋</div>
                <div class="kpi-value">₹<?php echo number_format($total_invoiced,0); ?></div>
                <div class="kpi-label">Invoiced (Period)</div>
            </div>
            <div class="kpi-card red">
                <div class="kpi-icon">⚠️</div>
                <div class="kpi-value">₹<?php echo number_format($overdue_amount,0); ?></div>
                <div class="kpi-label">Overdue Amount</div>
            </div>
            <div class="kpi-card green">
                <div class="kpi-icon">✅</div>
                <div class="kpi-value"><?php echo $profit_margin; ?>%</div>
                <div class="kpi-label">Profit Margin</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon">📊</div>
                <div class="kpi-value">₹<?php echo number_format($total_revenue - $total_expenses,0); ?></div>
                <div class="kpi-label">EBITDA (Approx)</div>
            </div>
        </div>
        
        <!-- Charts Row 1 -->
        <div class="grid-2">
            <div class="card"><h3>📈 Revenue vs Expenses Daily</h3><canvas id="chartTrend"></canvas></div>
            <div class="card"><h3>💸 Expense Breakdown</h3><canvas id="chartExpenses"></canvas></div>
        </div>
        
        <!-- Charts Row 2 -->
        <div class="grid-2">
            <div class="card"><h3>💰 Revenue Sources</h3><canvas id="chartRevenue"></canvas></div>
            <div class="card"><h3>📊 Budget vs Actual</h3><canvas id="chartBudget"></canvas></div>
        </div>
        
        <!-- Charts Row 3 -->
        <div class="grid-2">
            <div class="card"><h3>🏦 Cash Flow</h3><canvas id="chartCashflow"></canvas></div>
            <div class="card"><h3>💳 Payment Methods</h3><canvas id="chartPayment"></canvas></div>
        </div>
        
        <!-- Tables -->
        <div class="grid-2">
            <div class="card">
                <h3>📋 Recent Transactions</h3>
                <div style="max-height:300px;overflow-y:auto">
                    <table>
                        <thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Amount</th><th>Type</th></tr></thead>
                        <tbody>
                            <?php foreach($recent_transactions as $t): ?>
                            <tr>
                                <td><?php echo date('d M',strtotime($t['transaction_date'])); ?></td>
                                <td><?php echo htmlspecialchars($t['description']); ?></td>
                                <td><?php echo htmlspecialchars($t['category_name']); ?></td>
                                <td class="<?php echo $t['transaction_type']=='CREDIT'?'text-green':'text-red'; ?> text-bold"><?php echo $t['transaction_type']=='CREDIT'?'+':'-'; ?>₹<?php echo number_format($t['amount'],0); ?></td>
                                <td><span class="badge <?php echo $t['transaction_type']=='CREDIT'?'badge-green':'badge-red'; ?>"><?php echo $t['transaction_type']; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <h3>📋 Pending Invoices</h3>
                <div style="max-height:300px;overflow-y:auto">
                    <table>
                        <thead><tr><th>Invoice</th><th>Customer</th><th>Due Date</th><th>Amount</th><th>Paid</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach($pending_invoices as $inv): 
                                $badge = $inv['status']=='OVERDUE'?'badge-red':($inv['status']=='PARTIALLY_PAID'?'badge-yellow':'badge-blue'); ?>
                            <tr>
                                <td><strong><?php echo $inv['invoice_number']; ?></strong></td>
                                <td><?php echo htmlspecialchars($inv['customer_name']); ?></td>
                                <td><?php echo date('d M',strtotime($inv['due_date'])); ?></td>
                                <td class="text-bold">₹<?php echo number_format($inv['amount'],0); ?></td>
                                <td>₹<?php echo number_format($inv['paid_amount'],0); ?></td>
                                <td><span class="badge <?php echo $badge; ?>"><?php echo str_replace('_',' ',$inv['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- P&L Table -->
        <div class="card">
            <h3>📊 Profit & Loss Summary</h3>
            <table>
                <thead><tr><th>Month</th><th>Revenue</th><th>Expenses</th><th>Gross Profit</th><th>Margin</th></tr></thead>
                <tbody>
                    <?php foreach($pnl_monthly as $pnl): ?>
                    <tr>
                        <td><strong><?php echo date('M Y',strtotime($pnl['pnl_month'])); ?></strong></td>
                        <td class="text-green text-bold">₹<?php echo number_format($pnl['total_revenue'],0); ?></td>
                        <td class="text-red text-bold">₹<?php echo number_format($pnl['total_expenses'],0); ?></td>
                        <td class="text-bold">₹<?php echo number_format($pnl['gross_profit'],0); ?></td>
                        <td><span class="badge <?php echo $pnl['profit_margin']>=20?'badge-green':($pnl['profit_margin']>=10?'badge-yellow':'badge-red'); ?>"><?php echo $pnl['profit_margin']; ?>%</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded',function(){
        // Revenue vs Expenses Trend
        var d1=<?php echo json_encode($daily_trend); ?>;
        if(d1.length>0){new Chart(document.getElementById('chartTrend'),{type:'bar',data:{labels:d1.map(x=>x.transaction_date),datasets:[{label:'Revenue',data:d1.map(x=>x.revenue),backgroundColor:'#48bb78',borderRadius:3},{label:'Expenses',data:d1.map(x=>x.expenses),backgroundColor:'#e53e3e',borderRadius:3}]},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{boxWidth:10,font:{size:10}}}}}});}
        
        // Expense Breakdown
        var d2=<?php echo json_encode($expense_categories); ?>;
        if(d2.length>0){new Chart(document.getElementById('chartExpenses'),{type:'doughnut',data:{labels:d2.map(x=>x.category_name),datasets:[{data:d2.map(x=>x.total),backgroundColor:['#e53e3e','#ed8936','#f6ad55','#9f7aea','#4299e1','#a0aec0','#48bb78','#38b2ac']}]},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{boxWidth:10,font:{size:9}}}}}});}
        
        // Revenue Sources
        var d3=<?php echo json_encode($revenue_categories); ?>;
        if(d3.length>0){new Chart(document.getElementById('chartRevenue'),{type:'pie',data:{labels:d3.map(x=>x.category_name),datasets:[{data:d3.map(x=>x.total),backgroundColor:['#48bb78','#38b2ac','#4299e1','#9f7aea','#f6ad55']}]},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{boxWidth:10,font:{size:9}}}}}});}
        
        // Budget vs Actual
        var d4=<?php echo json_encode($budget_comparison); ?>;
        if(d4.length>0){new Chart(document.getElementById('chartBudget'),{type:'bar',data:{labels:d4.map(x=>x.category_name.length>12?x.category_name.substring(0,12)+'...':x.category_name),datasets:[{label:'Budget',data:d4.map(x=>x.budget_amount),backgroundColor:'#a0aec0',borderRadius:3},{label:'Actual',data:d4.map(x=>x.actual_amount),backgroundColor:'#276749',borderRadius:3}]},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{boxWidth:10,font:{size:10}}}}}});}
        
        // Cash Flow
        var d5=<?php echo json_encode($cashflow_trend); ?>;
        if(d5.length>0){new Chart(document.getElementById('chartCashflow'),{type:'line',data:{labels:d5.map(x=>x.cashflow_date),datasets:[{label:'Balance',data:d5.map(x=>x.closing_balance),borderColor:'#276749',backgroundColor:'rgba(39,103,73,0.1)',tension:0.3,fill:true}]},options:{responsive:true,plugins:{legend:{display:false}}}});}
        
        // Payment Methods
        var d6=<?php echo json_encode($payment_methods); ?>;
        if(d6.length>0){new Chart(document.getElementById('chartPayment'),{type:'pie',data:{labels:d6.map(x=>x.payment_method),datasets:[{data:d6.map(x=>x.total),backgroundColor:['#276749','#48bb78','#4299e1','#ed8936','#9f7aea']}]},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{boxWidth:10,font:{size:9}}}}}});}
    });
    </script>
</body>
</html>