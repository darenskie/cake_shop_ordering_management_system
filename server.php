<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class CakeShopWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $users;
    protected $conn;
    
    public function __construct($database) {
        $this->clients = new \SplObjectStorage;
        $this->users = [];
        $this->conn = $database;
        
        echo "====================================\n";
        echo "✅ Cake Shop WebSocket Server Started\n";
        echo "📡 Host: localhost\n";
        echo "🔌 Port: 8080\n";
        echo "⏰ Time: " . date('Y-m-d H:i:s') . "\n";
        echo "🔄 Full WebSocket CRUD: ENABLED\n";
        echo "📦 Products CRUD: YES (Create/Read/Update/Delete)\n";
        echo "📦 Orders CRUD: YES (Create/Read/Update)\n";
        echo "📦 Users CRUD: YES (Create/Read/Update)\n";
        echo "📦 Categories CRUD: YES (Create/Read/Update/Delete)\n";
        echo "====================================\n";
        
        try {
            $stmt = $this->conn->query("SELECT COUNT(*) FROM products");
            $count = $stmt->fetchColumn();
            echo "✅ Database connection: OK (" . $count . " products found)\n";
        } catch(Exception $e) {
            echo "❌ Database connection: FAILED - " . $e->getMessage() . "\n";
        }
        echo "====================================\n";
    }
    
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->resourceId = spl_object_hash($conn);
        echo "🔗 New connection! (ID: {$conn->resourceId})\n";
        echo "👥 Total clients: " . count($this->clients) . "\n";
        
        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => 'Connected to Cake Shop WebSocket Server',
            'time' => date('Y-m-d H:i:s'),
            'client_id' => $conn->resourceId
        ]));
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        echo "📨 Message received: " . substr($msg, 0, 100) . "...\n";
        
        if(!isset($data['type'])) {
            return;
        }
        
        switch($data['type']) {
            // Authentication
            case 'auth':
                $this->handleAuth($from, $data);
                break;
            
            // ========== PRODUCTS CRUD ==========
            case 'READ_PRODUCTS':
                $this->handleReadProducts($from, $data);
                break;
            case 'CREATE_PRODUCT':
                $this->handleCreateProduct($from, $data);
                break;
            case 'UPDATE_PRODUCT':
                $this->handleUpdateProduct($from, $data);
                break;
            case 'DELETE_PRODUCT':
                $this->handleDeleteProduct($from, $data);
                break;
            
            // ========== ORDERS CRUD ==========
            case 'READ_ORDERS':
                $this->handleReadOrders($from, $data);
                break;
            case 'CREATE_ORDER':
                $this->handleCreateOrder($from, $data);
                break;
            case 'UPDATE_ORDER_STATUS':
                $this->handleUpdateOrderStatus($from, $data);
                break;
            
            // ========== USERS CRUD ==========
            case 'READ_USERS':
                $this->handleReadUsers($from, $data);
                break;
            case 'CREATE_USER':
                $this->handleCreateUser($from, $data);
                break;
            case 'UPDATE_USER_ROLE':
                $this->handleUpdateUserRole($from, $data);
                break;
            case 'DELETE_USER':
                $this->handleDeleteUser($from, $data);
                break;
            
            // ========== CATEGORIES CRUD ==========
            case 'READ_CATEGORIES':
                $this->handleReadCategories($from, $data);
                break;
            case 'CREATE_CATEGORY':
                $this->handleCreateCategory($from, $data);
                break;
            case 'UPDATE_CATEGORY':
                $this->handleUpdateCategory($from, $data);
                break;
            case 'DELETE_CATEGORY':
                $this->handleDeleteCategory($from, $data);
                break;
            
            // Keep alive
            case 'ping':
                $from->send(json_encode([
                    'type' => 'pong',
                    'timestamp' => date('Y-m-d H:i:s')
                ]));
                break;
            
            default:
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Unknown message type: ' . $data['type']
                ]));
        }
    }
    
    // ========== AUTHENTICATION ==========
    public function handleAuth($from, $data) {
        if(isset($data['user_id'])) {
            $this->users[$from->resourceId] = [
                'user_id' => $data['user_id'],
                'role' => $data['role'] ?? 'customer',
                'username' => $data['username'] ?? 'User'
            ];
            
            $from->send(json_encode([
                'type' => 'auth_success',
                'message' => 'Authenticated successfully',
                'user_id' => $data['user_id'],
                'role' => $data['role'] ?? 'customer',
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            echo "✅ User {$data['user_id']} authenticated\n";
        } else {
            $from->send(json_encode([
                'type' => 'auth_failed',
                'message' => 'Missing user_id'
            ]));
        }
    }
    
    // ========== PRODUCTS CRUD ==========
    public function handleReadProducts($from, $data) {
        try {
            $sql = "SELECT p.*, c.name as category_name 
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE p.status = 'available' 
                    ORDER BY p.id DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $from->send(json_encode([
                'type' => 'PRODUCT_LIST',
                'payload' => $products,
                'count' => count($products),
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            echo "📤 Sent " . count($products) . " products to client\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to fetch products: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleCreateProduct($from, $data) {
        try {
            $product = $data['payload'];
            $name = $product['name'];
            $category_id = $product['category_id'] ?? null;
            $price = $product['price'];
            $stock = $product['stock'];
            $description = $product['description'] ?? '';
            $image = $product['image'] ?? '';
            
            $stmt = $this->conn->prepare("INSERT INTO products (name, category_id, price, stock, description, image, status) VALUES (?, ?, ?, ?, ?, ?, 'available')");
            $stmt->execute([$name, $category_id, $price, $stock, $description, $image]);
            $new_id = $this->conn->lastInsertId();
            
            $getProduct = $this->conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
            $getProduct->execute([$new_id]);
            $newProduct = $getProduct->fetch(PDO::FETCH_ASSOC);
            
            // Send ACK to sender
            $from->send(json_encode([
                'type' => 'PRODUCT_CREATED_ACK',
                'status' => 'success',
                'message' => 'Product created successfully',
                'product' => $newProduct
            ]));
            
            // Broadcast to ALL clients
            $broadcast = json_encode([
                'type' => 'PRODUCT_BROADCAST',
                'action' => 'created',
                'product' => $newProduct,
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            echo "✅ Product created: $name (ID: $new_id)\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to create product: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleUpdateProduct($from, $data) {
        try {
            $product = $data['payload'];
            $id = $product['id'];
            $name = $product['name'];
            $category_id = $product['category_id'] ?? null;
            $price = $product['price'];
            $stock = $product['stock'];
            $description = $product['description'] ?? '';
            $status = $product['status'] ?? 'available';
            $image = $product['image'] ?? '';
            
            $stmt = $this->conn->prepare("UPDATE products SET name = ?, category_id = ?, price = ?, stock = ?, description = ?, image = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $category_id, $price, $stock, $description, $image, $status, $id]);
            
            $getProduct = $this->conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
            $getProduct->execute([$id]);
            $updatedProduct = $getProduct->fetch(PDO::FETCH_ASSOC);
            
            // Send ACK to sender
            $from->send(json_encode([
                'type' => 'PRODUCT_UPDATED_ACK',
                'status' => 'success',
                'message' => 'Product updated successfully'
            ]));
            
            // Broadcast to ALL clients
            $broadcast = json_encode([
                'type' => 'PRODUCT_BROADCAST',
                'action' => 'updated',
                'product' => $updatedProduct,
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            echo "✅ Product updated: $name (ID: $id)\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to update product: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleDeleteProduct($from, $data) {
        try {
            $id = $data['payload']['id'];
            $name = $data['payload']['name'] ?? 'Unknown';
            
            $getProduct = $this->conn->prepare("SELECT name, image FROM products WHERE id = ?");
            $getProduct->execute([$id]);
            $product = $getProduct->fetch(PDO::FETCH_ASSOC);
            
            if($product && $product['image'] && !filter_var($product['image'], FILTER_VALIDATE_URL)) {
                $image_path = '../' . $product['image'];
                if(file_exists($image_path)) {
                    unlink($image_path);
                }
            }
            
            $stmt = $this->conn->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);
            
            // Send ACK to sender
            $from->send(json_encode([
                'type' => 'PRODUCT_DELETED_ACK',
                'status' => 'success',
                'message' => 'Product deleted successfully'
            ]));
            
            // Broadcast to ALL clients
            $broadcast = json_encode([
                'type' => 'PRODUCT_BROADCAST',
                'action' => 'deleted',
                'product_id' => $id,
                'product_name' => $product['name'] ?? $name,
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            echo "🗑️ Product deleted: {$product['name']} (ID: $id)\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to delete product: ' . $e->getMessage()
            ]));
        }
    }
    
    // ========== ORDERS CRUD ==========
    public function handleReadOrders($from, $data) {
        try {
            $user_id = $data['payload']['user_id'] ?? null;
            
            if($user_id) {
                $stmt = $this->conn->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 10");
                $stmt->execute([$user_id]);
            } else {
                $stmt = $this->conn->prepare("SELECT o.*, u.username FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.id DESC LIMIT 20");
                $stmt->execute();
            }
            
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $from->send(json_encode([
                'type' => 'ORDER_LIST',
                'payload' => $orders,
                'count' => count($orders),
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            echo "📤 Sent " . count($orders) . " orders to client\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to fetch orders: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleCreateOrder($from, $data) {
        try {
            $order = $data['payload'];
            $user_id = $order['user_id'];
            $product_id = $order['product_id'];
            $quantity = $order['quantity'];
            $address = $order['address'];
            $order_token = $order['order_token'] ?? md5(uniqid(rand(), true));
            
            $check = $this->conn->prepare("SELECT id FROM orders WHERE order_token = ?");
            $check->execute([$order_token]);
            
            if($check->rowCount() > 0) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Order already processed'
                ]));
                return;
            }
            
            $prod = $this->conn->prepare("SELECT price, name, stock FROM products WHERE id = ?");
            $prod->execute([$product_id]);
            $product = $prod->fetch(PDO::FETCH_ASSOC);
            
            if($product['stock'] < $quantity) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Not enough stock. Only ' . $product['stock'] . ' available.'
                ]));
                return;
            }
            
            $total = $product['price'] * $quantity;
            $order_number = 'ORD-' . date('Ymd') . '-' . rand(1000, 9999);
            
            $stmt = $this->conn->prepare("INSERT INTO orders (user_id, order_number, total_amount, shipping_address, status, order_token) VALUES (?, ?, ?, ?, 'pending', ?)");
            $stmt->execute([$user_id, $order_number, $total, $address, $order_token]);
            $order_id = $this->conn->lastInsertId();
            
            $item = $this->conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
            $item->execute([$order_id, $product_id, $quantity, $product['price'], $total]);
            
            $update = $this->conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $update->execute([$quantity, $product_id]);
            
            $getOrder = $this->conn->prepare("SELECT * FROM orders WHERE id = ?");
            $getOrder->execute([$order_id]);
            $newOrder = $getOrder->fetch(PDO::FETCH_ASSOC);
            $newOrder['product_name'] = $product['name'];
            $newOrder['quantity'] = $quantity;
            
            $getUser = $this->conn->prepare("SELECT username, full_name FROM users WHERE id = ?");
            $getUser->execute([$user_id]);
            $user = $getUser->fetch(PDO::FETCH_ASSOC);
            
            // Send ACK to sender
            $from->send(json_encode([
                'type' => 'ORDER_CREATED_ACK',
                'status' => 'success',
                'message' => 'Order placed successfully',
                'order_number' => $order_number,
                'order_id' => $order_id,
                'total' => $total
            ]));
            
            // Broadcast to ALL clients
            $broadcast = json_encode([
                'type' => 'ORDER_BROADCAST',
                'action' => 'created',
                'order' => $newOrder,
                'customer' => $user['username'] ?? 'Customer'
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            echo "📦 Order placed: $order_number by User $user_id\n";
        } catch(Exception $e) {
            echo "❌ Order error: " . $e->getMessage() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to place order: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleUpdateOrderStatus($from, $data) {
        try {
            $order_id = $data['payload']['order_id'];
            $status = $data['payload']['status'];
            
            $getOrder = $this->conn->prepare("SELECT order_number FROM orders WHERE id = ?");
            $getOrder->execute([$order_id]);
            $order = $getOrder->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $this->conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $stmt->execute([$status, $order_id]);
            
            // Send ACK to sender
            $from->send(json_encode([
                'type' => 'ORDER_UPDATED_ACK',
                'status' => 'success',
                'message' => 'Order status updated successfully'
            ]));
            
            // Broadcast to ALL clients
            $broadcast = json_encode([
                'type' => 'ORDER_BROADCAST',
                'action' => 'status_updated',
                'order_id' => $order_id,
                'order_number' => $order['order_number'],
                'new_status' => $status,
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            echo "📦 Order status updated: {$order['order_number']} -> $status\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to update order: ' . $e->getMessage()
            ]));
        }
    }
    
    // ========== USERS CRUD ==========
    public function handleReadUsers($from, $data) {
        try {
            $stmt = $this->conn->prepare("SELECT id, username, email, full_name, role, created_at FROM users ORDER BY id DESC");
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $from->send(json_encode([
                'type' => 'USER_LIST',
                'payload' => $users,
                'count' => count($users),
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            echo "📤 Sent " . count($users) . " users to client\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to fetch users: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleCreateUser($from, $data) {
        try {
            $user = $data['payload'];
            $username = $user['username'];
            $email = $user['email'];
            $password = password_hash($user['password'], PASSWORD_DEFAULT);
            $full_name = $user['full_name'];
            $role = $user['role'] ?? 'customer';
            
            $check = $this->conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check->execute([$username, $email]);
            
            if($check->rowCount() > 0) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Username or email already exists'
                ]));
                return;
            }
            
            $stmt = $this->conn->prepare("INSERT INTO users (username, email, password, full_name, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$username, $email, $password, $full_name, $role]);
            $new_id = $this->conn->lastInsertId();
            
            // Send ACK to sender
            $from->send(json_encode([
                'type' => 'USER_CREATED_ACK',
                'status' => 'success',
                'message' => 'User created successfully',
                'user_id' => $new_id
            ]));
            
            // Broadcast to ALL clients
            $broadcast = json_encode([
                'type' => 'USER_BROADCAST',
                'action' => 'created',
                'username' => $username,
                'full_name' => $full_name,
                'role' => $role,
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            echo "👤 User created: $username (Role: $role)\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to create user: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleUpdateUserRole($from, $data) {
        try {
            $user_id = $data['payload']['user_id'];
            $role = $data['payload']['role'];
            
            $getUser = $this->conn->prepare("SELECT username FROM users WHERE id = ?");
            $getUser->execute([$user_id]);
            $user = $getUser->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $this->conn->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$role, $user_id]);
            
            // Send ACK to sender
            $from->send(json_encode([
                'type' => 'USER_UPDATED_ACK',
                'status' => 'success',
                'message' => 'User role updated successfully'
            ]));
            
            // Broadcast to ALL clients
            $broadcast = json_encode([
                'type' => 'USER_BROADCAST',
                'action' => 'role_updated',
                'user_id' => $user_id,
                'username' => $user['username'],
                'new_role' => $role,
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            echo "👤 User role updated: {$user['username']} -> $role\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to update user role: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleDeleteUser($from, $data) {
        try {
            $user_id = $data['payload']['user_id'];
            
            $getUser = $this->conn->prepare("SELECT username FROM users WHERE id = ?");
            $getUser->execute([$user_id]);
            $user = $getUser->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $this->conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            
            // Send ACK to sender
            $from->send(json_encode([
                'type' => 'USER_DELETED_ACK',
                'status' => 'success',
                'message' => 'User deleted successfully'
            ]));
            
            // Broadcast to ALL clients
            $broadcast = json_encode([
                'type' => 'USER_BROADCAST',
                'action' => 'deleted',
                'user_id' => $user_id,
                'username' => $user['username'],
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            echo "👤 User deleted: {$user['username']}\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to delete user: ' . $e->getMessage()
            ]));
        }
    }
    
    // ========== CATEGORIES CRUD ==========
    public function handleReadCategories($from, $data) {
        try {
            $stmt = $this->conn->prepare("SELECT * FROM categories ORDER BY name");
            $stmt->execute();
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $from->send(json_encode([
                'type' => 'CATEGORY_LIST',
                'payload' => $categories,
                'count' => count($categories),
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            echo "📤 Sent " . count($categories) . " categories to client\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to fetch categories: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleCreateCategory($from, $data) {
        try {
            $name = $data['payload']['name'];
            
            $stmt = $this->conn->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmt->execute([$name]);
            $new_id = $this->conn->lastInsertId();
            
            $newCategory = ['id' => $new_id, 'name' => $name];
            
            // Send ACK to sender
            $from->send(json_encode([
                'type' => 'CATEGORY_CREATED_ACK',
                'status' => 'success',
                'message' => 'Category created successfully',
                'category' => $newCategory
            ]));
            
            // Broadcast to ALL clients
            $broadcast = json_encode([
                'type' => 'CATEGORY_BROADCAST',
                'action' => 'created',
                'category' => $newCategory,
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            echo "📁 Category created: $name (ID: $new_id)\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to create category: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleUpdateCategory($from, $data) {
        try {
            $id = $data['payload']['id'];
            $name = $data['payload']['name'];
            
            $stmt = $this->conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
            
            // Send ACK to sender
            $from->send(json_encode([
                'type' => 'CATEGORY_UPDATED_ACK',
                'status' => 'success',
                'message' => 'Category updated successfully'
            ]));
            
            // Broadcast to ALL clients
            $broadcast = json_encode([
                'type' => 'CATEGORY_BROADCAST',
                'action' => 'updated',
                'category' => ['id' => $id, 'name' => $name],
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            echo "📁 Category updated: ID $id -> $name\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to update category: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleDeleteCategory($from, $data) {
        try {
            $id = $data['payload']['id'];
            $name = $data['payload']['name'];
            
            $stmt = $this->conn->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->execute([$id]);
            
            // Send ACK to sender
            $from->send(json_encode([
                'type' => 'CATEGORY_DELETED_ACK',
                'status' => 'success',
                'message' => 'Category deleted successfully'
            ]));
            
            // Broadcast to ALL clients
            $broadcast = json_encode([
                'type' => 'CATEGORY_BROADCAST',
                'action' => 'deleted',
                'category_id' => $id,
                'category_name' => $name,
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            echo "📁 Category deleted: $name (ID: $id)\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to delete category: ' . $e->getMessage()
            ]));
        }
    }
    
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        unset($this->users[$conn->resourceId]);
        echo "🔌 Connection disconnected\n";
        echo "👥 Total clients: " . count($this->clients) . "\n";
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "❌ Error: {$e->getMessage()}\n";
        $conn->close();
    }
}

// Get database connection from db.php
global $conn;

if(!isset($conn)) {
    echo "❌ Database connection not found! Make sure db.php is correct.\n";
    exit(1);
}

// Create WebSocket server
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new CakeShopWebSocket($conn)
        )
    ),
    8080
);

echo "🚀 Server is running! Press Ctrl+C to stop.\n";
echo "📡 WebSocket URL: ws://localhost:8080\n";
echo "====================================\n\n";
$server->run();
?>