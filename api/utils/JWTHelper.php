<?php
/**
 * Simple JWT Helper Class (without external dependencies)
 */

class JWTHelper {
    
    public static function generateToken($user_data) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        
        $payload = json_encode([
            'iss' => API_BASE_URL,
            'aud' => API_BASE_URL,
            'iat' => time(),
            'exp' => time() + JWT_EXPIRY,
            'data' => [
                'user_id' => $user_data['id'],
                'username' => $user_data['username'],
                'email' => $user_data['email'],
                'role' => $user_data['role']
            ]
        ]);
        
        $base64_header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64_payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64_header . "." . $base64_payload, JWT_SECRET, true);
        $base64_signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64_header . "." . $base64_payload . "." . $base64_signature;
    }
    
    public static function validateToken($token) {
        try {
            $parts = explode('.', $token);
            
            if (count($parts) !== 3) {
                return false;
            }
            
            list($header, $payload, $signature) = $parts;
            
            // Verify signature
            $expected_signature = hash_hmac('sha256', $header . "." . $payload, JWT_SECRET, true);
            $expected_signature_base64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expected_signature));
            
            if (!hash_equals($signature, $expected_signature_base64)) {
                return false;
            }
            
            // Decode payload
            $payload_data = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)), true);
            
            // Check expiry
            if ($payload_data['exp'] < time()) {
                return false;
            }
            
            return $payload_data['data'];
        } catch (Exception $e) {
            return false;
        }
    }
    
    public static function getTokenFromHeaders() {
        $headers = getallheaders();
        
        if (isset($headers['Authorization'])) {
            $auth_header = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    public static function getCurrentUser() {
        $token = self::getTokenFromHeaders();
        
        if (!$token) {
            return false;
        }
        
        return self::validateToken($token);
    }
}
?>
