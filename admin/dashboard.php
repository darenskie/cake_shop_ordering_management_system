<?php
session_start();
require_once '../db.php';

if(!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}

// Get statistics
$total_products = $conn->query("SELECT COUNT(*) FROM products")->fetchColumn();
$total_orders = $conn->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$total_customers = $conn->query("SELECT COUNT(*) FROM users WHERE role='customer'")->fetchColumn();
$total_revenue = $conn->query("SELECT SUM(total_amount) FROM orders")->fetchColumn() ?: 0;

// Get pending orders count
$pending_orders = $conn->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();

// Get today's orders
$today_orders = $conn->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();

// Get recent orders with details
$recent_orders = $conn->query("
    SELECT o.*, u.username, u.full_name,
    (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
    FROM orders o 
    JOIN users u ON o.user_id = u.id 
    ORDER BY o.id DESC 
    LIMIT 5
")->fetchAll();

// Get recent audit logs
$recent_logs = $conn->query("
    SELECT a.*, u.username 
    FROM audit_logs a 
    LEFT JOIN users u ON a.user_id = u.id 
    ORDER BY a.id DESC 
    LIMIT 10
")->fetchAll();

// Get sales data for chart (last 7 days)
$sales_data = $conn->query("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as order_count,
        SUM(total_amount) as revenue,
        COUNT(DISTINCT user_id) as unique_customers
    FROM orders 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date
")->fetchAll();

$dates = [];
$revenues = [];
$order_counts = [];

foreach($sales_data as $data) {
    $dates[] = date('M d', strtotime($data['date']));
    $revenues[] = $data['revenue'] ?? 0;
    $order_counts[] = $data['order_count'] ?? 0;
}

// Get order status counts for pie chart
$status_counts = $conn->query("
    SELECT status, COUNT(*) as count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM orders), 1) as percentage
    FROM orders 
    GROUP BY status
")->fetchAll();

$statuses = [];
$status_counts_data = [];
$status_percentages = [];

foreach($status_counts as $status) {
    $statuses[] = ucfirst($status['status']);
    $status_counts_data[] = $status['count'];
    $status_percentages[] = $status['percentage'] ?? 0;
}

// Get top selling products
$top_products = $conn->query("
    SELECT p.name, SUM(oi.quantity) as total_sold,
    SUM(oi.subtotal) as total_revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    GROUP BY p.id, p.name
    ORDER BY total_sold DESC
    LIMIT 5
")->fetchAll();

// Get recent products added
$recent_products = $conn->query("
    SELECT name, price, created_at 
    FROM products 
    ORDER BY id DESC 
    LIMIT 5
")->fetchAll();

// Get low stock products
$low_stock = $conn->query("
    SELECT name, stock 
    FROM products 
    WHERE stock < 10 AND status = 'available'
    ORDER BY stock ASC
    LIMIT 5
")->fetchAll();

// Get monthly comparison
$this_month = $conn->query("
    SELECT COUNT(*) as orders, SUM(total_amount) as revenue
    FROM orders 
    WHERE MONTH(created_at) = MONTH(CURDATE()) 
    AND YEAR(created_at) = YEAR(CURDATE())
")->fetch();

$last_month = $conn->query("
    SELECT COUNT(*) as orders, SUM(total_amount) as revenue
    FROM orders 
    WHERE MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
    AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
")->fetch();

// Calculate growth percentages
$order_growth = 0;
$revenue_growth = 0;

if($last_month['orders'] > 0) {
    $order_growth = round((($this_month['orders'] - $last_month['orders']) / $last_month['orders']) * 100, 1);
}

if($last_month['revenue'] > 0) {
    $revenue_growth = round((($this_month['revenue'] - $last_month['revenue']) / $last_month['revenue']) * 100, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Cake Shop</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #2c3e50 0%, #1e2b38 100%);
            color: white;
            padding: 25px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            text-align: center;
            padding-bottom: 25px;
            border-bottom: 1px solid #34495e;
            margin-bottom: 25px;
        }

        .sidebar-header h2 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .sidebar-header h2 span {
            color: #ff6b6b;
        }

        .sidebar-header p {
            color: #a4b0be;
            font-size: 14px;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .sidebar-nav a {
            color: #a4b0be;
            text-decoration: none;
            padding: 12px 15px;
            border-radius: 8px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: #34495e;
            color: white;
            transform: translateX(5px);
        }

        .sidebar-nav a i {
            font-size: 20px;
            width: 25px;
        }

        /* Main Content */
        .content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .header h1 {
            color: #2c3e50;
            font-size: 32px;
        }

        .header h1 span {
            color: #ff6b6b;
            font-size: 16px;
            background: #fff0f0;
            padding: 5px 10px;
            border-radius: 20px;
            margin-left: 15px;
        }

        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .notification-badge {
            background: #ff6b6b;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
        }

        .logout-btn {
            background: #ff6b6b;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            transition: background 0.3s;
        }

        .logout-btn:hover {
            background: #ff5252;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: transform 0.3s;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: #ff6b6b;
        }

        .stat-icon {
            font-size: 40px;
            margin-bottom: 15px;
        }

        .stat-card h3 {
            color: #7f8c8d;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .stat-trend {
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .trend-up {
            color: #4ecdc4;
        }

        .trend-down {
            color: #ff6b6b;
        }

        /* Quick Actions */
        .quick-actions {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .quick-actions h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 12px 25px;
            border-radius: 8px;
            text-decoration: none;
            color: white;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-add { background: #ff6b6b; }
        .btn-pending { background: orange; }
        .btn-users { background: #4ecdc4; }
        .btn-logs { background: #34495e; }
        .btn-reports { background: #9b59b6; }

        /* Charts Container */
        .charts-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-header h3 {
            color: #2c3e50;
        }

        .chart-period {
            color: #7f8c8d;
            font-size: 14px;
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 20px;
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
        }

        /* Tables Grid */
        .tables-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        .table-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-header h3 {
            color: #2c3e50;
        }

        .view-all {
            color: #ff6b6b;
            text-decoration: none;
            font-size: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px;
            color: #7f8c8d;
            font-weight: 500;
            font-size: 13px;
            text-transform: uppercase;
            border-bottom: 2px solid #e9ecef;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            color: #2c3e50;
        }

        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge.pending {
            background: #fff3e0;
            color: orange;
        }

        .badge.processing {
            background: #e3f2fd;
            color: #2196f3;
        }

        .badge.completed {
            background: #e8f5e9;
            color: #4caf50;
        }

        .badge.cancelled {
            background: #ffebee;
            color: #f44336;
        }

        .status-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .status-connected {
            background: #4ecdc4;
            box-shadow: 0 0 10px #4ecdc4;
        }

        .low-stock {
            color: #ff6b6b;
            font-weight: bold;
        }

        /* System Status */
        .system-status {
            background: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #ff6b6b;
            color: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            z-index: 1000;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .charts-container {
                grid-template-columns: 1fr;
            }
            
            .tables-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }
            
            .content {
                margin-left: 0;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>🍰 <span>Cake Shop</span></h2>
                <p>Admin Panel</p>
            </div>
            
            <div class="sidebar-nav">
                <a href="dashboard.php" class="active">
                    <i>📊</i> Dashboard
                </a>
                <a href="products.php">
                    <i>🍰</i> Products
                </a>
                <a href="orders.php">
                    <i>📦</i> Orders
                    <?php if($pending_orders > 0): ?>
                        <span class="notification-badge"><?php echo $pending_orders; ?></span>
                    <?php endif; ?>
                </a>
                <a href="users.php">
                    <i>👥</i> Users
                </a>
                <a href="audit.php">
                    <i>📋</i> Audit Logs
                </a>
                <a href="reports.php">
                    <i>📈</i> Reports
                </a>
                <a href="settings.php">
                    <i>⚙️</i> Settings
                </a>
            </div>
            
            <div style="margin-top: auto; padding-top: 30px;">
                <div style="background: #34495e; padding: 15px; border-radius: 8px;">
                    <p style="color: #a4b0be; font-size: 12px; margin-bottom: 5px;">Logged in as</p>
                    <p style="color: white; font-weight: bold;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></p>
                    <p style="color: #ff6b6b; font-size: 12px;">Administrator</p>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <!-- Header -->
            <div class="header">
                <h1>
                    Dashboard 
                    <span><?php echo date('F d, Y'); ?></span>
                </h1>
                <div class="header-actions">
                    <div class="status-item">
                        <span class="status-indicator status-connected"></span>
                        <span id="ws-status">Connected</span>
                    </div>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <h3>Total Revenue</h3>
                    <div class="stat-value">₱<?php echo number_format($total_revenue, 2); ?></div>
                    <div class="stat-trend <?php echo $revenue_growth >= 0 ? 'trend-up' : 'trend-down'; ?>">
                        <?php echo $revenue_growth >= 0 ? '↑' : '↓'; ?> 
                        <?php echo abs($revenue_growth); ?>% vs last month
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">📦</div>
                    <h3>Total Orders</h3>
                    <div class="stat-value"><?php echo $total_orders; ?></div>
                    <div class="stat-trend">
                        <?php echo $today_orders; ?> today
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">👥</div>
                    <h3>Customers</h3>
                    <div class="stat-value"><?php echo $total_customers; ?></div>
                    <div class="stat-trend trend-up">
                        ↑ Active users
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">🍰</div>
                    <h3>Products</h3>
                    <div class="stat-value"><?php echo $total_products; ?></div>
                    <div class="stat-trend <?php echo !empty($low_stock) ? 'trend-down' : 'trend-up'; ?>">
                        <?php echo count($low_stock); ?> low in stock
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <h3>⚡ Quick Actions</h3>
                <div class="action-buttons">
                    <a href="products.php?action=add" class="action-btn btn-add">
                        ➕ Add New Product
                    </a>
                    <a href="orders.php?status=pending" class="action-btn btn-pending">
                        📦 View Pending Orders (<?php echo $pending_orders; ?>)
                    </a>
                    <a href="users.php" class="action-btn btn-users">
                        👥 Manage Users
                    </a>
                    <a href="audit.php" class="action-btn btn-logs">
                        📋 View Audit Logs
                    </a>
                    <a href="reports.php" class="action-btn btn-reports">
                        📊 Generate Report
                    </a>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-container">
                <!-- Sales Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>📈 Sales Overview (Last 7 Days)</h3>
                        <span class="chart-period">Revenue vs Orders</span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="salesChart"></canvas>
                    </div>
                </div>

                <!-- Order Status Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <h3>🥧 Order Status Distribution</h3>
                        <span class="chart-period">Current orders</span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="ordersChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Tables Grid -->
            <div class="tables-grid">
                <!-- Recent Orders -->
                <div class="table-card">
                    <div class="table-header">
                        <h3>📦 Recent Orders</h3>
                        <a href="orders.php" class="view-all">View All →</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recent_orders as $order): ?>
                            <tr>
                                <td><?php echo $order['order_number']; ?></td>
                                <td><?php echo htmlspecialchars($order['full_name']); ?></td>
                                <td><?php echo $order['item_count']; ?></td>
                                <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                <td>
                                    <span class="badge <?php echo $order['status']; ?>">
                                        <?php echo ucfirst($order['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($recent_orders)): ?>
                            <tr><td colspan="5" style="text-align:center;">No orders yet</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Top Products -->
                <div class="table-card">
                    <div class="table-header">
                        <h3>🔥 Top Selling Products</h3>
                        <a href="products.php" class="view-all">View All →</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Sold</th>
                                <th>Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($top_products as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><?php echo $product['total_sold']; ?> pcs</td>
                                <td>₱<?php echo number_format($product['total_revenue'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($top_products)): ?>
                            <tr><td colspan="3" style="text-align:center;">No sales data</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Low Stock Alert -->
                <div class="table-card">
                    <div class="table-header">
                        <h3>⚠️ Low Stock Alert</h3>
                        <a href="products.php" class="view-all">Manage →</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Current Stock</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($low_stock as $product): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td class="low-stock"><?php echo $product['stock']; ?> left</td>
                                <td><span class="badge pending">Reorder Soon</span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($low_stock)): ?>
                            <tr><td colspan="3" style="text-align:center; color:#4ecdc4;">All stock levels are healthy</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Recent Activity -->
                <div class="table-card">
                    <div class="table-header">
                        <h3>🕐 Recent Activity</h3>
                        <a href="audit.php" class="view-all">View All →</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>User</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($recent_logs as $log): ?>
                            <tr>
                                <td><?php echo date('H:i', strtotime($log['created_at'])); ?></td>
                                <td><?php echo $log['username'] ?: 'System'; ?></td>
                                <td><?php echo htmlspecialchars($log['details'] ?: $log['action']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- System Status -->
            <div class="system-status">
                <div class="status-item">
                    <span class="status-indicator status-connected"></span>
                    <strong>WebSocket:</strong> <span id="ws-status-text">Connected</span>
                </div>
                <div class="status-item">
                    <span class="status-indicator status-connected"></span>
                    <strong>Database:</strong> Connected
                </div>
                <div class="status-item">
                    <strong>Server Time:</strong> <?php echo date('Y-m-d H:i:s'); ?>
                </div>
                <div class="status-item">
                    <strong>PHP Version:</strong> <?php echo phpversion(); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Area -->
    <div id="notification"></div>

    <!-- Charts JavaScript -->
    <script>
        // Sales Chart
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dates); ?>,
                datasets: [
                    {
                        label: 'Revenue (₱)',
                        data: <?php echo json_encode($revenues); ?>,
                        borderColor: '#ff6b6b',
                        backgroundColor: 'rgba(255, 107, 107, 0.1)',
                        yAxisID: 'y-revenue',
                        tension: 0.4
                    },
                    {
                        label: 'Orders',
                        data: <?php echo json_encode($order_counts); ?>,
                        borderColor: '#4ecdc4',
                        backgroundColor: 'rgba(78, 205, 196, 0.1)',
                        yAxisID: 'y-orders',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    'y-revenue': {
                        type: 'linear',
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Revenue (₱)'
                        }
                    },
                    'y-orders': {
                        type: 'linear',
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Number of Orders'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });

        // Orders Status Chart
        const ordersCtx = document.getElementById('ordersChart').getContext('2d');
        new Chart(ordersCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($statuses); ?>,
                datasets: [{
                    data: <?php echo json_encode($status_counts_data); ?>,
                    backgroundColor: [
                        'orange',
                        '#2196f3',
                        '#4caf50',
                        '#f44336'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const percentage = <?php echo json_encode($status_percentages); ?>[context.dataIndex];
                                return `${label}: ${value} orders (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });

        // WebSocket Connection
        const ws = new WebSocket('ws://localhost:8080');
        const wsStatus = document.getElementById('ws-status');
        const wsStatusText = document.getElementById('ws-status-text');
        const notification = document.getElementById('notification');

        ws.onopen = function() {
            wsStatus.innerHTML = 'Connected';
            wsStatus.style.color = '#4ecdc4';
            wsStatusText.innerHTML = 'Connected';
            showNotification('✅ Connected to real-time server');
        };

        ws.onclose = function() {
            wsStatus.innerHTML = 'Disconnected';
            wsStatus.style.color = '#ff6b6b';
            wsStatusText.innerHTML = 'Disconnected';
        };

        ws.onmessage = function(event) {
            const data = JSON.parse(event.data);
            showNotification('🔔 ' + (data.message || 'New update received'));
            
            // Optional: Refresh specific parts of the page
            if(data.type === 'new_order') {
                // Update pending orders count
                location.reload(); // Simple refresh
            }
        };

        function showNotification(message) {
            const notificationDiv = document.createElement('div');
            notificationDiv.className = 'notification';
            notificationDiv.textContent = message;
            notification.appendChild(notificationDiv);
            
            setTimeout(() => {
                notificationDiv.remove();
            }, 3000);
        }
    </script>
</body>
</html>