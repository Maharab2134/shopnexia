<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';

class UserController {
    private $userModel;
    private $auth;
    
    public function __construct() {
        $this->userModel = new User();
        $this->auth = new AuthMiddleware();
    }
    
    public function getAddresses() {
        $userData = $this->auth->authenticate();
        $addresses = $this->userModel->getAddresses($userData->user_id);
        
        $this->sendResponse(200, ['addresses' => $addresses]);
    }
    
    public function addAddress() {
        $userData = $this->auth->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['address_type', 'full_name', 'phone', 'address_line1', 'city', 'division'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->sendResponse(400, ['error' => "$field is required"]);
            }
        }
        
        $addressData = [
            'user_id' => $userData->user_id,
            'address_type' => $data['address_type'],
            'full_name' => $data['full_name'],
            'phone' => $data['phone'],
            'address_line1' => $data['address_line1'],
            'address_line2' => $data['address_line2'] ?? '',
            'city' => $data['city'],
            'division' => $data['division'],
            'zip_code' => $data['zip_code'] ?? '',
            'country' => $data['country'] ?? 'Bangladesh',
            'is_default' => $data['is_default'] ?? false
        ];
        
        // If setting as default, update other addresses
        if ($addressData['is_default']) {
            $this->clearDefaultAddresses($userData->user_id);
        }
        
        $addressId = $this->createAddress($addressData);
        
        $this->sendResponse(201, [
            'message' => 'Address added successfully',
            'address_id' => $addressId
        ]);
    }
    
    public function updateAddress($id) {
        $userData = $this->auth->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Verify address belongs to user
        $address = $this->getAddress($id, $userData->user_id);
        if (!$address) {
            $this->sendResponse(404, ['error' => 'Address not found']);
        }
        
        $updateData = [];
        $allowedFields = ['address_type', 'full_name', 'phone', 'address_line1', 
                         'address_line2', 'city', 'division', 'zip_code', 'country', 'is_default'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (!empty($updateData)) {
            // If setting as default, update other addresses
            if (isset($updateData['is_default']) && $updateData['is_default']) {
                $this->clearDefaultAddresses($userData->user_id);
            }
            
            $this->updateAddressRecord($id, $updateData);
        }
        
        $this->sendResponse(200, ['message' => 'Address updated successfully']);
    }
    
    public function deleteAddress($id) {
        $userData = $this->auth->authenticate();
        
        // Verify address belongs to user
        $address = $this->getAddress($id, $userData->user_id);
        if (!$address) {
            $this->sendResponse(404, ['error' => 'Address not found']);
        }
        
        $this->deleteAddressRecord($id);
        
        $this->sendResponse(200, ['message' => 'Address deleted successfully']);
    }
    
    public function setDefaultAddress($id) {
        $userData = $this->auth->authenticate();
        
        // Verify address belongs to user
        $address = $this->getAddress($id, $userData->user_id);
        if (!$address) {
            $this->sendResponse(404, ['error' => 'Address not found']);
        }
        
        // Clear other default addresses
        $this->clearDefaultAddresses($userData->user_id);
        
        // Set this as default
        $this->updateAddressRecord($id, ['is_default' => 1]);
        
        $this->sendResponse(200, ['message' => 'Default address updated']);
    }
    
    private function createAddress($data) {
        $sql = "INSERT INTO user_addresses (user_id, address_type, full_name, phone, address_line1, 
                address_line2, city, division, zip_code, country, is_default) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->userModel->executeQuery($sql, [
            $data['user_id'],
            $data['address_type'],
            $data['full_name'],
            $data['phone'],
            $data['address_line1'],
            $data['address_line2'],
            $data['city'],
            $data['division'],
            $data['zip_code'],
            $data['country'],
            $data['is_default'] ? 1 : 0
        ]);
        
        return $this->userModel->db->lastInsertId();
    }
    
    private function getAddress($id, $userId) {
        $sql = "SELECT * FROM user_addresses WHERE id = ? AND user_id = ?";
        $stmt = $this->userModel->executeQuery($sql, [$id, $userId]);
        return $stmt->fetch();
    }
    
    private function updateAddressRecord($id, $data) {
        $fields = [];
        $values = [];
        
        foreach ($data as $field => $value) {
            $fields[] = "$field = ?";
            $values[] = $value;
        }
        $values[] = $id;
        
        $sql = "UPDATE user_addresses SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->userModel->executeQuery($sql, $values);
    }
    
    private function deleteAddressRecord($id) {
        $sql = "DELETE FROM user_addresses WHERE id = ?";
        $this->userModel->executeQuery($sql, [$id]);
    }
    
    private function clearDefaultAddresses($userId) {
        $sql = "UPDATE user_addresses SET is_default = 0 WHERE user_id = ?";
        $this->userModel->executeQuery($sql, [$userId]);
    }
    
    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
?>