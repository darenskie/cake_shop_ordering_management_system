<?php
session_start();
require_once '../db.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// Update user role
if(isset($_POST['update_role'])) {
    $user_id = $_POST['user_id'];
    $role = $_POST['role'];
    
    // Don't allow admin to demote themselves
    if($user_id == $_SESSION['user_id'] && $role != 'admin') {
        $error = "You cannot change your own admin role!";
    } else {
        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->execute([$role, $user_id]);
        
        // Log audit
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'UPDATE', 'users', ?, ?)");
        $log->execute([$_SESSION['user_id'], $user_id, "Updated user role to $role"]);
        
        $success = "User role updated successfully!";
    }
}

// Delete user
if(isset($_POST['delete_user'])) {
    $user_id = $_POST['user_id'];
    
    // Don't allow admin to delete themselves
    if($user_id == $_SESSION['user_id']) {
        $error = "You cannot delete your own account!";
    } else {
        // Get username for log
        $getUser = $conn->prepare("SELECT username, full_name FROM users WHERE id = ?");
        $getUser->execute([$user_id]);
        $user = $getUser->fetch();
        
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        
        // Log audit
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'DELETE', 'users', ?, ?)");
        $log->execute([$_SESSION['user_id'], $user_id, "Deleted user: " . ($user['username'] ?? 'Unknown')]);
        
        $success = "User deleted successfully!";
    }
}

// Get all users
$users = $conn->query("SELECT id, username, email, full_name, role, created_at FROM users ORDER BY id DESC")->fetchAll();

// Get statistics for dashboard
$total_users = $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_customers = $conn->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
$total_staff = $conn->query("SELECT COUNT(*) FROM users WHERE role='staff'")->fetchColumn();
$total_admins = $conn->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Users - Cake Shop</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .dashboard { display: flex; min-height: 100vh; }
        .sidebar {
            width: 250px;
            background: #2c3e50;
            color: white;
            padding: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        .sidebar h2 { margin-bottom: 20px; }
        .sidebar h2 span { color: #ff6b6b; }
        .sidebar a {
            display: block;
            color: #ecf0f1;
            text-decoration: none;
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
        }
        .sidebar a:hover, .sidebar a.active {
            background: #34495e;
        }
        .content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
        }
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .stat-card h3 { color: #666; font-size: 12px; margin-bottom: 5px; }
        .stat-value { font-size: 24px; font-weight: bold; color: #ff6b6b; }
        table {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th { background: #34495e; color: white; }
        .badge {
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            display: inline-block;
        }
        .badge.admin { background: #ff6b6b; color: white; }
        .badge.staff { background: #4ecdc4; color: white; }
        .badge.customer { background: #9b59b6; color: white; }
        .btn {
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }
        .btn-primary { background: #ff6b6b; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        select, button { padding: 5px; border-radius: 3px; }
        .msg {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .msg.success { background: #d4edda; color: #155724; }
        .msg.error { background: #f8d7da; color: #721c24; }
        @media (max-width: 768px) {
            .sidebar { width: 200px; }
            .content { margin-left: 200px; }
            .stats { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
            <h2>🍰 <span>Admin</span></h2>
            <p>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
            <nav>
                <a href="dashboard.php">📊 Dashboard</a>
                <a href="products.php">🍰 Products</a>
                <a href="categories.php">📁 Categories</a>
                <a href="orders.php">📦 Orders</a>
                <a href="users.php" class="active">👥 Users</a>
                <a href="audit.php">📋 Audit Logs</a>
                <a href="search.php">🔍 Search</a>
                <a href="../logout.php">🚪 Logout</a>
            </nav>
        </div>
        
        <div class="content">
            <div class="header">
                <h1>👥 User Management</h1>
                <p>Manage user accounts, roles, and permissions</p>
            </div>
            
            <div class="stats">
                <div class="stat-card">
                    <h3>Total Users</h3>
                    <div class="stat-value"><?php echo $total_users; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Customers</h3>
                    <div class="stat-value"><?php echo $total_customers; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Staff</h3>
                    <div class="stat-value"><?php echo $total_staff; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Admins</h3>
                    <div class="stat-value"><?php echo $total_admins; ?></div>
                </div>
            </div>
            
            <?php if(isset($success)): ?>
                <div class="msg success">✅ <?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if(isset($error)): ?>
                <div class="msg error">❌ <?php echo $error; ?></div>
            <?php endif; ?>
            
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($users as $user): ?>
                    <tr>
                        <td><?php echo $user['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td>
                            <span class="badge <?php echo $user['role']; ?>">
                                <?php echo ucfirst($user['role']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                        <td style="white-space: nowrap;">
                            <form method="POST" style="display: inline-flex; gap: 5px;">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <select name="role" style="padding: 4px;">
                                    <option value="customer" <?php echo $user['role']=='customer'?'selected':''; ?>>Customer</option>
                                    <option value="staff" <?php echo $user['role']=='staff'?'selected':''; ?>>Staff</option>
                                    <option value="admin" <?php echo $user['role']=='admin'?'selected':''; ?>>Admin</option>
                                </select>
                                <button type="submit" name="update_role" class="btn btn-primary">Update</button>
                            </form>
                            <?php if($user['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete user: <?php echo addslashes($user['username']); ?>?')">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" name="delete_user" class="btn btn-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="margin-top: 20px; padding: 15px; background: #e8f4f8; border-radius: 8px;">
                <h4>📋 Role Permissions:</h4>
                <table style="margin-top: 10px; width: 100%;">
                    <tr><th>Role</th><th>Permissions</th></tr>
                    <tr><td><span class="badge admin">Admin</span></td><td>Full access: Manage products, orders, users, categories, audit logs, settings</td></tr>
                    <tr><td><span class="badge staff">Staff</span></td><td>Limited access: View/manage orders, view products, update stock</td></tr>
                    <tr><td><span class="badge customer">Customer</span></td><td>Basic access: Browse products, place orders, view own order history</td></tr>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-hide message after 3 seconds
        setTimeout(function() {
            var msg = document.querySelector('.msg');
            if(msg) msg.style.display = 'none';
        }, 3000);
    </script>
</body>
</html>