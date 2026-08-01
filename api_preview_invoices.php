<?php
// api_preview_invoices.php - 获取已选发票图片的接口
require_once 'config.php';
if (!isset($_SESSION['user_id'])) die(json_encode([]));

$ids_str = $_GET['ids'] ?? '';
if (empty($ids_str)) die(json_encode([]));

// 解析传过来的 ID (例如 12,15,18)
$ids = explode(',', $ids_str);
$ids = array_filter(array_map('intval', $ids));
if (empty($ids)) die(json_encode([]));

// 安全构建 IN 查询
$in = str_repeat('?,', count($ids) - 1) . '?';
$params = array_values($ids);
$sql = "SELECT id, amount, file_path, file_type, seller_name FROM invoices WHERE id IN ($in)";
if (($_SESSION['role'] ?? 'user') !== 'admin') {
    $sql .= " AND user_id=?";
    $params[] = (int)$_SESSION['user_id'];
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
