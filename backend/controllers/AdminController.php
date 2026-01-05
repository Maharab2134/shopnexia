<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../middleware/AdminMiddleware.php';

class AdminController {
    private $userModel;
    private $orderModel;
    private $productModel;
    private $auth;
    
    public function __construct() {
        $this->userModel = new User();
        $this->orderModel = new Order();
        $this->productModel = new Product();
        $this->auth = new AdminMiddleware();
    }
    
    public function getDashboardStats() {
        $this->auth->authenticate();
        
        $stats = $this->orderModel->getDashboardStats();
        
        // Add user statistics
        $userStats = $this->userModel->getStatistics();
        $stats['user_stats'] = $userStats;
        
        // Add product statistics
        $productStats = $this->getProductStatistics();
        $stats = array_merge($stats, $productStats);
        
        $this->sendResponse(200, ['stats' => $stats]);
    }
    
    public function getRecentOrders() {
        $this->auth->authenticate();
        
        $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
        $orders = $this->orderModel->getRecentOrders($limit);
        
        $this->sendResponse(200, ['orders' => $orders]);
    }
    
    public function getTopProducts() {
        $this->auth->authenticate();
        
        $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
        $period = $_GET['period'] ?? 'monthly';
        
        $products = $this->getTopSellingProducts($limit, $period);
        
        $this->sendResponse(200, ['products' => $products]);
    }
    
    public function getSalesChart() {
        $this->auth->authenticate();
        
        $period = $_GET['period'] ?? '7days';
        $chartData = $this->orderModel->getSalesChartData($period);
        
        $this->sendResponse(200, ['chart_data' => $chartData]);
    }
    
    public function getRevenueAnalytics() {
        $this->auth->authenticate();
        
        $analytics = [
            'daily_revenue' => $this->getDailyRevenue(),
            'weekly_revenue' => $this->getWeeklyRevenue(),
            'monthly_revenue' => $this->getMonthlyRevenue(),
            'yearly_revenue' => $this->getYearlyRevenue(),
            'revenue_by_category' => $this->getRevenueByCategory(),
            'revenue_by_payment_method' => $this->getRevenueByPaymentMethod()
        ];
        
        $this->sendResponse(200, ['analytics' => $analytics]);
    }
    
    private function getProductStatistics() {
        $sql = "SELECT 
                COUNT(*) as total_products,
                SUM(stock_quantity) as total_stock,
                SUM(CASE WHEN stock_quantity <= low_stock_threshold THEN 1 ELSE 0 END) as low_stock_products,
                SUM(CASE WHEN is_featured = 1 THEN 1 ELSE 0 END) as featured_products
                FROM products";
        
        $stmt = $this->userModel->executeQuery($sql);
        return $stmt->fetch();
    }
    
    private function getTopSellingProducts($limit, $period) {
        $dateCondition = '';
        switch ($period) {
            case 'daily':
                $dateCondition = "AND o.created_at >= CURDATE()";
                break;
            case 'weekly':
                $dateCondition = "AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
                break;
            case 'monthly':
                $dateCondition = "AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                break;
            case 'yearly':
                $dateCondition = "AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
                break;
        }
        
        $sql = "SELECT p.*, SUM(oi.quantity) as total_sold, SUM(oi.total_price) as total_revenue
                FROM products p 
                JOIN order_items oi ON p.id = oi.product_id 
                JOIN orders o ON oi.order_id = o.id 
                WHERE o.order_status = 'delivered' 
                $dateCondition
                GROUP BY p.id 
                ORDER BY total_sold DESC 
                LIMIT ?";
        
        $stmt = $this->userModel->executeQuery($sql, [$limit]);
        return $stmt->fetchAll();
    }
    
    private function getDailyRevenue() {
        $sql = "SELECT SUM(total_amount) as revenue 
                FROM orders 
                WHERE DATE(created_at) = CURDATE() 
                AND order_status = 'delivered'";
        
        $stmt = $this->userModel->executeQuery($sql);
        return $stmt->fetch()['revenue'] ?? 0;
    }
    
    private function getWeeklyRevenue() {
        $sql = "SELECT SUM(total_amount) as revenue 
                FROM orders 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
                AND order_status = 'delivered'";
        
        $stmt = $this->userModel->executeQuery($sql);
        return $stmt->fetch()['revenue'] ?? 0;
    }
    
    private function getMonthlyRevenue() {
        $sql = "SELECT SUM(total_amount) as revenue 
                FROM orders 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
                AND order_status = 'delivered'";
        
        $stmt = $this->userModel->executeQuery($sql);
        return $stmt->fetch()['revenue'] ?? 0;
    }
    
    private function getYearlyRevenue() {
        $sql = "SELECT SUM(total_amount) as revenue 
                FROM orders 
                WHERE YEAR(created_at) = YEAR(CURDATE()) 
                AND order_status = 'delivered'";
        
        $stmt = $this->userModel->executeQuery($sql);
        return $stmt->fetch()['revenue'] ?? 0;
    }
    
    private function getRevenueByCategory() {
        $sql = "SELECT c.name as category, SUM(oi.total_price) as revenue
                FROM categories c 
                JOIN products p ON c.id = p.category_id 
                JOIN order_items oi ON p.id = oi.product_id 
                JOIN orders o ON oi.order_id = o.id 
                WHERE o.order_status = 'delivered' 
                GROUP BY c.id 
                ORDER BY revenue DESC";
        
        $stmt = $this->userModel->executeQuery($sql);
        return $stmt->fetchAll();
    }
    
    private function getRevenueByPaymentMethod() {
        $sql = "SELECT payment_method, SUM(total_amount) as revenue, COUNT(*) as order_count
                FROM orders 
                WHERE order_status = 'delivered' 
                GROUP BY payment_method 
                ORDER BY revenue DESC";
        
        $stmt = $this->userModel->executeQuery($sql);
        return $stmt->fetchAll();
    }
    
    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
?>