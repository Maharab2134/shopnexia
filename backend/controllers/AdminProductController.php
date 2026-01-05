<?php
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../helpers/Barcode.php';
require_once __DIR__ . '/../middleware/AdminMiddleware.php';

class AdminProductController {
    private $productModel;
    private $barcodeHelper;
    private $auth;
    
    public function __construct() {
        $this->productModel = new Product();
        $this->barcodeHelper = new BarcodeHelper();
        $this->auth = new AdminMiddleware();
    }
    
    public function getAll() {
        $this->auth->authenticate();
        
        $filters = [
            'search' => $_GET['search'] ?? '',
            'category_id' => $_GET['category'] ?? '',
            'is_active' => $_GET['active'] ?? '',
            'is_featured' => $_GET['featured'] ?? '',
            'stock_status' => $_GET['stock_status'] ?? ''
        ];
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($_GET['limit'] ?? 50)));
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
    
    public function create() {
        $this->auth->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['name', 'slug', 'category_id', 'regular_price', 'stock_quantity'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->sendResponse(400, ['error' => "$field is required"]);
            }
        }
        
        // Generate SKU if not provided
        if (empty($data['sku'])) {
            $data['sku'] = $this->generateSku($data['name'], $data['category_id']);
        }
        
        // Check if SKU exists
        $existingProduct = $this->productModel->findBySku($data['sku']);
        if ($existingProduct) {
            $this->sendResponse(400, ['error' => 'SKU already exists']);
        }
        
        // Generate barcode if not provided
        if (empty($data['barcode'])) {
            $barcodeInfo = $this->barcodeHelper->generateProductBarcode(0, $data['sku']);
            $data['barcode'] = $barcodeInfo['barcode'];
        }
        
        // Prepare product data
        $productData = [
            'sku' => $data['sku'],
            'barcode' => $data['barcode'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? '',
            'short_description' => $data['short_description'] ?? '',
            'category_id' => $data['category_id'],
            'brand' => $data['brand'] ?? '',
            'regular_price' => $data['regular_price'],
            'sale_price' => $data['sale_price'] ?? null,
            'cost_price' => $data['cost_price'] ?? null,
            'stock_quantity' => $data['stock_quantity'],
            'low_stock_threshold' => $data['low_stock_threshold'] ?? 5,
            'weight' => $data['weight'] ?? null,
            'dimensions' => $data['dimensions'] ?? null,
            'main_image' => $data['main_image'] ?? '',
            'image_gallery' => json_encode($data['image_gallery'] ?? []),
            'attributes' => json_encode($data['attributes'] ?? []),
            'specifications' => json_encode($data['specifications'] ?? []),
            'is_featured' => $data['is_featured'] ?? false,
            'is_active' => $data['is_active'] ?? true
        ];
        
        $productId = $this->productModel->create($productData);
        
        // Update barcode with actual product ID
        if (isset($barcodeInfo)) {
            $this->barcodeHelper->saveBarcodeToDatabase($productId, $barcodeInfo['barcode']);
        }
        
        $this->sendResponse(201, [
            'message' => 'Product created successfully',
            'product_id' => $productId,
            'sku' => $data['sku'],
            'barcode' => $data['barcode']
        ]);
    }
    
    public function getById($id) {
        $this->auth->authenticate();
        
        $product = $this->productModel->find($id);
        if (!$product) {
            $this->sendResponse(404, ['error' => 'Product not found']);
        }
        
        // Decode JSON fields
        if ($product['image_gallery']) {
            $product['image_gallery'] = json_decode($product['image_gallery'], true);
        }
        
        if ($product['attributes']) {
            $product['attributes'] = json_decode($product['attributes'], true);
        }
        
        if ($product['specifications']) {
            $product['specifications'] = json_decode($product['specifications'], true);
        }
        
        // Get product stats
        $product['stats'] = $this->productModel->getProductStats($id);
        
        $this->sendResponse(200, ['product' => $product]);
    }
    
    public function update($id) {
        $this->auth->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);
        
        $product = $this->productModel->find($id);
        if (!$product) {
            $this->sendResponse(404, ['error' => 'Product not found']);
        }
        
        // Check if SKU is being changed and exists
        if (isset($data['sku']) && $data['sku'] !== $product['sku']) {
            $existingProduct = $this->productModel->findBySku($data['sku']);
            if ($existingProduct && $existingProduct['id'] != $id) {
                $this->sendResponse(400, ['error' => 'SKU already exists']);
            }
        }
        
        // Check if slug is being changed and exists
        if (isset($data['slug']) && $data['slug'] !== $product['slug']) {
            $existingProduct = $this->productModel->findBySlug($data['slug']);
            if ($existingProduct && $existingProduct['id'] != $id) {
                $this->sendResponse(400, ['error' => 'Slug already exists']);
            }
        }
        
        // Prepare update data
        $updateData = [];
        $allowedFields = [
            'name', 'slug', 'description', 'short_description', 'category_id',
            'brand', 'regular_price', 'sale_price', 'cost_price',
            'stock_quantity', 'low_stock_threshold', 'weight', 'dimensions',
            'main_image', 'image_gallery', 'attributes', 'specifications',
            'is_featured', 'is_active'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if (in_array($field, ['image_gallery', 'attributes', 'specifications'])) {
                    $updateData[$field] = json_encode($data[$field]);
                } else {
                    $updateData[$field] = $data[$field];
                }
            }
        }
        
        if (!empty($updateData)) {
            $this->productModel->update($id, $updateData);
        }
        
        $this->sendResponse(200, ['message' => 'Product updated successfully']);
    }
    
    public function delete($id) {
        $this->auth->authenticate();
        
        $product = $this->productModel->find($id);
        if (!$product) {
            $this->sendResponse(404, ['error' => 'Product not found']);
        }
        
        // Check if product has orders
        $hasOrders = $this->checkProductOrders($id);
        if ($hasOrders) {
            $this->sendResponse(400, ['error' => 'Cannot delete product with existing orders']);
        }
        
        // Soft delete by setting inactive
        $this->productModel->update($id, ['is_active' => false]);
        
        $this->sendResponse(200, ['message' => 'Product deleted successfully']);
    }
    
    public function generateBarcode($id) {
        $this->auth->authenticate();
        
        $product = $this->productModel->find($id);
        if (!$product) {
            $this->sendResponse(404, ['error' => 'Product not found']);
        }
        
        // Generate new barcode
        $barcodeInfo = $this->barcodeHelper->generateProductBarcode($id, $product['sku']);
        
        // Update product with new barcode
        $this->productModel->update($id, ['barcode' => $barcodeInfo['barcode']]);
        
        $this->sendResponse(200, [
            'message' => 'Barcode generated successfully',
            'barcode' => $barcodeInfo['barcode'],
            'image' => $barcodeInfo['image']
        ]);
    }
    
    public function updateStock($id) {
        $this->auth->authenticate();
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['quantity']) || !isset($data['operation'])) {
            $this->sendResponse(400, ['error' => 'Quantity and operation are required']);
        }
        
        $product = $this->productModel->find($id);
        if (!$product) {
            $this->sendResponse(404, ['error' => 'Product not found']);
        }
        
        $this->productModel->updateStock($id, $data['quantity'], $data['operation']);
        
        $this->sendResponse(200, ['message' => 'Stock updated successfully']);
    }
    
    public function export() {
        $this->auth->authenticate();
        
        $filters = [
            'category_id' => $_GET['category'] ?? '',
            'is_active' => $_GET['active'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? ''
        ];
        
        $products = $this->getProductsForExport($filters);
        
        // Generate CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="products_export_' . date('Ymd_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, [
            'SKU', 'Barcode', 'Name', 'Category', 'Brand', 'Regular Price',
            'Sale Price', 'Cost Price', 'Stock Quantity', 'Low Stock Threshold',
            'Is Featured', 'Is Active', 'Views', 'Sales', 'Rating', 'Created Date'
        ]);
        
        // Data
        foreach ($products as $product) {
            fputcsv($output, [
                $product['sku'],
                $product['barcode'],
                $product['name'],
                $product['category_name'],
                $product['brand'],
                $product['regular_price'],
                $product['sale_price'],
                $product['cost_price'],
                $product['stock_quantity'],
                $product['low_stock_threshold'],
                $product['is_featured'] ? 'Yes' : 'No',
                $product['is_active'] ? 'Active' : 'Inactive',
                $product['views_count'],
                $product['sales_count'],
                $product['rating_avg'],
                $product['created_at']
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    private function generateSku($name, $categoryId) {
        $prefix = strtoupper(substr(preg_replace('/[^A-Z]/', '', $name), 0, 3));
        $categoryCode = str_pad($categoryId, 3, '0', STR_PAD_LEFT);
        $random = strtoupper(substr(uniqid(), -4));
        
        return $prefix . $categoryCode . $random;
    }
    
    private function checkProductOrders($productId) {
        $sql = "SELECT COUNT(*) as count FROM order_items WHERE product_id = ?";
        $stmt = $this->productModel->executeQuery($sql, [$productId]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
    }
    
    private function getProductsForExport($filters) {
        $sql = "SELECT p.*, c.name as category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                WHERE 1=1";
        
        $whereClauses = [];
        $params = [];
        
        if (!empty($filters['category_id'])) {
            $whereClauses[] = "p.category_id = ?";
            $params[] = $filters['category_id'];
        }
        
        if ($filters['is_active'] !== '') {
            $whereClauses[] = "p.is_active = ?";
            $params[] = $filters['is_active'];
        }
        
        if (!empty($filters['date_from'])) {
            $whereClauses[] = "DATE(p.created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $whereClauses[] = "DATE(p.created_at) <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($whereClauses)) {
            $sql .= " AND " . implode(" AND ", $whereClauses);
        }
        
        $sql .= " ORDER BY p.created_at DESC";
        
        $stmt = $this->productModel->executeQuery($sql, $params);
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