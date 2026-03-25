<?php
session_start();
require_once '../db.php';
require_once '../websocket_client.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// Get categories
$categories = $conn->query("SELECT * FROM categories")->fetchAll();

// Get all products (initial load via PHP, will be refreshed via WebSocket)
$products = $conn->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.id DESC")->fetchAll();
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
        
        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        }
        .loading-spinner {
            background: white;
            padding: 20px;
            border-radius: 10px;
            font-size: 18px;
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
            
            <div id="successMsg" class="msg success" style="display: none;"></div>
            
            <!-- Add Product Form - Now uses WebSocket instead of HTTP POST -->
            <div id="addForm" style="display: none;" class="add-form">
                <h3>➕ Add New Product</h3>
                <form id="productForm" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" id="productName" required placeholder="Enter product name">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Category *</label>
                            <select id="categoryId" required>
                                <option value="">Select Category</option>
                                <?php foreach($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Price (₱) *</label>
                            <input type="number" id="productPrice" step="0.01" required placeholder="0.00">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Stock *</label>
                            <input type="number" id="productStock" required placeholder="Quantity">
                        </div>
                        
                        <div class="form-group">
                            <label>Product Image URL</label>
                            <input type="text" id="productImage" placeholder="https://example.com/image.jpg">
                            <small style="color: #666;">Paste image URL or leave empty</small>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea id="productDescription" rows="3" placeholder="Enter product description..."></textarea>
                    </div>
                    
                    <div id="imagePreview"></div>
                    
                    <button type="submit" class="btn btn-primary">💾 Save Product</button>
                    <button type="button" class="btn" style="background:#6c757d; color:white;" onclick="toggleForm()">❌ Cancel</button>
                </form>
            </div>
            
            <!-- Products Table -->
            <div id="productsTableContainer">
                <table id="productsTable">
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
                        </thead>
                    <tbody id="productsTableBody">
                        <?php if(count($products) > 0): ?>
                            <?php foreach($products as $product): ?>
                            <tr data-id="<?php echo $product['id']; ?>">
                                <td>
                                    <?php 
                                    if(!empty($product['image'])):
                                        if(filter_var($product['image'], FILTER_VALIDATE_URL)):
                                            echo '<img src="' . htmlspecialchars($product['image']) . '" class="product-image" alt="' . htmlspecialchars($product['name']) . '">';
                                        else:
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
                                <td><span class="status <?php echo $product['status']; ?>"><?php echo ucfirst($product['status']); ?></span></td>
                                <td class="action-buttons">
                                    <button onclick="editProduct(<?php echo $product['id']; ?>)" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">✏️ Edit</button>
                                    <button onclick="deleteProduct(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>')" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;">🗑️ Delete</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="empty-state">🍰 No products found. Click "Add New Product" to get started!</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner">⏳ Processing...</div>
    </div>
    
    <script>
        // ========== WEBSOCKET CLIENT ==========
        let ws;
        let reconnectAttempts = 0;
        
        function connectWebSocket() {
            ws = new WebSocket('ws://localhost:8080');
            
            ws.onopen = function() {
                console.log('✅ Connected to WebSocket');
                reconnectAttempts = 0;
                showNotification('Connected to real-time server', 'success');
                
                // Authenticate
                ws.send(JSON.stringify({
                    type: 'auth',
                    user_id: <?php echo $_SESSION['user_id']; ?>,
                    role: '<?php echo $_SESSION['role']; ?>',
                    username: '<?php echo $_SESSION['username']; ?>'
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
                console.log('📨 Message received:', data.type);
                
                switch(data.type) {
                    case 'welcome':
                        showNotification(data.message, 'success');
                        break;
                        
                    case 'auth_success':
                        console.log('Authenticated successfully');
                        // Refresh products after authentication
                        refreshProducts();
                        break;
                        
                    case 'PRODUCT_LIST':
                        updateProductsTable(data.payload);
                        break;
                        
                    case 'PRODUCT_CREATED':
                        showNotification(`🆕 New product: ${data.payload.name} added`, 'info');
                        refreshProducts();
                        break;
                        
                    case 'PRODUCT_UPDATED':
                        showNotification(`✏️ Product updated: ${data.payload.name}`, 'info');
                        refreshProducts();
                        break;
                        
                    case 'PRODUCT_DELETED':
                        showNotification(`🗑️ Product deleted: ${data.payload.name}`, 'warning');
                        refreshProducts();
                        break;
                        
                    case 'CREATE_SUCCESS':
                        showNotification(data.message, 'success');
                        toggleForm();
                        document.getElementById('productForm').reset();
                        break;
                        
                    case 'UPDATE_SUCCESS':
                        showNotification(data.message, 'success');
                        break;
                        
                    case 'DELETE_SUCCESS':
                        showNotification(data.message, 'success');
                        break;
                        
                    case 'ORDER_CREATED':
                        showNotification(`📦 New order #${data.payload.order_number} from ${data.payload.username}`, 'success');
                        break;
                        
                    case 'pong':
                        // Keep alive
                        break;
                        
                    default:
                        console.log('Unknown message type:', data.type);
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
        
        function refreshProducts() {
            if(ws && ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({
                    type: 'READ_PRODUCTS',
                    payload: {}
                }));
            }
        }
        
        function updateProductsTable(products) {
            const tbody = document.getElementById('productsTableBody');
            if(!tbody) return;
            
            if(products.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="empty-state">🍰 No products found. Click "Add New Product" to get started!</td></tr>';
                return;
            }
            
            let html = '';
            products.forEach(product => {
                let imageHtml = '';
                if(product.image) {
                    if(product.image.startsWith('http')) {
                        imageHtml = `<img src="${escapeHtml(product.image)}" class="product-image" alt="${escapeHtml(product.name)}">`;
                    } else {
                        imageHtml = `<img src="../${escapeHtml(product.image)}" class="product-image" alt="${escapeHtml(product.name)}">`;
                    }
                } else {
                    imageHtml = '<div class="no-image">🍰</div>';
                }
                
                html += `
                    <tr data-id="${product.id}">
                        <td>${imageHtml}</td>
                        <td>${product.id}</td>
                        <td><strong>${escapeHtml(product.name)}</strong></td>
                        <td>${escapeHtml(product.category_name || 'Uncategorized')}</td>
                        <td>₱${parseFloat(product.price).toFixed(2)}</td>
                        <td>${product.stock}</td>
                        <td><span class="status ${product.status}">${product.status.charAt(0).toUpperCase() + product.status.slice(1)}</span></td>
                        <td class="action-buttons">
                            <button onclick="editProduct(${product.id})" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">✏️ Edit</button>
                            <button onclick="deleteProduct(${product.id}, '${escapeHtml(product.name)}')" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;">🗑️ Delete</button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }
        
        function escapeHtml(str) {
            if(!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if(m === '&') return '&amp;';
                if(m === '<') return '&lt;';
                if(m === '>') return '&gt;';
                return m;
            });
        }
        
        // CREATE PRODUCT via WebSocket
        function createProduct() {
            const name = document.getElementById('productName').value;
            const category_id = document.getElementById('categoryId').value;
            const price = document.getElementById('productPrice').value;
            const stock = document.getElementById('productStock').value;
            const description = document.getElementById('productDescription').value;
            const image = document.getElementById('productImage').value;
            
            if(!name || !category_id || !price || !stock) {
                showNotification('Please fill all required fields', 'warning');
                return;
            }
            
            if(ws && ws.readyState === WebSocket.OPEN) {
                showLoading(true);
                ws.send(JSON.stringify({
                    type: 'CREATE_PRODUCT',
                    payload: {
                        name: name,
                        category_id: parseInt(category_id),
                        price: parseFloat(price),
                        stock: parseInt(stock),
                        description: description,
                        image: image
                    }
                }));
                showLoading(false);
            } else {
                showNotification('WebSocket not connected. Please refresh the page.', 'warning');
            }
        }
        
        // DELETE PRODUCT via WebSocket
        function deleteProduct(id, name) {
            if(confirm(`Are you sure you want to delete "${name}"?`)) {
                if(ws && ws.readyState === WebSocket.OPEN) {
                    showLoading(true);
                    ws.send(JSON.stringify({
                        type: 'DELETE_PRODUCT',
                        payload: {
                            id: id,
                            name: name
                        }
                    }));
                    showLoading(false);
                } else {
                    showNotification('WebSocket not connected. Please refresh the page.', 'warning');
                }
            }
        }
        
        // EDIT PRODUCT (will be implemented via edit_product.php or WebSocket modal)
        function editProduct(id) {
            window.location.href = `edit_product.php?id=${id}`;
        }
        
        // UI Functions
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
                    img.style.maxWidth = '100px';
                    preview.appendChild(img);
                }
                reader.readAsDataURL(input.files[0]);
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
        
        function showLoading(show) {
            const overlay = document.getElementById('loadingOverlay');
            if(overlay) {
                overlay.style.display = show ? 'flex' : 'none';
            }
        }
        
        // Form submission handler
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('productForm');
            if(form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    createProduct();
                });
            }
            connectWebSocket();
        });
    </script>
</body>
</html>