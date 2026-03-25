<?php
require_once __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class CakeShopWebSocket implements MessageComponentInterface {
    protected $clients;
    
    public function __construct() {
        $this->clients = new \SplObjectStorage;
        echo "====================================\n";
        echo "✅ Cake Shop WebSocket Server Started\n";
        echo "📡 Host: localhost\n";
        echo "🔌 Port: 8080\n";
        echo "⏰ Time: " . date('Y-m-d H:i:s') . "\n";
        echo "====================================\n";
    }
    
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "🔗 New connection! ({$conn->resourceId})\n";
        echo "👥 Total clients: " . count($this->clients) . "\n";
        
        // Send welcome message
        $conn->send(json_encode([
            'type' => 'welcome',
            'message' => 'Connected to Cake Shop',
            'time' => date('Y-m-d H:i:s')
        ]));
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
        echo "📨 Message received: $msg\n";
        
        // Broadcast to all clients except sender
        foreach ($this->clients as $client) {
            if ($from !== $client) {
                $client->send($msg);
            }
        }
    }
    
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
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
$server->run();