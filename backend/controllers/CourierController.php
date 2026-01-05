<?php
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Courier.php';
require_once __DIR__ . '/../middleware/AdminMiddleware.php';

class CourierController {
    private $orderModel;
    private $courierModel;
    private $auth;
    
    public function __construct() {
        $this->orderModel = new Order();
        $this->courierModel = new Courier();
        $this->auth = new AdminMiddleware();
    }
    
    public function assignCourier($orderId) {
        $auth = new AdminMiddleware();
        $adminData = $auth->authenticate();
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['courier_name', 'tracking_number', 'delivery_charge', 'estimated_delivery'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->sendResponse(400, ['error' => "$field is required"]);
            }
        }
        
        // Verify order exists
        $order = $this->orderModel->getOrderDetails($orderId);
        if (!$order) {
            $this->sendResponse(404, ['error' => 'Order not found']);
        }
        
        // Check if courier already assigned
        $existingCourier = $this->courierModel->getByOrderId($orderId);
        if ($existingCourier) {
            $this->sendResponse(400, ['error' => 'Courier already assigned to this order']);
        }
        
        // Assign courier
        $courierData = [
            'order_id' => $orderId,
            'courier_name' => $data['courier_name'],
            'tracking_number' => $data['tracking_number'],
            'tracking_url' => $data['tracking_url'] ?? $this->generateTrackingUrl($data['courier_name'], $data['tracking_number']),
            'delivery_charge' => $data['delivery_charge'],
            'estimated_delivery' => $data['estimated_delivery'],
            'assigned_by' => $adminData->admin_id,
            'notes' => $data['notes'] ?? null
        ];
        
        $courierId = $this->courierModel->assignCourier($courierData);
        
        // Update order status to shipped
        $this->orderModel->updateOrderStatus($orderId, 'shipped');
        
        $this->sendResponse(201, [
            'message' => 'Courier assigned successfully',
            'courier_id' => $courierId,
            'tracking_number' => $data['tracking_number']
        ]);
    }
    
    public function updateCourierStatus($courierId) {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['status'])) {
            $this->sendResponse(400, ['error' => 'Status is required']);
        }
        
        $validStatuses = ['pending', 'picked', 'in_transit', 'delivered', 'failed'];
        if (!in_array($data['status'], $validStatuses)) {
            $this->sendResponse(400, ['error' => 'Invalid status']);
        }
        
        $courier = $this->courierModel->getById($courierId);
        if (!$courier) {
            $this->sendResponse(404, ['error' => 'Courier record not found']);
        }
        
        $updateData = ['status' => $data['status']];
        
        // If delivered, update delivery date
        if ($data['status'] === 'delivered') {
            $updateData['actual_delivery'] = date('Y-m-d H:i:s');
            
            // Update order status to delivered
            $this->orderModel->updateOrderStatus($courier['order_id'], 'delivered');
        }
        
        // If failed, add notes
        if ($data['status'] === 'failed' && !empty($data['notes'])) {
            $updateData['notes'] = $data['notes'];
        }
        
        $this->courierModel->update($courierId, $updateData);
        
        $this->sendResponse(200, ['message' => 'Courier status updated']);
    }
    
    public function getCourierTracking($orderId) {
        // This can be accessed by both users and admins
        try {
            $auth = new AuthMiddleware();
            $userData = $auth->authenticate();
            
            // Verify order belongs to user
            $order = $this->orderModel->getOrderDetails($orderId, $userData->user_id);
            if (!$order) {
                $this->sendResponse(404, ['error' => 'Order not found']);
            }
        } catch (Exception $e) {
            // If not authenticated as user, check if admin
            try {
                $adminAuth = new AdminMiddleware();
                $adminAuth->authenticate();
                
                // Admin can view any order
                $order = $this->orderModel->getOrderDetails($orderId);
                if (!$order) {
                    $this->sendResponse(404, ['error' => 'Order not found']);
                }
            } catch (Exception $e) {
                $this->sendResponse(401, ['error' => 'Unauthorized access']);
            }
        }
        
        $courier = $this->courierModel->getByOrderId($orderId);
        
        if (!$courier) {
            $this->sendResponse(404, ['error' => 'No courier assigned to this order']);
        }
        
        $trackingInfo = [
            'courier_name' => $courier['courier_name'],
            'tracking_number' => $courier['tracking_number'],
            'tracking_url' => $courier['tracking_url'],
            'status' => $courier['status'],
            'estimated_delivery' => $courier['estimated_delivery'],
            'actual_delivery' => $courier['actual_delivery'],
            'delivery_charge' => $courier['delivery_charge'],
            'status_history' => $this->getCourierStatusHistory($courierId)
        ];
        
        $this->sendResponse(200, ['tracking' => $trackingInfo]);
    }
    
    public function getAllCouriers() {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $filters = $this->buildCourierFilters();
        $result = $this->courierModel->getAllCouriers($filters, $limit, $offset);
        
        $this->sendResponse(200, [
            'couriers' => $result['couriers'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($result['total'] / $limit)
            ]
        ]);
    }
    
    public function getCourierStats() {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $stats = $this->courierModel->getStatistics();
        
        $this->sendResponse(200, ['stats' => $stats]);
    }
    
    public function updateTrackingInfo($courierId) {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $updateData = [];
        $allowedFields = ['tracking_number', 'tracking_url', 'estimated_delivery', 'notes'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (!empty($updateData)) {
            $this->courierModel->update($courierId, $updateData);
        }
        
        $this->sendResponse(200, ['message' => 'Tracking information updated']);
    }
    
    private function generateTrackingUrl($courierName, $trackingNumber) {
        $urls = [
            'pathao' => "https://pathao.com/track/{$trackingNumber}",
            'local' => "#",
            'others' => "#"
        ];
        
        return $urls[$courierName] ?? "#";
    }
    
    private function getCourierStatusHistory($courierId) {
        // This would typically come from a courier_status_history table
        // For now, return mock data
        return [
            ['status' => 'pending', 'timestamp' => date('Y-m-d H:i:s', strtotime('-2 days'))],
            ['status' => 'picked', 'timestamp' => date('Y-m-d H:i:s', strtotime('-1 day'))],
            ['status' => 'in_transit', 'timestamp' => date('Y-m-d H:i:s')]
        ];
    }
    
    private function buildCourierFilters() {
        $filters = [];
        
        if (!empty($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }
        
        if (!empty($_GET['courier_name'])) {
            $filters['courier_name'] = $_GET['courier_name'];
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
    
    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
?>