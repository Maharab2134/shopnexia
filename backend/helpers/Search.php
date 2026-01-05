<?php
class SearchHelper {
    private $db;
    
    public function __construct() {
        $database = new Database();
        $this->db = $database->getConnection();
    }
    
    public function searchProducts($query, $filters = [], $limit = 20, $offset = 0) {
        $sql = "SELECT p.*, c.name as category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                WHERE p.is_active = 1";
        
        $whereClauses = [];
        $params = [];
        
        // Add search query
        if (!empty($query)) {
            $whereClauses[] = $this->buildSearchCondition($query);
            $searchTerms = $this->parseSearchQuery($query);
            $params = array_merge($params, $searchTerms);
        }
        
        // Add filters
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                switch ($field) {
                    case 'category_id':
                        $whereClauses[] = "p.category_id = ?";
                        $params[] = $value;
                        break;
                    case 'min_price':
                        $whereClauses[] = "p.sale_price >= ?";
                        $params[] = $value;
                        break;
                    case 'max_price':
                        $whereClauses[] = "p.sale_price <= ?";
                        $params[] = $value;
                        break;
                    case 'brand':
                        $whereClauses[] = "p.brand = ?";
                        $params[] = $value;
                        break;
                    case 'in_stock':
                        if ($value) {
                            $whereClauses[] = "p.stock_quantity > 0";
                        }
                        break;
                    case 'is_featured':
                        $whereClauses[] = "p.is_featured = ?";
                        $params[] = $value;
                        break;
                }
            }
        }
        
        if (!empty($whereClauses)) {
            $sql .= " AND " . implode(" AND ", $whereClauses);
        }
        
        // Add sorting
        $sortField = $filters['sort'] ?? 'relevance';
        $sortOrder = $filters['order'] ?? 'DESC';
        
        $sql .= $this->buildOrderByClause($sortField, $sortOrder, !empty($query));
        
        // Add pagination
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM products p WHERE p.is_active = 1";
        if (!empty($whereClauses)) {
            $countSql .= " AND " . implode(" AND ", $whereClauses);
        }
        
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute(array_slice($params, 0, -2));
        $total = $countStmt->fetch()['total'];
        
        // Get filters for search results
        $availableFilters = $this->getAvailableFilters($whereClauses, $params);
        
        return [
            'products' => $products,
            'total' => $total,
            'filters' => $availableFilters
        ];
    }
    
    public function searchUsers($query, $filters = [], $limit = 20, $offset = 0) {
        $sql = "SELECT * FROM users WHERE 1=1";
        $whereClauses = [];
        $params = [];
        
        if (!empty($query)) {
            $whereClauses[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $searchTerm = "%$query%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                switch ($field) {
                    case 'is_verified':
                        $whereClauses[] = "is_verified = ?";
                        $params[] = $value;
                        break;
                    case 'is_blocked':
                        $whereClauses[] = "is_blocked = ?";
                        $params[] = $value;
                        break;
                    case 'date_from':
                        $whereClauses[] = "DATE(created_at) >= ?";
                        $params[] = $value;
                        break;
                    case 'date_to':
                        $whereClauses[] = "DATE(created_at) <= ?";
                        $params[] = $value;
                        break;
                }
            }
        }
        
        if (!empty($whereClauses)) {
            $sql .= " AND " . implode(" AND ", $whereClauses);
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function searchOrders($query, $filters = [], $limit = 20, $offset = 0) {
        $sql = "SELECT o.*, u.name as customer_name, u.email as customer_email 
                FROM orders o 
                JOIN users u ON o.user_id = u.id 
                WHERE 1=1";
        
        $whereClauses = [];
        $params = [];
        
        if (!empty($query)) {
            $whereClauses[] = "(o.order_number LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
            $searchTerm = "%$query%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        foreach ($filters as $field => $value) {
            if (!empty($value)) {
                switch ($field) {
                    case 'order_status':
                        $whereClauses[] = "o.order_status = ?";
                        $params[] = $value;
                        break;
                    case 'payment_status':
                        $whereClauses[] = "o.payment_status = ?";
                        $params[] = $value;
                        break;
                    case 'payment_method':
                        $whereClauses[] = "o.payment_method = ?";
                        $params[] = $value;
                        break;
                    case 'date_from':
                        $whereClauses[] = "DATE(o.created_at) >= ?";
                        $params[] = $value;
                        break;
                    case 'date_to':
                        $whereClauses[] = "DATE(o.created_at) <= ?";
                        $params[] = $value;
                        break;
                }
            }
        }
        
        if (!empty($whereClauses)) {
            $sql .= " AND " . implode(" AND ", $whereClauses);
        }
        
        $sql .= " ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function autoComplete($query, $type = 'products', $limit = 10) {
        switch ($type) {
            case 'products':
                $sql = "SELECT id, name, slug, main_image, sale_price 
                        FROM products 
                        WHERE is_active = 1 
                        AND (name LIKE ? OR sku LIKE ? OR barcode LIKE ?)
                        ORDER BY 
                            CASE 
                                WHEN name LIKE ? THEN 1
                                WHEN sku LIKE ? THEN 2
                                WHEN barcode LIKE ? THEN 3
                                ELSE 4
                            END,
                            sales_count DESC
                        LIMIT ?";
                
                $searchTerm = "%$query%";
                $exactTerm = "$query%";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    $exactTerm, $exactTerm, $exactTerm,
                    $searchTerm, $searchTerm, $searchTerm,
                    $limit
                ]);
                break;
                
            case 'categories':
                $sql = "SELECT id, name, slug, image 
                        FROM categories 
                        WHERE is_active = 1 
                        AND name LIKE ? 
                        ORDER BY display_order 
                        LIMIT ?";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute(["%$query%", $limit]);
                break;
                
            case 'users':
                $sql = "SELECT id, name, email, phone, avatar 
                        FROM users 
                        WHERE name LIKE ? OR email LIKE ? 
                        ORDER BY created_at DESC 
                        LIMIT ?";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute(["%$query%", "%$query%", $limit]);
                break;
                
            default:
                return [];
        }
        
        return $stmt->fetchAll();
    }
    
    public function getSearchSuggestions($query) {
        $suggestions = [];
        
        // Product suggestions
        $products = $this->autoComplete($query, 'products', 5);
        if (!empty($products)) {
            $suggestions['products'] = $products;
        }
        
        // Category suggestions
        $categories = $this->autoComplete($query, 'categories', 3);
        if (!empty($categories)) {
            $suggestions['categories'] = $categories;
        }
        
        // Popular search terms
        $popular = $this->getPopularSearchTerms($query);
        if (!empty($popular)) {
            $suggestions['popular'] = $popular;
        }
        
        return $suggestions;
    }
    
    private function buildSearchCondition($query) {
        $terms = $this->parseSearchQuery($query);
        $conditions = [];
        
        foreach ($terms as $term) {
            $conditions[] = "(p.name LIKE ? OR p.description LIKE ? OR p.short_description LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
        }
        
        return "(" . implode(" AND ", $conditions) . ")";
    }
    
    private function parseSearchQuery($query) {
        $terms = preg_split('/\s+/', trim($query));
        $searchTerms = [];
        
        foreach ($terms as $term) {
            $searchTerm = "%$term%";
            // Add 5 times for each term (for the 5 fields in condition)
            for ($i = 0; $i < 5; $i++) {
                $searchTerms[] = $searchTerm;
            }
        }
        
        return $searchTerms;
    }
    
    private function buildOrderByClause($sortField, $sortOrder, $hasQuery = false) {
        $orderBy = "";
        
        switch ($sortField) {
            case 'relevance':
                if ($hasQuery) {
                    $orderBy = " ORDER BY 
                        CASE 
                            WHEN p.name LIKE ? THEN 1
                            WHEN p.sku LIKE ? THEN 2
                            WHEN p.barcode LIKE ? THEN 3
                            ELSE 4
                        END";
                } else {
                    $orderBy = " ORDER BY p.created_at DESC";
                }
                break;
                
            case 'price':
                $orderBy = " ORDER BY p.sale_price " . ($sortOrder === 'asc' ? 'ASC' : 'DESC');
                break;
                
            case 'name':
                $orderBy = " ORDER BY p.name " . ($sortOrder === 'asc' ? 'ASC' : 'DESC');
                break;
                
            case 'popularity':
                $orderBy = " ORDER BY p.sales_count DESC";
                break;
                
            case 'rating':
                $orderBy = " ORDER BY p.rating_avg DESC";
                break;
                
            case 'newest':
                $orderBy = " ORDER BY p.created_at DESC";
                break;
                
            default:
                $orderBy = " ORDER BY p.created_at DESC";
        }
        
        return $orderBy;
    }
    
    private function getAvailableFilters($whereClauses, $params) {
        $filters = [];
        
        // Get available brands
        $brandSql = "SELECT DISTINCT brand FROM products WHERE is_active = 1 AND brand IS NOT NULL AND brand != ''";
        $stmt = $this->db->query($brandSql);
        $filters['brands'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get price ranges
        $priceSql = "SELECT 
                    MIN(sale_price) as min_price,
                    MAX(sale_price) as max_price,
                    AVG(sale_price) as avg_price
                    FROM products WHERE is_active = 1";
        $stmt = $this->db->query($priceSql);
        $filters['price_range'] = $stmt->fetch();
        
        // Get categories with count
        $catSql = "SELECT c.id, c.name, COUNT(p.id) as product_count 
                   FROM categories c 
                   LEFT JOIN products p ON c.id = p.category_id AND p.is_active = 1
                   WHERE c.is_active = 1 
                   GROUP BY c.id 
                   ORDER BY c.display_order";
        $stmt = $this->db->query($catSql);
        $filters['categories'] = $stmt->fetchAll();
        
        return $filters;
    }
    
    private function getPopularSearchTerms($query = null) {
        // In a real application, you would track search terms and their frequency
        // For now, return some sample data
        
        $popular = [
            'laptop', 'mobile phone', 'headphone', 'smart watch',
            'camera', 'tablet', 'printer', 'monitor'
        ];
        
        if ($query) {
            // Filter to include only terms similar to query
            $filtered = array_filter($popular, function($term) use ($query) {
                return stripos($term, $query) !== false;
            });
            return array_slice($filtered, 0, 5);
        }
        
        return array_slice($popular, 0, 5);
    }
    
    public function logSearch($query, $userId = null, $resultsCount = 0) {
        $sql = "INSERT INTO search_logs (query, user_id, results_count, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $query,
            $userId,
            $resultsCount,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
    }
}
?>