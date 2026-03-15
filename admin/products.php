<?php
session_start();
require_once '../db.php';

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
    
    // Log audit
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'CREATE', 'products', ?, ?)");
    $log->execute([$_SESSION['user_id'], $conn->lastInsertId(), "Added product: $name"]);
    
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
    
    // Delete image file if exists
    if($product && $product['image']) {
        $image_path = '../' . $product['image'];
        if(file_exists($image_path)) {
            unlink($image_path);
        }
    }
    
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);
    
    // Log audit
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'DELETE', 'products', ?, ?)");
    $log->execute([$_SESSION['user_id'], $id, "Deleted product: " . ($product['name'] ?? 'Unknown')]);
    
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
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #ddd;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
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
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
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
        th { background: #34495e; color: white; }
        .product-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 5px;
        }
        .no-image {
            width: 50px;
            height: 50px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 5px;
            color: #999;
        }
        .status {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
        }
        .status.available { background: #4ecdc4; color: white; }
        .status.out_of_stock { background: #ff6b6b; color: white; }
        .msg {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .msg.success { background: #d4edda; color: #155724; }
        .image-preview {
            max-width: 100px;
            max-height: 100px;
            margin-top: 10px;
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
                <h1>Manage Products</h1>
                <button class="btn btn-primary" onclick="toggleForm()">➕ Add New Product</button>
            </div>
            
            <?php if(isset($_GET['msg'])): ?>
                <div class="msg success">
                    <?php 
                    if($_GET['msg'] == 'added') echo "✅ Product added successfully!";
                    if($_GET['msg'] == 'deleted') echo "✅ Product deleted successfully!";
                    ?>
                </div>
            <?php endif; ?>
            
            <!-- Add Product Form -->
            <div id="addForm" style="display: none;" class="add-form">
                <h3>Add New Product</h3>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Product Name</label>
                        <input type="text" name="name" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category_id" required>
                                <option value="">Select Category</option>
                                <?php foreach($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Price (₱)</label>
                            <input type="number" name="price" step="0.01" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Stock</label>
                            <input type="number" name="stock" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Product Image</label>
                            <input type="file" name="image" accept="image/*" onchange="previewImage(this)">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3"></textarea>
                    </div>
                    
                    <div id="imagePreview"></div>
                    
                    <button type="submit" name="add_product" class="btn btn-primary">Save Product</button>
                    <button type="button" class="btn" style="background:#ccc;" onclick="toggleForm()">Cancel</button>
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
                    <?php foreach($products as $product): ?>
                    <tr>
                        <td>
                            <?php if($product['image']): ?>
                                <img src="../<?php echo htmlspecialchars($product['image']); ?>" class="product-image">
                            <?php else: ?>
                                <div class="no-image">🍰</div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $product['id']; ?></td>
                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                        <td><?php echo htmlspecialchars($product['category_name'] ?: 'Uncategorized'); ?></td>
                        <td>₱<?php echo number_format($product['price'], 2); ?></td>
                        <td><?php echo $product['stock']; ?></td>
                        <td><span class="status <?php echo $product['status']; ?>"><?php echo $product['status']; ?></span></td>
                        <td>
                            <a href="?delete=<?php echo $product['id']; ?>" class="btn btn-danger" onclick="return confirm('Delete this product?')">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        function toggleForm() {
            var form = document.getElementById('addForm');
            if(form.style.display === 'none') {
                form.style.display = 'block';
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
                    preview.appendChild(img);
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</body>
</html>