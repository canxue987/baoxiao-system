<?php
// api_bookkeeping.php - 记账本拉取接口 (员工与管理员通用版)
require_once 'config.php';
if (!isset($_SESSION['user_id'])) die(json_encode([]));

// ✨ 智能鉴权：如果是管理员并且传了 uid 参数，就查目标员工；否则查自己
$uid = (isset($_GET['uid']) && $_SESSION['role'] == 'admin') ? intval($_GET['uid']) : $_SESSION['user_id'];

// 查询该用户所有未报销的账单
$stmt = $pdo->prepare("SELECT * FROM bookkeeping WHERE user_id=? AND status='未报销'");
$stmt->execute([$uid]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>