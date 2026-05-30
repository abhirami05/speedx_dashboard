<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ceo', 'mis_manager'])) {
    header('Location: dashboard.php');
    exit();
}

try {
   $pdo = new PDO("mysql:host=sql201.infinityfree.com;dbname=if0_42049613_speedx_dashboard", "if0_42049613", "9846294820Amma");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $message = '';
    $error = '';
    
    // Handle different actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // ADD USER
        if ($_POST['action'] === 'add_user') {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, department, role, status) VALUES (?, MD5(?), ?, ?, ?, ?, 'active')");
            $stmt->execute([
                $_POST['username'],
                $_POST['password'],
                $_POST['full_name'],
                $_POST['email'],
                $_POST['department'],
                $_POST['role']
            ]);
            $message = "✅ User added successfully!";
        }
        
        // EDIT USER
        if ($_POST['action'] === 'edit_user') {
            if (!empty($_POST['password'])) {
                $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, department=?, role=?, status=?, password=MD5(?) WHERE id=?");
                $stmt->execute([
                    $_POST['full_name'],
                    $_POST['email'],
                    $_POST['department'],
                    $_POST['role'],
                    $_POST['status'],
                    $_POST['password'],
                    $_POST['user_id']
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, department=?, role=?, status=? WHERE id=?");
                $stmt->execute([
                    $_POST['full_name'],
                    $_POST['email'],
                    $_POST['department'],
                    $_POST['role'],
                    $_POST['status'],
                    $_POST['user_id']
                ]);
            }
            $message = "✅ User updated successfully!";
        }
        
        // DELETE USER
        if ($_POST['action'] === 'delete_user') {
            // Don't allow deleting yourself
            if ($_POST['user_id'] != $_SESSION['user_id']) {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$_POST['user_id']]);
                $message = "✅ User deleted successfully!";
            } else {
                $error = "❌ You cannot delete yourself!";
            }
        }
        
        // GRANT DEPARTMENT ACCESS
        if ($_POST['action'] === 'grant_access') {
            $stmt = $pdo->prepare("INSERT IGNORE INTO department_access (user_id, department_id, granted_by) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['user_id'], $_POST['department_id'], $_SESSION['user_id']]);
            $message = "✅ Department access granted!";
        }
        
        // REMOVE DEPARTMENT ACCESS
        if ($_POST['action'] === 'remove_access') {
            $stmt = $pdo->prepare("DELETE FROM department_access WHERE user_id = ? AND department_id = ?");
            $stmt->execute([$_POST['user_id'], $_POST['department_id']]);
            $message = "✅ Department access removed!";
        }
    }
    
    // Get all users with their department access
    $users = $pdo->query("
        SELECT u.*, 
               GROUP_CONCAT(DISTINCT da.department_id) as access_dept_ids,
               GROUP_CONCAT(DISTINCT d2.name) as access_dept_names
        FROM users u 
        LEFT JOIN department_access da ON u.id = da.user_id
        LEFT JOIN departments d2 ON da.department_id = d2.id
        GROUP BY u.id 
        ORDER BY u.id
    ")->fetchAll();
    
    // Get all departments
    $departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
    
    // Get specific user for editing
    $edit_user = null;
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare("
            SELECT u.*, 
                   GROUP_CONCAT(da.department_id) as access_departments
            FROM users u 
            LEFT JOIN department_access da ON u.id = da.user_id
            WHERE u.id = ?
            GROUP BY u.id
        ");
        $stmt->execute([$_GET['edit']]);
        $edit_user = $stmt->fetch();
    }
    
} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>SpeedX - Admin Panel</title>
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
        .header a:hover { background: rgba(255,255,255,0.3); }
        
        .container { 
            max-width: 1400px; 
            margin: 30px auto; 
            padding: 0 20px; 
        }
        
        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        h2 { 
            color: #2d3748; 
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .alert-success { background: #c6f6d5; color: #276749; }
        .alert-error { background: #fed7d7; color: #9b2c2c; }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group { margin-bottom: 15px; }
        label { 
            display: block; 
            margin-bottom: 5px; 
            color: #4a5568; 
            font-weight: 600;
            font-size: 14px;
        }
        input, select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            padding: 10px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-block;
            text-decoration: none;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a67d8; transform: translateY(-1px); }
        .btn-danger { background: #e53e3e; color: white; }
        .btn-danger:hover { background: #c53030; }
        .btn-success { background: #38a169; color: white; }
        .btn-success:hover { background: #2f855a; }
        .btn-warning { background: #d69e2e; color: white; }
        .btn-warning:hover { background: #b7791f; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        th { 
            background: #f7fafc; 
            color: #4a5568; 
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        tr:hover { background: #f7fafc; }
        
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-active { background: #c6f6d5; color: #276749; }
        .badge-inactive { background: #fed7d7; color: #9b2c2c; }
        .badge-ceo { background: #e9d8fd; color: #553c9a; }
        .badge-manager { background: #bee3f8; color: #2a4365; }
        
        .access-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        .access-tag {
            background: #edf2f7;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .remove-access {
            cursor: pointer;
            color: #e53e3e;
            font-weight: bold;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .close-btn {
            cursor: pointer;
            font-size: 24px;
            color: #a0aec0;
        }
        .close-btn:hover { color: #4a5568; }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
        }
        .tab {
            padding: 10px 20px;
            background: #edf2f7;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            color: #4a5568;
        }
        .tab.active {
            background: #667eea;
            color: white;
        }
        
        .user-actions {
            display: flex;
            gap: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Admin Control Panel</h1>
        <div>
            <a href="dashboard.php">📊 Dashboard</a>
            <span style="margin:0 10px;color:rgba(255,255,255,0.5);">|</span>
             <a href="manage_departments.php" style="background: #38a169;">🏢 Manage Departments</a>
            <span>Welcome, <?php echo $_SESSION['full_name']; ?></span>

</div>
        </div>
        
    </div>
    
    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <div class="tabs">
            <div class="tab active" onclick="showTab('users')">👥 Users Management</div>
            <div class="tab" onclick="showTab('add')">➕ Add User</div>
            <?php if ($edit_user): ?>
            <div class="tab active" onclick="showTab('edit')">✏️ Edit User</div>
            <?php endif; ?>
        </div>
        
        <!-- Edit User Section -->
        <?php if ($edit_user): ?>
        <div class="card" id="editSection">
            <h2>✏️ Edit User: <?php echo $edit_user['full_name']; ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Username (cannot change)</label>
                        <input type="text" value="<?php echo $edit_user['username']; ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>New Password (leave blank to keep current)</label>
                        <input type="password" name="password" placeholder="Enter new password">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" value="<?php echo $edit_user['full_name']; ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" value="<?php echo $edit_user['email']; ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Primary Department</label>
                        <select name="department" required>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['name']; ?>" 
                                    <?php echo ($edit_user['department'] == $dept['name']) ? 'selected' : ''; ?>>
                                    <?php echo $dept['name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" required>
                            <option value="ceo" <?php echo ($edit_user['role'] == 'ceo') ? 'selected' : ''; ?>>CEO</option>
                            <option value="mis_manager" <?php echo ($edit_user['role'] == 'mis_manager') ? 'selected' : ''; ?>>MIS Manager</option>
                            <option value="data_analyst_manager" <?php echo ($edit_user['role'] == 'data_analyst_manager') ? 'selected' : ''; ?>>Data Analyst Manager</option>
                            <option value="audit_manager" <?php echo ($edit_user['role'] == 'audit_manager') ? 'selected' : ''; ?>>Audit Manager</option>
                            <option value="department_manager" <?php echo ($edit_user['role'] == 'department_manager') ? 'selected' : ''; ?>>Department Manager</option>
                            <option value="employee" <?php echo ($edit_user['role'] == 'employee') ? 'selected' : ''; ?>>Employee</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" required>
                        <option value="active" <?php echo ($edit_user['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($edit_user['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">💾 Update User</button>
                <a href="admin.php" class="btn btn-warning">Cancel</a>
            </form>
            
            <!-- Department Access Section -->
            <h3 style="margin-top:30px; color:#2d3748;">🔑 Additional Department Access</h3>
            <p style="color:#718096; margin-bottom:15px;">Grant access to additional departments (beyond their primary department)</p>
            
            <div style="margin-bottom:20px;">
                <strong>Current Access:</strong>
                <div class="access-tags" style="margin-top:10px;">
                    <?php 
                    $access_depts = explode(',', $edit_user['access_departments'] ?? '');
                    foreach ($access_depts as $dept_id): 
                        if ($dept_id):
                            $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                            $stmt->execute([$dept_id]);
                            $dept_name = $stmt->fetch()['name'];
                    ?>
                        <span class="access-tag">
                            <?php echo $dept_name; ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="remove_access">
                                <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                                <input type="hidden" name="department_id" value="<?php echo $dept_id; ?>">
                                <button type="submit" class="remove-access" style="background:none;border:none;">×</button>
                            </form>
                        </span>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            </div>
            
            <form method="POST" style="display:flex; gap:10px;">
                <input type="hidden" name="action" value="grant_access">
                <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                <select name="department_id" style="flex:1;">
                    <?php foreach ($departments as $dept): ?>
                        <?php if (!in_array($dept['id'], $access_depts)): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo $dept['name']; ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-success">➕ Grant Access</button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Add User Section -->
        <div class="card" id="addSection" style="display:none;">
            <h2>➕ Add New User</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" placeholder="e.g., john_doe" required>
                    </div>
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" placeholder="Min 6 characters" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" placeholder="e.g., John Doe" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" placeholder="e.g., john@speedx.com" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Primary Department *</label>
                        <select name="department" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['name']; ?>"><?php echo $dept['name']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Role *</label>
                        <select name="role" required>
                            <option value="">Select Role</option>
                            <option value="department_manager">Department Manager</option>
                            <option value="employee">Employee</option>
                            <option value="data_analyst_manager">Data Analyst Manager</option>
                            <option value="audit_manager">Audit Manager</option>
                            <option value="mis_manager">MIS Manager</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">➕ Create User</button>
            </form>
        </div>
        
        <!-- Users List -->
        <div class="card" id="usersSection">
            <h2>👥 All Users (<?php echo count($users); ?>)</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Department</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Extra Access</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><strong>#<?php echo $user['id']; ?></strong></td>
                            <td><?php echo $user['username']; ?></td>
                            <td><?php echo $user['full_name']; ?></td>
                            <td><?php echo $user['email']; ?></td>
                            <td><?php echo $user['department']; ?></td>
                            <td>
                                <span class="badge <?php echo in_array($user['role'], ['ceo', 'mis_manager']) ? 'badge-ceo' : 'badge-manager'; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo $user['status'] == 'active' ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo ucfirst($user['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="access-tags">
                                    <?php if ($user['access_dept_names']): ?>
                                        <?php foreach (explode(',', $user['access_dept_names']) as $dept): ?>
                                            <span class="access-tag"><?php echo trim($dept); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span style="color:#a0aec0;">None</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="user-actions">
                                    <a href="admin.php?edit=<?php echo $user['id']; ?>" class="btn btn-primary btn-sm">✏️ Edit</a>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <form method="POST" onsubmit="return confirm('Delete user <?php echo $user['full_name']; ?>?')" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">🗑️ Delete</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function showTab(tabName) {
            // Hide all sections
            document.getElementById('usersSection').style.display = 'none';
            document.getElementById('addSection').style.display = 'none';
            
            // Show selected
            if (tabName === 'users') {
                document.getElementById('usersSection').style.display = 'block';
            } else if (tabName === 'add') {
                document.getElementById('addSection').style.display = 'block';
            }
            
            // Update tabs
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
        }
        
        // Show edit section if edit parameter exists
        <?php if ($edit_user): ?>
            showTab('edit');
        <?php endif; ?>
    </script>
</body>
</html>
