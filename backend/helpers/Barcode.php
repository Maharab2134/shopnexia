<?php
require_once __DIR__ . '/../vendor/autoload.php'; // For barcode library

class BarcodeHelper {
    private $generator;
    
    public function __construct() {
        // Initialize barcode generator (example using picqer/php-barcode-generator)
        $this->generator = new Picqer\Barcode\BarcodeGeneratorPNG();
    }
    
    public function generateBarcode($code, $type = 'CODE128') {
        try {
            // Generate barcode image
            $barcodeData = $this->generator->getBarcode($code, $type);
            
            // Save to file
            $filename = 'barcode_' . $code . '.png';
            $filepath = __DIR__ . '/../uploads/barcodes/' . $filename;
            
            file_put_contents($filepath, $barcodeData);
            
            return [
                'filename' => $filename,
                'filepath' => $filepath,
                'code' => $code,
                'type' => $type
            ];
        } catch (Exception $e) {
            throw new Exception("Barcode generation failed: " . $e->getMessage());
        }
    }
    
    public function generateProductBarcode($productId, $sku = null) {
        // Generate unique barcode
        $barcode = $this->generateUniqueBarcode();
        
        // Save to database
        $this->saveBarcodeToDatabase($productId, $barcode);
        
        // Generate image
        $barcodeInfo = $this->generateBarcode($barcode);
        
        return [
            'barcode' => $barcode,
            'image' => $barcodeInfo['filename'],
            'product_id' => $productId
        ];
    }
    
    public function validateBarcode($barcode) {
        // Validate barcode format (EAN-13, UPC, CODE128, etc.)
        if (empty($barcode)) {
            return false;
        }
        
        // Check length based on type
        $length = strlen($barcode);
        
        if ($length >= 8 && $length <= 13) {
            // EAN/UPC format
            return $this->validateEAN($barcode);
        } elseif ($length <= 128) {
            // CODE128 can have variable length
            return preg_match('/^[A-Za-z0-9\-\.\$\+\%\/\s]+$/', $barcode);
        }
        
        return false;
    }
    
    public function scanBarcode($imagePath) {
        // This would require a barcode scanning library
        // For now, return mock data
        return [
            'code' => '123456789012',
            'type' => 'EAN-13',
            'valid' => true
        ];
    }
    
    private function generateUniqueBarcode() {
        // Generate a unique barcode (EAN-13 format example)
        $prefix = '8'; // Bangladesh prefix
        $company = '78901'; // Company code
        $product = str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $code = $prefix . $company . $product;
        
        // Calculate check digit
        $checkDigit = $this->calculateEANCheckDigit($code);
        
        return $code . $checkDigit;
    }
    
    private function calculateEANCheckDigit($code) {
        $sum = 0;
        $code = str_pad($code, 12, '0', STR_PAD_LEFT);
        
        for ($i = 0; $i < 12; $i++) {
            $digit = (int)$code[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        
        $checkDigit = (10 - ($sum % 10)) % 10;
        return $checkDigit;
    }
    
    private function validateEAN($code) {
        if (!preg_match('/^\d+$/', $code)) {
            return false;
        }
        
        $length = strlen($code);
        if ($length !== 8 && $length !== 13) {
            return false;
        }
        
        // Validate check digit
        $data = substr($code, 0, -1);
        $checkDigit = substr($code, -1);
        $calculated = $this->calculateEANCheckDigit($data);
        
        return $checkDigit == $calculated;
    }
    
    private function saveBarcodeToDatabase($productId, $barcode) {
        $db = new Database();
        $conn = $db->getConnection();
        
        $sql = "UPDATE products SET barcode = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$barcode, $productId]);
        
        // Log barcode generation
        $this->logBarcodeActivity($productId, $barcode);
    }
    
    private function logBarcodeActivity($productId, $barcode) {
        $db = new Database();
        $conn = $db->getConnection();
        
        $sql = "INSERT INTO activity_logs (action, description, created_at) 
                VALUES ('barcode_generate', ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->execute(["Generated barcode $barcode for product #$productId"]);
    }
}
?>