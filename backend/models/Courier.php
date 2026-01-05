<?php
require_once 'Model.php';

class Courier extends Model {
    public function __construct() {
        parent::__construct('couriers');
    }
    
    public function getByOrderId($orderId) {
        $sql = "SELECT c.*, a.username as assigned_by_name 
                FROM couriers c 
                LEFT JOIN admins a ON c.assigned_by = a.id 
                WHERE c.order_id = ?";
        
        $stmt = $this->executeQuery($sql, [$orderId]);
        return $stmt->fetch();
    }
    
    public function assignCourier($data) {
        return $this->create($data);
    }
    
    public function getAllCouriers($filters = [], $limit = 20, $offset = 0) {
        $sql = "SELECT c.*, o.order_number, u.name as customer_name, a.username as assigned_by_name 
                FROM couriers c 
                JOIN orders o ON c.order_id = o.id 
                JOIN users u ON o.user_id = u.id 
                LEFT JOIN admins a ON c.assigned_by = a.id 
                WHERE 1=1";
        
        $whereClauses = [];
        $params = [];
        
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                switch ($field) {
                    case 'status':
                        $whereClauses[] = "c.status = ?";
                        $params[] = $value;
                        break;
                    case 'courier_name':
                        $whereClauses[] = "c.courier_name = ?";
                        $params[] = $value;
                        break;
                    case 'date_from':
                        $whereClauses[] = "DATE(c.assigned_at) >= ?";
                        $params[] = $value;
                        break;
                    case 'date_to':
                        $whereClauses[] = "DATE(c.assigned_at) <= ?";
                        $params[] = $value;
                        break;
                    case 'search':
                        $whereClauses[] = "(o.order_number LIKE ? OR u.name LIKE ? OR c.tracking_number LIKE ?)";
                        $searchTerm = "%$value%";
                        $params[] = $searchTerm;
                        $params[] = $searchTerm;
                        $params[] = $searchTerm;
                        break;
                }
            }
        }
        
        if (!empty($whereClauses)) {
            $sql .= " AND " . implode(" AND ", $whereClauses);
        }
        
        $sql .= " ORDER BY c.assigned_at DESC";
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM couriers c 
                     JOIN orders o ON c.order_id = o.id 
                     JOIN users u ON o.user_id = u.id 
                     WHERE 1=1";
        
        if (!empty($whereClauses)) {
            $countSql .= " AND " . implode(" AND ", $whereClauses);
        }
        
        $countStmt = $this->executeQuery($countSql, $params);
        $total = $countStmt->fetch()['total'];
        
        // Add limit and offset
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->executeQuery($sql, $params);
        $couriers = $stmt->fetchAll();
        
        return [
            'couriers' => $couriers,
            'total' => $total
        ];
    }
    
    public function getStatistics() {
        $stats = [];
        
        // Total assigned couriers
        $sql = "SELECT COUNT(*) as total FROM couriers";
        $stmt = $this->executeQuery($sql);
        $stats['total_assigned'] = $stmt->fetch()['total'];
        
        // Status breakdown
        $sql = "SELECT status, COUNT(*) as count FROM couriers GROUP BY status";
        $stmt = $this->executeQuery($sql);
        $stats['status_breakdown'] = $stmt->fetchAll();
        
        // Courier company breakdown
        $sql = "SELECT courier_name, COUNT(*) as count FROM couriers GROUP BY courier_name ORDER BY count DESC";
        $stmt = $this->executeQuery($sql);
        $stats['courier_breakdown'] = $stmt->fetchAll();
        
        // Delivery performance
        $sql = "SELECT 
                COUNT(*) as total_deliveries,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as successful_deliveries,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_deliveries,
                AVG(DATEDIFF(actual_delivery, assigned_at)) as avg_delivery_days
                FROM couriers 
                WHERE actual_delivery IS NOT NULL";
        
        $stmt = $this->executeQuery($sql);
        $stats['performance'] = $stmt->fetch();
        
        // Monthly trend
        $sql = "SELECT 
                DATE_FORMAT(assigned_at, '%Y-%m') as month,
                COUNT(*) as assigned_count,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count
                FROM couriers 
                WHERE assigned_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(assigned_at, '%Y-%m')
                ORDER BY month";
        
        $stmt = $this->executeQuery($sql);
        $stats['monthly_trend'] = $stmt->fetchAll();
        
        return $stats;
    }
    
    public function updateDeliveryStatus($orderId, $status, $notes = null) {
        $updateData = ['status' => $status];
        
        if ($status === 'delivered') {
            $updateData['actual_delivery'] = date('Y-m-d H:i:s');
        }
        
        if ($notes) {
            $updateData['notes'] = $notes;
        }
        
        $this->updateWhere(['order_id' => $orderId], $updateData);
    }
    
    private function updateWhere($conditions, $data) {
        $setClauses = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            $setClauses[] = "$field = ?";
            $params[] = $value;
        }
        
        $whereClauses = [];
        foreach ($conditions as $field => $value) {
            $whereClauses[] = "$field = ?";
            $params[] = $value;
        }
        
        $sql = "UPDATE couriers SET " . implode(', ', $setClauses) . 
               " WHERE " . implode(' AND ', $whereClauses);
        
        $this->executeQuery($sql, $params);
    }
}
?>