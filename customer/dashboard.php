<?php
session_start();
require_once '../db.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'customer') {
    header("Location: ../login.php");
    exit();
}

// Get available products with images
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
        <div class="products-grid">
            <?php foreach($products as $product): ?>
            <div class="product-card">
                <div class="product-image-container">
                    <?php 
                    // Get the image URL - use the same logic as admin products
                    $image_url = '';
                    if(!empty($product['image'])) {
                        if(filter_var($product['image'], FILTER_VALIDATE_URL)) {
                            $image_url = $product['image'];
                        } else {
                            $image_path = '../' . $product['image'];
                            if(file_exists($image_path)) {
                                $image_url = '../' . $product['image'];
                            }
                        }
                    }
                    
                    if($image_url): 
                    ?>
                        <img src="<?php echo htmlspecialchars($image_url); ?>" class="product-image" alt="<?php echo htmlspecialchars($product['name']); ?>">
                    <?php else: ?>
                        <div class="no-image">
                            <?php
                            // Show emoji based on product name
                            $name = strtolower($product['name']);
                            if(strpos($name, 'chocolate') !== false) echo '🍫';
                            elseif(strpos($name, 'strawberry') !== false) echo '🍓';
                            elseif(strpos($name, 'vanilla') !== false) echo '🍦';
                            elseif(strpos($name, 'red velvet') !== false) echo '❤️';
                            elseif(strpos($name, 'carrot') !== false) echo '🥕';
                            elseif(strpos($name, 'cheese') !== false) echo '🧀';
                            elseif(strpos($name, 'cupcake') !== false) echo '🧁';
                            else echo '🍰';
                            ?>
                            <span><?php echo htmlspecialchars($product['name']); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="product-info">
                    <div class="category"><?php echo htmlspecialchars($product['category_name'] ?? 'Cake'); ?></div>
                    <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                    <div class="price">₱<?php echo number_format($product['price'], 2); ?></div>
                    <div class="stock <?php echo $product['stock'] <= 5 ? 'out-of-stock' : ''; ?>">
                        <?php if($product['stock'] > 0): ?>
                            📦 In Stock: <?php echo $product['stock']; ?> left
                        <?php else: ?>
                            ❌ Out of Stock
                        <?php endif; ?>
                    </div>
                    <button class="order-btn" onclick="openOrderModal(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['price']; ?>, <?php echo $product['stock']; ?>)" 
                            <?php echo $product['stock'] <= 0 ? 'disabled' : ''; ?>>
                        <?php echo $product['stock'] > 0 ? '🛒 Order Now' : 'Sold Out'; ?>
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
            <form method="POST">
                <input type="hidden" name="product_id" id="product_id">
                <input type="hidden" name="place_order" value="1">
                
                <label>🍰 Product</label>
                <input type="text" id="product_name" readonly style="background:#f5f5f5;">
                
                <label>💰 Price per item</label>
                <input type="text" id="product_price" readonly style="background:#f5f5f5;">
                
                <label>🔢 Quantity</label>
                <input type="number" name="quantity" id="quantity" min="1" value="1" required onchange="updateTotal()">
                
                <label>💵 Total Amount</label>
                <input type="text" id="total_amount" readonly style="background:#f5f5f5; font-weight:bold; color:#ff6b6b;">
                
                <label>📍 Shipping Address</label>
                <textarea name="address" rows="3" placeholder="Enter your complete address" required></textarea>
                
                <button type="submit" class="order-btn" style="margin-top:15px;">✅ Confirm Order</button>
            </form>
        </div>
    </div>
    
    <script>
        let currentPrice = 0;
        let currentStock = 0;
        
        function openOrderModal(id, name, price, stock) {
            currentPrice = price;
            currentStock = stock;
            document.getElementById('product_id').value = id;
            document.getElementById('product_name').value = name;
            document.getElementById('product_price').value = '₱' + price.toFixed(2);
            document.getElementById('quantity').value = 1;
            document.getElementById('quantity').max = stock;
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
            
            // Validate quantity
            if(quantity > currentStock) {
                alert('Only ' + currentStock + ' items available in stock!');
                document.getElementById('quantity').value = currentStock;
                updateTotal();
            }
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('orderModal');
            if (event.target == modal) {
                closeModal();
            }
        };
        
        // Auto-hide success message
        setTimeout(function() {
            const success = document.querySelector('.success');
            if(success) {
                success.style.display = 'none';
            }
        }, 5000);
    </script>
</body>
</html>