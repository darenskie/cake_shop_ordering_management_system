<?php
session_start();
require_once '../db.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// Handle Add Category
if(isset($_POST['add_category'])) {
    $name = trim($_POST['name']);
    
    if(!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$name]);
        $new_id = $conn->lastInsertId();
        
        // Log audit
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'CREATE', 'categories', ?, ?)");
        $log->execute([$_SESSION['user_id'], $new_id, "Added category: $name"]);
        
        header("Location: categories.php?msg=added");
        exit();
    }
}

// Handle Delete Category
if(isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $name = $_GET['name'];
    
    // Check if category has products
    $check = $conn->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
    $check->execute([$id]);
    $product_count = $check->fetchColumn();
    
    if($product_count > 0) {
        $error = "Cannot delete category with $product_count products. Move or delete products first.";
    } else {
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        
        // Log audit
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'DELETE', 'categories', ?, ?)");
        $log->execute([$_SESSION['user_id'], $id, "Deleted category: $name"]);
        
        header("Location: categories.php?msg=deleted");
        exit();
    }
}

// Get all categories
$categories = $conn->query("SELECT * FROM categories ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Categories - Cake Shop</title>
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
            transition: background 0.3s;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #ff6b6b; color: white; }
        .btn-danger { background: #dc3545; color: white; padding: 5px 10px; font-size: 12px; }
        .add-form {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .form-group { margin-bottom: 15px; }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
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
        .msg {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .msg.success { background: #d4edda; color: #155724; }
        .msg.error { background: #f8d7da; color: #721c24; }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            background: #e9ecef;
        }
        @media (max-width: 768px) {
            .sidebar { width: 200px; }
            .content { margin-left: 200px; }
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
                <a href="categories.php" class="active">📁 Categories</a>
                <a href="orders.php">📦 Orders</a>
                <a href="users.php">👥 Users</a>
                <a href="audit.php">📋 Audit Logs</a>
                <a href="search.php">🔍 Search</a>
                <a href="../logout.php">🚪 Logout</a>
            </nav>
        </div>
        
        <div class="content">
            <div class="header">
                <h1>📁 Manage Categories</h1>
                <button class="btn btn-primary" onclick="toggleForm()">➕ Add Category</button>
            </div>
            
            <?php if(isset($_GET['msg'])): ?>
                <div class="msg success">✅ Category <?php echo $_GET['msg']; ?> successfully!</div>
            <?php endif; ?>
            
            <?php if(isset($error)): ?>
                <div class="msg error">❌ <?php echo $error; ?></div>
            <?php endif; ?>
            
            <div id="addForm" class="add-form" style="display: none;">
                <h3>➕ Add New Category</h3>
                <form method="POST">
                    <div class="form-group">
                        <input type="text" name="name" placeholder="Category Name (e.g., Birthday Cakes, Wedding Cakes)" required>
                    </div>
                    <button type="submit" name="add_category" class="btn btn-primary">💾 Save Category</button>
                    <button type="button" class="btn" style="background:#6c757d; color:white;" onclick="toggleForm()">Cancel</button>
                </form>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Category Name</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($categories) > 0): ?>
                        <?php foreach($categories as $cat): ?>
                        <tr>
                            <td><?php echo $cat['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($cat['name']); ?></strong></td>
                            <td><?php echo date('M d, Y', strtotime($cat['created_at'])); ?></td>
                            <td>
                                <a href="?delete=<?php echo $cat['id']; ?>&name=<?php echo urlencode($cat['name']); ?>" 
                                   class="btn btn-danger" onclick="return confirm('Delete category: <?php echo addslashes($cat['name']); ?>?\nThis will NOT delete products in this category, but they will become uncategorized.')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align:center; padding:40px;">📁 No categories yet. Click "Add Category" to create one.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        function toggleForm() {
            var form = document.getElementById('addForm');
            if(form.style.display === 'none') {
                form.style.display = 'block';
                form.scrollIntoView({ behavior: 'smooth' });
            } else {
                form.style.display = 'none';
            }
        }
        
        // Auto-hide message after 3 seconds
        setTimeout(function() {
            var msg = document.querySelector('.msg');
            if(msg) msg.style.display = 'none';
        }, 3000);
    </script>
</body>
</html>