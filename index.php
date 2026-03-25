<?php
session_start();
if(isset($_SESSION['user_id'])) {
    if($_SESSION['role'] == 'admin') {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: customer/dashboard.php");
    }
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Cake Shop Ordering System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            text-align: center;
            color: white;
            padding: 20px;
            max-width: 1200px;
        }
        h1 { font-size: 3em; margin-bottom: 20px; }
        p { font-size: 1.2em; margin-bottom: 40px; opacity: 0.9; }
        .buttons { margin-bottom: 60px; }
        .btn {
            display: inline-block;
            padding: 15px 30px;
            margin: 0 10px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: bold;
            transition: transform 0.3s;
        }
        .btn-primary {
            background: #ff6b6b;
            color: white;
        }
        .btn-secondary {
            background: white;
            color: #764ba2;
        }
        .btn:hover { transform: translateY(-3px); }
        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
        }
        .feature {
            background: white;
            color: #333;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .feature h3 { margin-bottom: 15px; color: #ff6b6b; }
        @media (max-width: 768px) {
            .features { grid-template-columns: 1fr; }
            h1 { font-size: 2em; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🍰 Cake Shop Ordering System</h1>
        <p>Real-time ordering with WebSocket technology</p>
        
        <div class="buttons">
            <a href="login.php" class="btn btn-primary">Login</a>
            <a href="register.php" class="btn btn-secondary">Register</a>
        </div>
        
        <div class="features">
            <div class="feature">
                <h3>⚡ Real-time Updates</h3>
                <p>Instant order notifications and live insights</p>
            </div>
            <div class="feature">
                <h3>🍰 Easy Ordering</h3>
                <p>Browse cakes and place orders seamlessly</p>
            </div>
            <div class="feature">
                <h3>📊 Analytics Dashboard</h3>
                <p>Track sales and popular items in real-time</p>
            </div>
        </div>
    </div>
</body>
</html>