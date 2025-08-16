<?php

session_start();
require_once 'db_connect.php';

$status = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '';
$transaction_ref = isset($_GET['transaction_ref']) ? htmlspecialchars(trim($_GET['transaction_ref']), ENT_QUOTES, 'UTF-8') : '';
$message = isset($_GET['message']) && is_string($_GET['message']) && !empty($_GET['message'])
    ? htmlspecialchars(urldecode($_GET['message']), ENT_QUOTES, 'UTF-8')
    : ($status === 'success' ? "Payment successful! Your order has been completed." : "Payment failed. Please try again or contact support.");
$redirect_url = $status === 'success' ? "dashboard.php?page=orders" : "dashboard.php?page=cart";
$redirect_delay = 3000;

// Fetch payment details
$payment_details = null;
if (!empty($transaction_ref)) {
    $sql_payment = "SELECT * FROM payments WHERE transaction_ref = ?";
    $stmt_payment = $conn->prepare($sql_payment);
    if ($stmt_payment) {
        $stmt_payment->bind_param("s", $transaction_ref);
        $stmt_payment->execute();
        $result = $stmt_payment->get_result();
        if ($result->num_rows > 0) {
            $payment_details = $result->fetch_assoc();
        }
        $stmt_payment->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Result - EDAHHPOS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6fa; }
        .main-content { max-width: 500px; margin: 3rem auto; background: #fff; border-radius: 1rem; box-shadow: 0 10px 30px rgba(0,0,0,0.08); padding: 2rem; text-align: center; }
        .status-icon { font-size: 3rem; margin-bottom: 1rem; }
        .status-icon.success { color: #28a745; }
        .status-icon.failed { color: #dc3545; }
        .success, .error { margin-bottom: 1rem; padding: 1rem; border-radius: 0.5rem; font-weight: 600; }
        .success { color: #28a745; background: #eafaf1; border: 1px solid #28a745; }
        .error { color: #dc3545; background: #fdeaea; border: 1px solid #dc3545; }
        .transaction-details { background: #f8f9fb; border-radius: 0.5rem; padding: 1rem; margin: 1rem 0; text-align: left; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #4a6baf; color: #fff; border-radius: 0.5rem; text-decoration: none; font-weight: 600; margin-top: 1rem; }
        .btn.success { background: #28a745; }
        .btn.failed { background: #dc3545; }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="status-icon <?php echo $status === 'success' ? 'success' : 'failed'; ?>">
            <i class="fas <?php echo $status === 'success' ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
        </div>
        <h2>Payment Status</h2>
        <p class="<?php echo $status === 'success' ? 'success' : 'error'; ?>">
            <?php echo $message; ?>
        </p>
        <?php if ($payment_details): ?>
        <div class="transaction-details">
            <strong>Transaction Reference:</strong> <?php echo htmlspecialchars($payment_details['transaction_ref']); ?><br>
            <strong>Amount:</strong> MWK <?php echo number_format($payment_details['amount'], 2); ?><br>
            <strong>Status:</strong> <?php echo ucfirst($payment_details['status']); ?><br>
            <strong>Date:</strong> <?php echo date('F j, Y g:i A', strtotime($payment_details['created_at'])); ?><br>
            <?php if (isset($payment_details['payment_method'])): ?>
            <strong>Payment Method:</strong> <?php echo htmlspecialchars($payment_details['payment_method']); ?><br>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <a href="<?php echo htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8'); ?>" class="btn <?php echo $status === 'success' ? 'success' : 'failed'; ?>">
            <?php echo $status === 'success' ? 'View Orders' : 'Return to Cart'; ?>
        </a>
    </div>
</body>
</html>