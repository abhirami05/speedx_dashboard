<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ceo', 'mis_manager'])) {
    header('Location: dashboard.php');
    exit();
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=speedx_dashboard", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $message = '';
    $error = '';
    
    // ADD DEPARTMENT
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'add_department') {
        $name = trim($_POST['department_name']);
        $description = trim($_POST['description']);
        
        if (!empty($name)) {
            $stmt = $pdo->prepare("INSERT INTO departments (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $description]);
            $message = "✅ Department '$name' added successfully!";
        } else {
            $error = "❌ Department name cannot be empty!";
        }
    }
    
    // EDIT DEPARTMENT
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'edit_department') {
        $id = $_POST['department_id'];
        $name = trim($_POST['department_name']);
        $description = trim($_POST['description']);
        
        $stmt = $pdo->prepare("UPDATE departments SET name = ?, description = ? WHERE id = ?");
        $stmt->execute([$name, $description, $id]);
        $message = "✅ Department updated successfully!";
    }
    
    // DELETE DEPARTMENT
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'delete_department') {
        $id = $_POST['department_id'];
        
        // Check if department has users
        $check = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE department = (SELECT name FROM departments WHERE id = ?)");
        $check->execute([$id]);
        $user_count = $check->fetch()['count'];
        
        if ($user_count > 0) {
            $error = "❌ Cannot delete! $user_count user(s) are assigned to this department. Reassign them first.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
            $stmt->execute([$id]);
            $message = "✅ Department deleted successfully!";
        }
    }
    
    // Get all departments
    $departments = $pdo->query("SELECT d.*, 
                                (SELECT COUNT(*) FROM users WHERE department = d.name) as user_count 
                                FROM departments d 
                                ORDER BY d.id")->fetchAll();
    
    // Get department for editing
    $edit_dept = null;
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
        $stmt->execute([$_GET['edit']]);
        $edit_dept = $stmt->fetch();
    }
    
} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>SpeedX - Manage Departments</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, sans-serif; 
            background: #f0f2f5; 
        }
        
        .header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header a { 
            color: white; 
            text-decoration: none;
            background: rgba(255,255,255,0.2);
            padding: 8px 15px;
            border-radius: 5px;
        }
        
        .container { 
            max-width: 1200px; 
            margin: 30px auto; 
            padding: 0 20px; 
        }
        
        .card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        h2 { 
            color: #2d3748; 
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert-success { background: #c6f6d5; color: #276749; border-left: 4px solid #38a169; }
        .alert-error { background: #fed7d7; color: #9b2c2c; border-left: 4px solid #e53e3e; }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group { margin-bottom: 20px; }
        label { 
            display: block; 
            margin-bottom: 8px; 
            color: #4a5568; 
            font-weight: 600;
            font-size: 14px;
        }
        input, textarea, select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            font-family: inherit;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        textarea { resize: vertical; min-height: 100px; }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-block;
            text-decoration: none;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a67d8; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
        .btn-danger { background: #e53e3e; color: white; }
        .btn-danger:hover { background: #c53030; }
        .btn-warning { background: #d69e2e; color: white; }
        .btn-warning:hover { background: #b7791f; }
        .btn-success { background: #38a169; color: white; }
        .btn-sm { padding: 6px 15px; font-size: 13px; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th { 
            background: #f7fafc; 
            color: #4a5568; 
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        tr:hover { background: #f7fafc; }
        
        .dept-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }
        
        .user-count {
            background: #edf2f7;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-card h3 {
            color: #718096;
            font-size: 14px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            color: #667eea;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🏢 Department Management</h1>
        <div>
            <a href="admin.php">👥 User Management</a>
            <a href="dashboard.php" style="margin-left:10px;">📊 Dashboard</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <h3>Total Departments</h3>
                <div class="number"><?php echo count($departments); ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Users</h3>
                <div class="number">
                    <?php 
                        $total_users = 0;
                        foreach ($departments as $d) $total_users += $d['user_count'];
                        echo $total_users;
                    ?>
                </div>
            </div>
        </div>
        
        <!-- Add/Edit Department Form -->
        <div class="card">
            <h2><?php echo $edit_dept ? '✏️ Edit Department' : '➕ Add New Department'; ?></h2>
            
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $edit_dept ? 'edit_department' : 'add_department'; ?>">
                <?php if ($edit_dept): ?>
                    <input type="hidden" name="department_id" value="<?php echo $edit_dept['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Department Name *</label>
                    <input type="text" 
                           name="department_name" 
                           placeholder="e.g., Research & Development"
                           value="<?php echo $edit_dept ? $edit_dept['name'] : ''; ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" 
                              placeholder="What does this department do?"><?php echo $edit_dept ? $edit_dept['description'] : ''; ?></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <?php echo $edit_dept ? '💾 Update Department' : '➕ Add Department'; ?>
                </button>
                
                <?php if ($edit_dept): ?>
                    <a href="manage_departments.php" class="btn btn-warning">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Departments List -->
        <div class="card">
            <h2>📋 All Departments</h2>
            
            <table>
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Description</th>
                        <th>Users</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($departments as $dept): ?>
                    <tr>
                        <td>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div class="dept-icon">
                                    <?php echo strtoupper(substr($dept['name'], 0, 2)); ?>
                                </div>
                                <strong><?php echo $dept['name']; ?></strong>
                            </div>
                        </td>
                        <td><?php echo $dept['description'] ?: 'No description'; ?></td>
                        <td>
                            <span class="user-count">
                                👤 <?php echo $dept['user_count']; ?> user(s)
                            </span>
                        </td>
                        <td style="color:#718096; font-size:13px;">
                            <?php echo date('d M Y', strtotime($dept['created_at'] ?? 'now')); ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="manage_departments.php?edit=<?php echo $dept['id']; ?>" 
                                   class="btn btn-warning btn-sm">✏️ Edit</a>
                                <form method="POST" style="display:inline;" 
                                      onsubmit="return confirm('Delete <?php echo $dept['name']; ?> department?')">
                                    <input type="hidden" name="action" value="delete_department">
                                    <input type="hidden" name="department_id" value="<?php echo $dept['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">🗑️ Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
