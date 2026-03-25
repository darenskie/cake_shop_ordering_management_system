<?php
session_start();
require_once '../db.php';
require_once '../websocket_client.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'customer') {
    header("Location: ../login.php");
    exit();
}

// Generate unique token for idempotency (prevents duplicate orders)
$order_token = md5(uniqid(rand(), true));

// Get customer's orders (still via HTTP for initial load)
$my_orders = $conn->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5");
$my_orders->execute([$_SESSION['user_id']]);
$orders = $my_orders->fetchAll();

// Handle success message from redirect
if(isset($_GET['success'])) {
    $success = "Order placed successfully! Order number: " . htmlspecialchars($_GET['order']);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Customer Dashboard - Cake Shop</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .logo { font-size: 24px; font-weight: bold; }
        .user-info { display: flex; gap: 20px; align-items: center; }
        .logout-btn {
            background: #ff6b6b;
            color: white;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            transition: background 0.3s;
        }
        .logout-btn:hover { background: #ff5252; }
        .container { max-width: 1200px; margin: 20px auto; padding: 0 20px; }
        .welcome {
            background: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .section-title {
            margin: 30px 0 20px;
            color: #333;
            border-bottom: 2px solid #ff6b6b;
            padding-bottom: 10px;
        }
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
        }
        .product-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        .product-image-container {
            height: 220px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }
        .product-card:hover .product-image {
            transform: scale(1.05);
        }
        .no-image {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 64px;
            background: linear-gradient(135deg, #f5f5f5, #e0e0e0);
        }
        .no-image span {
            font-size: 14px;
            color: #999;
            margin-top: 10px;
        }
        .product-info { padding: 20px; }
        .product-info h3 { 
            margin-bottom: 8px;
            font-size: 18px;
            color: #333;
        }
        .category {
            color: #ff6b6b;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        .price {
            font-size: 24px;
            font-weight: bold;
            color: #ff6b6b;
            margin-bottom: 8px;
        }
        .stock {
            color: #4ecdc4;
            font-size: 14px;
            margin-bottom: 15px;
        }
        .stock.out-of-stock {
            color: #dc3545;
        }
        .order-btn {
            width: 100%;
            padding: 12px;
            background: #ff6b6b;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .order-btn:hover { background: #ff5252; }
        .order-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        table {
            width: 100%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th { background: #34495e; color: white; }
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .badge.pending { background: #ffc107; color: #333; }
        .badge.processing { background: #17a2b8; color: white; }
        .badge.completed { background: #28a745; color: white; }
        .badge.cancelled { background: #dc3545; color: white; }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .modal-content {
            background: white;
            width: 90%;
            max-width: 500px;
            margin: 50px auto;
            padding: 30px;
            border-radius: 12px;
            animation: slideDown 0.3s;
        }
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .modal-content h2 {
            margin-bottom: 20px;
            color: #333;
        }
        .modal input, .modal textarea {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        .modal input:focus, .modal textarea:focus {
            outline: none;
            border-color: #ff6b6b;
        }
        .close {
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #999;
            transition: color 0.3s;
        }
        .close:hover {
            color: #333;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
            animation: slideDown 0.3s;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
            animation: slideDown 0.3s;
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
            .products-grid {
                grid-template-columns: 1fr;
            }
            .header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">🍰 <span style="color:#ff6b6b;">Cake</span> Shop</div>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?></span>
            <a href="../logout.php" class="logout-btn">🚪 Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if(isset($success)): ?>
            <div class="success">✅ <?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="welcome">
            <h1>Welcome to Cake Shop! 🎂</h1>
            <p>Browse our delicious cakes and place your order in real-time.</p>
        </div>
        
        <h2 class="section-title">🍰 Available Cakes</h2>
        <div id="productsGrid" class="products-grid">
            <div style="text-align: center; padding: 40px;">Loading products...</div>
        </div>
        
        <h2 class="section-title">📦 My Recent Orders</h2>
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Date</th>
                </thead>
            <tbody id="ordersTableBody">
                <?php foreach($orders as $order): ?>
                 <tr>
                    <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                    <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                    <td><span class="badge <?php echo $order['status']; ?>"><?php echo ucfirst($order['status']); ?></span></td>
                    <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                 </tr>
                <?php endforeach; ?>
                <?php if(empty($orders)): ?>
                 <tr><td colspan="4" style="text-align:center; padding: 40px;">📭 No orders yet. Start shopping!</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Order Modal -->
    <div id="orderModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>📝 Place Your Order</h2>
            <form id="orderForm">
                <input type="hidden" id="product_id">
                <input type="hidden" id="order_token" value="<?php echo $order_token; ?>">
                
                <label>🍰 Product</label>
                <input type="text" id="product_name" readonly style="background:#f5f5f5;">
                
                <label>💰 Price per item</label>
                <input type="text" id="product_price" readonly style="background:#f5f5f5;">
                
                <label>🔢 Quantity</label>
                <input type="number" id="quantity" min="1" value="1" required onchange="updateTotal()">
                
                <label>💵 Total Amount</label>
                <input type="text" id="total_amount" readonly style="background:#f5f5f5; font-weight:bold; color:#ff6b6b;">
                
                <label>📍 Shipping Address</label>
                <textarea id="address" rows="3" placeholder="Enter your complete address" required></textarea>
                
                <button type="submit" class="order-btn" style="margin-top:15px;">✅ Confirm Order</button>
            </form>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner">⏳ Processing your order...</div>
    </div>
    
    <script>
        let currentProductId = 0;
        let currentPrice = 0;
        let currentStock = 0;
        let currentProductName = '';
        
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
                        displayProducts(data.payload);
                        break;
                        
                    case 'PRODUCT_CREATED':
                        showNotification(`🆕 New product available: ${data.payload.name}`, 'info');
                        refreshProducts();
                        break;
                        
                    case 'PRODUCT_UPDATED':
                        showNotification(`✏️ Product updated: ${data.payload.name}`, 'info');
                        refreshProducts();
                        break;
                        
                    case 'PRODUCT_DELETED':
                        showNotification(`🗑️ Product removed: ${data.payload.name}`, 'warning');
                        refreshProducts();
                        break;
                        
                    case 'ORDER_SUCCESS':
                        showNotification(`✅ ${data.message} - Order #${data.order_number}`, 'success');
                        closeModal();
                        // Refresh orders
                        setTimeout(() => location.reload(), 1500);
                        break;
                        
                    case 'ORDER_CREATED':
                        if(data.payload.username !== '<?php echo $_SESSION['username']; ?>') {
                            showNotification(`📦 New order placed by ${data.payload.username}`, 'success');
                        }
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
        
        function displayProducts(products) {
            const grid = document.getElementById('productsGrid');
            if(!grid) return;
            
            if(products.length === 0) {
                grid.innerHTML = '<div style="text-align: center; padding: 40px;">🍰 No products available.</div>';
                return;
            }
            
            let html = '';
            products.forEach(product => {
                // Only show available products with stock
                if(product.status !== 'available' || product.stock <= 0) return;
                
                let imageHtml = '';
                if(product.image) {
                    if(product.image.startsWith('http')) {
                        imageHtml = `<img src="${escapeHtml(product.image)}" class="product-image" alt="${escapeHtml(product.name)}">`;
                    } else {
                        imageHtml = `<img src="../${escapeHtml(product.image)}" class="product-image" alt="${escapeHtml(product.name)}">`;
                    }
                } else {
                    // Show emoji based on product name
                    let emoji = '🍰';
                    const name = product.name.toLowerCase();
                    if(name.includes('chocolate')) emoji = '🍫';
                    else if(name.includes('strawberry')) emoji = '🍓';
                    else if(name.includes('vanilla')) emoji = '🍦';
                    else if(name.includes('red velvet')) emoji = '❤️';
                    else if(name.includes('carrot')) emoji = '🥕';
                    else if(name.includes('cheese')) emoji = '🧀';
                    else if(name.includes('cupcake')) emoji = '🧁';
                    
                    imageHtml = `<div class="no-image">${emoji}<span>${escapeHtml(product.name)}</span></div>`;
                }
                
                html += `
                    <div class="product-card">
                        <div class="product-image-container">
                            ${imageHtml}
                        </div>
                        <div class="product-info">
                            <div class="category">${escapeHtml(product.category_name || 'Cake')}</div>
                            <h3>${escapeHtml(product.name)}</h3>
                            <div class="price">₱${parseFloat(product.price).toFixed(2)}</div>
                            <div class="stock ${product.stock <= 5 ? 'out-of-stock' : ''}">
                                📦 In Stock: ${product.stock} left
                            </div>
                            <button class="order-btn" onclick="openOrderModal(${product.id}, '${escapeHtml(product.name)}', ${product.price}, ${product.stock})">
                                🛒 Order Now
                            </button>
                        </div>
                    </div>
                `;
            });
            
            if(html === '') {
                grid.innerHTML = '<div style="text-align: center; padding: 40px;">🍰 No products available.</div>';
            } else {
                grid.innerHTML = html;
            }
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
        
        // CREATE ORDER via WebSocket
        function placeOrder() {
            const product_id = currentProductId;
            const quantity = parseInt(document.getElementById('quantity').value);
            const address = document.getElementById('address').value;
            const order_token = document.getElementById('order_token').value;
            
            if(!address) {
                showNotification('Please enter your shipping address', 'warning');
                return;
            }
            
            if(quantity > currentStock) {
                showNotification(`Only ${currentStock} items available!`, 'warning');
                return;
            }
            
            if(ws && ws.readyState === WebSocket.OPEN) {
                showLoading(true);
                ws.send(JSON.stringify({
                    type: 'CREATE_ORDER',
                    payload: {
                        user_id: <?php echo $_SESSION['user_id']; ?>,
                        product_id: product_id,
                        quantity: quantity,
                        address: address,
                        order_token: order_token
                    }
                }));
                showLoading(false);
            } else {
                showNotification('WebSocket not connected. Please refresh the page.', 'warning');
            }
        }
        
        function openOrderModal(id, name, price, stock) {
            currentProductId = id;
            currentPrice = price;
            currentStock = stock;
            currentProductName = name;
            
            document.getElementById('product_id').value = id;
            document.getElementById('product_name').value = name;
            document.getElementById('product_price').value = '₱' + price.toFixed(2);
            document.getElementById('quantity').value = 1;
            document.getElementById('quantity').max = stock;
            document.getElementById('total_amount').value = '₱' + price.toFixed(2);
            document.getElementById('address').value = '';
            document.getElementById('orderModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('orderModal').style.display = 'none';
            currentProductId = 0;
        }
        
        function updateTotal() {
            const quantity = parseInt(document.getElementById('quantity').value) || 1;
            const total = currentPrice * quantity;
            document.getElementById('total_amount').value = '₱' + total.toFixed(2);
            
            if(quantity > currentStock) {
                showNotification(`Only ${currentStock} items available!`, 'warning');
                document.getElementById('quantity').value = currentStock;
                updateTotal();
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
        
        // Auto-hide success/error message after 5 seconds
        setTimeout(function() {
            const success = document.querySelector('.success');
            const error = document.querySelector('.error');
            if(success) success.style.display = 'none';
            if(error) error.style.display = 'none';
        }, 5000);
        
        window.onclick = function(event) {
            const modal = document.getElementById('orderModal');
            if (event.target == modal) {
                closeModal();
            }
        };
        
        // Form submission handler
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('orderForm');
            if(form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    placeOrder();
                });
            }
            connectWebSocket();
        });
    </script>
</body>
</html>