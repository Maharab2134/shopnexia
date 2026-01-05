<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../helpers/JWT.php';
require_once __DIR__ . '/../helpers/Validation.php';

class AuthController {
    private $userModel;
    private $jwt;
    private $validator;
    
    public function __construct() {
        $this->userModel = new User();
        $this->jwt = new JWT();
        $this->validator = new Validation();
    }
    
    public function register() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate input
        $errors = $this->validator->validateRegistration($data);
        if (!empty($errors)) {
            $this->sendResponse(400, ['errors' => $errors]);
        }
        
        // Check if user already exists
        if ($this->userModel->findByEmail($data['email'])) {
            $this->sendResponse(400, ['error' => 'Email already registered']);
        }
        
        if ($this->userModel->findByPhone($data['phone'])) {
            $this->sendResponse(400, ['error' => 'Phone number already registered']);
        }
        
        // Create user
        $userData = [
            'uuid' => $this->generateUuid(),
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => password_hash($data['password'], PASSWORD_BCRYPT)
        ];
        
        $userId = $this->userModel->create($userData);
        
        // Create token
        $tokenData = [
            'user_id' => $userId,
            'email' => $data['email'],
            'name' => $data['name']
        ];
        
        $token = $this->jwt->createToken($tokenData);
        
        $this->sendResponse(201, [
            'message' => 'Registration successful',
            'token' => $token,
            'user' => [
                'id' => $userId,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone']
            ]
        ]);
    }
    
    public function login() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate input
        if (empty($data['email']) || empty($data['password'])) {
            $this->sendResponse(400, ['error' => 'Email and password are required']);
        }
        
        // Find user
        $user = $this->userModel->findByEmail($data['email']);
        if (!$user) {
            $this->sendResponse(401, ['error' => 'Invalid credentials']);
        }
        
        // Check if blocked
        if ($user['is_blocked']) {
            $this->sendResponse(403, ['error' => 'Account is blocked. Please contact support.']);
        }
        
        // Verify password
        if (!password_verify($data['password'], $user['password'])) {
            $this->sendResponse(401, ['error' => 'Invalid credentials']);
        }
        
        // Update last login
        $this->userModel->updateLastLogin($user['id']);
        
        // Create token
        $tokenData = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'is_admin' => false
        ];
        
        $token = $this->jwt->createToken($tokenData);
        
        $this->sendResponse(200, [
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'avatar' => $user['avatar'],
                'is_verified' => (bool)$user['is_verified']
            ]
        ]);
    }
    
    public function adminLogin() {
        // Admin login logic
        // Similar to user login but checks admin table
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Admin login implementation
        // ...
        
        $this->sendResponse(200, ['message' => 'Admin login endpoint']);
    }
    
    public function getCurrentUser() {
        $authMiddleware = new AuthMiddleware();
        $userData = $authMiddleware->authenticate();
        
        $user = $this->userModel->find($userData->user_id);
        
        if (!$user) {
            $this->sendResponse(404, ['error' => 'User not found']);
        }
        
        // Remove sensitive data
        unset($user['password']);
        
        $this->sendResponse(200, ['user' => $user]);
    }
    
    public function updateProfile() {
        $authMiddleware = new AuthMiddleware();
        $userData = $authMiddleware->authenticate();
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        $userId = $userData->user_id;
        $success = $this->userModel->updateProfile($userId, $data);
        
        if ($success) {
            $user = $this->userModel->find($userId);
            unset($user['password']);
            $this->sendResponse(200, ['message' => 'Profile updated', 'user' => $user]);
        } else {
            $this->sendResponse(400, ['error' => 'Failed to update profile']);
        }
    }
    
    public function changePassword() {
        $authMiddleware = new AuthMiddleware();
        $userData = $authMiddleware->authenticate();
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate input
        if (empty($data['current_password']) || empty($data['new_password'])) {
            $this->sendResponse(400, ['error' => 'Current and new password are required']);
        }
        
        $user = $this->userModel->find($userData->user_id);
        
        // Verify current password
        if (!password_verify($data['current_password'], $user['password'])) {
            $this->sendResponse(401, ['error' => 'Current password is incorrect']);
        }
        
        // Update password
        $this->userModel->changePassword($user['id'], $data['new_password']);
        
        $this->sendResponse(200, ['message' => 'Password changed successfully']);
    }
    
    public function refreshToken() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['token'])) {
            $this->sendResponse(400, ['error' => 'Token is required']);
        }
        
        try {
            $newToken = $this->jwt->refreshToken($data['token']);
            $this->sendResponse(200, ['token' => $newToken]);
        } catch (Exception $e) {
            $this->sendResponse(401, ['error' => $e->getMessage()]);
        }
    }
    
    public function logout() {
        // For JWT, logout is handled client-side by removing the token
        $this->sendResponse(200, ['message' => 'Logged out successfully']);
    }
    
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
?>