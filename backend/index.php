<?php
// Enable CORS for all origins (adjust for production)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session
session_start();

// Error reporting (disable for production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Asia/Dhaka');

// Define base path
define('BASE_PATH', dirname(__FILE__));
define('UPLOAD_PATH', BASE_PATH . '/uploads/');

// Create upload directories if they don't exist
$uploadDirs = ['products', 'banners', 'users', 'barcodes'];
foreach ($uploadDirs as $dir) {
    $dirPath = UPLOAD_PATH . $dir;
    if (!is_dir($dirPath)) {
        mkdir($dirPath, 0755, true);
    }
}

// Autoload classes
spl_autoload_register(function ($className) {
    $directories = [
        'controllers/',
        'models/',
        'middleware/',
        'helpers/'
    ];
    
    foreach ($directories as $directory) {
        $file = BASE_PATH . '/' . $directory . $className . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
    
    // Log error instead of throwing exception to avoid breaking the API
    error_log("Class $className not found in autoloader");
});

// Helper function to send JSON response
function sendResponse($statusCode, $data, $message = null) {
    header('Content-Type: application/json');
    http_response_code($statusCode);
    
    $response = ['success' => $statusCode >= 200 && $statusCode < 300];
    
    if ($message !== null) {
        $response['message'] = $message;
    }
    
    if (is_array($data)) {
        $response = array_merge($response, $data);
    } else {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

// Helper function to parse request body
function getRequestBody() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/json') !== false) {
        $input = file_get_contents('php://input');
        return json_decode($input, true);
    } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
        return $_POST;
    } elseif (strpos($contentType, 'multipart/form-data') !== false) {
        return array_merge($_POST, $_FILES);
    } else {
        // Try to parse as JSON by default
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $data = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
        return $_REQUEST;
    }
}

// Parse URL
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Remove query string
$requestUri = strtok($requestUri, '?');

// Remove base path if exists
$basePath = '/shopnexia/backend';
if (strpos($requestUri, $basePath) === 0) {
    $requestUri = substr($requestUri, strlen($basePath));
}

// Ensure request URI starts with /
if (empty($requestUri) || $requestUri[0] !== '/') {
    $requestUri = '/' . $requestUri;
}

// Define API routes
$routes = [
    // ==================== AUTHENTICATION ROUTES ====================
    'POST:/api/auth/register' => ['AuthController', 'register'],
    'POST:/api/auth/login' => ['AuthController', 'login'],
    'POST:/api/auth/admin-login' => ['AuthController', 'adminLogin'],
    'POST:/api/auth/refresh-token' => ['AuthController', 'refreshToken'],
    'POST:/api/auth/logout' => ['AuthController', 'logout'],
    'GET:/api/auth/me' => ['AuthController', 'getCurrentUser'],
    'PUT:/api/auth/update-profile' => ['AuthController', 'updateProfile'],
    'POST:/api/auth/change-password' => ['AuthController', 'changePassword'],
    'POST:/api/auth/forgot-password' => ['AuthController', 'forgotPassword'],
    'POST:/api/auth/reset-password' => ['AuthController', 'resetPassword'],
    
    // ==================== USER ROUTES ====================
    'GET:/api/user/addresses' => ['UserController', 'getAddresses'],
    'POST:/api/user/addresses' => ['UserController', 'addAddress'],
    'GET:/api/user/addresses/(\d+)' => ['UserController', 'getAddress'],
    'PUT:/api/user/addresses/(\d+)' => ['UserController', 'updateAddress'],
    'DELETE:/api/user/addresses/(\d+)' => ['UserController', 'deleteAddress'],
    'PUT:/api/user/addresses/(\d+)/set-default' => ['UserController', 'setDefaultAddress'],
    
    // ==================== PRODUCT ROUTES ====================
    'GET:/api/products' => ['ProductController', 'getAll'],
    'GET:/api/products/search' => ['ProductController', 'search'],
    'GET:/api/products/featured' => ['ProductController', 'getFeatured'],
    'GET:/api/products/best-seller' => ['ProductController', 'getBestSellers'],
    'GET:/api/products/trending' => ['ProductController', 'getTrending'],
    'GET:/api/products/new-arrival' => ['ProductController', 'getNewArrivals'],
    'GET:/api/products/brands' => ['ProductController', 'getBrands'],
    'GET:/api/products/(\d+)' => ['ProductController', 'getById'],
    'GET:/api/products/slug/([a-zA-Z0-9-]+)' => ['ProductController', 'getBySlug'],
    'GET:/api/products/barcode/(.+)' => ['ProductController', 'getByBarcode'],
    'GET:/api/products/category/(\d+)' => ['ProductController', 'getByCategory'],
    'GET:/api/products/(\d+)/similar' => ['ProductController', 'getSimilar'],
    'GET:/api/products/(\d+)/recommended' => ['ProductController', 'getRecommended'],
    'POST:/api/products/(\d+)/view' => ['ProductController', 'trackView'],
    
    // ==================== CATEGORY ROUTES ====================
    'GET:/api/categories' => ['CategoryController', 'getAll'],
    'GET:/api/categories/(\d+)' => ['CategoryController', 'getById'],
    'GET:/api/categories/parent/(\d+)' => ['CategoryController', 'getParentCategories'],
    'GET:/api/categories/tree' => ['CategoryController', 'getCategoryTree'],
    
    // ==================== CART ROUTES ====================
    'GET:/api/cart' => ['CartController', 'getCart'],
    'POST:/api/cart' => ['CartController', 'addToCart'],
    'PUT:/api/cart/(\d+)' => ['CartController', 'updateCartItem'],
    'DELETE:/api/cart/(\d+)' => ['CartController', 'removeFromCart'],
    'DELETE:/api/cart' => ['CartController', 'clearCart'],
    'POST:/api/cart/apply-coupon' => ['CartController', 'applyCoupon'],
    'DELETE:/api/cart/remove-coupon' => ['CartController', 'removeCoupon'],
    
    // ==================== WISHLIST ROUTES ====================
    'GET:/api/wishlist' => ['WishlistController', 'getWishlist'],
    'POST:/api/wishlist/(\d+)' => ['WishlistController', 'addToWishlist'],
    'DELETE:/api/wishlist/(\d+)' => ['WishlistController', 'removeFromWishlist'],
    'GET:/api/wishlist/check/(\d+)' => ['WishlistController', 'checkWishlist'],
    
    // ==================== ORDER ROUTES ====================
    'GET:/api/orders' => ['OrderController', 'getUserOrders'],
    'GET:/api/orders/(\d+)' => ['OrderController', 'getOrderDetails'],
    'POST:/api/orders' => ['OrderController', 'createOrder'],
    'POST:/api/orders/(\d+)/cancel' => ['OrderController', 'cancelOrder'],
    'GET:/api/orders/(\d+)/track' => ['OrderController', 'trackOrder'],
    
    // ==================== PAYMENT ROUTES ====================
    'POST:/api/payments/(\d+)/initiate' => ['PaymentController', 'initiatePayment'],
    'POST:/api/payments/(\d+)/verify' => ['PaymentController', 'verifyPayment'],
    'GET:/api/payments/(\d+)/status' => ['PaymentController', 'getPaymentStatus'],
    
    // ==================== RETURN & REFUND ROUTES ====================
    'GET:/api/returns' => ['ReturnController', 'getUserReturns'],
    'POST:/api/returns' => ['ReturnController', 'requestReturn'],
    'GET:/api/returns/(\d+)' => ['ReturnController', 'getReturnDetails'],
    'POST:/api/returns/(\d+)/cancel' => ['ReturnController', 'cancelReturnRequest'],
    'GET:/api/refunds/(\d+)' => ['ReturnController', 'getRefundStatus'],
    
    // ==================== REVIEW ROUTES ====================
    'GET:/api/reviews/product/(\d+)' => ['ReviewController', 'getProductReviews'],
    'POST:/api/reviews' => ['ReviewController', 'addReview'],
    'PUT:/api/reviews/(\d+)' => ['ReviewController', 'updateReview'],
    'DELETE:/api/reviews/(\d+)' => ['ReviewController', 'deleteReview'],
    'POST:/api/reviews/(\d+)/helpful' => ['ReviewController', 'markAsHelpful'],
    
    // ==================== NOTIFICATION ROUTES ====================
    'GET:/api/notifications' => ['NotificationController', 'getNotifications'],
    'GET:/api/notifications/unread' => ['NotificationController', 'getUnreadNotifications'],
    'PUT:/api/notifications/(\d+)/read' => ['NotificationController', 'markAsRead'],
    'PUT:/api/notifications/read-all' => ['NotificationController', 'markAllAsRead'],
    'DELETE:/api/notifications/(\d+)' => ['NotificationController', 'deleteNotification'],
    
    // ==================== ADMIN DASHBOARD ROUTES ====================
    'GET:/api/admin/dashboard/stats' => ['AdminController', 'getDashboardStats'],
    'GET:/api/admin/dashboard/recent-orders' => ['AdminController', 'getRecentOrders'],
    'GET:/api/admin/dashboard/top-products' => ['AdminController', 'getTopProducts'],
    'GET:/api/admin/dashboard/sales-chart' => ['AdminController', 'getSalesChart'],
    'GET:/api/admin/dashboard/revenue' => ['AdminController', 'getRevenueAnalytics'],
    
    // ==================== ADMIN PRODUCT ROUTES ====================
    'GET:/api/admin/products' => ['AdminProductController', 'getAll'],
    'POST:/api/admin/products' => ['AdminProductController', 'create'],
    'GET:/api/admin/products/(\d+)' => ['AdminProductController', 'getById'],
    'PUT:/api/admin/products/(\d+)' => ['AdminProductController', 'update'],
    'DELETE:/api/admin/products/(\d+)' => ['AdminProductController', 'delete'],
    'POST:/api/admin/products/(\d+)/barcode' => ['AdminProductController', 'generateBarcode'],
    'PUT:/api/admin/products/(\d+)/stock' => ['AdminProductController', 'updateStock'],
    'GET:/api/admin/products/export' => ['AdminProductController', 'export'],
    
    // ==================== ADMIN CATEGORY ROUTES ====================
    'GET:/api/admin/categories' => ['CategoryController', 'adminGetAll'],
    'POST:/api/admin/categories' => ['CategoryController', 'adminCreate'],
    'PUT:/api/admin/categories/(\d+)' => ['CategoryController', 'adminUpdate'],
    'DELETE:/api/admin/categories/(\d+)' => ['CategoryController', 'adminDelete'],
    'PUT:/api/admin/categories/(\d+)/toggle' => ['CategoryController', 'adminToggleStatus'],
    
    // ==================== ADMIN ORDER ROUTES ====================
    'GET:/api/admin/orders' => ['OrderController', 'adminGetAll'],
    'PUT:/api/admin/orders/(\d+)/status' => ['OrderController', 'adminUpdateStatus'],
    'PUT:/api/admin/orders/(\d+)/assign-courier' => ['OrderController', 'adminAssignCourier'],
    'GET:/api/admin/orders/export' => ['OrderController', 'adminExport'],
    
    // ==================== ADMIN PAYMENT ROUTES ====================
    'GET:/api/admin/payments/pending' => ['PaymentController', 'adminGetPending'],
    'GET:/api/admin/payments/(\d+)' => ['PaymentController', 'adminGetDetails'],
    'PUT:/api/admin/payments/(\d+)/verify' => ['PaymentController', 'adminVerify'],
    'PUT:/api/admin/payments/(\d+)/reject' => ['PaymentController', 'adminReject'],
    'GET:/api/admin/payments/report' => ['PaymentController', 'adminGetReport'],
    
    // ==================== ADMIN RETURN ROUTES ====================
    'GET:/api/admin/returns/pending' => ['ReturnController', 'adminGetPending'],
    'GET:/api/admin/returns/(\d+)' => ['ReturnController', 'adminGetDetails'],
    'PUT:/api/admin/returns/(\d+)/approve' => ['ReturnController', 'adminApproveReturn'],
    'PUT:/api/admin/returns/(\d+)/reject' => ['ReturnController', 'adminReject'],
    'PUT:/api/admin/returns/(\d+)/mark-received' => ['ReturnController', 'adminMarkReceived'],
    'POST:/api/admin/refunds' => ['ReturnController', 'adminProcessRefund'],
    'PUT:/api/admin/refunds/(\d+)/status' => ['ReturnController', 'adminUpdateRefundStatus'],
    
    // ==================== ADMIN USER ROUTES ====================
    'GET:/api/admin/users' => ['UserAdminController', 'getAllUsers'],
    'GET:/api/admin/users/(\d+)' => ['UserAdminController', 'getUserDetails'],
    'PUT:/api/admin/users/(\d+)/block' => ['UserAdminController', 'toggleBlockUser'],
    'PUT:/api/admin/users/(\d+)/verify' => ['UserAdminController', 'verifyUser'],
    'DELETE:/api/admin/users/(\d+)' => ['UserAdminController', 'deleteUser'],
    'GET:/api/admin/users/(\d+)/orders' => ['UserAdminController', 'getUserOrders'],
    
    // ==================== ADMIN REVIEW ROUTES ====================
    'GET:/api/admin/reviews/pending' => ['ReviewController', 'adminGetPending'],
    'PUT:/api/admin/reviews/(\d+)/approve' => ['ReviewController', 'adminApproveReview'],
    'PUT:/api/admin/reviews/(\d+)/reject' => ['ReviewController', 'adminRejectReview'],
    'DELETE:/api/admin/reviews/(\d+)' => ['ReviewController', 'adminDeleteReview'],
    
    // ==================== ADMIN COUPON ROUTES ====================
    'GET:/api/admin/coupons' => ['CouponController', 'getAll'],
    'POST:/api/admin/coupons' => ['CouponController', 'create'],
    'PUT:/api/admin/coupons/(\d+)' => ['CouponController', 'update'],
    'DELETE:/api/admin/coupons/(\d+)' => ['CouponController', 'delete'],
    'PUT:/api/admin/coupons/(\d+)/toggle' => ['CouponController', 'toggleStatus'],
    
    // ==================== ADMIN BANNER ROUTES ====================
    'GET:/api/admin/banners' => ['BannerController', 'getAll'],
    'POST:/api/admin/banners' => ['BannerController', 'create'],
    'PUT:/api/admin/banners/(\d+)' => ['BannerController', 'update'],
    'DELETE:/api/admin/banners/(\d+)' => ['BannerController', 'delete'],
    'PUT:/api/admin/banners/(\d+)/toggle' => ['BannerController', 'toggleStatus'],
    'PUT:/api/admin/banners/reorder' => ['BannerController', 'reorder'],
    
    // ==================== ADMIN COURIER ROUTES ====================
    'POST:/api/admin/couriers/(\d+)/assign' => ['CourierController', 'assignCourier'],
    'PUT:/api/admin/couriers/(\d+)/status' => ['CourierController', 'updateCourierStatus'],
    'GET:/api/admin/couriers' => ['CourierController', 'getAllCouriers'],
    'GET:/api/admin/couriers/stats' => ['CourierController', 'getCourierStats'],
    'PUT:/api/admin/couriers/(\d+)/tracking' => ['CourierController', 'updateTrackingInfo'],
    'GET:/api/couriers/(\d+)/track' => ['CourierController', 'getCourierTracking'],
    
    // ==================== ADMIN NOTIFICATION ROUTES ====================
    'POST:/api/admin/notifications/send' => ['NotificationController', 'adminSendNotification'],
    'GET:/api/admin/notifications' => ['NotificationController', 'adminGetNotifications'],
    'GET:/api/admin/notifications/stats' => ['NotificationController', 'adminGetStatistics'],
    'DELETE:/api/admin/notifications/cleanup' => ['NotificationController', 'cleanupNotifications'],
    
    // ==================== ADMIN SETTINGS ROUTES ====================
    'GET:/api/admin/settings' => ['SettingsController', 'getAll'],
    'PUT:/api/admin/settings' => ['SettingsController', 'update'],
    'GET:/api/admin/settings/([a-zA-Z0-9_-]+)' => ['SettingsController', 'getByKey'],
    
    // ==================== ADMIN REPORTS ROUTES ====================
    'GET:/api/admin/reports/sales' => ['ReportController', 'getSalesReport'],
    'GET:/api/admin/reports/products' => ['ReportController', 'getProductReport'],
    'GET:/api/admin/reports/users' => ['ReportController', 'getUserReport'],
    'GET:/api/admin/reports/returns' => ['ReportController', 'getReturnsReport'],
    'GET:/api/admin/reports/export' => ['ReportController', 'exportReport'],
    
    // ==================== FILE UPLOAD ROUTES ====================
    'POST:/api/upload/image' => ['UploadController', 'uploadImage'],
    'POST:/api/upload/barcode' => ['UploadController', 'uploadBarcode'],
    'DELETE:/api/upload/(.+)' => ['UploadController', 'deleteFile'],
    
    // ==================== HEALTH CHECK ROUTES ====================
    'GET:/api/health' => ['HealthController', 'check'],
    'GET:/api/health/database' => ['HealthController', 'checkDatabase'],
];

// Find matching route
$matched = false;
foreach ($routes as $routePattern => $handler) {
    list($method, $pattern) = explode(':', $routePattern, 2);
    
    if ($requestMethod === $method && preg_match('#^' . $pattern . '$#', $requestUri, $matches)) {
        $matched = true;
        
        try {
            $controllerName = $handler[0];
            $methodName = $handler[1];
            
            // Check if controller class exists
            if (!class_exists($controllerName)) {
                sendResponse(500, ['error' => 'Controller not found'], "Internal server error");
            }
            
            $controller = new $controllerName();
            
            // Check if method exists
            if (!method_exists($controller, $methodName)) {
                sendResponse(500, ['error' => 'Method not found'], "Internal server error");
            }
            
            // Extract parameters from URL
            $params = array_slice($matches, 1);
            
            // Call controller method with parameters
            call_user_func_array([$controller, $methodName], $params);
            
        } catch (PDOException $e) {
            // Database error
            error_log("Database error: " . $e->getMessage());
            sendResponse(500, ['error' => 'Database error'], "Internal server error");
        } catch (Exception $e) {
            // General error
            error_log("Controller error: " . $e->getMessage());
            sendResponse(500, ['error' => $e->getMessage()], "Internal server error");
        }
        break;
    }
}

// Route not found
if (!$matched) {
    // Check if it's an API route
    if (strpos($requestUri, '/api/') === 0) {
        sendResponse(404, ['error' => 'API endpoint not found'], "Not Found");
    } else {
        // For non-API routes, check if file exists
        if (file_exists(BASE_PATH . $requestUri) && !is_dir(BASE_PATH . $requestUri)) {
            // Serve static file
            $extension = strtolower(pathinfo($requestUri, PATHINFO_EXTENSION));
            $mimeTypes = [
                'html' => 'text/html',
                'css' => 'text/css',
                'js' => 'application/javascript',
                'json' => 'application/json',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'svg' => 'image/svg+xml',
                'pdf' => 'application/pdf',
            ];
            
            if (isset($mimeTypes[$extension])) {
                header('Content-Type: ' . $mimeTypes[$extension]);
            }
            
            readfile(BASE_PATH . $requestUri);
        } else {
            // Return 404 for everything else
            sendResponse(404, ['error' => 'Route not found'], "Not Found");
        }
    }
}
?>