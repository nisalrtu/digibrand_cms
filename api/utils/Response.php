<?php
/**
 * Response Helper Class
 */

class Response {
    
    public static function json($data = null, $message = '', $status_code = 200, $success = true) {
        http_response_code($status_code);
        
        $response = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    public static function success($data = null, $message = 'Success') {
        self::json($data, $message, 200, true);
    }
    
    public static function error($message = 'Error occurred', $status_code = 400, $data = null) {
        self::json($data, $message, $status_code, false);
    }
    
    public static function unauthorized($message = 'Unauthorized access') {
        self::error($message, 401);
    }
    
    public static function forbidden($message = 'Access forbidden') {
        self::error($message, 403);
    }
    
    public static function notFound($message = 'Resource not found') {
        self::error($message, 404);
    }
    
    public static function serverError($message = 'Internal server error') {
        self::error($message, 500);
    }
    
    public static function created($data = null, $message = 'Resource created successfully') {
        self::json($data, $message, 201, true);
    }
    
    public static function validationError($errors, $message = 'Validation failed') {
        self::json(['errors' => $errors], $message, 422, false);
    }
}
?>
