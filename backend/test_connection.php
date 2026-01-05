<?php
// test_connection.php
require_once 'config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "Database connection successful!";
    
    // Test query
    $stmt = $conn->query("SELECT VERSION() as version");
    $result = $stmt->fetch();
    echo "<br>MySQL Version: " . $result['version'];
    
} catch (Exception $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>