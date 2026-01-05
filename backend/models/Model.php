<?php
require_once __DIR__ . '/../config/database.php';

class Model {
    protected $db;
    protected $table;
    
    public function __construct($table) {
        $database = new Database();
        $this->db = $database->getConnection();
        $this->table = $table;
    }
    
    protected function executeQuery($sql, $params = []) {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch(PDOException $e) {
            throw new Exception("Query failed: " . $e->getMessage());
        }
    }
    
    public function find($id) {
        $sql = "SELECT * FROM {$this->table} WHERE id = ?";
        $stmt = $this->executeQuery($sql, [$id]);
        return $stmt->fetch();
    }
    
    public function findAll($conditions = [], $limit = null, $offset = null) {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];
        
        if (!empty($conditions)) {
            $whereClauses = [];
            foreach ($conditions as $field => $value) {
                $whereClauses[] = "$field = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        if ($limit !== null) {
            $sql .= " LIMIT ?";
            $params[] = $limit;
            if ($offset !== null) {
                $sql .= " OFFSET ?";
                $params[] = $offset;
            }
        }
        
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetchAll();
    }
    
    public function create($data) {
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        $values = array_values($data);
        
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $this->executeQuery($sql, $values);
        return $this->db->lastInsertId();
    }
    
    public function update($id, $data) {
        $fields = [];
        $values = [];
        
        foreach ($data as $field => $value) {
            $fields[] = "$field = ?";
            $values[] = $value;
        }
        $values[] = $id;
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = ?";
        $this->executeQuery($sql, $values);
        return true;
    }
    
    public function delete($id) {
        $sql = "DELETE FROM {$this->table} WHERE id = ?";
        $this->executeQuery($sql, [$id]);
        return true;
    }
    
    public function count($conditions = []) {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        $params = [];
        
        if (!empty($conditions)) {
            $whereClauses = [];
            foreach ($conditions as $field => $value) {
                $whereClauses[] = "$field = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        $stmt = $this->executeQuery($sql, $params);
        $result = $stmt->fetch();
        return $result['count'];
    }
}
?>