<?php
session_start();
require_once '../db.php';
require_once '../websocket_client.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// Get product ID from URL
$product_id = isset($_GET['id']) ? $_GET['id'] : 0;

// Handle HTTP POST Update (Fallback)
if(isset($_POST['update_product'])) {
    $name = $_POST['name'];
    $category_id = $_POST['category_id'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $description = $_POST['description'];
    $status = $_POST['status'];
    $image = $_POST['current_image'] ?? '';
    
    // Handle image URL input
    if(!empty($_POST['image_url'])) {
        $image = $_POST['image_url'];
    }
    
    // Handle file upload
    if(isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if(in_array($ext, $allowed)) {
            $upload_dir = '../uploads/products/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $new_filename = uniqid() . '.' . $ext;
            $upload_path = $upload_dir . $new_filename;
            
            if(move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                // Delete old image if exists and is local file
                if(!empty($_POST['current_image']) && !filter_var($_POST['current_image'], FILTER_VALIDATE_URL)) {
                    $old_path = '../' . $_POST['current_image'];
                    if(file_exists($old_path)) {
                        unlink($old_path);
                    }
                }
                $image = 'uploads/products/' . $new_filename;
            }
        }
    }
    
    // Update product
    $stmt = $conn->prepare("UPDATE products SET name = ?, category_id = ?, price = ?, stock = ?, description = ?, image = ?, status = ? WHERE id = ?");
    $stmt->execute([$name, $category_id, $price, $stock, $description, $image, $status, $product_id]);
    
    // Log audit
    $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, details) VALUES (?, 'UPDATE', 'products', ?, ?)");
    $log->execute([$_SESSION['user_id'], $product_id, "Updated product: $name"]);
    
    // Broadcast via WebSocket (if server is running)
    broadcastWebSocket('product_updated', [
        'id' => $product_id,
        'name' => $name,
        'price' => $price,
        'stock' => $stock,
        'status' => $status,
        'image' => $image,
        'action_by' => $_SESSION['full_name']
    ]);
    
    header("Location: products.php?msg=updated");
    exit();
}

// Fetch product details
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if(!$product) {
    header("Location: products.php?msg=notfound");
    exit();
}

// Get categories for dropdown
$categories = $conn->query("SELECT * FROM categories")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Product - Cake Shop</title>
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
        .sidebar a:hover {
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
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
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
            gap: 20px;
        }
        .current-image {
            margin: 10px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e0e0e0;
        }
        .current-image img {
            max-width: 150px;
            max-height: 150px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .current-image p {
            margin-top: 10px;
            font-size: 12px;
            color: #666;
            word-break: break-all;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: opacity 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #ff6b6b;
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn:hover {
            opacity: 0.8;
        }
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .image-preview {
            max-width: 150px;
            max-height: 150px;
            margin-top: 10px;
            border-radius: 5px;
            border: 1px solid #ddd;
            padding: 5px;
        }
        .help-text {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #eee;
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
                <a href="orders.php">📦 Orders</a>
                <a href="users.php">👥 Users</a>
                <a href="audit.php">📋 Audit Logs</a>
                <a href="../logout.php">🚪 Logout</a>
            </nav>
        </div>
        
        <div class="content">
            <div class="header">
                <h1>✏️ Edit Product</h1>
                <p>Update product information below</p>
            </div>
            
            <div class="form-container">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="current_image" value="<?php echo htmlspecialchars($product['image']); ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Product Name *</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($product['name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Category *</label>
                            <select name="category_id" required>
                                <option value="">Select Category</option>
                                <?php foreach($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $product['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Price (₱) *</label>
                            <input type="number" name="price" step="0.01" value="<?php echo $product['price']; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Stock *</label>
                            <input type="number" name="stock" value="<?php echo $product['stock']; ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="4"><?php echo htmlspecialchars($product['description']); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="available" <?php echo $product['status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="out_of_stock" <?php echo $product['status'] == 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Current Image</label>
                        <div class="current-image">
                            <?php if(!empty($product['image'])): ?>
                                <?php if(filter_var($product['image'], FILTER_VALIDATE_URL)): ?>
                                    <img src="<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>">
                                    <p>Image URL: <?php echo htmlspecialchars($product['image']); ?></p>
                                <?php else: ?>
                                    <?php 
                                    $image_path = '../' . $product['image'];
                                    if(file_exists($image_path)): 
                                    ?>
                                        <img src="../<?php echo $product['image']; ?>" alt="<?php echo $product['name']; ?>">
                                        <p>Image Path: <?php echo htmlspecialchars($product['image']); ?></p>
                                    <?php else: ?>
                                        <div style="padding: 40px; background: #f0f0f0; border-radius: 8px;">⚠️ Image file not found</div>
                                        <p><?php echo htmlspecialchars($product['image']); ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="padding: 40px; background: #f0f0f0; border-radius: 8px;">🍰 No image set</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="form-group">
                        <label>📁 Upload New Image</label>
                        <input type="file" name="image" accept="image/*" onchange="previewImage(this)">
                        <p class="help-text">Allowed: JPG, JPEG, PNG, GIF. Leave empty to keep current image.</p>
                        <div id="imagePreview"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>🔗 OR Enter Image URL</label>
                        <input type="text" name="image_url" placeholder="https://example.com/cake-image.jpg" 
                               value="<?php echo filter_var($product['image'], FILTER_VALIDATE_URL) ? $product['image'] : ''; ?>">
                        <p class="help-text">Paste a direct link to an image from the web (e.g., from Pexels, Unsplash)</p>
                    </div>
                    
                    <div class="button-group">
                        <button type="submit" name="update_product" class="btn btn-primary">💾 Save Changes</button>
                        <a href="products.php" class="btn btn-secondary">❌ Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner">⏳ Updating product...</div>
    </div>
    
    <script>
        function previewImage(input) {
            var preview = document.getElementById('imagePreview');
            preview.innerHTML = '';
            
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    var img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'image-preview';
                    img.style.maxWidth = '150px';
                    img.style.maxHeight = '150px';
                    img.style.marginTop = '10px';
                    img.style.borderRadius = '5px';
                    img.style.border = '1px solid #ddd';
                    img.style.padding = '5px';
                    preview.appendChild(img);
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Show loading on form submit
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            if(form) {
                form.addEventListener('submit', function(e) {
                    document.getElementById('loadingOverlay').style.display = 'flex';
                });
            }
        });
        
        // Auto-hide success/error message after 3 seconds (if any)
        setTimeout(function() {
            const msg = document.querySelector('.msg');
            if(msg) {
                msg.style.display = 'none';
            }
        }, 3000);
    </script>
</body>
</html>