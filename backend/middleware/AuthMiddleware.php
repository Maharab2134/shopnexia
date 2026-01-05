<?php
require_once __DIR__ . '/../helpers/JWT.php';

class AuthMiddleware {
    private $jwt;
    
    public function __construct() {
        $this->jwt = new JWT();
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
            return $decoded->data;
        } catch (Exception $e) {
            $this->sendUnauthorized();
        }
    }
    
    public function authenticateAdmin() {
        $user = $this->authenticate();
        
        // Check if user is admin
        // This requires checking the admins table or user role
        if (!isset($user->is_admin) || !$user->is_admin) {
            $this->sendForbidden();
        }
        
        return $user;
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
}
?>