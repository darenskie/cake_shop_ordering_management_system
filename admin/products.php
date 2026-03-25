<?php
session_start();
require_once '../db.php';
require_once '../websocket_client.php'; // ADD THIS LINE

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// Create upload directory if it doesn't exist
$upload_dir = '../uploads/products/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle Add Product with Image
if(isset($_POST['add_product'])) {
    $name = $_POST['name'];
    $category_id = $_POST['category_id'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $description = $_POST['description'];
    $image = '';
    
    // Handle image upload
    if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if(in_array($ext, $allowed)) {
            // Generate unique filename
            $new_filename = uniqid() . '.' . $ext;
            $upload_path = $upload_dir . $new_filename;
            
            if(move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                $image = 'uploads/products/' . $new_filename;
            }
        }
    }
    
    $stmt = $conn->prepare("INSERT INTO products (name, category_id, price, stock, description, image, status) VALUES (?, ?, ?, ?, ?, ?, 'available')");
    $stmt->execute([$name, $category_id, $price, $stock, $description, $image]);
    $new_id = $conn->lastInsertId();
    
    // Log audit
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'CREATE', 'products', ?, ?)");
    $log->execute([$_SESSION['user_id'], $new_id, "Added product: $name"]);
    
    // ADD WEBSOCKET BROADCAST FOR PRODUCT ADDED
    broadcastWebSocket('product_added', [
        'id' => $new_id,
        'name' => $name,
        'price' => $price,
        'stock' => $stock,
        'image' => $image,
        'category_id' => $category_id,
        'action_by' => $_SESSION['full_name']
    ]);
    
    header("Location: products.php?msg=added");
    exit();
}

// Handle Delete Product
if(isset($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Get product info to delete image too
    $prod = $conn->prepare("SELECT name, image FROM products WHERE id = ?");
    $prod->execute([$id]);
    $product = $prod->fetch();
    $product_name = $product['name'] ?? 'Unknown';
    
    // Delete image file if exists and is local file
    if($product && $product['image'] && !filter_var($product['image'], FILTER_VALIDATE_URL)) {
        $image_path = '../' . $product['image'];
        if(file_exists($image_path)) {
            unlink($image_path);
        }
    }
    
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);
    
    // Log audit
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'DELETE', 'products', ?, ?)");
    $log->execute([$_SESSION['user_id'], $id, "Deleted product: $product_name"]);
    
    // ADD WEBSOCKET BROADCAST FOR PRODUCT DELETED
    broadcastWebSocket('product_deleted', [
        'id' => $id,
        'name' => $product_name,
        'action_by' => $_SESSION['full_name']
    ]);
    
    header("Location: products.php?msg=deleted");
    exit();
}

// Get all products
$products = $conn->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.id DESC")->fetchAll();

// Get categories
$categories = $conn->query("SELECT * FROM categories")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Products - Cake Shop</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ddd;
            background: white;
            padding: 20px;
            border-radius: 8px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            transition: opacity 0.3s;
        }
        .btn:hover { opacity: 0.8; }
        .btn-primary {
            background: #ff6b6b;
            color: white;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
            padding: 5px 10px;
            font-size: 12px;
        }
        .btn-success {
            background: #4ecdc4;
            color: white;
        }
        .add-form {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            animation: slideDown 0.3s ease;
        }
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #ff6b6b;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
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
            vertical-align: middle;
        }
        th { 
            background: #34495e; 
            color: white;
            font-weight: 600;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .product-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            display: block;
        }
        .no-image {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 30px;
            color: #999;
        }
        .status {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .status.available { 
            background: #d4edda; 
            color: #155724;
        }
        .status.out_of_stock { 
            background: #f8d7da; 
            color: #721c24;
        }
        .msg {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            animation: slideDown 0.3s ease;
        }
        .msg.success { 
            background: #d4edda; 
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .image-preview {
            max-width: 100px;
            max-height: 100px;
            margin-top: 10px;
            border-radius: 5px;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            animation: slideIn 0.3s ease;
        }
        .notification-content {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            padding: 12px 20px;
            min-width: 250px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .notification-success { border-left: 4px solid #28a745; }
        .notification-info { border-left: 4px solid #17a2b8; }
        .notification-warning { border-left: 4px solid #ffc107; }
        .notification-close {
            margin-left: auto;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            color: #999;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 200px;
            }
            .content {
                margin-left: 200px;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            table {
                font-size: 12px;
            }
            th, td {
                padding: 8px;
            }
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
                <a href="products.php" class="active">🍰 Products</a>
                <a href="orders.php">📦 Orders</a>
                <a href="users.php">👥 Users</a>
                <a href="audit.php">📋 Audit Logs</a>
                <a href="../logout.php">🚪 Logout</a>
            </nav>
        </div>
        
        <div class="content">
            <div class="header">
                <h1>🍰 Manage Products</h1>
                <button class="btn btn-primary" onclick="toggleForm()">➕ Add New Product</button>
            </div>
            
            <?php if(isset($_GET['msg'])): ?>
                <div class="msg success">
                    <?php 
                    if($_GET['msg'] == 'added') echo "✅ Product added successfully!";
                    if($_GET['msg'] == 'deleted') echo "✅ Product deleted successfully!";
                    if($_GET['msg'] == 'updated') echo "✅ Product updated successfully!";
                    ?>
                </div>
            <?php endif; ?>
            
            <!-- Add Product Form -->
            <div id="addForm" style="display: none;" class="add-form">
                <h3>➕ Add New Product</h3>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" name="name" required placeholder="Enter product name">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Category *</label>
                            <select name="category_id" required>
                                <option value="">Select Category</option>
                                <?php foreach($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Price (₱) *</label>
                            <input type="number" name="price" step="0.01" required placeholder="0.00">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Stock *</label>
                            <input type="number" name="stock" required placeholder="Quantity">
                        </div>
                        
                        <div class="form-group">
                            <label>Product Image</label>
                            <input type="file" name="image" accept="image/*" onchange="previewImage(this)">
                            <small style="color: #666;">Allowed: JPG, JPEG, PNG, GIF</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3" placeholder="Enter product description..."></textarea>
                    </div>
                    
                    <div id="imagePreview"></div>
                    
                    <button type="submit" name="add_product" class="btn btn-primary">💾 Save Product</button>
                    <button type="button" class="btn" style="background:#6c757d; color:white;" onclick="toggleForm()">❌ Cancel</button>
                </form>
            </div>
            
            <!-- Products Table -->
            <table>
                <thead>
                    <tr>
                        <th>Image</th>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($products) > 0): ?>
                        <?php foreach($products as $product): ?>
                        <tr>
                            <td>
                                <?php 
                                // Check if image exists
                                if(!empty($product['image'])):
                                    // Check if it's a URL or local path
                                    if(filter_var($product['image'], FILTER_VALIDATE_URL)):
                                        // Online image URL
                                        echo '<img src="' . htmlspecialchars($product['image']) . '" class="product-image" alt="' . htmlspecialchars($product['name']) . '">';
                                    else:
                                        // Local image path - check if file exists
                                        $image_path = '../' . $product['image'];
                                        if(file_exists($image_path)):
                                            echo '<img src="../' . htmlspecialchars($product['image']) . '" class="product-image" alt="' . htmlspecialchars($product['name']) . '">';
                                        else:
                                            echo '<div class="no-image">🍰</div>';
                                        endif;
                                    endif;
                                else:
                                    echo '<div class="no-image">🍰</div>';
                                endif;
                                ?>
                            </td>
                            <td><?php echo $product['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($product['category_name'] ?: 'Uncategorized'); ?></td>
                            <td>₱<?php echo number_format($product['price'], 2); ?></td>
                            <td><?php echo $product['stock']; ?></td>
                            <td>
                                <span class="status <?php echo $product['status']; ?>">
                                    <?php echo ucfirst($product['status']); ?>
                                </span>
                            </td>
                            <td class="action-buttons">
                                <a href="edit_product.php?id=<?php echo $product['id']; ?>" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">✏️ Edit</a>
                                <a href="?delete=<?php echo $product['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this product?')">🗑️ Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="empty-state">
                                🍰 No products found. Click "Add New Product" to get started!
                            </td>
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
        
        function previewImage(input) {
            var preview = document.getElementById('imagePreview');
            preview.innerHTML = '';
            
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'image-preview';
                    img.style.border = '1px solid #ddd';
                    img.style.padding = '5px';
                    preview.appendChild(img);
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Auto-hide success message after 3 seconds
        setTimeout(function() {
            var msg = document.querySelector('.msg');
            if(msg) {
                msg.style.display = 'none';
            }
        }, 3000);
        
        // ========== WEBSOCKET CLIENT ==========
        let ws;
        let reconnectAttempts = 0;
        
        function connectWebSocket() {
            ws = new WebSocket('ws://localhost:8080');
            
            ws.onopen = function() {
                console.log('✅ Connected to WebSocket');
                reconnectAttempts = 0;
                
                // Authenticate
                ws.send(JSON.stringify({
                    type: 'auth',
                    user_id: <?php echo $_SESSION['user_id']; ?>,
                    role: '<?php echo $_SESSION['role']; ?>'
                }));
                
                // Send ping every 30 seconds
                setInterval(() => {
                    if(ws.readyState === WebSocket.OPEN) {
                        ws.send(JSON.stringify({ type: 'ping' }));
                    }
                }, 30000);
            };
            
            ws.onmessage = function(event) {
                const data = JSON.parse(event.data);
                
                switch(data.type) {
                    case 'welcome':
                        showNotification('Connected to real-time server', 'success');
                        break;
                        
                    case 'crud':
                        handleCRUDEvent(data);
                        break;
                        
                    case 'pong':
                        // Keep alive
                        break;
                }
            };
            
            ws.onclose = function() {
                console.log('❌ WebSocket Disconnected');
                if(reconnectAttempts < 5) {
                    reconnectAttempts++;
                    setTimeout(connectWebSocket, 3000);
                }
            };
        }
        
        function handleCRUDEvent(data) {
            const { action, data: eventData } = data;
            
            switch(action) {
                case 'product_added':
                    showNotification(`🆕 New product: ${eventData.name} added by ${eventData.action_by}`, 'info');
                    break;
                    
                case 'product_updated':
                    showNotification(`✏️ Product updated: ${eventData.name}`, 'info');
                    break;
                    
                case 'product_deleted':
                    showNotification(`🗑️ Product deleted: ${eventData.name}`, 'warning');
                    break;
                    
                case 'order_placed':
                    showNotification(`📦 New order #${eventData.order_number} from ${eventData.username}`, 'success');
                    break;
            }
            
            // Refresh page if on products page
            if(action.includes('product') && window.location.href.includes('products.php')) {
                setTimeout(() => location.reload(), 2000);
            }
        }
        
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.innerHTML = `
                <div class="notification-content notification-${type}">
                    <span>${type === 'success' ? '✅' : type === 'warning' ? '⚠️' : '🔔'}</span>
                    <span>${message}</span>
                    <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                if(notification.parentElement) {
                    notification.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }
        
        // Connect WebSocket when page loads
        document.addEventListener('DOMContentLoaded', connectWebSocket);
    </script>
</body>
</html>