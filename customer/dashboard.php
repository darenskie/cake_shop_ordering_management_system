<?php
session_start();
require_once '../db.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'customer') {
    header("Location: ../login.php");
    exit();
}

// Get available products
$products = $conn->query("
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE p.status = 'available' AND p.stock > 0
    ORDER BY p.id DESC
")->fetchAll();

// Get customer's orders
$my_orders = $conn->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5");
$my_orders->execute([$_SESSION['user_id']]);
$orders = $my_orders->fetchAll();

// Handle place order
if(isset($_POST['place_order'])) {
    $product_id = $_POST['product_id'];
    $quantity = $_POST['quantity'];
    $address = $_POST['address'];
    
    // Get product price
    $prod = $conn->prepare("SELECT price, name FROM products WHERE id = ?");
    $prod->execute([$product_id]);
    $product = $prod->fetch();
    
    $total = $product['price'] * $quantity;
    $order_number = 'ORD-' . date('Ymd') . '-' . rand(1000, 9999);
    
    // Create order
    $stmt = $conn->prepare("INSERT INTO orders (user_id, order_number, total_amount, shipping_address, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->execute([$_SESSION['user_id'], $order_number, $total, $address]);
    $order_id = $conn->lastInsertId();
    
    // Add order item
    $item = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
    $item->execute([$order_id, $product_id, $quantity, $product['price'], $total]);
    
    // Update stock
    $update = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
    $update->execute([$quantity, $product_id]);
    
    // Log audit
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'ORDER', 'orders', ?, ?)");
    $log->execute([$_SESSION['user_id'], $order_id, "Placed order for " . $product['name']]);
    
    $success = "Order placed successfully! Order number: $order_number";
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
        }
        .logo { font-size: 24px; font-weight: bold; }
        .user-info { display: flex; gap: 20px; align-items: center; }
        .logout-btn {
            background: #ff6b6b;
            color: white;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
        }
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
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }
        .product-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .product-image {
            height: 180px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
        }
        .product-info { padding: 20px; }
        .product-info h3 { margin-bottom: 10px; }
        .category {
            color: #666;
            font-size: 12px;
            margin-bottom: 10px;
        }
        .price {
            font-size: 20px;
            font-weight: bold;
            color: #ff6b6b;
            margin-bottom: 10px;
        }
        .stock {
            color: #4ecdc4;
            font-size: 14px;
            margin-bottom: 15px;
        }
        .order-btn {
            width: 100%;
            padding: 10px;
            background: #ff6b6b;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .order-btn:hover { background: #ff5252; }
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
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
        }
        .badge.pending { background: orange; color: white; }
        .badge.processing { background: blue; color: white; }
        .badge.completed { background: green; color: white; }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        .modal-content {
            background: white;
            width: 90%;
            max-width: 500px;
            margin: 50px auto;
            padding: 30px;
            border-radius: 10px;
        }
        .modal input, .modal textarea {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .close {
            float: right;
            font-size: 24px;
            cursor: pointer;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">🍰 Cake Shop</div>
        <div class="user-info">
            <span>Welcome, <?php echo $_SESSION['full_name']; ?></span>
            <a href="../logout.php" class="logout-btn">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if(isset($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="welcome">
            <h1>Welcome to Cake Shop! 🎂</h1>
            <p>Browse our delicious cakes and place your order in real-time.</p>
        </div>
        
        <h2 class="section-title">🍰 Available Cakes</h2>
        <div class="products-grid">
            <?php foreach($products as $product): ?>
            <div class="product-card">
                <div class="product-image">🍰</div>
                <div class="product-info">
                    <h3><?php echo $product['name']; ?></h3>
                    <div class="category"><?php echo $product['category_name'] ?? 'Cake'; ?></div>
                    <div class="price">₱<?php echo number_format($product['price'], 2); ?></div>
                    <div class="stock">In Stock: <?php echo $product['stock']; ?></div>
                    <button class="order-btn" onclick="openOrderModal(<?php echo $product['id']; ?>, '<?php echo $product['name']; ?>', <?php echo $product['price']; ?>)">
                        Order Now
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <h2 class="section-title">📦 My Recent Orders</h2>
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($orders as $order): ?>
                <tr>
                    <td><?php echo $order['order_number']; ?></td>
                    <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                    <td><span class="badge <?php echo $order['status']; ?>"><?php echo $order['status']; ?></span></td>
                    <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($orders)): ?>
                <tr><td colspan="4" style="text-align:center;">No orders yet</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Order Modal -->
    <div id="orderModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Place Order</h2>
            <form method="POST">
                <input type="hidden" name="product_id" id="product_id">
                <input type="hidden" name="place_order" value="1">
                
                <label>Product</label>
                <input type="text" id="product_name" readonly>
                
                <label>Price</label>
                <input type="text" id="product_price" readonly>
                
                <label>Quantity</label>
                <input type="number" name="quantity" id="quantity" min="1" value="1" required onchange="updateTotal()">
                
                <label>Total Amount</label>
                <input type="text" id="total_amount" readonly>
                
                <label>Shipping Address</label>
                <textarea name="address" rows="3" required></textarea>
                
                <button type="submit" class="order-btn" style="margin-top:15px;">Confirm Order</button>
            </form>
        </div>
    </div>
    
    <script>
        let currentPrice = 0;
        
        function openOrderModal(id, name, price) {
            currentPrice = price;
            document.getElementById('product_id').value = id;
            document.getElementById('product_name').value = name;
            document.getElementById('product_price').value = '₱' + price.toFixed(2);
            document.getElementById('quantity').value = 1;
            document.getElementById('total_amount').value = '₱' + price.toFixed(2);
            document.getElementById('orderModal').style.display = 'block';
        }
        
        function closeModal() {
            document.getElementById('orderModal').style.display = 'none';
        }
        
        function updateTotal() {
            const quantity = document.getElementById('quantity').value;
            const total = currentPrice * quantity;
            document.getElementById('total_amount').value = '₱' + total.toFixed(2);
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('orderModal');
            if (event.target == modal) {
                closeModal();
            }
        };
    </script>
</body>
</html>