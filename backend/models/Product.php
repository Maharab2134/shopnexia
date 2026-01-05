<?php
require_once 'Model.php';

class Product extends Model {
    public function __construct() {
        parent::__construct('products');
    }
    
    public function findAllWithFilters($filters = [], $limit = 20, $offset = 0) {
        $sql = "SELECT p.*, c.name as category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id";
        
        $whereClauses = [];
        $params = [];
        
        if (!empty($filters['category_id'])) {
            $whereClauses[] = "p.category_id = ?";
            $params[] = $filters['category_id'];
        }
        
        if (!empty($filters['search'])) {
            $whereClauses[] = "(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if (isset($filters['is_featured']) && $filters['is_featured'] !== '') {
            $whereClauses[] = "p.is_featured = ?";
            $params[] = $filters['is_featured'];
        }
        
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $whereClauses[] = "p.is_active = ?";
            $params[] = $filters['is_active'];
        }
        
        if (!empty($filters['min_price'])) {
            $whereClauses[] = "p.sale_price >= ?";
            $params[] = $filters['min_price'];
        }
        
        if (!empty($filters['max_price'])) {
            $whereClauses[] = "p.sale_price <= ?";
            $params[] = $filters['max_price'];
        }
        
        if (!empty($filters['brand'])) {
            $whereClauses[] = "p.brand = ?";
            $params[] = $filters['brand'];
        }
        
        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        
        // Add sorting
        $sortField = $filters['sort'] ?? 'created_at';
        $sortOrder = $filters['order'] ?? 'DESC';
        $validSortFields = ['name', 'price', 'created_at', 'rating_avg', 'sales_count'];
        $sortField = in_array($sortField, $validSortFields) ? $sortField : 'created_at';
        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        
        $sql .= " ORDER BY p.{$sortField} {$sortOrder}";
        
        // Add limit and offset
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->executeQuery($sql, $params);
        $products = $stmt->fetchAll();
        
        // Get total count for pagination
        $countSql = "SELECT COUNT(*) as total FROM products p";
        if (!empty($whereClauses)) {
            $countSql .= " WHERE " . implode(" AND ", $whereClauses);
        }
        $countStmt = $this->executeQuery($countSql, array_slice($params, 0, -2));
        $total = $countStmt->fetch()['total'];
        
        return [
            'products' => $products,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
    
    public function findBySlug($slug) {
        $sql = "SELECT p.*, c.name as category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                WHERE p.slug = ?";
        $stmt = $this->executeQuery($sql, [$slug]);
        $product = $stmt->fetch();
        
        if ($product) {
            // Update view count
            $this->incrementViews($product['id']);
        }
        
        return $product;
    }
    
    public function findByBarcode($barcode) {
        $sql = "SELECT * FROM products WHERE barcode = ?";
        $stmt = $this->executeQuery($sql, [$barcode]);
        $product = $stmt->fetch();
        
        if ($product) {
            $this->incrementViews($product['id']);
        }
        
        return $product;
    }
    
    public function findBySku($sku) {
        $sql = "SELECT * FROM products WHERE sku = ?";
        $stmt = $this->executeQuery($sql, [$sku]);
        return $stmt->fetch();
    }
    
    public function incrementViews($productId) {
        // Increment total views
        $sql = "UPDATE products SET views_count = views_count + 1 WHERE id = ?";
        $this->executeQuery($sql, [$productId]);
        
        // Update or create product stats
        $date = date('Y-m-d');
        $statsSql = "INSERT INTO product_stats (product_id, daily_views, last_updated) 
                     VALUES (?, 1, NOW()) 
                     ON DUPLICATE KEY UPDATE 
                     daily_views = daily_views + 1, 
                     last_updated = NOW()";
        $this->executeQuery($statsSql, [$productId]);
        
        // Also update weekly and monthly views in stats table
        $updateSql = "UPDATE product_stats 
                      SET weekly_views = (SELECT COUNT(*) FROM product_views 
                                        WHERE product_id = ? AND viewed_at > DATE_SUB(NOW(), INTERVAL 7 DAY)),
                          monthly_views = (SELECT COUNT(*) FROM product_views 
                                          WHERE product_id = ? AND viewed_at > DATE_SUB(NOW(), INTERVAL 30 DAY))
                      WHERE product_id = ?";
        $this->executeQuery($updateSql, [$productId, $productId, $productId]);
    }
    
    public function trackProductView($userId, $productId, $sessionId, $ipAddress, $userAgent) {
        $sql = "INSERT INTO product_views (user_id, product_id, session_id, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)";
        $this->executeQuery($sql, [$userId, $productId, $sessionId, $ipAddress, $userAgent]);
        return true;
    }
    
    public function incrementSales($productId, $quantity) {
        $sql = "UPDATE products 
                SET sales_count = sales_count + ?, 
                    stock_quantity = stock_quantity - ?,
                    updated_at = NOW() 
                WHERE id = ?";
        $this->executeQuery($sql, [$quantity, $quantity, $productId]);
        
        // Update product stats
        $statsSql = "INSERT INTO product_stats (product_id, daily_sales, last_updated) 
                     VALUES (?, ?, NOW()) 
                     ON DUPLICATE KEY UPDATE 
                     daily_sales = daily_sales + ?, 
                     last_updated = NOW()";
        $this->executeQuery($statsSql, [$productId, $quantity, $quantity]);
        
        // Update weekly and monthly sales
        $updateSql = "UPDATE product_stats 
                      SET weekly_sales = (SELECT SUM(quantity) FROM order_items oi 
                                        JOIN orders o ON oi.order_id = o.id 
                                        WHERE oi.product_id = ? AND o.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)),
                          monthly_sales = (SELECT SUM(quantity) FROM order_items oi 
                                          JOIN orders o ON oi.order_id = o.id 
                                          WHERE oi.product_id = ? AND o.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY))
                      WHERE product_id = ?";
        $this->executeQuery($updateSql, [$productId, $productId, $productId]);
    }
    
    public function updateStock($productId, $quantity, $operation = 'add') {
        if ($operation === 'add') {
            $sql = "UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?";
        } else {
            $sql = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?";
        }
        $this->executeQuery($sql, [$quantity, $productId]);
        return true;
    }
    
    public function updateRating($productId) {
        $sql = "UPDATE products p 
                SET rating_avg = (
                    SELECT AVG(rating) FROM reviews 
                    WHERE product_id = ? AND is_approved = 1
                ),
                rating_count = (
                    SELECT COUNT(*) FROM reviews 
                    WHERE product_id = ? AND is_approved = 1
                )
                WHERE id = ?";
        $this->executeQuery($sql, [$productId, $productId, $productId]);
        return true;
    }
    
    public function getFeaturedProducts($limit = 10) {
        $sql = "SELECT * FROM products 
                WHERE is_featured = 1 AND is_active = 1 
                ORDER BY created_at DESC 
                LIMIT ?";
        $stmt = $this->executeQuery($sql, [$limit]);
        return $stmt->fetchAll();
    }
    
    public function getBestSellers($limit = 10) {
        $sql = "SELECT p.*, SUM(oi.quantity) as total_sold 
                FROM products p 
                JOIN order_items oi ON p.id = oi.product_id 
                JOIN orders o ON oi.order_id = o.id 
                WHERE o.order_status = 'delivered' 
                GROUP BY p.id 
                ORDER BY total_sold DESC 
                LIMIT ?";
        $stmt = $this->executeQuery($sql, [$limit]);
        return $stmt->fetchAll();
    }
    
    public function getTrendingProducts($limit = 10) {
        $sql = "SELECT p.*, COUNT(pv.id) as view_count 
                FROM products p 
                LEFT JOIN product_views pv ON p.id = pv.product_id 
                WHERE pv.viewed_at > DATE_SUB(NOW(), INTERVAL 7 DAY) 
                GROUP BY p.id 
                ORDER BY view_count DESC 
                LIMIT ?";
        $stmt = $this->executeQuery($sql, [$limit]);
        return $stmt->fetchAll();
    }
    
    public function getNewArrivals($limit = 10) {
        $sql = "SELECT * FROM products 
                WHERE is_active = 1 
                ORDER BY created_at DESC 
                LIMIT ?";
        $stmt = $this->executeQuery($sql, [$limit]);
        return $stmt->fetchAll();
    }
    
    public function getSimilarProducts($productId, $categoryId, $limit = 6) {
        $sql = "SELECT * FROM products 
                WHERE category_id = ? AND id != ? AND is_active = 1 
                ORDER BY RAND() 
                LIMIT ?";
        $stmt = $this->executeQuery($sql, [$categoryId, $productId, $limit]);
        return $stmt->fetchAll();
    }
    
    public function getRecommendedProducts($userId, $limit = 6) {
        // Get user's order history to recommend products
        $sql = "SELECT DISTINCT p.* 
                FROM products p 
                JOIN order_items oi ON p.id = oi.product_id 
                JOIN orders o ON oi.order_id = o.id 
                WHERE o.user_id = ? AND p.is_active = 1 
                ORDER BY o.created_at DESC 
                LIMIT ?";
        $stmt = $this->executeQuery($sql, [$userId, $limit]);
        return $stmt->fetchAll();
    }
    
    public function getProductStats($productId) {
        $sql = "SELECT * FROM product_stats WHERE product_id = ?";
        $stmt = $this->executeQuery($sql, [$productId]);
        return $stmt->fetch();
    }
    
    public function getBrands() {
        $sql = "SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' ORDER BY brand";
        $stmt = $this->executeQuery($sql);
        return $stmt->fetchAll();
    }
}
?>