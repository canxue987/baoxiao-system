<?php
// api_wallet_list.php - 智能拦截器：获取票夹发票，并自动剔除被记账本占用的发票
require_once 'config.php';
if (!isset($_SESSION['user_id'])) die(json_encode([]));

// 支持管理员传参查看他人
$uid = (isset($_GET['uid']) && $_SESSION['role'] == 'admin') ? intval($_GET['uid']) : $_SESSION['user_id'];
// 如果是正在编辑某条记账记录，要把这笔记录自己占用的发票放行，否则无法回显
$exclude_bk_id = isset($_GET['exclude_bk_id']) ? intval($_GET['exclude_bk_id']) : 0;

// 1. 查找所有已被【其他未报销记账记录】占用的发票 ID
$sql_bk = "SELECT wallet_ids FROM bookkeeping WHERE user_id=? AND status='未报销' AND wallet_ids != ''";
$params_bk = [$uid];
if ($exclude_bk_id > 0) {
    $sql_bk .= " AND id != ?";
    $params_bk[] = $exclude_bk_id;
}

$stmt_bk = $pdo->prepare($sql_bk);
$stmt_bk->execute($params_bk);
$bk_used_ids = [];
while($row = $stmt_bk->fetch()) {
    $ids = explode(',', $row['wallet_ids']);
    foreach($ids as $i) if(is_numeric($i)) $bk_used_ids[] = $i;
}

// 2. 获取所有 unused 闲置发票
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE user_id=? AND status='unused'");
$stmt->execute([$uid]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. 剔除被占用的发票
$filtered = array_filter($invoices, function($inv) use ($bk_used_ids) {
    return !in_array($inv['id'], $bk_used_ids);
});

echo json_encode(array_values($filtered));
?>