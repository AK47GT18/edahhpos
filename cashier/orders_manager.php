<?php

session_start();
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? null;

    if ($action === 'pending') {
        $data = getCustomerPendingOrders();
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }

    if ($action === 'completed') {
        // optional limit via query string
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
        $data = getCompletedOrders($limit);
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }

    if ($action === 'stats') {
        $stats = getDashboardStats();
        echo json_encode(['status' => 'success', 'data' => $stats]);
        exit;
    }

    if ($action === 'order_details') {
        $order_id = intval($_GET['order_id'] ?? 0);
        $order = getOrderDetails($order_id);
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
                    'items' => $order['items']
                ]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        }
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

if ($method === 'POST') {
    $ajax = $_POST['ajax'] ?? null;

    if ($ajax === 'mark_collected') {
        $order_id = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
        $csrf = $_POST['csrf_token'] ?? '';

        if (!validateCsrfToken($csrf)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid security token']);
            exit;
        }

        if (!$order_id) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid order id']);
            exit;
        }

        $ok = markOrderAsCollected($order_id);

        if ($ok) {
            // return updated stats so frontend can update badges
            $stats = getDashboardStats();
            echo json_encode([
                'status' => 'success',
                'message' => "Order #{$order_id} marked as collected",
                'stats' => $stats
            ]);
            exit;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to mark order as collected']);
            exit;
        }
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown ajax action']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
exit;