<?php
require_once __DIR__ . '/../models/Model.php';

class CategoryController {
    private $categoryModel;
    
    public function __construct() {
        $this->categoryModel = new Model('categories');
    }
    
    public function getAll() {
        $sql = "SELECT * FROM categories WHERE is_active = 1 ORDER BY display_order, name";
        $stmt = $this->categoryModel->executeQuery($sql);
        $categories = $stmt->fetchAll();
        
        $this->sendResponse(200, ['categories' => $categories]);
    }
    
    public function getById($id) {
        $category = $this->categoryModel->find($id);
        
        if (!$category) {
            $this->sendResponse(404, ['error' => 'Category not found']);
        }
        
        $this->sendResponse(200, ['category' => $category]);
    }
    
    public function getParentCategories($parentId = null) {
        if ($parentId === null) {
            $sql = "SELECT * FROM categories WHERE parent_id IS NULL AND is_active = 1 ORDER BY display_order";
        } else {
            $sql = "SELECT * FROM categories WHERE parent_id = ? AND is_active = 1 ORDER BY display_order";
        }
        
        $params = $parentId ? [$parentId] : [];
        $stmt = $this->categoryModel->executeQuery($sql, $params);
        $categories = $stmt->fetchAll();
        
        $this->sendResponse(200, ['categories' => $categories]);
    }
    
    public function getCategoryTree() {
        $sql = "SELECT * FROM categories WHERE is_active = 1 ORDER BY parent_id, display_order";
        $stmt = $this->categoryModel->executeQuery($sql);
        $categories = $stmt->fetchAll();
        
        $tree = $this->buildCategoryTree($categories);
        
        $this->sendResponse(200, ['categories' => $tree]);
    }
    
    private function buildCategoryTree($categories, $parentId = null) {
        $branch = [];
        
        foreach ($categories as $category) {
            if ($category['parent_id'] == $parentId) {
                $children = $this->buildCategoryTree($categories, $category['id']);
                if ($children) {
                    $category['children'] = $children;
                }
                $branch[] = $category;
            }
        }
        
        return $branch;
    }
    
    // Admin methods
    public function adminGetAll() {
        $sql = "SELECT c1.*, c2.name as parent_name 
                FROM categories c1 
                LEFT JOIN categories c2 ON c1.parent_id = c2.id 
                ORDER BY c1.display_order, c1.name";
        
        $stmt = $this->categoryModel->executeQuery($sql);
        $categories = $stmt->fetchAll();
        
        $this->sendResponse(200, ['categories' => $categories]);
    }
    
    public function adminCreate() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if (empty($data['name']) || empty($data['slug'])) {
            $this->sendResponse(400, ['error' => 'Name and slug are required']);
        }
        
        // Check if slug exists
        $existing = $this->getCategoryBySlug($data['slug']);
        if ($existing) {
            $this->sendResponse(400, ['error' => 'Slug already exists']);
        }
        
        $categoryData = [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? '',
            'parent_id' => $data['parent_id'] ?? null,
            'image' => $data['image'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'display_order' => $data['display_order'] ?? 0
        ];
        
        $categoryId = $this->categoryModel->create($categoryData);
        
        $this->sendResponse(201, [
            'message' => 'Category created successfully',
            'category_id' => $categoryId
        ]);
    }
    
    public function adminUpdate($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $category = $this->categoryModel->find($id);
        if (!$category) {
            $this->sendResponse(404, ['error' => 'Category not found']);
        }
        
        // Check if slug exists for another category
        if (isset($data['slug']) && $data['slug'] !== $category['slug']) {
            $existing = $this->getCategoryBySlug($data['slug']);
            if ($existing && $existing['id'] != $id) {
                $this->sendResponse(400, ['error' => 'Slug already exists']);
            }
        }
        
        $updateData = [];
        $allowedFields = ['name', 'slug', 'description', 'parent_id', 'image', 'is_active', 'display_order'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (!empty($updateData)) {
            $this->categoryModel->update($id, $updateData);
        }
        
        $this->sendResponse(200, ['message' => 'Category updated successfully']);
    }
    
    public function adminDelete($id) {
        // Check if category has products
        $productCount = $this->countProductsInCategory($id);
        if ($productCount > 0) {
            $this->sendResponse(400, ['error' => 'Cannot delete category with products']);
        }
        
        // Check if category has subcategories
        $subcategoryCount = $this->countSubcategories($id);
        if ($subcategoryCount > 0) {
            $this->sendResponse(400, ['error' => 'Cannot delete category with subcategories']);
        }
        
        $this->categoryModel->delete($id);
        
        $this->sendResponse(200, ['message' => 'Category deleted successfully']);
    }
    
    public function adminToggleStatus($id) {
        $category = $this->categoryModel->find($id);
        if (!$category) {
            $this->sendResponse(404, ['error' => 'Category not found']);
        }
        
        $newStatus = $category['is_active'] ? 0 : 1;
        $this->categoryModel->update($id, ['is_active' => $newStatus]);
        
        $this->sendResponse(200, [
            'message' => 'Category status updated',
            'is_active' => $newStatus
        ]);
    }
    
    private function getCategoryBySlug($slug) {
        $sql = "SELECT * FROM categories WHERE slug = ?";
        $stmt = $this->categoryModel->executeQuery($sql, [$slug]);
        return $stmt->fetch();
    }
    
    private function countProductsInCategory($categoryId) {
        $sql = "SELECT COUNT(*) as count FROM products WHERE category_id = ?";
        $stmt = $this->categoryModel->executeQuery($sql, [$categoryId]);
        return $stmt->fetch()['count'];
    }
    
    private function countSubcategories($parentId) {
        $sql = "SELECT COUNT(*) as count FROM categories WHERE parent_id = ?";
        $stmt = $this->categoryModel->executeQuery($sql, [$parentId]);
        return $stmt->fetch()['count'];
    }
    
    private function sendResponse($statusCode, $data) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}
?>