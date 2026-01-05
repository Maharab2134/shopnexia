<?php
require_once 'Model.php';

class Notification extends Model {
    public function __construct() {
        parent::__construct('notifications');
    }
    
    public function createNotification($data) {
        return $this->create($data);
    }
    
    public function getUserNotifications($userId, $limit = 20, $offset = 0) {
        $sql = "SELECT * FROM notifications 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $this->executeQuery($sql, [$userId, $limit, $offset]);
        return $stmt->fetchAll();
    }
    
    public function getUnreadCount($userId) {
        $sql = "SELECT COUNT(*) as count FROM notifications 
                WHERE user_id = ? AND is_read = 0";
        
        $stmt = $this->executeQuery($sql, [$userId]);
        return $stmt->fetch()['count'];
    }
    
    public function markAsRead($notificationId, $userId = null) {
        $whereClause = "id = ?";
        $params = [$notificationId];
        
        if ($userId !== null) {
            $whereClause .= " AND user_id = ?";
            $params[] = $userId;
        }
        
        $sql = "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE $whereClause";
        $this->executeQuery($sql, $params);
        
        return true;
    }
    
    public function markAllAsRead($userId) {
        $sql = "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ?";
        $this->executeQuery($sql, [$userId]);
        
        return true;
    }
    
    public function sendOrderNotification($userId, $orderId, $type, $message) {
        $notificationData = [
            'user_id' => $userId,
            'title' => $this->getOrderNotificationTitle($type),
            'message' => $message,
            'notification_type' => 'order',
            'related_entity_type' => 'order',
            'related_entity_id' => $orderId,
            'metadata' => json_encode(['order_id' => $orderId, 'type' => $type])
        ];
        
        return $this->createNotification($notificationData);
    }
    
    public function sendPaymentNotification($userId, $paymentId, $type, $message) {
        $notificationData = [
            'user_id' => $userId,
            'title' => $this->getPaymentNotificationTitle($type),
            'message' => $message,
            'notification_type' => 'payment',
            'related_entity_type' => 'payment',
            'related_entity_id' => $paymentId,
            'metadata' => json_encode(['payment_id' => $paymentId, 'type' => $type])
        ];
        
        return $this->createNotification($notificationData);
    }
    
    public function sendSystemNotification($userIds, $title, $message, $metadata = null) {
        if (!is_array($userIds)) {
            $userIds = [$userIds];
        }
        
        $notifications = [];
        foreach ($userIds as $userId) {
            $notificationData = [
                'user_id' => $userId,
                'title' => $title,
                'message' => $message,
                'notification_type' => 'system',
                'metadata' => json_encode($metadata)
            ];
            
            $notifications[] = $this->createNotification($notificationData);
        }
        
        return $notifications;
    }
    
    public function sendPromotionalNotification($userIds, $title, $message, $promoData = null) {
        if (!is_array($userIds)) {
            $userIds = [$userIds];
        }
        
        $notifications = [];
        foreach ($userIds as $userId) {
            $notificationData = [
                'user_id' => $userId,
                'title' => $title,
                'message' => $message,
                'notification_type' => 'promotion',
                'metadata' => json_encode($promoData)
            ];
            
            $notifications[] = $this->createNotification($notificationData);
        }
        
        return $notifications;
    }
    
    public function cleanupOldNotifications($days = 30) {
        $sql = "DELETE FROM notifications 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY) 
                AND is_read = 1";
        
        $this->executeQuery($sql, [$days]);
        
        return true;
    }
    
    private function getOrderNotificationTitle($type) {
        $titles = [
            'created' => 'New Order Placed',
            'confirmed' => 'Order Confirmed',
            'processing' => 'Order Processing',
            'shipped' => 'Order Shipped',
            'delivered' => 'Order Delivered',
            'cancelled' => 'Order Cancelled'
        ];
        
        return $titles[$type] ?? 'Order Update';
    }
    
    private function getPaymentNotificationTitle($type) {
        $titles = [
            'pending' => 'Payment Pending',
            'processing' => 'Payment Processing',
            'completed' => 'Payment Completed',
            'failed' => 'Payment Failed',
            'refunded' => 'Payment Refunded'
        ];
        
        return $titles[$type] ?? 'Payment Update';
    }
}
?>