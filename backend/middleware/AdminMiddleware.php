<?php
require_once __DIR__ . '/../helpers/JWT.php';
require_once __DIR__ . '/../config/database.php';

class AdminMiddleware {
    private $jwt;
    private $db;
    
    public function __construct() {
        $this->jwt = new JWT();
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function authenticate() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        
        if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $this->sendUnauthorized();
        }
        
        $token = $matches[1];
        
        try {
            $decoded = $this->jwt->validateToken($token);
            
            // Check if user is admin
            if (!$this->isAdmin($decoded->data)) {
                $this->sendForbidden();
            }
            
            return $decoded->data;
        } catch (Exception $e) {
            $this->sendUnauthorized();
        }
    }
    
    private function isAdmin($userData) {
        // Check in admins table
        $sql = "SELECT * FROM admins WHERE id = ? OR email = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userData->user_id ?? 0, $userData->email ?? '']);
        
        $admin = $stmt->fetch();
        
        if ($admin) {
            // Add admin info to user data
            $userData->admin_id = $admin['id'];
            $userData->admin_role = $admin['role'];
            $userData->admin_permissions = json_decode($admin['permissions'] ?? '[]', true);
            return true;
        }
        
        // Check if user has admin flag
        if (isset($userData->is_admin) && $userData->is_admin) {
            return true;
        }
        
        return false;
    }
    
    public function checkPermission($permission) {
        $userData = $this->authenticate();
        
        if ($userData->admin_role === 'super_admin') {
            return true;
        }
        
        if (!isset($userData->admin_permissions) || !is_array($userData->admin_permissions)) {
            $this->sendForbidden();
        }
        
        if (!in_array($permission, $userData->admin_permissions)) {
            $this->sendForbidden();
        }
        
        return true;
    }
    
    public function isSuperAdmin() {
        $userData = $this->authenticate();
        return $userData->admin_role === 'super_admin';
    }
    
    private function sendUnauthorized() {
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode(['error' => 'Unauthorized access']);
        exit;
    }
    
    private function sendForbidden() {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    public function logAdminActivity($action, $description, $metadata = null) {
        $userData = $this->authenticate();
        
        $sql = "INSERT INTO activity_logs (admin_id, user_type, action, description, metadata, ip_address, user_agent) 
                VALUES (?, 'admin', ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $userData->admin_id,
            $action,
            $description,
            json_encode($metadata),
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
    }
}
?>