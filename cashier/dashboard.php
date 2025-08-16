<?php
session_start();

require_once 'db_connect.php';
require_once 'functions.php';

// Ensure user_email and user_name are set
if (!isset($_SESSION['user_email'])) {
    $_SESSION['user_email'] = 'cashier@example.com';
}
if (!isset($_SESSION['user_name'])) {
    $_SESSION['user_name'] = 'Cashier';
}

// Initialize cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Generate CSRF token if not set
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateCsrfToken();
}

// Determine which section to display
$section = filter_input(INPUT_GET, 'section', FILTER_SANITIZE_STRING) ?? 'dashboard';

// Handle AJAX requests
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    if ($_POST['ajax'] === 'store_cart_data') {
        $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
        $cart_data = json_decode($_POST['cart_data'] ?? '{}', true);
        $transaction_ref = sanitizeInput($_POST['transaction_ref'] ?? '');

        if (!validateCsrfToken($csrf_token)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid security token'
            ]);
            exit;
        }

        if (empty($cart_data) || empty($transaction_ref)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid cart data or transaction reference'
            ]);
            exit;
        }

        $_SESSION['cart_data'] = $cart_data;
        $_SESSION['transaction_ref'] = $transaction_ref;

        echo json_encode([
            'status' => 'success',
            'message' => 'Cart data stored in session'
        ]);
        exit;
    } elseif ($_POST['ajax'] === 'add_to_cart') {
        $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');
        $barcode = sanitizeInput($_POST['barcode'] ?? '');

        if (!validateCsrfToken($csrf_token)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid security token. Please try again.',
                'message_type' => 'danger'
            ]);
            exit;
        }

        if (empty($barcode)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Please enter a barcode',
                'message_type' => 'danger'
            ]);
            exit;
        }

        $products = getProductByBarcode($barcode);

        if (!empty($products)) {
            $product = $products[0];

            // Check if product already in cart
            $item_index = -1;
            foreach ($_SESSION['cart'] as $index => $item) {
                if ($item['product_id'] == $product['product_id']) {
                    $item_index = $index;
                    break;
                }
            }

            if ($item_index >= 0) {
                // Update existing item quantity
                $_SESSION['cart'][$item_index]['quantity'] += 1;
            } else {
                // Add new item to cart
                $_SESSION['cart'][] = [
                    'product_id' => $product['product_id'],
                    'name' => $product['name'],
                    'price' => $product['price'],
                    'quantity' => 1,
                    'barcodes' => $barcode
                ];
            }

            // Calculate cart total for response
            $cart_total = 0;
            foreach ($_SESSION['cart'] as $item) {
                $cart_total += $item['price'] * $item['quantity'];
            }

            echo json_encode([
                'status' => 'success',
                'message' => "{$product['name']} (MWK" . number_format($product['price'], 2) . ") added to cart",
                'message_type' => 'success',
                'cart_total' => number_format($cart_total, 2),
                'cart_count' => count($_SESSION['cart'])
            ]);
            exit;
        }

        echo json_encode([
            'status' => 'error',
            'message' => "Product not found for barcode: " . htmlspecialchars($barcode),
            'message_type' => 'danger'
        ]);
        exit;
    } elseif ($_POST['ajax'] === 'cart_operation') {
        $operation = sanitizeInput($_POST['operation'] ?? '');

        if ($operation === 'clear') {
            $_SESSION['cart'] = [];
            unset($_SESSION['cart_data'], $_SESSION['transaction_ref']);
            echo json_encode([
                'status' => 'success',
                'message' => 'Cart cleared successfully',
                'cart_total' => '0.00',
                'cart_count' => 0
            ]);
            exit;
        }

        if ($operation === 'remove_item') {
            $item_index = intval($_POST['item_index'] ?? -1);
            if (isset($_SESSION['cart'][$item_index])) {
                $item_name = $_SESSION['cart'][$item_index]['name'];
                unset($_SESSION['cart'][$item_index]);
                $_SESSION['cart'] = array_values($_SESSION['cart']);

                $cart_total = 0;
                foreach ($_SESSION['cart'] as $item) {
                    $cart_total += $item['price'] * $item['quantity'];
                }

                echo json_encode([
                    'status' => 'success',
                    'message' => "Removed {$item_name} from cart",
                    'cart_total' => number_format($cart_total, 2),
                    'cart_count' => count($_SESSION['cart'])
                ]);
                exit;
            }
        }

        if ($operation === 'update_quantity') {
            $item_index = intval($_POST['item_index'] ?? -1);
            $new_quantity = intval($_POST['quantity'] ?? 0);

            if (isset($_SESSION['cart'][$item_index]) && $new_quantity > 0) {
                $_SESSION['cart'][$item_index]['quantity'] = $new_quantity;

                $cart_total = 0;
                foreach ($_SESSION['cart'] as $item) {
                    $cart_total += $item['price'] * $item['quantity'];
                }

                echo json_encode([
                    'status' => 'success',
                    'message' => 'Quantity updated',
                    'cart_total' => number_format($cart_total, 2)
                ]);
                exit;
            }
        }

        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid operation'
        ]);
        exit;
    } elseif ($_POST['ajax'] === 'process_payment') {
        $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
        $tx_ref = sanitizeInput($_POST['tx_ref'] ?? '');
        $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');

        if (!validateCsrfToken($csrf_token)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid security token'
            ]);
            exit;
        }

        if (empty($_SESSION['cart'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Cart is empty'
            ]);
            exit;
        }

        if (empty($payment_method)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Please select a payment method'
            ]);
            exit;
        }

        $total = 0;
        foreach ($_SESSION['cart'] as $item) {
            $total += $item['price'] * $item['quantity'];
        }

        $order_id = createOrder($_SESSION['user_id'], $_SESSION['cart'], $payment_method, $total);

        if ($order_id) {
            if ($payment_method === 'cash') {
                $payment_result = confirmPayment($order_id);
                if ($payment_result['success']) {
                    $_SESSION['cart'] = [];
                    unset($_SESSION['cart_data'], $_SESSION['transaction_ref']);
                    echo json_encode([
                        'status' => 'success',
                        'message' => "Order #$order_id completed successfully. Total: MWK" . number_format($total, 2),
                        'order_id' => $order_id,
                        'total' => number_format($total, 2),
                        'redirect' => 'completed_orders.php'
                    ]);
                    exit;
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => "Failed to process payment: {$payment_result['error']}"
                    ]);
                    exit;
                }
            } elseif ($payment_method === 'Mobile Transfer') {
                if ($tx_ref) {
                    // Verify payment
                    $payment_status = verifyPaychanguPayment($tx_ref);
                    if ($payment_status['status'] === 'success' && $payment_status['data']['status'] === 'success') {
                        // Update orders table
                        updateOrderStatus($order_id, 'completed');

                        // Insert into payments table
                        insertPaymentRecord(
                            $order_id,
                            $tx_ref,
                            $_SESSION['user_email'],
                            $_SESSION['user_name'],
                            '', // Last name not available
                            $total,
                            'success',
                            $payment_method
                        );

                        $_SESSION['cart'] = [];
                        unset($_SESSION['cart_data'], $_SESSION['transaction_ref']);
                        echo json_encode([
                            'status' => 'success',
                            'message' => "Order #$order_id completed successfully. Total: MWK" . number_format($total, 2),
                            'order_id' => $order_id,
                            'total' => number_format($total, 2),
                            'redirect' => 'completed_orders.php'
                        ]);
                        exit;
                    } else {
                        echo json_encode([
                            'status' => 'error',
                            'message' => "Payment verification failed: " . ($payment_status['message'] ?? 'Unknown error')
                        ]);
                        exit;
                    }
                } else {
                    // Initiate mobile payment
                    $tx_ref = 'PA' . $order_id . time();
                    $_SESSION['pending_order_id'] = $order_id;
                    echo json_encode([
                        'status' => 'pending',
                        'message' => "Initiating mobile payment for Order #$order_id",
                        'order_id' => $order_id,
                        'total' => number_format($total, 2),
                        'tx_ref' => $tx_ref,
                        'pending' => true
                    ]);
                    exit;
                }
            }
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to create order. Please try again.'
            ]);
            exit;
        }
    } elseif ($_POST['ajax'] === 'confirm_correction') {
        $order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
        $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');

        if (!validateCsrfToken($csrf_token)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid security token'
            ]);
            exit;
        }

        if (!$order_id) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid order ID'
            ]);
            exit;
        }

        try {
            // Start transaction
            $conn->begin_transaction();

            // Update order status
            $stmt = $conn->prepare("
                UPDATE orders 
                SET status = 'completed' 
                WHERE order_id = ? AND status = 'pending'
            ");
            
            if (!$stmt) {
                throw new Exception("Failed to prepare status update query");
            }

            $stmt->bind_param('i', $order_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update order status");
            }
            
            if ($stmt->affected_rows === 0) {
                throw new Exception("Order not found or already completed");
            }

            $stmt->close();

            // Log the activity
            logActivity($_SESSION['user_id'], "Confirmed payment for order #$order_id", 'payment_confirmation');

            // Commit transaction
            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => "Payment confirmed for order #$order_id",
                'order_id' => $order_id
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error confirming payment: " . $e->getMessage());
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
        exit;
    } elseif ($_POST['ajax'] === 'mark_collected') {
        $order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
        $csrf_token = sanitizeInput($_POST['csrf_token'] ?? '');

        if (!validateCsrfToken($csrf_token)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid security token']);
            exit;
        }

        if ($order_id && markOrderAsCollected($order_id)) {
            echo json_encode(['status' => 'success', 'message' => "Order #{$order_id} marked as collected"]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to mark order as collected']);
        }
        exit;
    }
}

// Handle GET AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'stats') {
        $stats = getDashboardStats();
        echo json_encode([
            'status' => 'success',
            'stats' => $stats,
            'message' => 'Stats retrieved successfully'
        ]);
        exit;
    } elseif ($_GET['ajax'] === 'product_details') {
        $barcode = sanitizeInput($_GET['barcode'] ?? '');
        $products = getProductByBarcode($barcode);
        echo json_encode([
            'status' => !empty($products) ? 'success' : 'error',
            'data' => !empty($products) ? [
                'product_id' => $products[0]['product_id'],
                'name' => $products[0]['name'],
                'price' => $products[0]['price'],
                'category' => $products[0]['category']
            ] : [],
            'message' => !empty($products) ? 'Product found' : "Product not found for barcode: $barcode"
        ]);
        exit;
    } elseif ($_GET['ajax'] === 'completed_orders_data') {
        $completed_orders = getCompletedOrders();
        error_log("Sending completed orders response: " . json_encode($completed_orders));
        echo json_encode(['status' => 'success', 'data' => $completed_orders]);
        exit;
    } elseif ($_GET['ajax'] === 'pending_orders_data') {
        $pending_orders = getCustomerPendingOrders();
        error_log("Sending pending orders response: " . json_encode($pending_orders));
        echo json_encode(['status' => 'success', 'data' => $pending_orders]);
        exit;
    } elseif ($_GET['ajax'] === 'sales_report_data') {
        $start_date = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_STRING) ?: date('Y-m-d', strtotime('-7 days'));
        $end_date = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_STRING) ?: date('Y-m-d');
        $report = getSalesReport($start_date, $end_date);
        echo json_encode(['status' => 'success', 'data' => $report]);
        exit;
    } elseif ($_GET['ajax'] === 'cart_data') {
        $cart_total = 0;
        foreach ($_SESSION['cart'] as $item) {
            $cart_total += $item['price'] * $item['quantity'];
        }
        echo json_encode([
            'status' => 'success',
            'cart' => $_SESSION['cart'],
            'cart_total' => number_format($cart_total, 2),
            'cart_count' => count($_SESSION['cart'])
        ]);
        exit;
    }
}

// Function to verify Paychangu payment
function verifyPaychanguPayment($tx_ref) {
    $secret_key = 'SEC-S1l5Jkcc9FSgJao8tlm2kcFwbb9xi13u';
    $url = "https://api.paychangu.com/verify-payment/{$tx_ref}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . $secret_key
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        return json_decode($response, true);
    } else {
        return [
            'status' => 'error',
            'message' => $error ?: 'Failed to verify payment with PayChangu API'
        ];
    }
}

// Function to update order status
function updateOrderStatus($order_id, $status) {
    global $conn;
    $stmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?");
    $stmt->bind_param('si', $status, $order_id);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Function to insert payment record
function insertPaymentRecord($order_id, $transaction_ref, $email, $first_name, $last_name, $amount, $status, $payment_method) {
    global $conn;
    $stmt = $conn->prepare("
        INSERT INTO payments (order_id, transaction_ref, email, first_name, last_name, amount, status, payment_method, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $stmt->bind_param('issssiss', $order_id, $transaction_ref, $email, $first_name, $last_name, $amount, $status, $payment_method);
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

// Data for initial page load
$stats = getDashboardStats();
$recent_orders = getCompletedOrders(5);
$pending_orders_data = getCustomerPendingOrders();

// Calculate cart total
$cart_total = 0;
foreach ($_SESSION['cart'] as $item) {
    $cart_total += $item['price'] * $item['quantity'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Auntie Eddah POS Dashboard - Manage orders, payments, and sales">
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token']; ?>">
    <title>Cashier Dashboard | Auntie Eddah POS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://in.paychangu.com/js/popup.js"></script>
</head>
<body>

    <header role="banner" class="header">
        <div class="header-content">
            <div class="logo">
                <i class="fas fa-cash-register" aria-hidden="true"></i> 
                <span>Cashier Dashboard</span>
            </div>
            <div class="header-actions">
                <button class="btn btn-primary quick-sale-btn" onclick="dashboard.showSection('new-sale')">
                    <i class="fas fa-plus"></i> New Sale
                </button>
                <div class="user-profile">
                    <i class="fas fa-user-circle"></i>
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Cashier'); ?></span>
                </div>
            </div>
        </div>
    </header>

    <aside class="sidebar" role="navigation" aria-label="Main navigation">
        <nav class="sidebar-nav">
            <a href="#dashboard" class="nav-link active" onclick="dashboard.showSection('dashboard')" aria-current="page">
                <i class="fas fa-tachometer-alt" aria-hidden="true"></i>
                <span>Dashboard</span>
            </a>
            <a href="#new-sale" class="nav-link" onclick="dashboard.showSection('new-sale')">
                <i class="fas fa-barcode" aria-hidden="true"></i>
                <span>New Sale</span>
                <?php if (count($_SESSION['cart']) > 0): ?>
                    <span class="badge" id="cart-badge"><?php echo count($_SESSION['cart']); ?></span>
                <?php endif; ?>
            </a>
            <a href="#pending-orders" class="nav-link" onclick="dashboard.showSection('pending-orders')">
                <i class="fas fa-clock" aria-hidden="true"></i>
                <span>Pending Orders</span>
                <?php if (count($pending_orders_data) > 0): ?>
                    <span class="badge" id="pending-orders-badge"><?php echo count($pending_orders_data); ?></span>
                <?php endif; ?>
            </a>
            <a href="#completed-orders" class="nav-link" onclick="dashboard.showSection('completed-orders')">
                <i class="fas fa-check-circle" aria-hidden="true"></i>
                <span>Completed Orders</span>
            </a>
            <a href="#sales-report" class="nav-link" onclick="dashboard.showSection('sales-report')">
                <i class="fas fa-chart-bar" aria-hidden="true"></i>
                <span>Sales Report</span>
            </a>
            <a href="logout.php" class="nav-link logout">
                <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                <span>Logout</span>
            </a>
        </nav>
    </aside>

    <main class="main-content" role="main">
        <div class="content-wrapper">
            <!-- Notification Container -->
            <div class="notification-container" id="notification-container"></div>

            <!-- Dashboard Section -->
            <section id="dashboard-section" class="content-section active">
                <!-- Dashboard Stats -->
                <section class="dashboard-stats" aria-labelledby="stats-heading">
                    <h1 id="stats-heading" class="section-title">
                        <i class="fas fa-chart-line"></i> Today's Overview
                    </h1>
                    
                    <div class="stats-grid">
                        <div class="stat-card orders-card animate__animated animate__fadeInUp">
                            <div class="stat-icon">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                            <div class="stat-content">
                                <h3>Orders Today</h3>
                                <p class="stat-number" id="orders-today"><?php echo $stats['orders_today']; ?></p>
                                <span class="stat-label">Total orders processed</span>
                            </div>
                        </div>
                        
                        <div class="stat-card pending-card animate__animated animate__fadeInUp" style="animation-delay: 0.1s">
                            <div class="stat-icon">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <div class="stat-content">
                                <h3>Pending Payments</h3>
                                <p class="stat-number" id="pending-payments"><?php echo $stats['pending_payments']; ?></p>
                                <span class="stat-label">Awaiting confirmation</span>
                            </div>
                        </div>
                        
                        <div class="stat-card transactions-card animate__animated animate__fadeInUp" style="animation-delay: 0.2s">
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-content">
                                <h3>Completed</h3>
                                <p class="stat-number" id="transactions-count"><?php echo $stats['transactions_count']; ?></p>
                                <span class="stat-label">Successful transactions</span>
                            </div>
                        </div>
                        
                        <div class="stat-card sales-card animate__animated animate__fadeInUp" style="animation-delay: 0.3s">
                            <div class="stat-icon">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="stat-content">
                                <h3>Total Sales</h3>
                                <p class="stat-number" id="total-sales">MWK<?php echo number_format($stats['total_sales_today'], 2); ?></p>
                                <span class="stat-label">Revenue today</span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Quick Actions -->
                <section class="quick-actions-section">
                    <h2 class="section-title">
                        <i class="fas fa-bolt"></i> Quick Actions
                    </h2>
                    
                    <div class="action-grid">
                        <button class="action-btn primary-action" onclick="dashboard.showSection('new-sale')">
                            <i class="fas fa-barcode"></i>
                            <span>Start New Sale</span>
                            <small>Scan products & process payment</small>
                        </button>
                        
                        <button class="action-btn" onclick="dashboard.showSection('pending-orders')">
                            <i class="fas fa-clock"></i>
                            <span>View Pending Orders</span>
                            <small>Review orders awaiting payment</small>
                        </button>
                        
                        <button class="action-btn" onclick="dashboard.showSection('completed-orders')">
                            <i class="fas fa-check-circle"></i>
                            <span>View Completed Orders</span>
                            <small>Browse all successful transactions</small>
                        </button>
                        
                        <button class="action-btn" onclick="dashboard.showSection('sales-report')">
                            <i class="fas fa-chart-bar"></i>
                            <span>Generate Sales Report</span>
                            <small>Analyze sales data & trends</small>
                        </button>
                    </div>
                </section>

                <!-- Recent Orders -->
                <section class="recent-orders-section">
                    <h2 class="section-title">
                        <i class="fas fa-history"></i> Recent Completed Orders
                    </h2>
                    <div class="table-container">
                        <table id="recent-orders-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Completed At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_orders)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center;">No recent completed orders.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_orders as $order): ?>
                                        <tr data-order-id="<?php echo $order['order_id']; ?>">
                                            <td><strong>#<?php echo $order['order_id']; ?></strong></td>
                                            <td><?php echo htmlspecialchars(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? '')); ?></td>
                                            <td><strong class="order-amount">MWK<?php echo number_format($order['total'], 2); ?></strong></td>
                                            <td>
                                                <span class="payment-method <?php echo $order['payment_method']; ?>">
                                                    <?php 
                                                    $icons = [
                                                        'cash' => 'fas fa-money-bill-wave',
                                                        'mobile transfer' => 'fas fa-mobile-alt',
                                                       
                                                    ];
                                                    $icon = $icons[$order['payment_method']] ?? 'fas fa-question';
                                                    ?>
                                                    <i class="<?php echo $icon; ?>"></i>
                                                    <?php echo ucfirst(str_replace('_', ' ', $order['payment_method'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                    $timestamp = !empty($order['updated_at']) ? $order['updated_at'] : $order['created_at'];
                                                    echo date('M j, Y', strtotime($timestamp)); 
                                                ?><br>
                                                <small><?php echo date('H:i', strtotime($timestamp)); ?></small>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 5px;">
                                                    <a href="order_details.php?id=<?php echo $order['order_id']; ?>" class="btn btn-info btn-sm view-order-details" data-order-id="<?php echo $order['order_id']; ?>">
                                                        <i class="fas fa-eye"></i> View Details
                                                    </a>
                                                    <button class="btn btn-primary btn-sm" data-action="print-receipt" data-order-id="<?php echo $order['order_id']; ?>">
                                                        <i class="fas fa-print"></i> Print
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </section>

            <!-- New Sale Section -->
            <section id="new-sale-section" class="content-section">
                <h1 class="section-title"><i class="fas fa-barcode"></i> New Sale</h1>
                <div class="sale-container">
                    <div class="product-scan-section">
                        <div class="form-group">
                            <label for="barcode-input">Scan Barcode</label>
                            <input type="text" id="barcode-input" class="form-control" placeholder="Enter barcode or product code" autofocus>
                        </div>
                        <div id="product-preview" class="product-preview" style="display: none;">
                            <p><strong>Product:</strong> <span id="product-name"></span></p>
                            <p><strong>Price:</strong> <span id="product-price"></span></p>
                            <p><strong>Category:</strong> <span id="product-category"></span></p>
                        </div>
                        <button id="add-to-cart-btn" class="btn btn-primary btn-block">
                            <i class="fas fa-cart-plus"></i> Add to Cart
                        </button>
                    </div>

                    <div class="cart-section">
                        <h2><i class="fas fa-shopping-cart"></i> Cart (<span id="cart-count"><?php echo count($_SESSION['cart']); ?></span> items)</h2>
                        <div class="cart-items-container">
                            <ul id="cart-items-list" class="cart-items-list">
                                <?php if (empty($_SESSION['cart'])): ?>
                                    <li class="empty-cart-message">Your cart is empty. Scan a product to add.</li>
                                <?php else: ?>
                                    <?php foreach ($_SESSION['cart'] as $index => $item): ?>
                                        <li class="cart-item" data-index="<?php echo $index; ?>">
                                            <div class="item-details">
                                                <span class="item-name"><?php echo htmlspecialchars($item['name']); ?></span>
                                                <span class="item-price">MWK<?php echo number_format($item['price'], 2); ?></span>
                                            </div>
                                            <div class="item-controls">
                                                <button class="btn btn-sm btn-secondary decrease-qty" data-index="<?php echo $index; ?>">-</button>
                                                <span class="quantity"><?php echo $item['quantity']; ?></span>
                                                <button class="btn btn-sm btn-secondary increase-qty" data-index="<?php echo $index; ?>">+</button>
                                                <button class="btn btn-sm btn-danger remove-item" data-index="<?php echo $index; ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="cart-summary">
                            <p>Total: <strong id="cart-total">MWK<?php echo number_format($cart_total, 2); ?></strong></p>
                            <div class="form-group">
                                <label for="payment-method">Payment Method</label>
                                <select id="payment-method" class="form-control">
                                    <option value="">Select Payment Method</option>
                                    <option value="cash">Cash</option>
                                    <option value="Mobile Transfer">Mobile Transfer</option>
                                </select>
                            </div>
                            <button id="process-payment-btn" class="btn btn-success btn-block" disabled>
                                <i class="fas fa-money-bill-wave"></i> Process Payment
                            </button>
                            <button id="clear-cart-btn" class="btn btn-warning btn-block">
                                <i class="fas fa-trash-alt"></i> Clear Cart
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Pending Orders Section -->
            <section id="pending-orders-section" class="content-section">
                <h2 class="section-title">
                    <i class="fas fa-clock"></i> Orders Pending Collection
                    (<span id="pending-count"><?php echo count($pending_orders_data); ?></span>)
                </h2>
                
                <div class="table-container">
                    <table id="pending-orders-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Amount</th>
                                <th>Payment Method</th>
                                <th>Completed At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending_orders_data)): ?>
                                <tr>
                                    <td colspan="5" class="text-center">
                                        <p>No orders pending collection</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pending_orders_data as $order): ?>
                                    <tr data-order-id="<?php echo $order['order_id']; ?>">
                                        <td><strong>#<?php echo $order['order_id']; ?></strong></td>
                                        <td><strong>MWK<?php echo number_format($order['total'], 2); ?></strong></td>
                                        <td>
                                            <span class="payment-method <?php echo strtolower($order['payment_method']); ?>">
                                                <i class="fas fa-<?php echo $order['payment_method'] === 'cash' ? 'money-bill-wave' : 'mobile-alt'; ?>"></i>
                                                <?php echo ucfirst($order['payment_method']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($order['created_at'])); ?><br>
                                            <small><?php echo date('H:i', strtotime($order['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <button type="button" class="btn btn-primary btn-sm mark-collected"
                                                        data-order-id="<?php echo $order['order_id']; ?>"
                                                        data-csrf-token="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <i class="fas fa-box"></i> Mark as Collected
                                                </button>
                                                <a href="order_details.php?id=<?php echo $order['order_id']; ?>" class="btn btn-info btn-sm view-order-details" data-order-id="<?php echo $order['order_id']; ?>">
                                                    <i class="fas fa-eye"></i> View Details
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Completed Orders Section -->
            <section id="completed-orders-section" class="content-section">
                <h2 class="section-title">
                    <i class="fas fa-check-circle"></i> Collected Orders
                    (<span id="completed-count"><?php echo count($recent_orders); ?></span>)
                </h2>
                
                <div class="table-container">
                    <table id="completed-orders-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Amount</th>
                                <th>Payment Method</th>
                                <th>Collected At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_orders)): ?>
                                <tr>
                                    <td colspan="5" class="text-center">
                                        <p>No collected orders</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_orders as $order): ?>
                                    <tr data-order-id="<?php echo $order['order_id']; ?>">
                                        <td><strong>#<?php echo $order['order_id']; ?></strong></td>
                                        <td><strong>MWK<?php echo number_format($order['total'], 2); ?></strong></td>
                                        <td>
                                            <span class="payment-method <?php echo strtolower($order['payment_method']); ?>">
                                                <i class="fas fa-<?php echo $order['payment_method'] === 'cash' ? 'money-bill-wave' : 'mobile-alt'; ?>"></i>
                                                <?php echo ucfirst($order['payment_method']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($order['created_at'])); ?><br>
                                            <small><?php echo date('H:i', strtotime($order['created_at'])); ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="order_details.php?id=<?php echo $order['order_id']; ?>" class="btn btn-info btn-sm view-order-details" data-order-id="<?php echo $order['order_id']; ?>">
                                                    <i class="fas fa-eye"></i> View Details
                                                </a>
                                                <button class="btn btn-primary btn-sm" data-action="print-receipt" data-order-id="<?php echo $order['order_id']; ?>">
                                                    <i class="fas fa-print"></i> Print
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Sales Report Section -->
            <section id="sales-report-section" class="content-section">
                <h2 class="section-title"><i class="fas fa-chart-bar"></i> Sales Report</h2>
                
                <form id="sales-report-form" method="GET" style="margin-bottom: 20px;">
                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <div class="form-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars(date('Y-m-d', strtotime('-7 days'))); ?>" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="end_date">End Date</label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                        <a href="#" id="download-csv-btn" class="btn btn-success">
                            <i class="fas fa-download"></i> Download CSV
                        </a>
                    </div>
                </form>

                <div id="sales-report-content">
                    <!-- Sales report data will be loaded here via AJAX -->
                    <div style="text-align: center; padding: 40px;">
                        <i class="fas fa-inbox" style="font-size: 4rem; color: var(--secondary-color); margin-bottom: 20px;"></i>
                        <h3>No Sales Data</h3>
                        <p>Select a date range and click Filter to view sales data.</p>
                    </div>
                </div>
            </section>
        </div>

    </main>

    <!-- Order Details Modal -->
    <div id="order-details-modal" class="modal" style="display:none;">
      <div class="modal-content">
        <span class="close-modal" id="close-order-modal">&times;</span>
        <div id="order-details-body">
          <!-- Order details will be loaded here -->
        </div>
      </div>
    </div>
    <style>
    .modal {
      position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
      background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 9999;
    }
    .modal-content {
      background: #fff; padding: 30px; border-radius: 10px; min-width: 350px; max-width: 95vw; max-height: 90vh; overflow-y: auto; position: relative;
    }
    .close-modal {
      position: absolute; top: 10px; right: 20px; font-size: 2rem; color: #888; cursor: pointer;
    }
    </style>

<script src="script.js"></script>

</body>
</html>
<?php
$conn->close();
?>