<?php
require_once __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class CakeShopWebSocket implements MessageComponentInterface {
    protected $clients;
    protected $users;
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->users = [];
        echo "====================================\n";
        echo "✅ Cake Shop WebSocket Server Started\n";
        echo "📡 Host: localhost\n";
        echo "🔌 Port: 8080\n";
        echo "⏰ Time: " . date('Y-m-d H:i:s') . "\n";
        echo "🔄 CRUD Broadcasting: ENABLED\n";
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
            'message' => 'Connected to Cake Shop',
            'time' => date('Y-m-d H:i:s')
        ]));
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        echo "📨 Message received: " . substr($msg, 0, 100) . "...\n";
        
        if(isset($data['type'])) {
            switch($data['type']) {
                case 'auth':
                    $this->users[$from->resourceId] = $data['user_id'];
                    $from->send(json_encode([
                        'type' => 'auth_success',
                        'message' => 'Authenticated',
                        'timestamp' => date('Y-m-d H:i:s')
                    ]));
                    break;
                    
                case 'ping':
                    $from->send(json_encode([
                        'type' => 'pong',
                        'timestamp' => date('Y-m-d H:i:s')
                    ]));
                    break;
                    
                case 'broadcast':
                    // Broadcast to all clients
                    foreach ($this->clients as $client) {
                        $client->send($data['message']);
                    }
                    break;
            }
        }
    }
    
    public function broadcastToAll($message) {
        foreach ($this->clients as $client) {
            $client->send($message);
        }
        echo "📢 Broadcast sent to " . count($this->clients) . " clients\n";
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

// Create HTTP server to receive broadcasts
$http = new HttpServer(new WsServer(new CakeShopWebSocket()));
$server = IoServer::factory($http, 8080);

echo "🚀 Server is running! Press Ctrl+C to stop.\n";
echo "📡 WebSocket URL: ws://localhost:8080\n";
echo "====================================\n\n";

$server->run();
?>