<?php
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class OrderController {
    private $orderModel;
    private $auth;
    
    public function __construct() {
        $this->orderModel = new Order();
        $this->auth = new AuthMiddleware();
    }
    
    public function getUserOrders() {
        $userData = $this->auth->authenticate();
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        
        $result = $this->orderModel->getUserOrders($userData->user_id, $limit, ($page - 1) * $limit);
        
        $this->sendResponse(200, [
            'orders' => $result['orders'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($result['total'] / $limit)
            ]
        ]);
    }
    
    public function getOrderDetails($id) {
        $userData = $this->auth->authenticate();
        $order = $this->orderModel->getOrderDetails($id, $userData->user_id);
        
        if (!$order) {
            $this->sendResponse(404, ['error' => 'Order not found']);
        }
        
        $this->sendResponse(200, ['order' => $order]);
    }
    
    public function createOrder() {
        $userData = $this->auth->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required data
        $required = ['address_id', 'items', 'payment_method', 'total_amount'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                $this->sendResponse(400, ['error' => "$field is required"]);
            }
        }
        
        // Validate items array
        if (!is_array($data['items']) || empty($data['items'])) {
            $this->sendResponse(400, ['error' => 'Order items are required']);
        }
        
        try {
            $result = $this->orderModel->createOrder($userData->user_id, $data);
            
            $this->sendResponse(201, [
                'message' => 'Order created successfully',
                'order_id' => $result['order_id'],
                'order_number' => $result['order_number']
            ]);
        } catch (Exception $e) {
            $this->sendResponse(400, ['error' => $e->getMessage()]);
        }
    }
    
    public function cancelOrder($id) {
        $userData = $this->auth->authenticate();
        
        try {
            $this->orderModel->cancelOrder($id, $userData->user_id);
            $this->sendResponse(200, ['message' => 'Order cancelled successfully']);
        } catch (Exception $e) {
            $this->sendResponse(400, ['error' => $e->getMessage()]);
        }
    }
    
    public function trackOrder($id) {
        $userData = $this->auth->authenticate();
        $order = $this->orderModel->getOrderDetails($id, $userData->user_id);
        
        if (!$order) {
            $this->sendResponse(404, ['error' => 'Order not found']);
        }
        
        $trackingInfo = [
            'order_status' => $order['order_status'],
            'created_at' => $order['created_at'],
            'estimated_delivery' => $order['courier']['estimated_delivery'] ?? null,
            'tracking_number' => $order['courier']['tracking_number'] ?? null,
            'tracking_url' => $order['courier']['tracking_url'] ?? null,
            'status_history' => $this->getOrderStatusHistory($id)
        ];
        
        $this->sendResponse(200, ['tracking' => $trackingInfo]);
    }
    
    // Admin methods
    public function adminGetAll() {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;
        
        $filters = $this->buildOrderFilters();
        $result = $this->getAllOrders($filters, $limit, $offset);
        
        $this->sendResponse(200, [
            'orders' => $result['orders'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($result['total'] / $limit)
            ]
        ]);
    }
    
    public function adminUpdateStatus($id) {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['status'])) {
            $this->sendResponse(400, ['error' => 'Status is required']);
        }
        
        try {
            $this->orderModel->updateOrderStatus($id, $data['status']);
            $this->sendResponse(200, ['message' => 'Order status updated']);
        } catch (Exception $e) {
            $this->sendResponse(400, ['error' => $e->getMessage()]);
        }
    }
    
    public function adminAssignCourier($id) {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['courier_name', 'tracking_number', 'delivery_charge', 'estimated_delivery'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->sendResponse(400, ['error' => "$field is required"]);
            }
        }
        
        $courierData = [
            'courier_name' => $data['courier_name'],
            'tracking_number' => $data['tracking_number'],
            'tracking_url' => $data['tracking_url'] ?? null,
            'delivery_charge' => $data['delivery_charge'],
            'estimated_delivery' => $data['estimated_delivery'],
            'assigned_by' => $auth->authenticate()->admin_id
        ];
        
        try {
            $this->orderModel->assignCourier($id, $courierData);
            $this->sendResponse(200, ['message' => 'Courier assigned successfully']);
        } catch (Exception $e) {
            $this->sendResponse(400, ['error' => $e->getMessage()]);
        }
    }
    
    public function adminExport() {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $filters = $this->buildOrderFilters();
        $orders = $this->getAllOrdersForExport($filters);
        
        // Generate CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="orders_export_' . date('Ymd_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, [
            'Order Number', 'Customer', 'Email', 'Phone', 'Total Amount',
            'Payment Method', 'Order Status', 'Payment Status', 'Created Date'
        ]);
        
        // Data
        foreach ($orders as $order) {
            fputcsv($output, [
                $order['order_number'],
                $order['customer_name'],
                $order['customer_email'],
                $order['customer_phone'],
                $order['total_amount'],
                $order['payment_method'],
                $order['order_status'],
                $order['payment_status'],
                $order['created_at']
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    private function getOrderStatusHistory($orderId) {
        // This would typically come from an order_status_history table
        // For now, return a simple array
        return [
            ['status' => 'pending', 'timestamp' => date('Y-m-d H:i:s')],
            ['status' => 'confirmed', 'timestamp' => date('Y-m-d H:i:s', strtotime('+1 hour'))]
        ];
    }
    
    private function buildOrderFilters() {
        $filters = [];
        
        if (!empty($_GET['status'])) {
            $filters['order_status'] = $_GET['status'];
        }
        
        if (!empty($_GET['payment_status'])) {
            $filters['payment_status'] = $_GET['payment_status'];
        }
        
        if (!empty($_GET['date_from'])) {
            $filters['date_from'] = $_GET['date_from'];
        }
        
        if (!empty($_GET['date_to'])) {
            $filters['date_to'] = $_GET['date_to'];
        }
        
        if (!empty($_GET['search'])) {
            $filters['search'] = $_GET['search'];
        }
        
        return $filters;
    }
    
    private function getAllOrders($filters, $limit, $offset) {
        $sql = "SELECT o.*, u.name as customer_name, u.email as customer_email 
                FROM orders o 
                JOIN users u ON o.user_id = u.id";
        
        $whereClauses = [];
        $params = [];
        
        foreach ($filters as $field => $value) {
            if ($field === 'date_from') {
                $whereClauses[] = "DATE(o.created_at) >= ?";
                $params[] = $value;
            } elseif ($field === 'date_to') {
                $whereClauses[] = "DATE(o.created_at) <= ?";
                $params[] = $value;
            } elseif ($field === 'search') {
                $whereClauses[] = "(o.order_number LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
                $searchTerm = "%$value%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            } else {
                $whereClauses[] = "o.$field = ?";
                $params[] = $value;
            }
        }
        
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        $sql .= " ORDER BY o.created_at DESC";
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM orders o JOIN users u ON o.user_id = u.id";
        if (!empty($whereClauses)) {
            $countSql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        $countStmt = $this->orderModel->executeQuery($countSql, $params);
        $total = $countStmt->fetch()['total'];
        
        // Add limit and offset
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->orderModel->executeQuery($sql, $params);
        $orders = $stmt->fetchAll();
        
        return [
            'orders' => $orders,
            'total' => $total
        ];
    }
    
    private function getAllOrdersForExport($filters) {
        $sql = "SELECT o.*, u.name as customer_name, u.email as customer_email, u.phone as customer_phone 
                FROM orders o 
                JOIN users u ON o.user_id = u.id";
        
        $whereClauses = [];
        $params = [];
        
        foreach ($filters as $field => $value) {
            if ($field === 'date_from') {
                $whereClauses[] = "DATE(o.created_at) >= ?";
                $params[] = $value;
            } elseif ($field === 'date_to') {
                $whereClauses[] = "DATE(o.created_at) <= ?";
                $params[] = $value;
            } elseif ($field === 'search') {
                $whereClauses[] = "(o.order_number LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
                $searchTerm = "%$value%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            } else {
                $whereClauses[] = "o.$field = ?";
                $params[] = $value;
            }
        }
        
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        $sql .= " ORDER BY o.created_at DESC";
        
        $stmt = $this->orderModel->executeQuery($sql, $params);
        return $stmt->fetchAll();
    }
    
    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
?>