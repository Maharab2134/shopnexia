<?php
class Validation {
    public function validateRegistration($data) {
        $errors = [];
        
        // Validate name
        if (empty($data['name'])) {
            $errors['name'] = 'Name is required';
        } elseif (strlen($data['name']) < 2) {
            $errors['name'] = 'Name must be at least 2 characters';
        }
        
        // Validate email
        if (empty($data['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        }
        
        // Validate phone (Bangladeshi format)
        if (empty($data['phone'])) {
            $errors['phone'] = 'Phone number is required';
        } elseif (!preg_match('/^(?:\+88|01)?(?:\d{11}|\d{13})$/', $data['phone'])) {
            $errors['phone'] = 'Invalid phone number format';
        }
        
        // Validate password
        if (empty($data['password'])) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($data['password']) < 6) {
            $errors['password'] = 'Password must be at least 6 characters';
        }
        
        // Validate confirm password
        if ($data['password'] !== ($data['confirm_password'] ?? '')) {
            $errors['confirm_password'] = 'Passwords do not match';
        }
        
        return $errors;
    }
    
    public function validateProduct($data) {
        $errors = [];
        
        // Validate required fields
        $required = ['name', 'slug', 'category_id', 'regular_price', 'stock_quantity'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        // Validate price
        if (isset($data['regular_price']) && $data['regular_price'] <= 0) {
            $errors['regular_price'] = 'Price must be greater than 0';
        }
        
        if (isset($data['sale_price']) && $data['sale_price'] >= $data['regular_price']) {
            $errors['sale_price'] = 'Sale price must be less than regular price';
        }
        
        // Validate stock
        if (isset($data['stock_quantity']) && $data['stock_quantity'] < 0) {
            $errors['stock_quantity'] = 'Stock quantity cannot be negative';
        }
        
        // Validate slug format
        if (isset($data['slug']) && !preg_match('/^[a-z0-9-]+$/', $data['slug'])) {
            $errors['slug'] = 'Slug can only contain lowercase letters, numbers, and hyphens';
        }
        
        return $errors;
    }
    
    public function validateOrder($data) {
        $errors = [];
        
        $required = ['address_id', 'items', 'payment_method'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        // Validate items array
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $index => $item) {
                if (empty($item['product_id']) || empty($item['quantity']) || $item['quantity'] <= 0) {
                    $errors['items'][$index] = 'Invalid item data';
                }
            }
        }
        
        // Validate payment method
        $validMethods = ['bkash', 'nagad', 'rocket', 'cod'];
        if (isset($data['payment_method']) && !in_array($data['payment_method'], $validMethods)) {
            $errors['payment_method'] = 'Invalid payment method';
        }
        
        return $errors;
    }
    
    public function validateAddress($data) {
        $errors = [];
        
        $required = ['address_type', 'full_name', 'phone', 'address_line1', 'city', 'division'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        // Validate address type
        $validTypes = ['home', 'office', 'others'];
        if (isset($data['address_type']) && !in_array($data['address_type'], $validTypes)) {
            $errors['address_type'] = 'Invalid address type';
        }
        
        // Validate phone
        if (isset($data['phone']) && !preg_match('/^(?:\+88|01)?(?:\d{11}|\d{13})$/', $data['phone'])) {
            $errors['phone'] = 'Invalid phone number format';
        }
        
        return $errors;
    }
    
    public function sanitizeInput($input) {
        if (is_array($input)) {
            foreach ($input as $key => $value) {
                $input[$key] = $this->sanitizeInput($value);
            }
            return $input;
        }
        
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    public function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    
    public function validatePhone($phone) {
        return preg_match('/^(?:\+88|01)?(?:\d{11}|\d{13})$/', $phone);
    }
    
    public function validateNumeric($value, $min = null, $max = null) {
        if (!is_numeric($value)) {
            return false;
        }
        
        if ($min !== null && $value < $min) {
            return false;
        }
        
        if ($max !== null && $value > $max) {
            return false;
        }
        
        return true;
    }
}
?>