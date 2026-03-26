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
    
    // Pass database connection in constructor
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
        echo "📖 Read via WebSocket: ENABLED\n";
        echo "✏️ Create/Update/Delete via WebSocket: ENABLED\n";
        echo "====================================\n";
        
        // Test database connection
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
            case 'auth':
                $this->handleAuth($from, $data);
                break;
                
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
                
            case 'READ_ORDERS':
                $this->handleReadOrders($from, $data);
                break;
                
            case 'CREATE_ORDER':
                $this->handleCreateOrder($from, $data);
                break;
                
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
    
    public function handleReadProducts($from, $data) {
        try {
            if(!$this->conn) {
                throw new Exception("Database connection not available");
            }
            
            // Get all available products with stock > 0
            $sql = "SELECT p.*, c.name as category_name 
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE p.status = 'available' 
                    ORDER BY p.id DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert to array with proper formatting
            $productList = [];
            foreach($products as $product) {
                $productList[] = [
                    'id' => (int)$product['id'],
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'price' => (float)$product['price'],
                    'stock' => (int)$product['stock'],
                    'image' => $product['image'],
                    'category_id' => $product['category_id'] ? (int)$product['category_id'] : null,
                    'category_name' => $product['category_name'],
                    'status' => $product['status']
                ];
            }
            
            $response = json_encode([
                'type' => 'PRODUCT_LIST',
                'payload' => $productList,
                'count' => count($productList),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $from->send($response);
            echo "📤 Sent " . count($productList) . " products to client\n";
            
        } catch(Exception $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
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
            $category_id = $product['category_id'];
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
            
            $newProduct['action_by'] = $this->users[$from->resourceId]['username'] ?? 'Admin';
            $newProduct['action_type'] = 'created';
            
            $broadcast = json_encode([
                'type' => 'PRODUCT_CREATED',
                'payload' => $newProduct,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            $from->send(json_encode([
                'type' => 'CREATE_SUCCESS',
                'message' => 'Product created successfully',
                'product' => $newProduct,
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
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
            $category_id = $product['category_id'];
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
            
            $updatedProduct['action_by'] = $this->users[$from->resourceId]['username'] ?? 'Admin';
            $updatedProduct['action_type'] = 'updated';
            
            $broadcast = json_encode([
                'type' => 'PRODUCT_UPDATED',
                'payload' => $updatedProduct,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            $from->send(json_encode([
                'type' => 'UPDATE_SUCCESS',
                'message' => 'Product updated successfully',
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
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
            
            $deletedData = [
                'id' => $id,
                'name' => $product['name'] ?? $name,
                'action_by' => $this->users[$from->resourceId]['username'] ?? 'Admin'
            ];
            
            $broadcast = json_encode([
                'type' => 'PRODUCT_DELETED',
                'payload' => $deletedData,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            $from->send(json_encode([
                'type' => 'DELETE_SUCCESS',
                'message' => 'Product deleted successfully',
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            echo "🗑️ Product deleted: {$product['name']} (ID: $id)\n";
        } catch(Exception $e) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to delete product: ' . $e->getMessage()
            ]));
        }
    }
    
    public function handleReadOrders($from, $data) {
        try {
            $user_id = $data['payload']['user_id'] ?? null;
            
            if($user_id) {
                $stmt = $this->conn->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 5");
                $stmt->execute([$user_id]);
            } else {
                $stmt = $this->conn->prepare("SELECT * FROM orders ORDER BY id DESC LIMIT 5");
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
            $order_token = $order['order_token'];
            
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
            $newOrder['username'] = $user['username'];
            $newOrder['full_name'] = $user['full_name'];
            
            $broadcast = json_encode([
                'type' => 'ORDER_CREATED',
                'payload' => $newOrder,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            foreach ($this->clients as $client) {
                $client->send($broadcast);
            }
            
            $from->send(json_encode([
                'type' => 'ORDER_SUCCESS',
                'message' => 'Order placed successfully',
                'order_number' => $order_number,
                'order_id' => $order_id,
                'total' => $total,
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            
            echo "📦 Order placed: $order_number by User $user_id\n";
        } catch(Exception $e) {
            echo "❌ Order error: " . $e->getMessage() . "\n";
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Failed to place order: ' . $e->getMessage()
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

// Check if database connection exists
if(!isset($conn)) {
    echo "❌ Database connection not found! Make sure db.php is correct.\n";
    exit(1);
}

// Create WebSocket server with database connection
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