<?php
session_start();

require_once 'db_connect.php';
require_once 'functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'cashier') {
    header("Location: login.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['message'] = "Invalid order ID.";
    $_SESSION['message_type'] = "danger-color";
    header("Location: dashboard.php");
    exit;
}

$order_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
$order = null;

try {
    // Fetch order with pending status and not yet collected
    $stmt = $conn->prepare(
        "SELECT o.order_id, o.total_amount as total, o.payment_method, o.created_at, o.collected, 
                u.first_name, u.last_name
         FROM orders o 
         LEFT JOIN users u ON o.user_id = u.user_id 
         WHERE o.order_id = ? AND o.status = 'pending' AND o.collected = 'no'"
    );
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();
    $stmt->close();

    if (!$order) {
        $_SESSION['message'] = "Order not found, already processed, or already collected.";
        $_SESSION['message_type'] = "danger-color";
        header("Location: dashboard.php");
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_collection'])) {
        $csrf_token = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_STRING);
        if (!validateCsrfToken($csrf_token)) {
            $_SESSION['message'] = "Invalid CSRF token.";
            $_SESSION['message_type'] = "danger-color";
            header("Location: confirm_correction.php?id=$order_id");
            exit;
        }

        try {
            // Mark order as collected and update status to completed
            $stmt = $conn->prepare("UPDATE orders SET collected = 'yes', status = 'completed', updated_at = NOW() WHERE order_id = ? AND collected = 'no'");
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected > 0) {
                // Log activity
                logActivity($_SESSION['user_id'], "Order #$order_id marked as collected.", 'collection_confirmation');
                $_SESSION['message'] = "Order #$order_id marked as collected.";
                $_SESSION['message_type'] = "success-color";
                header("Location: completed_orders.php");
                exit;
            } else {
                $_SESSION['message'] = "Order #$order_id could not be marked as collected. It may already be collected or completed.";
                $_SESSION['message_type'] = "danger-color";
                header("Location: confirm_correction.php?id=$order_id");
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['message'] = "Error marking order as collected: " . $e->getMessage();
            $_SESSION['message_type'] = "danger-color";
            header("Location: confirm_correction.php?id=$order_id");
            exit;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching order #$order_id: " . $e->getMessage());
    $_SESSION['message'] = "An error occurred while fetching the order.";
    $_SESSION['message_type'] = "danger-color";
    header("Location: dashboard.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Collection | Auntie Eddah POS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header>
        <div class="logo"><i class="fas fa-cash-register"></i> Cashier Panel</div>
        <div class="user-profile">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
        </div>
    </header>

    <aside class="sidebar">
        <nav class="sidebar-nav">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="pending_orders.php"><i class="fas fa-clock"></i> Pending Orders</a>
            <a href="completed_orders.php"><i class="fas fa-check-circle"></i> Completed Orders</a>
            <a href="scan_barcode.php"><i class="fas fa-barcode"></i> Scan Barcode</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </nav>
    </aside>

    <main class="main-content">
        <div class="content">
            <section class="section">
                <h2><i class="fas fa-box"></i> Confirm Collection for Order #<?php echo $order['order_id']; ?></h2>
                <?php if (isset($_SESSION['message'])): ?>
                    <p class="<?php echo $_SESSION['message_type']; ?>"><?php echo $_SESSION['message']; unset($_SESSION['message'], $_SESSION['message_type']); ?></p>
                <?php endif; ?>
                <p><strong>Customer:</strong> <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></p>
                <p><strong>Amount:</strong> ZMW <?php echo number_format($order['total'], 2); ?></p>
                <p><strong>Payment Method:</strong> <?php echo ucfirst($order['payment_method']); ?></p>
                <p><strong>Created At:</strong> <?php echo $order['created_at']; ?></p>
                <form action="" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                    <button type="submit" name="confirm_collection" class="btn"><i class="fas fa-box"></i> Confirm Collection</button>
                    <a href="dashboard.php" class="btn danger-btn"><i class="fas fa-times"></i> Cancel</a>
                </form>
            </section>
        </div>

        <footer>
            © <?php echo date('Y'); ?> Auntie Eddah POS
        </footer>
    </main>
</body>
</html>