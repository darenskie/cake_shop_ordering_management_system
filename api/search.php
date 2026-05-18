<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../db.php';

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

if($method == 'GET') {
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';
    $type = isset($_GET['type']) ? $_GET['type'] : 'all';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;
    
    $response = ['status' => 'success', 'data' => [], 'total' => 0];
    
    if($search) {
        if($type == 'products' || $type == 'all') {
            $stmt = $conn->prepare("SELECT p.*, c.name as category_name, 'product' as type 
                                    FROM products p 
                                    LEFT JOIN categories c ON p.category_id = c.id 
                                    WHERE p.name LIKE ? OR p.description LIKE ? 
                                    LIMIT ? OFFSET ?");
            $stmt->execute(["%$search%", "%$search%", $limit, $offset]);
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $count = $conn->prepare("SELECT COUNT(*) FROM products WHERE name LIKE ? OR description LIKE ?");
            $count->execute(["%$search%", "%$search%"]);
            $total = $count->fetchColumn();
            
            $response['data'] = array_merge($response['data'], $products);
            $response['total'] = $total;
        }
        
        if($type == 'orders' || $type == 'all') {
            $stmt = $conn->prepare("SELECT o.*, u.username, 'order' as type 
                                    FROM orders o 
                                    JOIN users u ON o.user_id = u.id 
                                    WHERE o.order_number LIKE ? 
                                    LIMIT ? OFFSET ?");
            $stmt->execute(["%$search%", $limit, $offset]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response['data'] = array_merge($response['data'], $orders);
        }
        
        if($type == 'users' || $type == 'all') {
            $stmt = $conn->prepare("SELECT id, username, email, full_name, role, 'user' as type 
                                    FROM users 
                                    WHERE username LIKE ? OR email LIKE ? OR full_name LIKE ? 
                                    LIMIT ? OFFSET ?");
            $stmt->execute(["%$search%", "%$search%", "%$search%", $limit, $offset]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $response['data'] = array_merge($response['data'], $users);
        }
    }
    
    echo json_encode($response);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}
?>