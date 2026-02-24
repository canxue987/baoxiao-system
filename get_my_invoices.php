<?php
// get_my_invoices.php
require_once 'config.php';

if(!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

// 简单的查询：只查当前用户的、未使用的发票
$sql = "SELECT * FROM invoices WHERE user_id = ? AND status = 'unused' ORDER BY invoice_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$_SESSION['user_id']]);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($list);
?>