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
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->users = [];
        
        // Get database connection
        global $conn;
        $this->conn = $conn;
        
        echo "====================================\n";
        echo "✅ Cake Shop WebSocket Server Started\n";
        echo "📡 Host: localhost\n";
        echo "🔌 Port: 8080\n";
        echo "⏰ Time: " . date('Y-m-d H:i:s') . "\n";
        echo "🔄 Full WebSocket CRUD: ENABLED\n";
        echo "📖 Read via WebSocket: ENABLED\n";
        echo "✏️ Create/Update/Delete via WebSocket: ENABLED\n";
        echo "====================================\n";
    }
    
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->resourceId = spl_object_hash($conn);
        echo "🔗 New connection! (ID: {$conn->resourceId})\n";
        echo "👥 Total clients: " . count($this->clients) . "\n";
        
        // Send welcome message
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
                
            // READ - Get Products
            case 'READ_PRODUCTS':
                $this->handleReadProducts($from, $data);
                break;
                
            // CREATE - Add Product
            case 'CREATE_PRODUCT':
                $this->handleCreateProduct($from, $data);
                break;
                
            // UPDATE - Edit Product
            case 'UPDATE_PRODUCT':
                $this->handleUpdateProduct($from, $data);
                break;
                
            // DELETE - Remove Product
            case 'DELETE_PRODUCT':
                $this->handleDeleteProduct($from, $data);
                break;
                
            // READ - Get Orders
            case 'READ_ORDERS':
                $this->handleReadOrders($from, $data);
                break;
                
            // CREATE - Place Order
            case 'CREATE_ORDER':
                $this->handleCreateOrder($from, $data);
                break;
                
            // Ping/Pong for keep-alive
            case 'ping':
                $from->send(json_encode([
                    'type' => 'pong',
                    'timestamp' => date('Y-m-d H:i:s')
                ]));
                break;
                
            // Broadcast to all clients
            case 'broadcast':
                foreach ($this->clients as $client) {
                    $client->send($data['message']);
                }
                break;
                
            default:
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Unknown message type: ' . $data['type'],
                    'timestamp' => date('Y-m-d H:i:s')
                ]));
        }
    }
    
    // Handle Authentication
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
                'message' => 'Missing user_id',
                'timestamp' => date('Y-m-d H:i:s')
            ]));
        }
    }
    
    // READ - Get all products
    public function handleReadProducts($from, $data) {
        try {
            // Get filter if provided
            $category = $data['payload']['category'] ?? null;
            $status = $data['payload']['status'] ?? null;
            
            $sql = "SELECT p.*, c.name as category_name 
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id";
            
            $conditions = [];
            $params = [];
            
            if($category) {
                $conditions[] = "c.name = ?";
                $params[] = $category;
            }
            
            if($status) {
                $conditions[] = "p.status = ?";
                $params[] = $status;
            }
            
            if(count($conditions) > 0) {
                $sql .= " WHERE " . implode(" AND ", $conditions);
            }
            
            $sql .= " ORDER BY p.id DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll();
            
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
                'message' => 'Failed to fetch products: ' . $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ]));
        }
    }
    
    // CREATE - Add new product
    public function handleCreateProduct($from, $data) {
        try {
            $product = $data['payload'];
            $name = $product['name'];
            $category_id = $product['category_id'];
            $price = $product['price'];
            $stock = $product['stock'];
            $description = $product['description'] ?? '';
            $image = $product['image'] ?? '';
            
            // Insert into database
            $stmt = $this->conn->prepare("INSERT INTO products (name, category_id, price, stock, description, image, status) VALUES (?, ?, ?, ?, ?, ?, 'available')");
            $stmt->execute([$name, $category_id, $price, $stock, $description, $image]);
            $new_id = $this->conn->lastInsertId();
            
            // Get full product details
            $getProduct = $this->conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
            $getProduct->execute([$new_id]);
            $newProduct = $getProduct->fetch();
            
            // Add action by info
            $newProduct['action_by'] = $this->users[$from->resourceId]['username'] ?? 'Admin';
            $newProduct['action_type'] = 'created';
            
            // Broadcast to all clients
            $broadcast = json_encode([
                'type' => 'PRODUCT_CREATED',
                'payload' => $newProduct,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            // Confirm to sender
            $from->send(json_encode([
                'type' => 'CREATE_SUCCESS',
                'payload' => $newProduct,
                'message' => 'Product created successfully',
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            echo "✅ Product created: $name (ID: $new_id)\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to create product: ' . $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ]));
        }
    }
    
    // UPDATE - Edit product
    public function handleUpdateProduct($from, $data) {
        try {
            $product = $data['payload'];
            $id = $product['id'];
            $name = $product['name'];
            $category_id = $product['category_id'];
            $price = $product['price'];
            $stock = $product['stock'];
            $description = $product['description'] ?? '';
            $status = $product['status'] ?? 'available';
            $image = $product['image'] ?? '';
            
            // Update database
            $stmt = $this->conn->prepare("UPDATE products SET name = ?, category_id = ?, price = ?, stock = ?, description = ?, image = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $category_id, $price, $stock, $description, $image, $status, $id]);
            
            // Get updated product
            $getProduct = $this->conn->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
            $getProduct->execute([$id]);
            $updatedProduct = $getProduct->fetch();
            
            // Add action by info
            $updatedProduct['action_by'] = $this->users[$from->resourceId]['username'] ?? 'Admin';
            $updatedProduct['action_type'] = 'updated';
            
            // Broadcast to all clients
            $broadcast = json_encode([
                'type' => 'PRODUCT_UPDATED',
                'payload' => $updatedProduct,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            // Confirm to sender
            $from->send(json_encode([
                'type' => 'UPDATE_SUCCESS',
                'payload' => $updatedProduct,
                'message' => 'Product updated successfully',
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            echo "✅ Product updated: $name (ID: $id)\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to update product: ' . $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ]));
        }
    }
    
    // DELETE - Remove product
    public function handleDeleteProduct($from, $data) {
        try {
            $id = $data['payload']['id'];
            $name = $data['payload']['name'] ?? 'Unknown';
            
            // Get product info before deleting
            $getProduct = $this->conn->prepare("SELECT name, image FROM products WHERE id = ?");
            $getProduct->execute([$id]);
            $product = $getProduct->fetch();
            
            // Delete image file if exists
            if($product && $product['image'] && !filter_var($product['image'], FILTER_VALIDATE_URL)) {
                $image_path = '../' . $product['image'];
                if(file_exists($image_path)) {
                    unlink($image_path);
                }
            }
            
            // Delete from database
            $stmt = $this->conn->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$id]);
            
            $deletedData = [
                'id' => $id,
                'name' => $product['name'] ?? $name,
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ];
            
            // Broadcast to all clients
            $broadcast = json_encode([
                'type' => 'PRODUCT_DELETED',
                'payload' => $deletedData,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            // Confirm to sender
            $from->send(json_encode([
                'type' => 'DELETE_SUCCESS',
                'payload' => $deletedData,
                'message' => 'Product deleted successfully',
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            echo "🗑️ Product deleted: {$product['name']} (ID: $id)\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to delete product: ' . $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ]));
        }
    }
    
    // READ - Get orders for a user
    public function handleReadOrders($from, $data) {
        try {
            $user_id = $data['payload']['user_id'] ?? null;
            
            $sql = "SELECT o.*, 
                    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
                    FROM orders o";
            
            if($user_id) {
                $sql .= " WHERE o.user_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$user_id]);
            } else {
                $stmt = $this->conn->prepare($sql);
                $stmt->execute();
            }
            
            $orders = $stmt->fetchAll();
            
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
                'message' => 'Failed to fetch orders: ' . $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ]));
        }
    }
    
    // CREATE - Place new order
    public function handleCreateOrder($from, $data) {
        try {
            $order = $data['payload'];
            $user_id = $order['user_id'];
            $product_id = $order['product_id'];
            $quantity = $order['quantity'];
            $address = $order['address'];
            $order_token = $order['order_token'];
            
            // Check if order already processed (idempotency)
            $check = $this->conn->prepare("SELECT id FROM orders WHERE order_token = ?");
            $check->execute([$order_token]);
            
            if($check->rowCount() > 0) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Order already processed',
                    'timestamp' => date('Y-m-d H:i:s')
                ]));
                return;
            }
            
            // Get product price and stock
            $prod = $this->conn->prepare("SELECT price, name, stock FROM products WHERE id = ?");
            $prod->execute([$product_id]);
            $product = $prod->fetch();
            
            if($product['stock'] < $quantity) {
                $from->send(json_encode([
                    'type' => 'error',
                    'message' => 'Not enough stock',
                    'timestamp' => date('Y-m-d H:i:s')
                ]));
                return;
            }
            
            $total = $product['price'] * $quantity;
            $order_number = 'ORD-' . date('Ymd') . '-' . rand(1000, 9999);
            
            // Create order
            $stmt = $this->conn->prepare("INSERT INTO orders (user_id, order_number, total_amount, shipping_address, status, order_token) VALUES (?, ?, ?, ?, 'pending', ?)");
            $stmt->execute([$user_id, $order_number, $total, $address, $order_token]);
            $order_id = $this->conn->lastInsertId();
            
            // Add order item
            $item = $this->conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)");
            $item->execute([$order_id, $product_id, $quantity, $product['price'], $total]);
            
            // Update stock
            $update = $this->conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $update->execute([$quantity, $product_id]);
            
            // Get full order details
            $getOrder = $this->conn->prepare("SELECT * FROM orders WHERE id = ?");
            $getOrder->execute([$order_id]);
            $newOrder = $getOrder->fetch();
            $newOrder['product_name'] = $product['name'];
            $newOrder['quantity'] = $quantity;
            
            // Get user info
            $getUser = $this->conn->prepare("SELECT username, full_name FROM users WHERE id = ?");
            $getUser->execute([$user_id]);
            $user = $getUser->fetch();
            $newOrder['username'] = $user['username'];
            $newOrder['full_name'] = $user['full_name'];
            
            // Broadcast to all admin clients
            $broadcast = json_encode([
                'type' => 'ORDER_CREATED',
                'payload' => $newOrder,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            // Confirm to sender
            $from->send(json_encode([
                'type' => 'ORDER_SUCCESS',
                'payload' => $newOrder,
                'message' => 'Order placed successfully',
                'order_number' => $order_number,
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            echo "📦 Order placed: $order_number by User $user_id\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to place order: ' . $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ]));
        }
    }
    
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        unset($this->users[$conn->resourceId]);
        echo "🔌 Connection {$conn->resourceId} disconnected\n";
        echo "👥 Total clients: " . count($this->clients) . "\n";
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "❌ Error: {$e->getMessage()}\n";
        $conn->close();
    }
}

// Create WebSocket server
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new CakeShopWebSocket()
        )
    ),
    8080
);

echo "🚀 Server is running! Press Ctrl+C to stop.\n";
echo "📡 WebSocket URL: ws://localhost:8080\n";
echo "====================================\n\n";

$server->run();
?>