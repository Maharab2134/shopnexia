<?php
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Return.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AdminMiddleware.php';

class ReturnController {
    private $orderModel;
    private $returnModel;
    private $auth;
    
    public function __construct() {
        $this->orderModel = new Order();
        $this->returnModel = new Return();
        $this->auth = new AuthMiddleware();
    }
    
    public function getUserReturns() {
        $userData = $this->auth->authenticate();
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        
        $returns = $this->returnModel->getUserReturns($userData->user_id, $limit, $offset);
        $total = $this->returnModel->countUserReturns($userData->user_id);
        
        $this->sendResponse(200, [
            'returns' => $returns,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }
    
    public function requestReturn() {
        $userData = $this->auth->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['order_id', 'product_id', 'quantity', 'reason'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->sendResponse(400, ['error' => "$field is required"]);
            }
        }
        
        // Verify order belongs to user and can be returned
        $order = $this->orderModel->getOrderDetails($data['order_id'], $userData->user_id);
        if (!$order) {
            $this->sendResponse(404, ['error' => 'Order not found']);
        }
        
        // Check if order can be returned (within return period, etc.)
        if (!$this->isOrderReturnable($order)) {
            $this->sendResponse(400, ['error' => 'This order cannot be returned']);
        }
        
        // Check if product exists in order
        $productInOrder = false;
        foreach ($order['items'] as $item) {
            if ($item['product_id'] == $data['product_id']) {
                $productInOrder = true;
                // Verify quantity
                if ($item['quantity'] < $data['quantity']) {
                    $this->sendResponse(400, ['error' => 'Return quantity exceeds ordered quantity']);
                }
                break;
            }
        }
        
        if (!$productInOrder) {
            $this->sendResponse(400, ['error' => 'Product not found in order']);
        }
        
        // Check if return already requested for this product
        $existingReturn = $this->returnModel->getExistingReturn($data['order_id'], $data['product_id']);
        if ($existingReturn) {
            $this->sendResponse(400, ['error' => 'Return already requested for this product']);
        }
        
        // Create return request
        $returnData = [
            'order_id' => $data['order_id'],
            'user_id' => $userData->user_id,
            'product_id' => $data['product_id'],
            'quantity' => $data['quantity'],
            'reason' => $data['reason'],
            'notes' => $data['notes'] ?? null,
            'return_number' => 'RET' . date('Ymd') . strtoupper(uniqid())
        ];
        
        $returnId = $this->returnModel->createReturn($returnData);
        
        $this->sendResponse(201, [
            'message' => 'Return request submitted successfully',
            'return_id' => $returnId,
            'return_number' => $returnData['return_number']
        ]);
    }
    
    public function getReturnDetails($id) {
        $userData = $this->auth->authenticate();
        $return = $this->returnModel->getReturnDetails($id, $userData->user_id);
        
        if (!$return) {
            $this->sendResponse(404, ['error' => 'Return request not found']);
        }
        
        $this->sendResponse(200, ['return' => $return]);
    }
    
    public function cancelReturnRequest($id) {
        $userData = $this->auth->authenticate();
        
        $return = $this->returnModel->getReturnDetails($id, $userData->user_id);
        if (!$return) {
            $this->sendResponse(404, ['error' => 'Return request not found']);
        }
        
        // Check if return can be cancelled
        if (!in_array($return['return_status'], ['requested', 'approved'])) {
            $this->sendResponse(400, ['error' => 'Cannot cancel return at this stage']);
        }
        
        $this->returnModel->cancelReturn($id);
        
        $this->sendResponse(200, ['message' => 'Return request cancelled']);
    }
    
    public function getRefundStatus($returnId) {
        $userData = $this->auth->authenticate();
        
        $refund = $this->returnModel->getRefundDetails($returnId, $userData->user_id);
        
        if (!$refund) {
            $this->sendResponse(404, ['error' => 'Refund not found']);
        }
        
        $this->sendResponse(200, ['refund' => $refund]);
    }
    
    // Admin methods
    public function adminGetPending() {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        
        $returns = $this->returnModel->getPendingReturns($limit, $offset);
        $total = $this->returnModel->countPendingReturns();
        
        $this->sendResponse(200, [
            'returns' => $returns,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }
    
    public function adminGetDetails($id) {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $return = $this->returnModel->getReturnDetails($id);
        
        if (!$return) {
            $this->sendResponse(404, ['error' => 'Return request not found']);
        }
        
        $this->sendResponse(200, ['return' => $return]);
    }
    
    public function adminApproveReturn($id) {
        $auth = new AdminMiddleware();
        $adminData = $auth->authenticate();
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['action'])) {
            $this->sendResponse(400, ['error' => 'Action is required']);
        }
        
        $return = $this->returnModel->getReturnDetails($id);
        if (!$return) {
            $this->sendResponse(404, ['error' => 'Return request not found']);
        }
        
        if ($data['action'] === 'approve') {
            $this->returnModel->approveReturn($id, $adminData->admin_id, $data['notes'] ?? null);
            $this->sendResponse(200, ['message' => 'Return approved']);
        } elseif ($data['action'] === 'reject') {
            if (empty($data['reason'])) {
                $this->sendResponse(400, ['error' => 'Rejection reason is required']);
            }
            $this->returnModel->rejectReturn($id, $adminData->admin_id, $data['reason']);
            $this->sendResponse(200, ['message' => 'Return rejected']);
        } else {
            $this->sendResponse(400, ['error' => 'Invalid action']);
        }
    }
    
    public function adminMarkReceived($id) {
        $auth = new AdminMiddleware();
        $adminData = $auth->authenticate();
        
        $return = $this->returnModel->getReturnDetails($id);
        if (!$return) {
            $this->sendResponse(404, ['error' => 'Return request not found']);
        }
        
        $this->returnModel->markAsReceived($id, $adminData->admin_id);
        
        // Restore product stock
        $this->restoreProductStock($return);
        
        $this->sendResponse(200, ['message' => 'Return marked as received']);
    }
    
    public function adminProcessRefund() {
        $auth = new AdminMiddleware();
        $adminData = $auth->authenticate();
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['return_id', 'refund_method', 'refund_amount'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->sendResponse(400, ['error' => "$field is required"]);
            }
        }
        
        $return = $this->returnModel->getReturnDetails($data['return_id']);
        if (!$return) {
            $this->sendResponse(404, ['error' => 'Return request not found']);
        }
        
        // Process refund
        $refundData = [
            'return_id' => $data['return_id'],
            'refund_method' => $data['refund_method'],
            'refund_amount' => $data['refund_amount'],
            'transaction_number' => $data['transaction_number'] ?? null,
            'refund_date' => date('Y-m-d H:i:s'),
            'processed_by' => $adminData->admin_id,
            'notes' => $data['notes'] ?? null
        ];
        
        $refundId = $this->returnModel->processRefund($refundData);
        
        $this->sendResponse(201, [
            'message' => 'Refund processed successfully',
            'refund_id' => $refundId
        ]);
    }
    
    public function adminUpdateRefundStatus($id) {
        $auth = new AdminMiddleware();
        $adminData = $auth->authenticate();
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['status'])) {
            $this->sendResponse(400, ['error' => 'Status is required']);
        }
        
        $validStatuses = ['pending', 'processing', 'completed', 'failed'];
        if (!in_array($data['status'], $validStatuses)) {
            $this->sendResponse(400, ['error' => 'Invalid status']);
        }
        
        $this->returnModel->updateRefundStatus($id, $data['status'], $adminData->admin_id);
        
        $this->sendResponse(200, ['message' => 'Refund status updated']);
    }
    
    private function isOrderReturnable($order) {
        // Check if order was delivered
        if ($order['order_status'] !== 'delivered') {
            return false;
        }
        
        // Check if within return period (e.g., 7 days from delivery)
        $deliveryDate = strtotime($order['courier']['actual_delivery'] ?? $order['updated_at']);
        $returnDeadline = strtotime('+7 days', $deliveryDate);
        
        if (time() > $returnDeadline) {
            return false;
        }
        
        return true;
    }
    
    private function restoreProductStock($return) {
        $productModel = new Product();
        $productModel->updateStock($return['product_id'], $return['quantity'], 'add');
    }
    
    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
?>