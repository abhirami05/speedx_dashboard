<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.html');
    exit();
}

$user_name = $_SESSION['full_name'];
$user_role = $_SESSION['role'];

try {
     $pdo = new PDO("mysql:host=sql201.infinityfree.com;dbname=if0_42049613_speedx_dashboard", "if0_42049613", "9846294820Amma","" [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch(PDOException $e) { die("Connection failed: " . $e->getMessage()); }

// Get actual date range from attendance data
$att_min_date = $pdo->query("SELECT MIN(attendance_date) FROM attendance")->fetchColumn() ?: date('Y-m-d');
$att_max_date = $pdo->query("SELECT MAX(attendance_date) FROM attendance")->fetchColumn() ?: date('Y-m-d');

// Date filter - default to data range if available
$date_from = $_GET['date_from'] ?? $att_min_date;
$date_to = $_GET['date_to'] ?? $att_max_date;

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

// ==========================================
// WORKFORCE OVERVIEW
// ==========================================
$total_employees = q($pdo, "SELECT COUNT(*) FROM employees");
$active_employees = q($pdo, "SELECT COUNT(*) FROM employees WHERE employee_status = 'ACTIVE'");
$inactive_employees = q($pdo, "SELECT COUNT(*) FROM employees WHERE employee_status = 'INACTIVE'");
$resigned_employees = q($pdo, "SELECT COUNT(*) FROM employees WHERE employee_status = 'RESIGNED'");
$terminated_employees = q($pdo, "SELECT COUNT(*) FROM employees WHERE employee_status = 'TERMINATED'");

// New hires
$new_hires = q($pdo, "SELECT COUNT(*) FROM employees WHERE joining_date BETWEEN ? AND ?", [$date_from, $date_to]);

// ==========================================
// ATTRITION
// ==========================================
$attrition_count = q($pdo, "SELECT COUNT(*) FROM attrition WHERE resignation_date BETWEEN ? AND ?", [$date_from, $date_to]);
$attrition_rate = $total_employees > 0 ? round(($attrition_count / $total_employees) * 100, 2) : 0;
$avg_tenure = q($pdo, "SELECT ROUND(AVG(tenure_months), 1) FROM attrition WHERE resignation_date BETWEEN ? AND ?", [$date_from, $date_to]);

$attrition_reasons = qAll($pdo, "
    SELECT exit_reason, COUNT(*) as cnt 
    FROM attrition 
    WHERE resignation_date BETWEEN ? AND ? 
    GROUP BY exit_reason 
    ORDER BY cnt DESC
", [$date_from, $date_to]);

// ==========================================
// ATTENDANCE - FIXED
// ==========================================
$has_attendance = q($pdo, "SELECT COUNT(*) FROM attendance");

$total_attendance_days = q($pdo, "SELECT COUNT(*) FROM attendance WHERE attendance_date BETWEEN ? AND ?", [$date_from, $date_to]);
$present_days = q($pdo, "SELECT COUNT(*) FROM attendance WHERE attendance_status = 'PRESENT' AND attendance_date BETWEEN ? AND ?", [$date_from, $date_to]);
$absent_days = q($pdo, "SELECT COUNT(*) FROM attendance WHERE attendance_status = 'ABSENT' AND attendance_date BETWEEN ? AND ?", [$date_from, $date_to]);
$half_days = q($pdo, "SELECT COUNT(*) FROM attendance WHERE attendance_status = 'HALF_DAY' AND attendance_date BETWEEN ? AND ?", [$date_from, $date_to]);
$leave_days = q($pdo, "SELECT COUNT(*) FROM attendance WHERE attendance_status = 'LEAVE' AND attendance_date BETWEEN ? AND ?", [$date_from, $date_to]);

$attendance_rate = $total_attendance_days > 0 ? round(($present_days / $total_attendance_days) * 100, 1) : 0;
$absenteeism_rate = $total_attendance_days > 0 ? round(($absent_days / $total_attendance_days) * 100, 1) : 0;

$avg_working_hours = q($pdo, "
    SELECT ROUND(AVG(working_hours), 1) FROM attendance 
    WHERE attendance_date BETWEEN ? AND ? AND working_hours IS NOT NULL AND working_hours > 0
", [$date_from, $date_to]);
$avg_working_hours = $avg_working_hours ?: 0;

// Daily attendance trend
$attendance_trend = qAll($pdo, "
    SELECT attendance_date, COUNT(*) as total,
        SUM(CASE WHEN attendance_status = 'PRESENT' THEN 1 ELSE 0 END) as present,
        SUM(CASE WHEN attendance_status = 'ABSENT' THEN 1 ELSE 0 END) as absent
    FROM attendance WHERE attendance_date BETWEEN ? AND ?
    GROUP BY attendance_date ORDER BY attendance_date
", [$date_from, $date_to]);

// Department-wise attendance
$dept_attendance = qAll($pdo, "
    SELECT e.department, COUNT(DISTINCT a.attendance_id) as total_records,
        SUM(CASE WHEN a.attendance_status = 'PRESENT' THEN 1 ELSE 0 END) as present_count
    FROM attendance a JOIN employees e ON a.employee_id = e.employee_id
    WHERE a.attendance_date BETWEEN ? AND ?
    GROUP BY e.department
", [$date_from, $date_to]);

// ==========================================
// RECRUITMENT
// ==========================================
$open_positions = q($pdo, "SELECT COUNT(DISTINCT position_applied) FROM recruitment WHERE joining_status = 'PENDING' AND interview_status != 'REJECTED'");
$candidates_pipeline = q($pdo, "SELECT COUNT(*) FROM recruitment WHERE interview_status IN ('SCREENING', 'INTERVIEW')");
$total_candidates = q($pdo, "SELECT COUNT(*) FROM recruitment WHERE application_date BETWEEN ? AND ?", [$date_from, $date_to]);
$joined_candidates = q($pdo, "SELECT COUNT(*) FROM recruitment WHERE joining_status = 'JOINED' AND application_date BETWEEN ? AND ?", [$date_from, $date_to]);
$hiring_success_rate = $total_candidates > 0 ? round(($joined_candidates / $total_candidates) * 100, 1) : 0;

$candidates_by_source = qAll($pdo, "SELECT source, COUNT(*) as cnt FROM recruitment WHERE application_date BETWEEN ? AND ? GROUP BY source ORDER BY cnt DESC", [$date_from, $date_to]);
$recent_candidates = qAll($pdo, "SELECT * FROM recruitment WHERE application_date BETWEEN ? AND ? ORDER BY application_date DESC LIMIT 10", [$date_from, $date_to]);

// ==========================================
// PERFORMANCE
// ==========================================
$avg_performance = q($pdo, "SELECT ROUND(AVG(productivity_score), 1) FROM employee_performance") ?: 0;
$high_performers = q($pdo, "SELECT COUNT(*) FROM employee_performance WHERE performance_category = 'HIGH'");
$medium_performers = q($pdo, "SELECT COUNT(*) FROM employee_performance WHERE performance_category = 'MEDIUM'");
$low_performers = q($pdo, "SELECT COUNT(*) FROM employee_performance WHERE performance_category = 'LOW'");

$performance_dist = [
    ['category' => 'High', 'count' => (int)$high_performers, 'color' => '#48bb78'],
    ['category' => 'Medium', 'count' => (int)$medium_performers, 'color' => '#f6ad55'],
    ['category' => 'Low', 'count' => (int)$low_performers, 'color' => '#e53e3e'],
];

$dept_performance = qAll($pdo, "
    SELECT e.department, ROUND(AVG(ep.productivity_score), 1) as avg_productivity,
        ROUND(AVG(ep.manager_rating), 1) as avg_manager_rating, COUNT(*) as employees_reviewed
    FROM employee_performance ep JOIN employees e ON ep.employee_id = e.employee_id
    GROUP BY e.department ORDER BY avg_productivity DESC
");

// ==========================================
// LEAVES & TRAINING
// ==========================================
$leaves_by_type = qAll($pdo, "SELECT leave_type, COUNT(*) as cnt FROM leaves WHERE start_date BETWEEN ? AND ? GROUP BY leave_type ORDER BY cnt DESC", [$date_from, $date_to]);
$total_leaves = q($pdo, "SELECT COUNT(*) FROM leaves WHERE start_date BETWEEN ? AND ?", [$date_from, $date_to]);
$pending_leaves = q($pdo, "SELECT COUNT(*) FROM leaves WHERE leave_status = 'PENDING' AND start_date BETWEEN ? AND ?", [$date_from, $date_to]);

$total_trainings = q($pdo, "SELECT COUNT(*) FROM training_records WHERE completion_date BETWEEN ? AND ?", [$date_from, $date_to]);
$completed_trainings = q($pdo, "SELECT COUNT(*) FROM training_records WHERE certification_status = 'COMPLETED' AND completion_date BETWEEN ? AND ?", [$date_from, $date_to]);

// Department distribution
$dept_distribution = qAll($pdo, "SELECT department, COUNT(*) as cnt FROM employees WHERE employee_status = 'ACTIVE' GROUP BY department ORDER BY cnt DESC");

// Recent hires
$recent_hires = qAll($pdo, "SELECT employee_name, department, designation, joining_date FROM employees WHERE joining_date BETWEEN ? AND ? ORDER BY joining_date DESC LIMIT 10", [$date_from, $date_to]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SpeedX - HR Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; }
        
        .top-header {
            background: linear-gradient(135deg, #c53030, #9b2c2c);
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
            padding: 6px 12px; background: white; color: #c53030;
            border: none; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 12px;
        }
        .date-filter label { font-size: 12px; }
        .btn { background: rgba(255,255,255,0.15); color: white; padding: 7px 14px; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; }
        
        .container { max-width: 1600px; margin: 12px auto; padding: 0 15px; }
        
        .kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 12px; }
        .kpi-card {
            background: white; padding: 15px 18px; border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04); border-left: 3px solid #c53030; text-align: center;
        }
        .kpi-card.green { border-left-color: #48bb78; }
        .kpi-card.blue { border-left-color: #4299e1; }
        .kpi-card.orange { border-left-color: #ed8936; }
        .kpi-card.red { border-left-color: #e53e3e; }
        
        .kpi-icon { font-size: 24px; margin-bottom: 5px; }
        .kpi-value { font-size: 26px; font-weight: bold; color: #1a202c; }
        .kpi-label { font-size: 10px; color: #718096; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }
        .kpi-sub { font-size: 10px; color: #a0aec0; margin-top: 2px; }
        
        .section-title { font-size: 15px; color: #2d3748; font-weight: 700; margin: 18px 0 10px 0; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0; }
        
        .charts-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px; }
        .card {
            background: white; padding: 15px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .card h3 { font-size: 13px; color: #2d3748; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; }
        .card canvas { max-height: 220px; }
        
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th { background: #f7fafc; padding: 7px 8px; text-align: left; font-size: 9px; text-transform: uppercase; color: #4a5568; }
        td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
        tr:hover { background: #f7fafc; }
        
        .badge { padding: 2px 8px; border-radius: 6px; font-size: 9px; font-weight: 600; }
        .badge-green { background: #c6f6d5; color: #276749; }
        .badge-red { background: #fed7d7; color: #9b2c2c; }
        .badge-yellow { background: #fefcbf; color: #975a16; }
        .badge-purple { background: #e9d8fd; color: #553c9a; }
        
        @media (max-width: 1200px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } .charts-2 { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .kpi-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="top-header">
        <div>
            <h1>👥 HR Dashboard</h1>
            <small style="opacity:0.7;">SpeedX Quick Commerce • Data: <?php echo date('d M', strtotime($att_min_date)); ?> - <?php echo date('d M', strtotime($att_max_date)); ?></small>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <form class="date-filter" method="GET">
                <label>From:</label>
                <input type="date" name="date_from" value="<?php echo $date_from; ?>">
                <label>To:</label>
                <input type="date" name="date_to" value="<?php echo $date_to; ?>">
                <button type="submit">🔍 Apply</button>
            </form>
            <a href="../dashboard.php" class="btn">🏠 Menu</a>
            <a href="../logout.php" class="btn">🚪 Logout</a>
        </div>
    </div>
    
    <div class="container">
        <!-- WORKFORCE OVERVIEW -->
        <div class="section-title">👥 Workforce Overview</div>
        <div class="kpi-row">
            <div class="kpi-card"><div class="kpi-icon">👥</div><div class="kpi-value"><?php echo $total_employees; ?></div><div class="kpi-label">Total Employees</div></div>
            <div class="kpi-card green"><div class="kpi-icon">✅</div><div class="kpi-value"><?php echo $active_employees; ?></div><div class="kpi-label">Active</div><div class="kpi-sub"><?php echo $inactive_employees; ?> Inactive | <?php echo $resigned_employees; ?> Resigned</div></div>
            <div class="kpi-card blue"><div class="kpi-icon">🆕</div><div class="kpi-value"><?php echo $new_hires; ?></div><div class="kpi-label">New Hires</div></div>
            <div class="kpi-card <?php echo $attrition_rate > 10 ? 'red' : 'green'; ?>"><div class="kpi-icon">🚪</div><div class="kpi-value"><?php echo $attrition_rate; ?>%</div><div class="kpi-label">Attrition Rate</div><div class="kpi-sub"><?php echo $attrition_count; ?> resigned | <?php echo $avg_tenure; ?> mo avg</div></div>
        </div>
        
        <div class="charts-2">
            <div class="card"><h3>🏢 Department Distribution</h3><canvas id="deptChart"></canvas></div>
            <div class="card"><h3>📋 Attrition Reasons</h3><canvas id="attritionChart"></canvas></div>
        </div>
        
        <!-- ATTENDANCE -->
        <div class="section-title">📋 Attendance (<?php echo number_format($total_attendance_days); ?> records)</div>
        <div class="kpi-row">
            <div class="kpi-card green"><div class="kpi-icon">📊</div><div class="kpi-value"><?php echo $attendance_rate; ?>%</div><div class="kpi-label">Attendance Rate</div><div class="kpi-sub"><?php echo $present_days; ?> present</div></div>
            <div class="kpi-card <?php echo $absenteeism_rate > 5 ? 'red' : 'green'; ?>"><div class="kpi-icon">⚠️</div><div class="kpi-value"><?php echo $absenteeism_rate; ?>%</div><div class="kpi-label">Absenteeism</div><div class="kpi-sub"><?php echo $absent_days; ?> absent</div></div>
            <div class="kpi-card blue"><div class="kpi-icon">⏱️</div><div class="kpi-value"><?php echo $avg_working_hours; ?>h</div><div class="kpi-label">Avg Working Hours</div></div>
            <div class="kpi-card"><div class="kpi-icon">📅</div><div class="kpi-value"><?php echo $half_days + $leave_days; ?></div><div class="kpi-label">Half Day + Leave</div><div class="kpi-sub"><?php echo $half_days; ?> half | <?php echo $leave_days; ?> leave</div></div>
        </div>
        
        <div class="charts-2">
            <div class="card"><h3>📈 Daily Attendance Trend</h3><canvas id="attendanceTrend"></canvas></div>
            <div class="card"><h3>📊 Attendance Status</h3><canvas id="attendanceStatus"></canvas></div>
        </div>
        
        <!-- RECRUITMENT -->
        <div class="section-title">🎯 Recruitment</div>
        <div class="kpi-row">
            <div class="kpi-card orange"><div class="kpi-icon">📋</div><div class="kpi-value"><?php echo $open_positions; ?></div><div class="kpi-label">Open Positions</div></div>
            <div class="kpi-card blue"><div class="kpi-icon">👥</div><div class="kpi-value"><?php echo $candidates_pipeline; ?></div><div class="kpi-label">In Pipeline</div></div>
            <div class="kpi-card green"><div class="kpi-icon">✅</div><div class="kpi-value"><?php echo $hiring_success_rate; ?>%</div><div class="kpi-label">Hiring Success</div><div class="kpi-sub"><?php echo $joined_candidates; ?>/<?php echo $total_candidates; ?> joined</div></div>
            <div class="kpi-card"><div class="kpi-icon">📢</div><div class="kpi-value"><?php echo $total_candidates; ?></div><div class="kpi-label">Total Applicants</div></div>
        </div>
        
        <div class="charts-2">
            <div class="card"><h3>📊 Candidates by Source</h3><canvas id="sourceChart"></canvas></div>
            <div class="card"><h3>📋 Recent Candidates</h3>
                <div style="max-height:220px;overflow-y:auto;">
                    <table>
                        <thead><tr><th>Candidate</th><th>Position</th><th>Source</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($recent_candidates as $c): 
                                $badge = 'badge-yellow';
                                if ($c['interview_status'] == 'SELECTED') $badge = 'badge-green';
                                if ($c['interview_status'] == 'REJECTED') $badge = 'badge-red';
                            ?>
                            <tr><td><strong><?php echo htmlspecialchars($c['candidate_name']); ?></strong></td><td><?php echo htmlspecialchars($c['position_applied']); ?></td><td><?php echo $c['source']; ?></td><td><span class="badge <?php echo $badge; ?>"><?php echo $c['interview_status']; ?></span></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- PERFORMANCE -->
        <div class="section-title">⭐ Performance</div>
        <div class="kpi-row">
            <div class="kpi-card green"><div class="kpi-icon">⭐</div><div class="kpi-value"><?php echo $avg_performance; ?>%</div><div class="kpi-label">Avg Score</div></div>
            <div class="kpi-card"><div class="kpi-icon">🏆</div><div class="kpi-value"><?php echo $high_performers; ?></div><div class="kpi-label">High</div></div>
            <div class="kpi-card orange"><div class="kpi-icon">📊</div><div class="kpi-value"><?php echo $medium_performers; ?></div><div class="kpi-label">Medium</div></div>
            <div class="kpi-card red"><div class="kpi-icon">⚠️</div><div class="kpi-value"><?php echo $low_performers; ?></div><div class="kpi-label">Low</div></div>
        </div>
        
        <div class="charts-2">
            <div class="card"><h3>📊 Performance Distribution</h3><canvas id="perfChart"></canvas></div>
            <div class="card"><h3>🏢 Dept Performance</h3><canvas id="deptPerfChart"></canvas></div>
        </div>
        
        <!-- RECENT HIRES -->
        <div class="card">
            <h3>🆕 Recent Hires</h3>
            <table>
                <thead><tr><th>Employee</th><th>Department</th><th>Designation</th><th>Joining Date</th></tr></thead>
                <tbody>
                    <?php foreach ($recent_hires as $h): ?>
                    <tr><td><strong><?php echo htmlspecialchars($h['employee_name']); ?></strong></td><td><span class="badge badge-purple"><?php echo $h['department']; ?></span></td><td><?php echo htmlspecialchars($h['designation'] ?? '-'); ?></td><td><?php echo $h['joining_date']; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
    // Department Distribution
    const deptData = <?php echo json_encode($dept_distribution); ?>;
    if (deptData.length > 0) {
        new Chart(document.getElementById('deptChart'), {
            type: 'doughnut',
            data: { labels: deptData.map(d => d.department), datasets: [{ data: deptData.map(d => d.cnt), backgroundColor: ['#c53030','#4299e1','#48bb78','#ed8936','#9f7aea','#f6ad55'] }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } } } }
        });
    }
    
    // Attrition Reasons
    const attrData = <?php echo json_encode($attrition_reasons); ?>;
    if (attrData.length > 0) {
        new Chart(document.getElementById('attritionChart'), {
            type: 'bar', data: { labels: attrData.map(d => d.exit_reason), datasets: [{ data: attrData.map(d => d.cnt), backgroundColor: '#c53030', borderRadius: 3 }] },
            options: { responsive: true, indexAxis: 'y', plugins: { legend: { display: false } } }
        });
    }
    
    // Attendance Trend
    const attTrend = <?php echo json_encode($attendance_trend); ?>;
    if (attTrend.length > 0) {
        new Chart(document.getElementById('attendanceTrend'), {
            type: 'line',
            data: { labels: attTrend.map(d => d.attendance_date), datasets: [{ label: 'Present', data: attTrend.map(d => d.present), borderColor: '#48bb78', tension: 0.3 }, { label: 'Absent', data: attTrend.map(d => d.absent), borderColor: '#e53e3e', tension: 0.3 }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } } } }
        });
    }
    
    // Attendance Status
    new Chart(document.getElementById('attendanceStatus'), {
        type: 'doughnut',
        data: { labels: ['Present','Absent','Half Day','Leave'], datasets: [{ data: [<?php echo "$present_days,$absent_days,$half_days,$leave_days"; ?>], backgroundColor: ['#48bb78','#e53e3e','#f6ad55','#4299e1'] }] },
        options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } } } }
    });
    
    // Candidates by Source
    const sourceData = <?php echo json_encode($candidates_by_source); ?>;
    if (sourceData.length > 0) {
        new Chart(document.getElementById('sourceChart'), {
            type: 'pie', data: { labels: sourceData.map(d => d.source), datasets: [{ data: sourceData.map(d => d.cnt), backgroundColor: ['#4299e1','#48bb78','#ed8936','#9f7aea','#e53e3e'] }] },
            options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } } } }
        });
    }
    
    // Performance Distribution
    const perfData = <?php echo json_encode($performance_dist); ?>;
    new Chart(document.getElementById('perfChart'), {
        type: 'doughnut',
        data: { labels: perfData.map(d => d.category), datasets: [{ data: perfData.map(d => d.count), backgroundColor: perfData.map(d => d.color) }] },
        options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } } } }
    });
    
    // Department Performance
    const deptPerf = <?php echo json_encode($dept_performance); ?>;
    if (deptPerf.length > 0) {
        new Chart(document.getElementById('deptPerfChart'), {
            type: 'bar', data: { labels: deptPerf.map(d => d.department), datasets: [{ label: 'Productivity', data: deptPerf.map(d => d.avg_productivity), backgroundColor: '#c53030', borderRadius: 3 }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { max: 100 } } }
        });
    }
    </script>
</body>
</html>