<?php
require_once 'Model.php';

class User extends Model {
    public function __construct() {
        parent::__construct('users');
    }
    
    public function findByEmail($email) {
        $sql = "SELECT * FROM users WHERE email = ?";
        $stmt = $this->executeQuery($sql, [$email]);
        return $stmt->fetch();
    }
    
    public function findByPhone($phone) {
        $sql = "SELECT * FROM users WHERE phone = ?";
        $stmt = $this->executeQuery($sql, [$phone]);
        return $stmt->fetch();
    }
    
    public function updateLastLogin($id) {
        $sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
        $this->executeQuery($sql, [$id]);
    }
    
    public function getAddresses($userId) {
        $sql = "SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC";
        $stmt = $this->executeQuery($sql, [$userId]);
        return $stmt->fetchAll();
    }
    
    public function getDefaultAddress($userId) {
        $sql = "SELECT * FROM user_addresses WHERE user_id = ? AND is_default = 1 LIMIT 1";
        $stmt = $this->executeQuery($sql, [$userId]);
        return $stmt->fetch();
    }
    
    public function updateProfile($id, $data) {
        $allowedFields = ['name', 'phone', 'avatar'];
        $updateData = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (!empty($updateData)) {
            return parent::update($id, $updateData);
        }
        
        return false;
    }
    
    public function changePassword($id, $newPassword) {
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $sql = "UPDATE users SET password = ? WHERE id = ?";
        $this->executeQuery($sql, [$hashedPassword, $id]);
        return true;
    }
    
    public function verifyUser($id) {
        $sql = "UPDATE users SET is_verified = 1 WHERE id = ?";
        $this->executeQuery($sql, [$id]);
        return true;
    }
    
    public function toggleBlock($id, $status) {
        $sql = "UPDATE users SET is_blocked = ? WHERE id = ?";
        $this->executeQuery($sql, [$status, $id]);
        return true;
    }
    
    public function getStatistics() {
        $sql = "SELECT 
                COUNT(*) as total_users,
                SUM(is_verified) as verified_users,
                SUM(is_blocked) as blocked_users,
                DATE(created_at) as date
                FROM users 
                GROUP BY DATE(created_at) 
                ORDER BY date DESC 
                LIMIT 30";
        $stmt = $this->executeQuery($sql);
        return $stmt->fetchAll();
    }
}
?>