<?php
/**
 * Client Model
 */

require_once __DIR__ . '/../config/database.php';

class Client {
    private $conn;
    private $table_name = "clients";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    public function create($data) {
        $query = "INSERT INTO " . $this->table_name . " 
                  (company_name, contact_person, mobile_number, address, city) 
                  VALUES (:company_name, :contact_person, :mobile_number, :address, :city)";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':company_name', $data['company_name']);
        $stmt->bindParam(':contact_person', $data['contact_person']);
        $stmt->bindParam(':mobile_number', $data['mobile_number']);
        $stmt->bindParam(':address', $data['address']);
        $stmt->bindParam(':city', $data['city']);
        
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    public function getAll($filters = []) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE 1=1";
        $params = [];
        
        if (isset($filters['search']) && !empty($filters['search'])) {
            $query .= " AND (company_name LIKE :search OR contact_person LIKE :search OR mobile_number LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (isset($filters['is_active'])) {
            $query .= " AND is_active = :is_active";
            $params[':is_active'] = $filters['is_active'];
        }
        
        $query .= " ORDER BY company_name ASC";
        
        // Add pagination
        if (isset($filters['limit']) && isset($filters['offset'])) {
            $query .= " LIMIT :limit OFFSET :offset";
            $params[':limit'] = (int)$filters['limit'];
            $params[':offset'] = (int)$filters['offset'];
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            if ($key === ':limit' || $key === ':offset') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        
        if ($stmt->execute()) {
            return $stmt->fetchAll();
        }
        
        return [];
    }
    
    public function getById($id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            return $stmt->fetch();
        }
        
        return false;
    }
    
    public function update($id, $data) {
        $set_clauses = [];
        $params = [':id' => $id];
        
        $allowed_fields = ['company_name', 'contact_person', 'mobile_number', 'address', 'city', 'is_active'];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                $set_clauses[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }
        
        if (empty($set_clauses)) {
            return false;
        }
        
        $query = "UPDATE " . $this->table_name . " 
                  SET " . implode(', ', $set_clauses) . " 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        return $stmt->execute();
    }
    
    public function delete($id) {
        $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        
        return $stmt->execute();
    }
    
    public function toggleStatus($id) {
        $query = "UPDATE " . $this->table_name . " 
                  SET is_active = NOT is_active 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        
        return $stmt->execute();
    }
    
    public function getStats($id) {
        $query = "SELECT 
                    COUNT(DISTINCT p.id) as total_projects,
                    COUNT(DISTINCT i.id) as total_invoices,
                    COALESCE(SUM(i.total_amount), 0) as total_revenue,
                    COALESCE(SUM(i.balance_amount), 0) as pending_amount
                  FROM " . $this->table_name . " c
                  LEFT JOIN projects p ON c.id = p.client_id
                  LEFT JOIN invoices i ON c.id = i.client_id
                  WHERE c.id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            return $stmt->fetch();
        }
        
        return false;
    }
    
    public function getCount($filters = []) {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name . " WHERE 1=1";
        $params = [];
        
        if (isset($filters['search']) && !empty($filters['search'])) {
            $query .= " AND (company_name LIKE :search OR contact_person LIKE :search OR mobile_number LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        
        if (isset($filters['is_active'])) {
            $query .= " AND is_active = :is_active";
            $params[':is_active'] = $filters['is_active'];
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        if ($stmt->execute()) {
            $result = $stmt->fetch();
            return $result['total'];
        }
        
        return 0;
    }
}
?>
