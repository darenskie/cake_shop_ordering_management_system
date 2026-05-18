<?php
session_start();
require_once '../db.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? $_GET['type'] : 'products';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$results = [];
$total = 0;

if($search) {
    if($type == 'products') {
        $stmt = $conn->prepare("SELECT p.*, c.name as category_name, 'product' as type 
                                FROM products p 
                                LEFT JOIN categories c ON p.category_id = c.id 
                                WHERE p.name LIKE ? OR p.description LIKE ? 
                                ORDER BY p.id DESC
                                LIMIT ? OFFSET ?");
        $stmt->execute(["%$search%", "%$search%", $limit, $offset]);
        $results = $stmt->fetchAll();
        
        $count = $conn->prepare("SELECT COUNT(*) FROM products WHERE name LIKE ? OR description LIKE ?");
        $count->execute(["%$search%", "%$search%"]);
        $total = $count->fetchColumn();
    } 
    elseif($type == 'orders') {
        $stmt = $conn->prepare("SELECT o.*, u.username, u.full_name, 'order' as type 
                                FROM orders o 
                                JOIN users u ON o.user_id = u.id 
                                WHERE o.order_number LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?
                                ORDER BY o.id DESC
                                LIMIT ? OFFSET ?");
        $stmt->execute(["%$search%", "%$search%", "%$search%", $limit, $offset]);
        $results = $stmt->fetchAll();
        
        $count = $conn->prepare("SELECT COUNT(*) FROM orders o 
                                JOIN users u ON o.user_id = u.id 
                                WHERE o.order_number LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?");
        $count->execute(["%$search%", "%$search%", "%$search%"]);
        $total = $count->fetchColumn();
    } 
    elseif($type == 'users') {
        $stmt = $conn->prepare("SELECT id, username, email, full_name, role, created_at, 'user' as type 
                                FROM users 
                                WHERE username LIKE ? OR email LIKE ? OR full_name LIKE ? 
                                ORDER BY id DESC
                                LIMIT ? OFFSET ?");
        $stmt->execute(["%$search%", "%$search%", "%$search%", $limit, $offset]);
        $results = $stmt->fetchAll();
        
        $count = $conn->prepare("SELECT COUNT(*) FROM users WHERE username LIKE ? OR email LIKE ? OR full_name LIKE ?");
        $count->execute(["%$search%", "%$search%", "%$search%"]);
        $total = $count->fetchColumn();
    }
}

$total_pages = ceil($total / $limit);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Global Search - Cake Shop</title>
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
        .search-header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .search-form {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .search-input {
            flex: 1;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        .search-btn {
            padding: 12px 24px;
            background: #ff6b6b;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .type-filter {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .type-btn {
            padding: 8px 16px;
            background: #e9ecef;
            color: #333;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s;
        }
        .type-btn.active {
            background: #ff6b6b;
            color: white;
        }
        .result-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 4px solid #ff6b6b;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .result-card:hover {
            transform: translateX(5px);
        }
        .result-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
            margin-right: 10px;
        }
        .type-product { background: #4ecdc4; color: white; }
        .type-order { background: #ffc107; color: #333; }
        .type-user { background: #9b59b6; color: white; }
        .result-title {
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }
        .result-detail {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }
        .result-meta {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .page-link {
            padding: 8px 12px;
            background: white;
            text-decoration: none;
            color: #333;
            border-radius: 5px;
            transition: all 0.3s;
        }
        .page-link:hover {
            background: #e9ecef;
        }
        .page-link.active {
            background: #ff6b6b;
            color: white;
        }
        .result-count {
            margin-bottom: 15px;
            color: #666;
        }
        .no-results {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 8px;
            color: #999;
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
                <a href="categories.php">📁 Categories</a>
                <a href="orders.php">📦 Orders</a>
                <a href="users.php">👥 Users</a>
                <a href="audit.php">📋 Audit Logs</a>
                <a href="search.php" class="active">🔍 Search</a>
                <a href="../logout.php">🚪 Logout</a>
            </nav>
        </div>
        
        <div class="content">
            <h1>🔍 Global Search</h1>
            
            <div class="search-header">
                <form class="search-form" method="GET" action="">
                    <input type="text" name="q" class="search-input" placeholder="Search products, orders, users by name, email, order number..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                    <button type="submit" class="search-btn">🔍 Search</button>
                </form>
                
                <div class="type-filter">
                    <a href="?q=<?php echo urlencode($search); ?>&type=products" class="type-btn <?php echo $type == 'products' ? 'active' : ''; ?>">🍰 Products</a>
                    <a href="?q=<?php echo urlencode($search); ?>&type=orders" class="type-btn <?php echo $type == 'orders' ? 'active' : ''; ?>">📦 Orders</a>
                    <a href="?q=<?php echo urlencode($search); ?>&type=users" class="type-btn <?php echo $type == 'users' ? 'active' : ''; ?>">👥 Users</a>
                </div>
            </div>
            
            <?php if($search): ?>
                <div class="result-count">
                    📊 Found <strong><?php echo $total; ?></strong> result(s) for "<strong><?php echo htmlspecialchars($search); ?></strong>" in <?php echo $type; ?>
                </div>
                
                <?php if(count($results) > 0): ?>
                    <?php foreach($results as $result): ?>
                        <div class="result-card">
                            <span class="result-type type-<?php echo $result['type']; ?>">
                                <?php echo ucfirst($result['type']); ?>
                            </span>
                            
                            <?php if($result['type'] == 'product'): ?>
                                <div class="result-title">🍰 <?php echo htmlspecialchars($result['name']); ?></div>
                                <div class="result-detail">💰 ₱<?php echo number_format($result['price'], 2); ?> | 📦 Stock: <?php echo $result['stock']; ?></div>
                                <div class="result-detail">📁 Category: <?php echo htmlspecialchars($result['category_name'] ?? 'Uncategorized'); ?></div>
                                <?php if(!empty($result['description'])): ?>
                                    <div class="result-meta">📝 <?php echo htmlspecialchars(substr($result['description'], 0, 100)); ?>...</div>
                                <?php endif; ?>
                                <div class="result-meta">
                                    <a href="edit_product.php?id=<?php echo $result['id']; ?>" style="color:#ff6b6b;">✏️ Edit</a>
                                </div>
                                
                            <?php elseif($result['type'] == 'order'): ?>
                                <div class="result-title">📦 Order #<?php echo htmlspecialchars($result['order_number']); ?></div>
                                <div class="result-detail">👤 Customer: <?php echo htmlspecialchars($result['full_name']); ?> (@<?php echo htmlspecialchars($result['username']); ?>)</div>
                                <div class="result-detail">💰 Total: ₱<?php echo number_format($result['total_amount'], 2); ?> | Status: <span style="color:<?php echo $result['status']=='pending'?'orange':($result['status']=='completed'?'green':'blue'); ?>"><?php echo ucfirst($result['status']); ?></span></div>
                                <div class="result-meta">📅 <?php echo date('M d, Y H:i', strtotime($result['created_at'])); ?></div>
                                
                            <?php elseif($result['type'] == 'user'): ?>
                                <div class="result-title">👤 <?php echo htmlspecialchars($result['full_name']); ?></div>
                                <div class="result-detail">🔑 Username: <?php echo htmlspecialchars($result['username']); ?> | Role: <strong><?php echo ucfirst($result['role']); ?></strong></div>
                                <div class="result-detail">📧 Email: <?php echo htmlspecialchars($result['email']); ?></div>
                                <div class="result-meta">📅 Joined: <?php echo date('M d, Y', strtotime($result['created_at'])); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if($total_pages > 1): ?>
                        <div class="pagination">
                            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?q=<?php echo urlencode($search); ?>&type=<?php echo $type; ?>&page=<?php echo $i; ?>" class="page-link <?php echo $page == $i ? 'active' : ''; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="no-results">
                        🔍 No results found for "<strong><?php echo htmlspecialchars($search); ?></strong>" in <?php echo $type; ?><br>
                        <small>Try searching for something else or check the spelling.</small>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-results">
                    🔍 Enter a search term above to find products, orders, or users.<br>
                    <small>You can search by name, email, order number, description, etc.</small>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>