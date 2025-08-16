<?php
session_start();
$config = require __DIR__ . '/config.php';

// Get user info from session
$email = $_SESSION['user_email'] ?? 'customer@example.com';
$first_name = $_SESSION['user_first_name'] ?? 'First';
$last_name = $_SESSION['user_last_name'] ?? 'Last';

// Calculate cart/order total
$amount = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $amount += $item['price'] * $item['quantity'];
    }
}
if ($amount <= 0) $amount = 1000; // fallback for demo

$tx_ref = uniqid('AEPOS-');
$callback_url = $config['paychangu']['callback_url'];
$return_url = $config['paychangu']['return_url'];

echo json_encode([
    'status' => 'success',
    'payment' => [
        'public_key' => $config['paychangu']['public_key'],
        'tx_ref' => $tx_ref,
        'amount' => $amount,
        'currency' => 'MWK',
        'callback_url' => $callback_url,
        'return_url' => $return_url,
        'email' => $email,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'title' => 'Auntie Eddah POS Payment',
        'description' => 'Payment for your order at Auntie Eddah POS',
        'uuid' => session_id()
    ]
]);