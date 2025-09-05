<?php
/**
 * Database Configuration for Invoice Manager API
 */

class Database {
    public $conn;

    // Local Development Settings
    private $host = 'localhost'; 
    private $db_name = 'digibrand_crm';
    private $username = 'root';
    private $password = '';

    // Production Settings (uncomment and update for hosting)
    // private $host = 'localhost'; 
    // private $db_name = 'pgmocpbh_invoice'; // Your hosting database name
    // private $username = 'pgmocpbh_invoice'; // Your hosting database username
    // private $password = 'Bandaranayake123'; // Your hosting database password

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            // In production, log errors instead of displaying them
            error_log("Database connection error: " . $exception->getMessage());
            
            // For development, show the error
            if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
                echo "Connection error: " . $exception->getMessage();
            } else {
                // In production, show generic error
                die("Database connection failed");
            }
        }
        
        return $this->conn;
    }
}
?>
