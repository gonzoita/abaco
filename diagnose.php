<?php
// diagnose.php
require_once __DIR__ . '/backend/config/database.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    $db = Database::getConnection();
    echo "DB Connection: OK\n";
    
    // Check users
    $users = $db->query("SELECT id, name, email FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo "=== USERS ===\n";
    print_r($users);
    
    // Check transactions
    $txs = $db->query("SELECT id, user_id, type, amount, date, description FROM transactions")->fetchAll(PDO::FETCH_ASSOC);
    echo "=== ALL TRANSACTIONS ===\n";
    print_r($txs);
    
    // Check reports query output
    if (!empty($users)) {
        foreach ($users as $u) {
            $userId = $u['id'];
            echo "=== REPORT QUERY FOR USER {$userId} ({$u['name']}) for Month 7, Year 2026 ===\n";
            $stmt = $db->prepare("SELECT type, SUM(amount) as total FROM transactions WHERE user_id = ? AND MONTH(date) = 7 AND YEAR(date) = 2026 GROUP BY type");
            $stmt->execute([$userId]);
            print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
