<?php
/**
 * Order Details Page
 * Displays detailed information for a specific order
 */

session_start();
require_once 'db_connect.php';
require_once 'functions.php';

// Check authentication
if (!validateUserSession() || !hasRole('cashier')) {
    header("Location: login.php");
    exit;
}

// Get order ID from URL
$order_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$order_id) {
    $_SESSION['message'] = "Invalid order ID.";
    $_SESSION['message_type'] = "danger";
    header("Location: orders_manager.php?action=pending");
    exit;
}

// Fetch order details
$order = getOrderById($order_id);
if (!$order) {
    $_SESSION['message'] = "Order not found.";
    $_SESSION['message_type'] = "danger";
    header("Location: orders_manager.php?action=pending");
    exit;
}

// Fetch order items
$order_items = getOrderItems($order_id);

// Generate CSRF token
$csrf_token = generateCsrfToken();

// Handle any POST actions if needed (e.g., print receipt, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Example: Handle print receipt or other actions
    // For now, no specific POST actions defined
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $csrf_token; ?>">
    <title>Order #<?php echo $order_id; ?> Details - Auntie Eddah POS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background-color: #f4f4f4;
            color: #333;
            font-size: 16px;
            line-height: 1.6;
        }
        .dashboard {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        header {
            background-color: #2c3e50;
            color: white;
            padding: 15px 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1200px;
            margin: 0 auto;
        }
        .header-content h1 {
            margin: 0;
            font-size: 24px;
        }
        .header-content h1 i {
            margin-right: 10px;
        }
        .user-profile {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .user-profile span {
            font-size: 16px;
        }
        .btn {
            padding: 8px 16px;
            text-decoration: none;
            color: white;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: none;
        }
        .btn-danger {
            background-color: #e74c3c;
        }
        .btn-danger:hover {
            background-color: #c0392b;
        }
        .btn-secondary {
            background-color: #7f8c8d;
        }
        .btn-secondary:hover {
            background-color: #6c7a7b;
        }
        .btn-primary {
            background-color: #3498db;
        }
        .btn-primary:hover {
            background-color: #2980b9;
        }
        .btn-success {
            background-color: #2ecc71;
        }
        .btn-success:hover {
            background-color: #27ae60;
        }
        .sidebar {
            width: 250px;
            background-color: #34495e;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            padding-top: 80px;
        }
        .sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar ul li {
            padding: 15px 20px;
        }
        .sidebar ul li a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar ul li a:hover {
            background-color: #2c3e50;
            padding-left: 10px;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
            flex-grow: 1;
        }
        .order-details-container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .order-summary, .order-items {
            margin-bottom: 30px;
        }
        .order-summary h3, .order-items h3 {
            margin-top: 0;
            font-size: 20px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 5px;
        }
        .order-summary p {
            margin: 10px 0;
            font-size: 16px;
        }
        .status {
            padding: 4px 8px;
            border-radius: 4px;
            color: white;
            font-size: 14px;
        }
        .status.pending {
            background-color: #f39c12;
        }
        .status.completed {
            background-color: #2ecc71;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .table th, .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .table th {
            background-color: #3498db;
            color: white;
        }
        .table tr:hover {
            background-color: #f9f9f9;
        }
        .order-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .inline-form {
            display: inline-block;
        }
        footer {
            background-color: #2c3e50;
            color: white;
            text-align: center;
            padding: 10px 0;
            margin-top: auto;
        }
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        .notification {
            padding: 10px 20px;
            margin-bottom: 10px;
            border-radius: 4px;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .notification.success {
            background-color: #2ecc71;
        }
        .notification.error {
            background-color: #e74c3c;
        }
        .notification.fade-out {
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: static;
                padding-top: 0;
            }
            .main-content {
                margin-left: 0;
            }
            .header-content h1 {
                font-size: 20px;
            }
            .table th, .table td {
                padding: 8px;
                font-size: 14px;
            }
            .order-actions {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                text-align: center;
                justify-content: center;
            }
        }
    </style>
    <script src="script.js" defer></script>
</head>
<body>
    <main class="dashboard">
        <!-- Notification Container -->
        <div id="notification-container" class="notification-container"></div>

        <!-- Header -->
        <header>
            <div class="header-content">
                <h1><i class="fas fa-file-alt"></i> Order #<?php echo $order_id; ?> Details</h1>
            </div>
        </header>

        <!-- Sidebar Navigation -->
        <nav class="sidebar">
        </nav>

        <!-- Main Content -->
        <div class="main-content">
            <section id="order-details-section" class="content-section active">
                <div class="order-details-container">
                    <!-- Order Summary -->
                    <div class="order-summary">
                        <h3>Order Summary</h3>
                        <p><strong>Order ID:</strong> #<?php echo $order['order_id']; ?></p>
                        <p><strong>Status:</strong> <span class="status <?php echo strtolower($order['status']); ?>"><?php echo ucfirst($order['status']); ?></span></p>
                        <p><strong>Total Amount:</strong> MWK<?php echo number_format($order['total'], 2); ?></p>
                        <p><strong>Payment Method:</strong> <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?></p>
                        <p><strong>Created At:</strong> <?php echo date('Y-m-d H:i:s', strtotime($order['created_at'])); ?></p>
                        <?php if ($order['status'] === 'completed' && isset($order['updated_at'])): ?>
                            <p><strong>Completed At:</strong> <?php echo date('Y-m-d H:i:s', strtotime($order['updated_at'])); ?></p>
                        <?php endif; ?>
                        <p><strong>Customer:</strong> <?php echo htmlspecialchars(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')); ?> (<?php echo htmlspecialchars($order['email'] ?? 'N/A'); ?>)</p>
                        <?php if (isset($order['payment_status'])): ?>
                            <p><strong>Payment Status:</strong> <?php echo ucfirst($order['payment_status']); ?></p>
                        <?php endif; ?>
                    </div>

                    <!-- Order Items Table -->
                    <div class="order-items">
                        <h3>Order Items</h3>
                        <?php if (!empty($order_items)): ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Product ID</th>
                                        <th>Product Name</th>
                                        <th>Product Code</th>
                                        <th>Quantity</th>
                                        <th>Price</th>
                                        <th>Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($order_items as $item): ?>
                                        <tr>
                                            <td><?php echo $item['product_id']; ?></td>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo htmlspecialchars($item['product_code'] ?? 'N/A'); ?></td>
                                            <td><?php echo $item['quantity']; ?></td>
                                            <td>MWK<?php echo number_format($item['price'], 2); ?></td>
                                            <td>MWK<?php echo number_format($item['price'] * $item['quantity'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>No items found for this order.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="order-actions">
                        <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                        <?php if ($order['status'] === 'completed'): ?>
                            <button class="btn btn-primary" onclick="printReceipt(<?php echo $order_id; ?>)"><i class="fas fa-print"></i> Print Receipt</button>
                        <?php endif; ?>
                        <?php if ($order['status'] === 'pending'): ?>
                            <form method="POST" class="confirm-correction-form inline-form">
                                <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="confirm_correction" value="1">
                                <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Confirm Correction</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
          </main>

    <script>
        // Example print receipt function (can be expanded in script.js)
        function printReceipt(orderId) {
            // Implement print logic, e.g., open a print window with order details
            window.print();
        }

        // Handle form submissions if needed (e.g., via AJAX)
        document.querySelectorAll('.confirm-correction-form').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(form);
                try {
                    const response = await fetch('orders_manager.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();
                    if (data.status === 'success') {
                        showToast(data.message, 'success');
                        // Redirect or update UI
                        setTimeout(() => location.href = 'dashboard.php', 2000);
                    } else {
                        showToast(data.message, 'error');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showToast('An error occurred.', 'error');
                }
            });
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>
<?php
require_once 'db_connect.php';
require_once 'functions.php';

$order_id = intval($_GET['id'] ?? 0);
$order = getOrderDetails($order_id); // You must implement this function to fetch all info

if (isset($_GET['ajax'])) {
    if ($order) {
        echo json_encode([
            'status' => 'success',
            'order' => [
                'order_id' => $order['order_id'],
                'customer' => $order['first_name'] . ' ' . $order['last_name'],
                'total' => $order['total'],
                'payment_method' => $order['payment_method'],
                'status' => $order['status'],
                'collected' => $order['collected'],
                'created_at' => $order['created_at'],
                'updated_at' => $order['updated_at'],
                'items' => $order['items'] // array of items
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
    }
    exit;
}
?>
