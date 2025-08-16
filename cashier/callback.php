<?php
session_start();

require_once '../vendor/autoload.php';
require_once 'config.php';
require_once 'db_connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// Get tx_ref from GET or POST
$transaction_ref = $_GET['tx_ref'] ?? $_POST['tx_ref'] ?? null;
if (!$transaction_ref) {
    error_log("No transaction_ref provided to callback.php");
    header("Location: return.php?status=failed&message=Missing%20transaction%20reference");
    exit();
}

// Fetch payment and cart data from DB
$sql = "SELECT * FROM payments WHERE transaction_ref = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $transaction_ref);
$stmt->execute();
$result = $stmt->get_result();
$payment = $result->fetch_assoc();
$stmt->close();

if (!$payment) {
    error_log("No payment record found for transaction_ref: $transaction_ref");
    header("Location: return.php?status=failed&message=No%20payment%20record%20found&transaction_ref=" . urlencode($transaction_ref));
    exit();
}

// If you stored cart data as JSON in the payments table:
$cart_data = isset($payment['cart_data']) ? json_decode($payment['cart_data'], true) : null;

$config['paychangu']['secret_key'] = 'SEC-S1l5Jkcc9FSgJao8tlm2kcFwbb9xi13u';

$client = new Client();

try {
    $api_endpoint = "https://api.paychangu.com/verify-payment/{$transaction_ref}";
    $response = $client->request('GET', $api_endpoint, [
        'headers' => [
            'Authorization' => 'Bearer ' . $config['paychangu']['secret_key'],
            'Accept' => 'application/json',
        ],
    ]);

    $data = json_decode($response->getBody(), true);

    if ($response->getStatusCode() === 200 && isset($data['status']) && $data['status'] === 'success' && $data['data']['status'] === 'success') {
        $payment_status = 'completed';

        // Begin database transaction
        $conn->begin_transaction();

        try {
            // Update payments table
            $sql_update = "UPDATE payments SET status = ?, created_at = NOW() WHERE transaction_ref = ?";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param("ss", $payment_status, $transaction_ref);
            $stmt_update->execute();
            $stmt_update->close();

            // Calculate total amount
            $total_amount = $payment['amount'];

            // Insert order data
            $sql = "INSERT INTO orders (user_id, total, payment_method, status, created_at, total_amount) VALUES (?, ?, ?, ?, NOW(), ?)";
            $stmt = $conn->prepare($sql);
            $order_status = 'completed';
            $payment_method = 'PayChangu';
            $stmt->bind_param(
                "idssd",
                $payment['user_id'],
                $total_amount,
                $payment_method,
                $order_status,
                $total_amount
            );
            $stmt->execute();
            $order_id = $conn->insert_id;
            $stmt->close();

            // Insert order items if you have them
            if ($cart_data && isset($cart_data['items'])) {
                $sql_items = "INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)";

                $stmt_items = $conn->prepare($sql_items);
                foreach ($cart_data['items'] as $item) {
                    $stmt_items->bind_param("iiid", $order_id, $item['product_id'], $item['quantity'], $item['price']);
                    $stmt_items->execute();
                }
                $stmt_items->close();
            }

            $conn->commit();

            // Optionally send email here...

            // Redirect to return.php
            $redirect_url = "return.php?status=success&message=Order%20completed%20successfully&transaction_ref=" . urlencode($transaction_ref);
            header("Location: $redirect_url");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    } else {
        // Handle pending or failed payment
        $status_failed = 'failed';
        $error_message = isset($data['message']) ? $data['message'] : "Payment not successful or still pending: " . json_encode($data);

        // Update payment status to failed
        $sql_update = "UPDATE payments SET status = ?, created_at = NOW() WHERE transaction_ref = ?";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param("ss", $status_failed, $transaction_ref);
        $stmt_update->execute();
        $stmt_update->close();

        header("Location: return.php?status=failed&message=" . urlencode($error_message) . "&transaction_ref=" . urlencode($transaction_ref));
        exit();
    }
} catch (Exception $e) {
    $status_failed = 'failed';
    $sql_update = "UPDATE payments SET status = ?, created_at = NOW() WHERE transaction_ref = ?";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bind_param("ss", $status_failed, $transaction_ref);
    $stmt_update->execute();
    $stmt_update->close();

    header("Location: return.php?status=failed&message=" . urlencode($e->getMessage()) . "&transaction_ref=" . urlencode($transaction_ref));
    exit();
} finally {
    $conn->close();
}
?>