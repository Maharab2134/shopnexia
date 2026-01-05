<?php
class JWT {
    private $secretKey = "shopnexia_secret_key_2024";
    private $algorithm = 'HS256';
    
    public function createToken($data, $expiry = 86400) { // 24 hours default
        $header = $this->base64UrlEncode(json_encode([
            'alg' => $this->algorithm,
            'typ' => 'JWT'
        ]));
        
        $payload = $this->base64UrlEncode(json_encode([
            'data' => $data,
            'iat' => time(),
            'exp' => time() + $expiry
        ]));
        
        $signature = $this->base64UrlEncode(
            hash_hmac('sha256', "$header.$payload", $this->secretKey, true)
        );
        
        return "$header.$payload.$signature";
    }
    
    public function validateToken($token) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            throw new Exception('Invalid token format');
        }
        
        list($header, $payload, $signature) = $parts;
        
        $validSignature = $this->base64UrlEncode(
            hash_hmac('sha256', "$header.$payload", $this->secretKey, true)
        );
        
        if (!hash_equals($validSignature, $signature)) {
            throw new Exception('Invalid token signature');
        }
        
        $decodedPayload = json_decode($this->base64UrlDecode($payload));
        
        if (isset($decodedPayload->exp) && $decodedPayload->exp < time()) {
            throw new Exception('Token has expired');
        }
        
        return $decodedPayload;
    }
    
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private function base64UrlDecode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
    
    public function refreshToken($token, $expiry = 86400) {
        try {
            $decoded = $this->validateToken($token);
            return $this->createToken($decoded->data, $expiry);
        } catch (Exception $e) {
            throw new Exception('Cannot refresh invalid token');
        }
    }
}
?>