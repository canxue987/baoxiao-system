<?php
// api_admin_wallet.php
require_once 'config.php';

// 权限验证：必须是管理员
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['error' => '无权访问']);
    exit;
}

$target_uid = $_GET['uid'] ?? 0;

if (!$target_uid) {
    echo json_encode([]); 
    exit;
}

// 查询目标用户的“未使用”发票
$sql = "SELECT * FROM invoices WHERE user_id = ? AND status = 'unused' ORDER BY invoice_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$target_uid]);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($list);
?>