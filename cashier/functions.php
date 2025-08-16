<?php
require_once __DIR__ . '/db_connect.php';

global $conn;

/**
 * Check if a table exists in the database
 */
function tableExists($connection, $tableName) {
    try {
        if ($connection instanceof mysqli) {
            $result = $connection->query("SHOW TABLES LIKE '$tableName'");
            return $result && $result->num_rows > 0;
        }
        return false;
    } catch (Exception $e) {
        error_log("Error checking table existence for $tableName: " . $e->getMessage());
        return false;
    }
}

/**
 * Sanitize input
 */
function sanitizeInput($input, $type = 'string') {
    if ($type === 'int') {
        return filter_var($input, FILTER_VALIDATE_INT) ?: 0;
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate CSRF token
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get product by barcode
 */
function getProductByBarcode($barcode) {
    global $conn;
    
    $barcode = sanitizeInput($barcode);
    
    if (empty($barcode)) {
        error_log("Empty barcode provided to getProductByBarcode");
        return [];
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT product_id, name, price, category, stock_quantity
            FROM products
            WHERE (barcodes = ? OR barcodes LIKE ?) AND is_active = 1 AND deleted_at IS NULL
            LIMIT 1
        ");
        
        $likeBarcode = "%$barcode%";
        $stmt->bind_param("ss", $barcode, $likeBarcode);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        
        $stmt->close();
        
        if (empty($products)) {
            error_log("No product found for barcode: '$barcode' (exact or LIKE match)");
        } else {
            error_log("Product found for barcode: '$barcode' - " . json_encode($products[0]));
        }
        
        return $products;
        
    } catch (Exception $e) {
        error_log("Error fetching product by barcode '$barcode': " . $e->getMessage());
        return [];
    }
}

/**
 * Get pending orders from customer orders (system-wide)
 */
function getCustomerPendingOrders() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                o.order_id, 
                o.user_id,
                COALESCE(o.total_amount, 0) as total, 
                o.payment_method, 
                o.created_at,
                o.status,
                o.collected
            FROM orders o 
            WHERE o.status = 'completed' 
            AND o.collected = 'no'
            ORDER BY o.created_at DESC
        ");

        if (!$stmt) {
            error_log("Failed to prepare pending orders query: " . $conn->error);
            return [];
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $orders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $orders;
    } catch (Exception $e) {
        error_log("Error fetching pending orders: " . $e->getMessage());
        return [];
    }
}

/**
 * Get completed orders
 */
function getCompletedOrders($limit = 50) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                o.order_id, 
                o.user_id,
                COALESCE(o.total_amount, 0) as total, 
                o.payment_method, 
                o.created_at, 
                o.status,
                o.collected
            FROM orders o 
            WHERE o.status = 'completed' 
            AND o.collected = 'yes'
            ORDER BY o.created_at DESC
            LIMIT ?
        ");

        if (!$stmt) {
            error_log("Failed to prepare completed orders query: " . $conn->error);
            return [];
        }

        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $orders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $orders;
    } catch (Exception $e) {
        error_log("Error fetching completed orders: " . $e->getMessage());
        return [];
    }
}

/**
 * Get order items
 */
function getOrderItems($order_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT oi.product_id, oi.quantity, oi.price, p.name, p.product_code
            FROM order_items oi
            JOIN products p ON oi.product_id = p.product_id
            WHERE oi.order_id = ?
        ");
        
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        
        $stmt->close();
        return $items;
        
    } catch (Exception $e) {
        error_log("Error fetching order items for order #$order_id: " . $e->getMessage());
        return [];
    }
}

/**
 * Confirm payment for an order
 */
function confirmPayment($order_id) {
    global $conn;
    
    try {
        $conn->begin_transaction();
        
        // Verify order exists and is pending, fetch customer details
        $stmt = $conn->prepare("
            SELECT o.status, o.customer_id, o.total_amount, o.payment_method, c.first_name, c.last_name, c.email 
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.customer_id
            WHERE o.order_id = ?
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result->fetch_assoc();
        $stmt->close();
        
        if (!$order) {
            $conn->rollback();
            error_log("Failed to confirm payment for order #$order_id: Order not found");
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        if ($order['status'] !== 'pending') {
            $conn->rollback();
            error_log("Failed to confirm payment for order #$order_id: Order status is '{$order['status']}', expected 'pending'");
            return ['success' => false, 'error' => "Order status is '{$order['status']}', expected 'pending'"];
        }
        
        // Set defaults for anonymous customers
        $first_name = $order['first_name'] ?? 'Anonymous';
        $last_name = $order['last_name'] ?? '';
        $email = $order['email'] ?? '';
        
        // Update order status to completed
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = 'completed', updated_at = NOW()
            WHERE order_id = ?
        ");
        $stmt->bind_param("i", $order_id);
        $success = $stmt->execute();
        if (!$success) {
            $conn->rollback();
            error_log("Failed to update order status for order #$order_id: " . $stmt->error . " (Error Code: " . $stmt->errno . ")");
            $stmt->close();
            return ['success' => false, 'error' => 'Failed to update order status: ' . $stmt->error];
        }
        $stmt->close();
        
        // Insert payment record for cash payments
        if ($order['payment_method'] === 'cash') {
            $stmt = $conn->prepare("
                INSERT INTO payments (order_id, amount, payment_method, transaction_ref, status, created_at, email, firstname, surname)
                VALUES (?, ?, 'cash', CONCAT('CASH-', ?), 'successful', NOW(), ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    status = 'successful',
                    amount = ?,
                    payment_method = 'cash',
                    email = ?,
                    firstname = ?,
                    surname = ?
            ");
            $stmt->bind_param("idisssdsss", $order_id, $order['total_amount'], $order_id, $email, $first_name, $last_name, $order['total_amount'], $email, $first_name, $last_name);
            $payment_success = $stmt->execute();
            if (!$payment_success) {
                $conn->rollback();
                error_log("Failed to insert payment record for order #$order_id: " . $stmt->error . " (Error Code: " . $stmt->errno . ")");
                $stmt->close();
                return ['success' => false, 'error' => 'Failed to insert payment record: ' . $stmt->error];
            }
            $stmt->close();
            error_log("Payment confirmed for order #$order_id (cash payment, payment record created)");
        } else {
            error_log("Payment confirmed for order #$order_id (non-cash payment, no payment record update needed)");
        }
        
        $conn->commit();
        return ['success' => true];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error confirming payment for order #$order_id: " . $e->getMessage() . " (Exception)");
        return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Process mobile payment
 */
function processMobilePayment($order_id, $amount, $payment_method, $transaction_ref) {
    global $conn;
    
    try {
        $conn->begin_transaction();
        
        // Verify order exists and is pending, fetch customer details
        $stmt = $conn->prepare("
            SELECT o.status, o.total_amount, o.payment_method, c.first_name, c.last_name, c.email 
            FROM orders o
            LEFT JOIN customers c ON o.customer_id = c.customer_id
            WHERE o.order_id = ?
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result->fetch_assoc();
        $stmt->close();
        
        if (!$order || $order['status'] !== 'pending') {
            $conn->rollback();
            error_log("Failed to process mobile payment for order #$order_id: Order not found or not in pending status");
            return false;
        }
        
        // Set defaults for anonymous customers
        $first_name = $order['first_name'] ?? 'Anonymous';
        $last_name = $order['last_name'] ?? '';
        $email = $order['email'] ?? '';
        
        // Insert payment record
        $stmt = $conn->prepare("
            INSERT INTO payments (order_id, amount, payment_method, transaction_ref, status, created_at, email, firstname, surname)
            VALUES (?, ?, ?, ?, 'successful', NOW(), ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                status = 'successful',
                amount = ?,
                payment_method = ?,
                email = ?,
                firstname = ?,
                surname = ?
        ");
        $stmt->bind_param("idsssssdssss", $order_id, $amount, $payment_method, $transaction_ref, $email, $first_name, $last_name, $amount, $payment_method, $email, $first_name, $last_name);
        $stmt->execute();
        $stmt->close();
        
        // Update order status
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = 'completed', updated_at = NOW()
            WHERE order_id = ?
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $stmt->close();
        
        error_log("Mobile payment processed for order #$order_id: amount=$amount, payment_method=$payment_method");
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error processing mobile payment for order #$order_id: " . $e->getMessage());
        return false;
    }
}

/**
 * Get dashboard statistics
 */
function getDashboardStats() {
    global $conn;
    
    $stats = [
        'orders_today' => 0,
        'pending_payments' => 0,
        'transactions_count' => 0,
        'total_sales_today' => 0
    ];
    
    try {
        // Orders today
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE DATE(created_at) = CURDATE()
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['orders_today'] = $row['count'];
        $stmt->close();
        
        // Pending payments
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE status = 'pending'
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['pending_payments'] = $row['count'];
        $stmt->close();
        
        // Total transactions today
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE DATE(created_at) = CURDATE() AND status = 'completed'
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['transactions_count'] = $row['count'];
        $stmt->close();
        
        // Total sales today
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total 
            FROM orders 
            WHERE DATE(created_at) = CURDATE() AND status = 'completed'
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stats['total_sales_today'] = $row['total'];
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Error fetching dashboard stats: " . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Get sales report data
 */
function getSalesReport($start_date = null, $end_date = null) {
    global $conn;
    
    if (!$start_date) $start_date = date('Y-m-d', strtotime('-7 days'));
    if (!$end_date) $end_date = date('Y-m-d');
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                DATE(p.created_at) as sale_date,
                COUNT(p.id) as orders_count,
                SUM(p.amount) as total_sales,
                COALESCE(p.payment_method, 'unknown') as payment_method
            FROM payments p
            WHERE DATE(p.created_at) BETWEEN ? AND ?
            GROUP BY DATE(p.created_at), COALESCE(p.payment_method, 'unknown')
            ORDER BY sale_date DESC, payment_method
        ");
        
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $report = [];
        while ($row = $result->fetch_assoc()) {
            $report[] = $row;
        }
        
        $stmt->close();
        return $report;
        
    } catch (Exception $e) {
        error_log("Error generating sales report: " . $e->getMessage());
        return [];
    }
}

/**
 * Validate user session
 */
function validateUserSession() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        return false;
    }
    return true;
}

/**
 * Check if user has required role
 */
function hasRole($required_role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $required_role;
}

/**
 * Log user activity
 */
function logActivity($user_id, $action, $action_type) {
    global $conn;
    
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, action, action_type, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("issss", $user_id, $action, $action_type, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Error logging activity: " . $e->getMessage());
    }
}

/**
 * Get order by ID
 */
function getOrderById($order_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT o.order_id, o.total_amount as total, o.payment_method, o.created_at, o.status,
                   c.first_name, c.last_name, c.email,
                   (SELECT status FROM payments p WHERE p.order_id = o.order_id LIMIT 1) as payment_status
            FROM orders o 
            LEFT JOIN customers c ON o.customer_id = c.customer_id 
            WHERE o.order_id = ?
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $order = $result->fetch_assoc();
        $stmt->close();
        return $order;
    } catch (Exception $e) {
        error_log("Error fetching order #$order_id: " . $e->getMessage());
        return false;
    }
}

/**
 * Create a new order
 */
function createOrder($user_id, $cart_items, $payment_method, $total, $customer_id = null) {
    global $conn;
    
    try {
        $conn->begin_transaction();
        
        // Validate cart items
        foreach ($cart_items as $index => $item) {
            if (!isset($item['product_id'], $item['quantity'], $item['price']) || 
                !is_numeric($item['product_id']) || 
                $item['quantity'] <= 0 || 
                $item['price'] <= 0) {
                $conn->rollback();
                error_log("Invalid cart item at index $index: " . json_encode($item));
                return false;
            }
        }
        
        // Verify product existence and stock
        foreach ($cart_items as $item) {
            $stmt = $conn->prepare("
                SELECT stock_quantity 
                FROM products 
                WHERE product_id = ? AND is_active = 1 AND deleted_at IS NULL
            ");
            $stmt->bind_param("i", $item['product_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $product = $result->fetch_assoc();
            $stmt->close();
            
            if (!$product) {
                $conn->rollback();
                error_log("Product not found or inactive for product_id {$item['product_id']}");
                return false;
            }
            
            if ($product['stock_quantity'] < $item['quantity']) {
                $conn->rollback();
                error_log("Insufficient stock for product_id {$item['product_id']}: requested={$item['quantity']}, available={$product['stock_quantity']}");
                return false;
            }
        }
        
        // Insert into orders table
        $stmt = $conn->prepare("
            INSERT INTO orders (user_id, customer_id, total, total_amount, payment_method, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->bind_param("iidds", $user_id, $customer_id, $total, $total, $payment_method);
        $stmt->execute();
        $order_id = $conn->insert_id;
        $stmt->close();
        
        // Insert order items
        $stmt = $conn->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, price)
            VALUES (?, ?, ?, ?)
        ");
        
        foreach ($cart_items as $item) {
            $stmt->bind_param("iiid", $order_id, $item['product_id'], $item['quantity'], $item['price']);
            $stmt->execute();
            
            // Update stock quantity
            $update_stmt = $conn->prepare("
                UPDATE products 
                SET stock_quantity = stock_quantity - ? 
                WHERE product_id = ? AND stock_quantity >= ?
            ");
            $update_stmt->bind_param("iii", $item['quantity'], $item['product_id'], $item['quantity']);
            $update_stmt->execute();
            
            if ($update_stmt->affected_rows === 0) {
                $conn->rollback();
                error_log("Stock update failed for product_id {$item['product_id']} in order #$order_id: requested={$item['quantity']}");
                return false;
            }
            $update_stmt->close();
        }
        
        $stmt->close();
        $conn->commit();
        error_log("Order created successfully: #$order_id, user_id=$user_id, customer_id=$customer_id, total=$total, payment_method=$payment_method");
        return $order_id;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error creating order for user #$user_id: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark order as collected
 */
function markOrderAsCollected($order_id) {
    global $conn;
    $stmt = $conn->prepare("UPDATE orders SET collected = 'yes', updated_at = NOW() WHERE order_id = ? AND collected != 'yes'");
    if (!$stmt) return false;
    $stmt->bind_param('i', $order_id);
    $success = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $success && ($affected > 0);
}
?>