<?php
require_once 'Model.php';

class Payment extends Model {
    public function __construct() {
        parent::__construct('payments');
    }
    
    public function getByOrderId($orderId) {
        $sql = "SELECT * FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1";
        $stmt = $this->executeQuery($sql, [$orderId]);
        return $stmt->fetch();
    }
    
    public function createPayment($data) {
        return $this->create($data);
    }
    
    public function updatePaymentStatus($id, $status) {
        $sql = "UPDATE payments SET status = ? WHERE id = ?";
        $this->executeQuery($sql, [$status, $id]);
    }
    
    public function verifyPayment($id, $data) {
        $this->update($id, $data);
    }
    
    public function getPendingPayments($limit, $offset) {
        $sql = "SELECT p.*, o.order_number, u.name as customer_name 
                FROM payments p 
                JOIN orders o ON p.order_id = o.id 
                JOIN users u ON o.user_id = u.id 
                WHERE p.status = 'pending' 
                ORDER BY p.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $this->executeQuery($sql, [$limit, $offset]);
        return $stmt->fetchAll();
    }
    
    public function countPendingPayments() {
        $sql = "SELECT COUNT(*) as total FROM payments WHERE status = 'pending'";
        $stmt = $this->executeQuery($sql);
        return $stmt->fetch()['total'];
    }
    
    public function getPaymentReport($dateFrom, $dateTo) {
        // Daily payment summary
        $sql = "SELECT 
                DATE(p.created_at) as date,
                COUNT(*) as payment_count,
                SUM(p.amount) as total_amount,
                p.payment_method,
                p.status
                FROM payments p 
                WHERE DATE(p.created_at) BETWEEN ? AND ?
                GROUP BY DATE(p.created_at), p.payment_method, p.status
                ORDER BY date DESC";
        
        $stmt = $this->executeQuery($sql, [$dateFrom, $dateTo]);
        $dailySummary = $stmt->fetchAll();
        
        // Payment method summary
        $sql = "SELECT 
                payment_method,
                COUNT(*) as payment_count,
                SUM(amount) as total_amount,
                AVG(amount) as average_amount
                FROM payments 
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY payment_method
                ORDER BY total_amount DESC";
        
        $stmt = $this->executeQuery($sql, [$dateFrom, $dateTo]);
        $methodSummary = $stmt->fetchAll();
        
        // Status summary
        $sql = "SELECT 
                status,
                COUNT(*) as payment_count,
                SUM(amount) as total_amount
                FROM payments 
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY status
                ORDER BY status";
        
        $stmt = $this->executeQuery($sql, [$dateFrom, $dateTo]);
        $statusSummary = $stmt->fetchAll();
        
        return [
            'daily_summary' => $dailySummary,
            'method_summary' => $methodSummary,
            'status_summary' => $statusSummary,
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo
            ]
        ];
    }
}
?>