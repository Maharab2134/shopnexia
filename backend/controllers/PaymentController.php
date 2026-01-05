<?php
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Payment.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AdminMiddleware.php';

class PaymentController {
    private $orderModel;
    private $paymentModel;
    private $auth;
    
    public function __construct() {
        $this->orderModel = new Order();
        $this->paymentModel = new Payment();
        $this->auth = new AuthMiddleware();
    }
    
    public function initiatePayment($orderId) {
        $userData = $this->auth->authenticate();
        
        // Verify order belongs to user
        $order = $this->orderModel->getOrderDetails($orderId, $userData->user_id);
        if (!$order) {
            $this->sendResponse(404, ['error' => 'Order not found']);
        }
        
        // Check if payment already exists
        $existingPayment = $this->paymentModel->getByOrderId($orderId);
        if ($existingPayment) {
            $this->sendResponse(400, ['error' => 'Payment already initiated']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate based on payment method
        $paymentMethod = $order['payment_method'];
        $validationErrors = $this->validatePaymentData($paymentMethod, $data);
        
        if (!empty($validationErrors)) {
            $this->sendResponse(400, ['errors' => $validationErrors]);
        }
        
        // Create payment record
        $paymentData = [
            'order_id' => $orderId,
            'payment_method' => $paymentMethod,
            'amount' => $order['total_amount'],
            'sender_number' => $data['sender_number'] ?? null,
            'transaction_number' => $data['transaction_number'] ?? null,
            'payment_date' => date('Y-m-d H:i:s')
        ];
        
        $paymentId = $this->paymentModel->createPayment($paymentData);
        
        // Update order payment status
        $this->orderModel->updatePaymentStatus($orderId, 'processing');
        
        $this->sendResponse(201, [
            'message' => 'Payment initiated successfully',
            'payment_id' => $paymentId,
            'payment_status' => 'pending'
        ]);
    }
    
    public function verifyPayment($orderId) {
        $userData = $this->auth->authenticate();
        
        // Verify order belongs to user
        $order = $this->orderModel->getOrderDetails($orderId, $userData->user_id);
        if (!$order) {
            $this->sendResponse(404, ['error' => 'Order not found']);
        }
        
        $payment = $this->paymentModel->getByOrderId($orderId);
        if (!$payment) {
            $this->sendResponse(404, ['error' => 'Payment not found']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // User can only verify COD payments or provide additional info
        if ($order['payment_method'] === 'cod') {
            // For COD, user can confirm receipt
            if (isset($data['confirm_receipt']) && $data['confirm_receipt']) {
                $this->paymentModel->updatePaymentStatus($payment['id'], 'verified');
                $this->orderModel->updatePaymentStatus($orderId, 'completed');
                
                $this->sendResponse(200, ['message' => 'Payment confirmed']);
            }
        }
        
        // For other methods, user can only check status
        $this->sendResponse(200, [
            'payment' => $payment,
            'message' => 'Payment verification is pending admin approval'
        ]);
    }
    
    public function getPaymentStatus($orderId) {
        $userData = $this->auth->authenticate();
        
        // Verify order belongs to user
        $order = $this->orderModel->getOrderDetails($orderId, $userData->user_id);
        if (!$order) {
            $this->sendResponse(404, ['error' => 'Order not found']);
        }
        
        $payment = $this->paymentModel->getByOrderId($orderId);
        
        $this->sendResponse(200, [
            'payment_status' => $order['payment_status'],
            'payment_details' => $payment
        ]);
    }
    
    // Admin methods
    public function adminGetPending() {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $payments = $this->paymentModel->getPendingPayments($limit, $offset);
        $total = $this->paymentModel->countPendingPayments();
        
        $this->sendResponse(200, [
            'payments' => $payments,
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
        
        $payment = $this->paymentModel->getById($id);
        if (!$payment) {
            $this->sendResponse(404, ['error' => 'Payment not found']);
        }
        
        // Get order details
        $order = $this->orderModel->getOrderDetails($payment['order_id'], null);
        
        $this->sendResponse(200, [
            'payment' => $payment,
            'order' => $order
        ]);
    }
    
    public function adminVerify($id) {
        $auth = new AdminMiddleware();
        $adminData = $auth->authenticate();
        
        $payment = $this->paymentModel->getById($id);
        if (!$payment) {
            $this->sendResponse(404, ['error' => 'Payment not found']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Verify payment
        $verificationData = [
            'status' => 'verified',
            'verified_by' => $adminData->admin_id,
            'verification_date' => date('Y-m-d H:i:s'),
            'notes' => $data['notes'] ?? null
        ];
        
        $this->paymentModel->verifyPayment($id, $verificationData);
        
        // Update order payment status
        $this->orderModel->updatePaymentStatus($payment['order_id'], 'completed');
        
        $this->sendResponse(200, ['message' => 'Payment verified successfully']);
    }
    
    public function adminReject($id) {
        $auth = new AdminMiddleware();
        $adminData = $auth->authenticate();
        
        $payment = $this->paymentModel->getById($id);
        if (!$payment) {
            $this->sendResponse(404, ['error' => 'Payment not found']);
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['reason'])) {
            $this->sendResponse(400, ['error' => 'Rejection reason is required']);
        }
        
        // Reject payment
        $rejectionData = [
            'status' => 'rejected',
            'verified_by' => $adminData->admin_id,
            'verification_date' => date('Y-m-d H:i:s'),
            'notes' => $data['reason']
        ];
        
        $this->paymentModel->verifyPayment($id, $rejectionData);
        
        // Update order payment status
        $this->orderModel->updatePaymentStatus($payment['order_id'], 'failed');
        
        $this->sendResponse(200, ['message' => 'Payment rejected']);
    }
    
    public function adminGetReport() {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo = $_GET['date_to'] ?? date('Y-m-d');
        
        $report = $this->paymentModel->getPaymentReport($dateFrom, $dateTo);
        
        $this->sendResponse(200, ['report' => $report]);
    }
    
    private function validatePaymentData($method, $data) {
        $errors = [];
        
        switch ($method) {
            case 'bkash':
            case 'nagad':
            case 'rocket':
                if (empty($data['sender_number'])) {
                    $errors[] = 'Sender number is required';
                }
                if (empty($data['transaction_number'])) {
                    $errors[] = 'Transaction number is required';
                }
                break;
                
            case 'cod':
                // No validation needed for COD
                break;
                
            default:
                $errors[] = 'Invalid payment method';
        }
        
        return $errors;
    }
    
    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
?>