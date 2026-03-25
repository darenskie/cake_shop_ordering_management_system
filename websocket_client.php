<?php
// websocket_client.php - Helper functions for WebSocket broadcasting

function broadcastWebSocket($event, $data) {
    // Send broadcast to WebSocket server
    $message = json_encode([
        'type' => 'crud',
        'action' => $event,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    // Try to send via HTTP to WebSocket server
    try {
        $ch = curl_init('http://localhost:8080/broadcast');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['message' => $message]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_exec($ch);
        curl_close($ch);
    } catch(Exception $e) {
        // Silent fail
    }
}
?>