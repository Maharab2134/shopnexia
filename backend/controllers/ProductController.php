<?php
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../helpers/Validation.php';

class ProductController {
    private $productModel;
    private $auth;
    private $validator;
    
    public function __construct() {
        $this->productModel = new Product();
        $this->auth = new AuthMiddleware();
        $this->validator = new Validation();
    }
    
    public function getAll() {
        $filters = [
            'search' => $_GET['search'] ?? '',
            'category_id' => $_GET['category'] ?? '',
            'min_price' => $_GET['min_price'] ?? '',
            'max_price' => $_GET['max_price'] ?? '',
            'brand' => $_GET['brand'] ?? '',
            'is_featured' => $_GET['featured'] ?? '',
            'is_active' => $_GET['active'] ?? '',
            'sort' => $_GET['sort'] ?? 'created_at',
            'order' => $_GET['order'] ?? 'DESC'
        ];
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $result = $this->productModel->findAllWithFilters($filters, $limit, $offset);
        
        $this->sendResponse(200, [
            'products' => $result['products'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($result['total'] / $limit)
            ]
        ]);
    }
    
    public function getById($id) {
        $product = $this->productModel->find($id);
        
        if (!$product) {
            $this->sendResponse(404, ['error' => 'Product not found']);
        }
        
        // Track view if user is authenticated
        try {
            $userData = $this->auth->authenticate();
            $sessionId = session_id();
            $ipAddress = $_SERVER['REMOTE_ADDR'];
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            
            $this->productModel->trackProductView(
                $userData->user_id,
                $id,
                $sessionId,
                $ipAddress,
                $userAgent
            );
        } catch (Exception $e) {
            // User not authenticated, still track with session
            $sessionId = session_id();
            $ipAddress = $_SERVER['REMOTE_ADDR'];
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
            
            $this->productModel->trackProductView(
                null,
                $id,
                $sessionId,
                $ipAddress,
                $userAgent
            );
        }
        
        $this->sendResponse(200, ['product' => $product]);
    }
    
    public function getBySlug($slug) {
        $product = $this->productModel->findBySlug($slug);
        
        if (!$product) {
            $this->sendResponse(404, ['error' => 'Product not found']);
        }
        
        $this->sendResponse(200, ['product' => $product]);
    }
    
    public function getByBarcode($barcode) {
        $product = $this->productModel->findByBarcode($barcode);
        
        if (!$product) {
            $this->sendResponse(404, ['error' => 'Product not found']);
        }
        
        $this->sendResponse(200, ['product' => $product]);
    }
    
    public function search() {
        $query = $_GET['q'] ?? '';
        $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
        
        if (empty($query)) {
            $this->sendResponse(400, ['error' => 'Search query is required']);
        }
        
        $filters = ['search' => $query];
        $result = $this->productModel->findAllWithFilters($filters, $limit, 0);
        
        $this->sendResponse(200, [
            'products' => $result['products'],
            'total' => $result['total']
        ]);
    }
    
    public function getByCategory($categoryId) {
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $filters = ['category_id' => $categoryId];
        $result = $this->productModel->findAllWithFilters($filters, $limit, $offset);
        
        $this->sendResponse(200, [
            'products' => $result['products'],
            'pagination' => [
                'total' => $result['total'],
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($result['total'] / $limit)
            ]
        ]);
    }
    
    public function getFeatured() {
        $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
        $products = $this->productModel->getFeaturedProducts($limit);
        
        $this->sendResponse(200, ['products' => $products]);
    }
    
    public function getBestSellers() {
        $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
        $products = $this->productModel->getBestSellers($limit);
        
        $this->sendResponse(200, ['products' => $products]);
    }
    
    public function getTrending() {
        $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
        $products = $this->productModel->getTrendingProducts($limit);
        
        $this->sendResponse(200, ['products' => $products]);
    }
    
    public function getNewArrivals() {
        $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
        $products = $this->productModel->getNewArrivals($limit);
        
        $this->sendResponse(200, ['products' => $products]);
    }
    
    public function getSimilar($id) {
        $product = $this->productModel->find($id);
        
        if (!$product) {
            $this->sendResponse(404, ['error' => 'Product not found']);
        }
        
        $limit = min(10, max(1, intval($_GET['limit'] ?? 6)));
        $products = $this->productModel->getSimilarProducts($id, $product['category_id'], $limit);
        
        $this->sendResponse(200, ['products' => $products]);
    }
    
    public function getRecommended($id) {
        try {
            $userData = $this->auth->authenticate();
            $limit = min(10, max(1, intval($_GET['limit'] ?? 6)));
            $products = $this->productModel->getRecommendedProducts($userData->user_id, $limit);
            
            $this->sendResponse(200, ['products' => $products]);
        } catch (Exception $e) {
            // Return popular products if user not authenticated
            $products = $this->productModel->getFeaturedProducts(6);
            $this->sendResponse(200, ['products' => $products]);
        }
    }
    
    public function trackView($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $sessionId = $data['session_id'] ?? session_id();
        $ipAddress = $_SERVER['REMOTE_ADDR'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        
        $userId = null;
        try {
            $userData = $this->auth->authenticate();
            $userId = $userData->user_id;
        } catch (Exception $e) {
            // User not authenticated
        }
        
        $this->productModel->trackProductView($userId, $id, $sessionId, $ipAddress, $userAgent);
        
        $this->sendResponse(200, ['message' => 'View tracked']);
    }
    
    public function getBrands() {
        $brands = $this->productModel->getBrands();
        $this->sendResponse(200, ['brands' => $brands]);
    }
    
    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
?>