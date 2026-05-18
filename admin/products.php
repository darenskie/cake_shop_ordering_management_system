<?php
session_start();
require_once '../db.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// Handle HTTP POST Add Product
if(isset($_POST['add_product'])) {
    $name = $_POST['name'];
    $category_id = $_POST['category_id'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $description = $_POST['description'];
    $image = $_POST['image_url'] ?? '';
    
    if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if(in_array($ext, $allowed)) {
            $upload_dir = '../uploads/products/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            $new_filename = uniqid() . '.' . $ext;
            if(move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $new_filename)) {
                $image = 'uploads/products/' . $new_filename;
            }
        }
    }
    
    $stmt = $conn->prepare("INSERT INTO products (name, category_id, price, stock, description, image, status) VALUES (?, ?, ?, ?, ?, ?, 'available')");
    $stmt->execute([$name, $category_id, $price, $stock, $description, $image]);
    $new_id = $conn->lastInsertId();
    
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'CREATE', 'products', ?, ?)");
    $log->execute([$_SESSION['user_id'], $new_id, "Added product: $name"]);
    
    header("Location: products.php?msg=added");
    exit();
}

// Handle HTTP POST Delete Product
if(isset($_POST['delete_product'])) {
    $id = $_POST['product_id'];
    $name = $_POST['product_name'];
    
    $prod = $conn->prepare("SELECT name, image FROM products WHERE id = ?");
    $prod->execute([$id]);
    $product = $prod->fetch();
    
    if($product && $product['image'] && !filter_var($product['image'], FILTER_VALIDATE_URL)) {
        $image_path = '../' . $product['image'];
        if(file_exists($image_path)) unlink($image_path);
    }
    
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$id]);
    
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'DELETE', 'products', ?, ?)");
    $log->execute([$_SESSION['user_id'], $id, "Deleted product: " . ($product['name'] ?? $name)]);
    
    header("Location: products.php?msg=deleted");
    exit();
}

$categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$products = $conn->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.id DESC")->fetchAll();
$success_msg = '';

if(isset($_GET['msg'])) {
    if($_GET['msg'] == 'added') $success_msg = "Product added successfully!";
    if($_GET['msg'] == 'deleted') $success_msg = "Product deleted successfully!";
    if($_GET['msg'] == 'updated') $success_msg = "Product updated successfully!";
}
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
        }
        .sidebar a:hover, .sidebar a.active { background: #34495e; }
        .content { flex: 1; margin-left: 250px; padding: 20px; }
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
            display: none;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        table {
            width: 100%;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; vertical-align: middle; }
        th { background: #34495e; color: white; }
        .product-image { width: 50px; height: 50px; object-fit: cover; border-radius: 5px; }
        .no-image { width: 50px; height: 50px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border-radius: 5px; font-size: 24px; }
        .status { padding: 3px 8px; border-radius: 3px; font-size: 12px; }
        .status.available { background: #d4edda; color: #155724; }
        .status.out_of_stock { background: #f8d7da; color: #721c24; }
        .msg { padding: 10px; border-radius: 5px; margin-bottom: 20px; background: #d4edda; color: #155724; }
        @media (max-width: 768px) { .sidebar { width: 200px; } .content { margin-left: 200px; } .form-row { grid-template-columns: 1fr; } }
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
                <a href="categories.php">📁 Categories</a>
                <a href="orders.php">📦 Orders</a>
                <a href="users.php">👥 Users</a>
                <a href="audit.php">📋 Audit Logs</a>
                <a href="search.php">🔍 Search</a>
                <a href="../logout.php">🚪 Logout</a>
            </nav>
        </div>
        
        <div class="content">
            <div class="header">
                <h1>🍰 Manage Products</h1>
                <button class="btn btn-primary" onclick="toggleForm()">➕ Add New Product</button>
            </div>
            
            <?php if($success_msg): ?>
                <div class="msg">✅ <?php echo $success_msg; ?></div>
            <?php endif; ?>
            
            <!-- Add Product Form -->
            <div id="addForm" class="add-form">
                <h3>➕ Add New Product</h3>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" name="name" required>
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
                            <input type="number" name="price" step="0.01" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Stock *</label>
                            <input type="number" name="stock" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Image URL</label>
                            <input type="text" name="image_url" placeholder="https://example.com/image.jpg">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>OR Upload Image File</label>
                        <input type="file" name="image" accept="image/*" onchange="previewImage(this)">
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3"></textarea>
                    </div>
                    
                    <div id="imagePreview"></div>
                    
                    <button type="submit" name="add_product" class="btn btn-primary">💾 Save Product</button>
                    <button type="button" class="btn" style="background:#6c757d; color:white;" onclick="toggleForm()">Cancel</button>
                </form>
            </div>
            
            <!-- Delete Form -->
            <form id="deleteForm" method="POST" style="display: none;">
                <input type="hidden" name="product_id" id="deleteProductId">
                <input type="hidden" name="product_name" id="deleteProductName">
                <input type="hidden" name="delete_product" value="1">
            </form>
            
            <!-- Products Table -->
            <table>
                <thead>
                    <tr><th>Image</th><th>ID</th><th>Name</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach($products as $product): ?>
                    <tr>
                        <td>
                            <?php if(!empty($product['image'])): ?>
                                <img src="../<?php echo htmlspecialchars($product['image']); ?>" class="product-image">
                            <?php else: ?>
                                <div class="no-image">🍰</div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $product['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($product['category_name'] ?: 'Uncategorized'); ?></td>
                        <td>₱<?php echo number_format($product['price'], 2); ?></td>
                        <td><?php echo $product['stock']; ?></td>
                        <td><span class="status <?php echo $product['status']; ?>"><?php echo ucfirst($product['status']); ?></span></td>
                        <td>
                            <a href="edit_product.php?id=<?php echo $product['id']; ?>" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">Edit</a>
                            <button onclick="deleteProduct(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>')" class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;">Delete</button>
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
            if(form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
            } else {
                form.style.display = 'none';
            }
        }
        
        function previewImage(input) {
            var preview = document.getElementById('imagePreview');
            preview.innerHTML = '';
            if(input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var img = document.createElement('img');
                    img.src = e.target.result;
                    img.style.maxWidth = '100px';
                    img.style.marginTop = '10px';
                    img.style.borderRadius = '5px';
                    preview.appendChild(img);
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function deleteProduct(id, name) {
            if(confirm('Delete product: ' + name + '?')) {
                document.getElementById('deleteProductId').value = id;
                document.getElementById('deleteProductName').value = name;
                document.getElementById('deleteForm').submit();
            }
        }
        
        setTimeout(function() {
            var msg = document.querySelector('.msg');
            if(msg) msg.style.display = 'none';
        }, 3000);
    </script>
</body>
</html>