<?php
require_once 'Model.php';

class ReturnModel extends Model {
    public function __construct() {
        parent::__construct('returns');
    }
    
    public function getUserReturns($userId, $limit, $offset) {
        $sql = "SELECT r.*, o.order_number, p.name as product_name, p.main_image as product_image 
                FROM returns r 
                JOIN orders o ON r.order_id = o.id 
                JOIN products p ON r.product_id = p.id 
                WHERE r.user_id = ? 
                ORDER BY r.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $this->executeQuery($sql, [$userId, $limit, $offset]);
        return $stmt->fetchAll();
    }
    
    public function countUserReturns($userId) {
        $sql = "SELECT COUNT(*) as total FROM returns WHERE user_id = ?";
        $stmt = $this->executeQuery($sql, [$userId]);
        return $stmt->fetch()['total'];
    }
    
    public function getReturnDetails($returnId, $userId = null) {
        $sql = "SELECT r.*, o.order_number, o.total_amount, 
                p.name as product_name, p.main_image as product_image, p.sku,
                u.name as customer_name, u.email as customer_email, u.phone as customer_phone
                FROM returns r 
                JOIN orders o ON r.order_id = o.id 
                JOIN products p ON r.product_id = p.id 
                JOIN users u ON r.user_id = u.id 
                WHERE r.id = ?";
        
        $params = [$returnId];
        
        if ($userId !== null) {
            $sql .= " AND r.user_id = ?";
            $params[] = $userId;
        }
        
        $stmt = $this->executeQuery($sql, $params);
        $return = $stmt->fetch();
        
        if ($return) {
            // Get refund details if exists
            $refundSql = "SELECT * FROM refunds WHERE return_id = ?";
            $refundStmt = $this->executeQuery($refundSql, [$returnId]);
            $return['refund'] = $refundStmt->fetch();
        }
        
        return $return;
    }
    
    public function getExistingReturn($orderId, $productId) {
        $sql = "SELECT * FROM returns 
                WHERE order_id = ? AND product_id = ? 
                AND return_status IN ('requested', 'approved', 'received')";
        
        $stmt = $this->executeQuery($sql, [$orderId, $productId]);
        return $stmt->fetch();
    }
    
    public function createReturn($data) {
        return $this->create($data);
    }
    
    public function cancelReturn($returnId) {
        $this->update($returnId, [
            'return_status' => 'cancelled',
            'refund_status' => 'rejected'
        ]);
    }
    
    public function getRefundDetails($returnId, $userId = null) {
        $sql = "SELECT rf.*, r.return_number 
                FROM refunds rf 
                JOIN returns r ON rf.return_id = r.id 
                WHERE rf.return_id = ?";
        
        $params = [$returnId];
        
        if ($userId !== null) {
            $sql .= " AND r.user_id = ?";
            $params[] = $userId;
        }
        
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetch();
    }
    
    // Admin methods
    public function getPendingReturns($limit, $offset) {
        $sql = "SELECT r.*, o.order_number, p.name as product_name, 
                u.name as customer_name, u.email as customer_email 
                FROM returns r 
                JOIN orders o ON r.order_id = o.id 
                JOIN products p ON r.product_id = p.id 
                JOIN users u ON r.user_id = u.id 
                WHERE r.return_status = 'requested' 
                ORDER BY r.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $this->executeQuery($sql, [$limit, $offset]);
        return $stmt->fetchAll();
    }
    
    public function countPendingReturns() {
        $sql = "SELECT COUNT(*) as total FROM returns WHERE return_status = 'requested'";
        $stmt = $this->executeQuery($sql);
        return $stmt->fetch()['total'];
    }
    
    public function approveReturn($returnId, $adminId, $notes = null) {
        $this->update($returnId, [
            'return_status' => 'approved',
            'refund_status' => 'approved',
            'approved_by' => $adminId,
            'approved_at' => date('Y-m-d H:i:s'),
            'notes' => $notes
        ]);
    }
    
    public function rejectReturn($returnId, $adminId, $reason) {
        $this->update($returnId, [
            'return_status' => 'rejected',
            'refund_status' => 'rejected',
            'approved_by' => $adminId,
            'approved_at' => date('Y-m-d H:i:s'),
            'notes' => $reason
        ]);
    }
    
    public function markAsReceived($returnId, $adminId) {
        $this->update($returnId, [
            'return_status' => 'received',
            'received_at' => date('Y-m-d H:i:s'),
            'notes' => 'Product received for return'
        ]);
    }
    
    public function processRefund($data) {
        $refundModel = new Model('refunds');
        return $refundModel->create($data);
    }
    
    public function updateRefundStatus($refundId, $status, $adminId = null) {
        $updateData = ['status' => $status];
        
        if ($status === 'completed' && $adminId) {
            $updateData['processed_by'] = $adminId;
        }
        
        $refundModel = new Model('refunds');
        $refundModel->update($refundId, $updateData);
        
        // Update return refund status
        $refund = $refundModel->find($refundId);
        if ($refund) {
            $this->update($refund['return_id'], ['refund_status' => $status]);
        }
    }
}
?>