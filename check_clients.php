<?php
require_once 'core/Database.php';

$db = new Database();
$conn = $db->getConnection();

echo "CLIENTS TABLE STRUCTURE:\n";
$stmt = $conn->prepare('DESCRIBE clients');
$stmt->execute();
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($columns as $col) {
    echo $col['Field'] . " - " . $col['Type'] . " - Null: " . $col['Null'] . " - Default: " . $col['Default'] . "\n";
}
?>
