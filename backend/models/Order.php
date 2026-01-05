<?php
require_once 'Model.php';

class Order extends Model {
    public function __construct() {
        parent::__construct('orders');
    }
    
    public function createOrder($userId, $data) {
        $orderNumber = 'ORD' . date('Ymd') . strtoupper(uniqid());
        
        $orderData = [
            'order_number' => $orderNumber,
            'user_id' => $userId,
            'user_address_id' => $data['address_id'],
            'subtotal' => $data['subtotal'],
            'discount' => $data['discount'] ?? 0,
            'delivery_charge' => $data['delivery_charge'] ?? 0,
            'tax_amount' => $data['tax_amount'] ?? 0,
            'total_amount' => $data['total_amount'],
            'payment_method' => $data['payment_method'],
            'notes' => $data['notes'] ?? null
        ];
        
        $orderId = $this->create($orderData);
        
        // Create order items
        foreach ($data['items'] as $item) {
            $this->addOrderItem($orderId, $item);
        }
        
        return [
            'order_id' => $orderId,
            'order_number' => $orderNumber
        ];
    }
    
    private function addOrderItem($orderId, $item) {
        $sql = "INSERT INTO order_items (order_id, product_id, product_name, product_image, sku, barcode, quantity, unit_price, total_price) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $this->executeQuery($sql, [
            $orderId,
            $item['product_id'],
            $item['product_name'],
            $item['product_image'],
            $item['sku'],
            $item['barcode'],
            $item['quantity'],
            $item['unit_price'],
            $item['total_price']
        ]);
    }
    
    public function getUserOrders($userId, $limit = 20, $offset = 0) {
        $sql = "SELECT o.*, COUNT(oi.id) as item_count 
                FROM orders o 
                LEFT JOIN order_items oi ON o.id = oi.order_id 
                WHERE o.user_id = ? 
                GROUP BY o.id 
                ORDER BY o.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $this->executeQuery($sql, [$userId, $limit, $offset]);
        $orders = $stmt->fetchAll();
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM orders WHERE user_id = ?";
        $countStmt = $this->executeQuery($countSql, [$userId]);
        $total = $countStmt->fetch()['total'];
        
        return [
            'orders' => $orders,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
    
    public function getOrderDetails($orderId, $userId = null) {
        $sql = "SELECT o.*, 
                ua.full_name, ua.phone, ua.address_line1, ua.address_line2, ua.city, ua.division, ua.zip_code, ua.country,
                COUNT(oi.id) as item_count
                FROM orders o 
                JOIN user_addresses ua ON o.user_address_id = ua.id 
                LEFT JOIN order_items oi ON o.id = oi.order_id 
                WHERE o.id = ?";
        
        if ($userId) {
            $sql .= " AND o.user_id = ?";
            $stmt = $this->executeQuery($sql, [$orderId, $userId]);
        } else {
            $stmt = $this->executeQuery($sql, [$orderId]);
        }
        
        $order = $stmt->fetch();
        
        if ($order) {
            // Get order items
            $itemsSql = "SELECT oi.*, p.slug as product_slug 
                        FROM order_items oi 
                        LEFT JOIN products p ON oi.product_id = p.id 
                        WHERE oi.order_id = ?";
            $itemsStmt = $this->executeQuery($itemsSql, [$orderId]);
            $order['items'] = $itemsStmt->fetchAll();
            
            // Get payment info
            $paymentSql = "SELECT * FROM payments WHERE order_id = ?";
            $paymentStmt = $this->executeQuery($paymentSql, [$orderId]);
            $order['payment'] = $paymentStmt->fetch();
            
            // Get courier info
            $courierSql = "SELECT * FROM couriers WHERE order_id = ?";
            $courierStmt = $this->executeQuery($courierSql, [$orderId]);
            $order['courier'] = $courierStmt->fetch();
        }
        
        return $order;
    }
    
    public function updateOrderStatus($orderId, $status) {
        $validStatuses = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];
        
        if (!in_array($status, $validStatuses)) {
            throw new Exception("Invalid order status");
        }
        
        $sql = "UPDATE orders SET order_status = ?, updated_at = NOW() WHERE id = ?";
        $this->executeQuery($sql, [$status, $orderId]);
        
        // Log activity
        $this->logOrderActivity($orderId, "Order status updated to: $status");
        
        return true;
    }
    
    public function cancelOrder($orderId, $userId) {
        // Check if order can be cancelled (only pending or confirmed orders)
        $sql = "SELECT order_status FROM orders WHERE id = ? AND user_id = ?";
        $stmt = $this->executeQuery($sql, [$orderId, $userId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            throw new Exception("Order not found");
        }
        
        if (!in_array($order['order_status'], ['pending', 'confirmed'])) {
            throw new Exception("Order cannot be cancelled at this stage");
        }
        
        $sql = "UPDATE orders SET order_status = 'cancelled', updated_at = NOW() WHERE id = ?";
        $this->executeQuery($sql, [$orderId]);
        
        // Restore product stock
        $this->restoreStock($orderId);
        
        // Log activity
        $this->logOrderActivity($orderId, "Order cancelled by user");
        
        return true;
    }
    
    private function restoreStock($orderId) {
        $sql = "SELECT product_id, quantity FROM order_items WHERE order_id = ?";
        $stmt = $this->executeQuery($sql, [$orderId]);
        $items = $stmt->fetchAll();
        
        foreach ($items as $item) {
            $updateSql = "UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?";
            $this->executeQuery($updateSql, [$item['quantity'], $item['product_id']]);
        }
    }
    
    public function assignCourier($orderId, $courierData) {
        $sql = "INSERT INTO couriers (order_id, courier_name, tracking_number, tracking_url, delivery_charge, estimated_delivery, assigned_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $this->executeQuery($sql, [
            $orderId,
            $courierData['courier_name'],
            $courierData['tracking_number'],
            $courierData['tracking_url'] ?? null,
            $courierData['delivery_charge'],
            $courierData['estimated_delivery'],
            $courierData['assigned_by']
        ]);
        
        // Update order status to shipped
        $this->updateOrderStatus($orderId, 'shipped');
        
        return true;
    }
    
    public function getDashboardStats() {
        $stats = [];
        
        // Total orders
        $sql = "SELECT COUNT(*) as total FROM orders";
        $stmt = $this->executeQuery($sql);
        $stats['total_orders'] = $stmt->fetch()['total'];
        
        // Today's orders
        $sql = "SELECT COUNT(*) as total FROM orders WHERE DATE(created_at) = CURDATE()";
        $stmt = $this->executeQuery($sql);
        $stats['today_orders'] = $stmt->fetch()['total'];
        
        // Pending orders
        $sql = "SELECT COUNT(*) as total FROM orders WHERE order_status = 'pending'";
        $stmt = $this->executeQuery($sql);
        $stats['pending_orders'] = $stmt->fetch()['total'];
        
        // Total revenue
        $sql = "SELECT SUM(total_amount) as total FROM orders WHERE order_status = 'delivered'";
        $stmt = $this->executeQuery($sql);
        $stats['total_revenue'] = $stmt->fetch()['total'] ?? 0;
        
        // Today's revenue
        $sql = "SELECT SUM(total_amount) as total FROM orders WHERE DATE(created_at) = CURDATE() AND order_status = 'delivered'";
        $stmt = $this->executeQuery($sql);
        $stats['today_revenue'] = $stmt->fetch()['total'] ?? 0;
        
        return $stats;
    }
    
    public function getRecentOrders($limit = 10) {
        $sql = "SELECT o.*, u.name as customer_name, COUNT(oi.id) as item_count 
                FROM orders o 
                JOIN users u ON o.user_id = u.id 
                LEFT JOIN order_items oi ON o.id = oi.order_id 
                GROUP BY o.id 
                ORDER BY o.created_at DESC 
                LIMIT ?";
        
        $stmt = $this->executeQuery($sql, [$limit]);
        return $stmt->fetchAll();
    }
    
    public function getSalesChartData($period = '7days') {
        switch ($period) {
            case '7days':
                $interval = '7 DAY';
                break;
            case '30days':
                $interval = '30 DAY';
                break;
            case '12months':
                $interval = '12 MONTH';
                break;
            default:
                $interval = '7 DAY';
        }
        
        $sql = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as order_count,
                SUM(total_amount) as revenue
                FROM orders 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL $interval)
                GROUP BY DATE(created_at)
                ORDER BY date";
        
        $stmt = $this->executeQuery($sql);
        return $stmt->fetchAll();
    }
    
    private function logOrderActivity($orderId, $action) {
        $sql = "INSERT INTO activity_logs (user_type, action, description, created_at) 
                VALUES ('system', 'order_update', ?, NOW())";
        $this->executeQuery($sql, ["Order #{$orderId}: {$action}"]);
    }
}
?>