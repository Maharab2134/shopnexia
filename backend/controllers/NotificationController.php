<?php
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/AdminMiddleware.php';

class NotificationController {
    private $notificationModel;
    private $auth;
    
    public function __construct() {
        $this->notificationModel = new Notification();
        $this->auth = new AuthMiddleware();
    }
    
    public function getNotifications() {
        $userData = $this->auth->authenticate();
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $notifications = $this->notificationModel->getUserNotifications($userData->user_id, $limit, $offset);
        $unreadCount = $this->notificationModel->getUnreadCount($userData->user_id);
        
        $this->sendResponse(200, [
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
            'pagination' => [
                'page' => $page,
                'limit' => $limit
            ]
        ]);
    }
    
    public function getUnreadNotifications() {
        $userData = $this->auth->authenticate();
        
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        
        // Get unread notifications
        $sql = "SELECT * FROM notifications 
                WHERE user_id = ? AND is_read = 0 
                ORDER BY created_at DESC 
                LIMIT ?";
        
        $stmt = $this->notificationModel->executeQuery($sql, [$userData->user_id, $limit]);
        $notifications = $stmt->fetchAll();
        
        $this->sendResponse(200, ['notifications' => $notifications]);
    }
    
    public function markAsRead($id) {
        $userData = $this->auth->authenticate();
        
        $this->notificationModel->markAsRead($id, $userData->user_id);
        
        $this->sendResponse(200, ['message' => 'Notification marked as read']);
    }
    
    public function markAllAsRead() {
        $userData = $this->auth->authenticate();
        
        $this->notificationModel->markAllAsRead($userData->user_id);
        
        $this->sendResponse(200, ['message' => 'All notifications marked as read']);
    }
    
    public function deleteNotification($id) {
        $userData = $this->auth->authenticate();
        
        // Verify notification belongs to user
        $sql = "DELETE FROM notifications WHERE id = ? AND user_id = ?";
        $this->notificationModel->executeQuery($sql, [$id, $userData->user_id]);
        
        $this->sendResponse(200, ['message' => 'Notification deleted']);
    }
    
    // Admin methods
    public function adminSendNotification() {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['title', 'message', 'recipients'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->sendResponse(400, ['error' => "$field is required"]);
            }
        }
        
        $recipients = $data['recipients'];
        $notificationType = $data['type'] ?? 'system';
        
        if ($recipients === 'all') {
            // Send to all users
            $userModel = new User();
            $users = $userModel->findAll(['is_blocked' => 0]);
            $userIds = array_column($users, 'id');
        } elseif ($recipients === 'selected' && !empty($data['user_ids'])) {
            $userIds = $data['user_ids'];
        } else {
            $this->sendResponse(400, ['error' => 'Invalid recipients']);
        }
        
        $sentCount = 0;
        foreach ($userIds as $userId) {
            $notificationData = [
                'user_id' => $userId,
                'title' => $data['title'],
                'message' => $data['message'],
                'notification_type' => $notificationType,
                'related_entity_type' => $data['related_type'] ?? null,
                'related_entity_id' => $data['related_id'] ?? null,
                'metadata' => json_encode($data['metadata'] ?? [])
            ];
            
            $this->notificationModel->createNotification($notificationData);
            $sentCount++;
        }
        
        $this->sendResponse(201, [
            'message' => 'Notification sent successfully',
            'sent_count' => $sentCount
        ]);
    }
    
    public function adminGetNotifications() {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT n.*, u.name as user_name, u.email as user_email 
                FROM notifications n 
                JOIN users u ON n.user_id = u.id 
                WHERE n.notification_type IN ('system', 'promotion')
                ORDER BY n.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $stmt = $this->notificationModel->executeQuery($sql, [$limit, $offset]);
        $notifications = $stmt->fetchAll();
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM notifications WHERE notification_type IN ('system', 'promotion')";
        $countStmt = $this->notificationModel->executeQuery($countSql);
        $total = $countStmt->fetch()['total'];
        
        $this->sendResponse(200, [
            'notifications' => $notifications,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }
    
    public function adminGetStatistics() {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $stats = [];
        
        // Total notifications
        $sql = "SELECT COUNT(*) as total FROM notifications";
        $stmt = $this->notificationModel->executeQuery($sql);
        $stats['total_notifications'] = $stmt->fetch()['total'];
        
        // Unread notifications
        $sql = "SELECT COUNT(*) as unread FROM notifications WHERE is_read = 0";
        $stmt = $this->notificationModel->executeQuery($sql);
        $stats['unread_notifications'] = $stmt->fetch()['total'];
        
        // By type
        $sql = "SELECT notification_type, COUNT(*) as count FROM notifications GROUP BY notification_type";
        $stmt = $this->notificationModel->executeQuery($sql);
        $stats['by_type'] = $stmt->fetchAll();
        
        // Daily trend
        $sql = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as notification_count,
                SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read_count
                FROM notifications 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date";
        
        $stmt = $this->notificationModel->executeQuery($sql);
        $stats['daily_trend'] = $stmt->fetchAll();
        
        $this->sendResponse(200, ['statistics' => $stats]);
    }
    
    public function cleanupNotifications() {
        $auth = new AdminMiddleware();
        $auth->authenticate();
        
        $days = intval($_GET['days'] ?? 30);
        
        $cleaned = $this->notificationModel->cleanupOldNotifications($days);
        
        $this->sendResponse(200, [
            'message' => 'Old notifications cleaned up',
            'days_old' => $days,
            'cleaned' => $cleaned
        ]);
    }
    
    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
?>