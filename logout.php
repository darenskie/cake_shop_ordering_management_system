<?php
session_start();
require_once 'db.php';

// Only try to log audit if user is logged in
if(isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    try {
        // Check if user still exists in database
        $check_user = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $check_user->execute([$_SESSION['user_id']]);
        
        if($check_user->rowCount() > 0) {
            // User exists, log the audit
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details) VALUES (?, 'LOGOUT', ?)");
            $log->execute([$_SESSION['user_id'], "User logged out: " . ($_SESSION['username'] ?? 'Unknown')]);
        } else {
            // User doesn't exist, log without user_id
            $log = $conn->prepare("INSERT INTO audit_logs (action, details) VALUES ('LOGOUT', ?)");
            $log->execute(["User logged out (user no longer exists)"]);
        }
    } catch(PDOException $e) {
        // If audit log fails, just continue with logout
        error_log("Logout audit failed: " . $e->getMessage());
    }
}

// Clear all session data
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();