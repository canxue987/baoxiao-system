<?php
// header.php
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$current_page = basename($_SERVER['PHP_SELF']);

// --- 新增：读取公司列表 ---
$comp_file = __DIR__ . '/db/companies.json';
$sys_companies = [];
if (file_exists($comp_file)) {
    $sys_companies = json_decode(file_get_contents($comp_file), true);
}
// 如果文件不存在或为空，给个默认保底，防止报错
if (empty($sys_companies)) {
    $sys_companies = ["默认公司"];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>企业报销管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <script>
        const GLOBAL_COMPANIES = <?php echo json_encode($sys_companies); ?>;
    </script>
</head>
<body>

<div class="sidebar">
    <div class="logo-area">
        报销管理系统
    </div>
    
    <div class="nav-menu">
        <?php if($_SESSION['role'] == 'admin'): ?>
            <a href="admin.php" class="nav-item <?php echo $current_page=='admin.php'?'active':''; ?>">
                <i class="ri-dashboard-line"></i> 管理仪表盘
            </a>
            
            <a href="admin_file.php" class="nav-item <?php echo $current_page=='admin_file.php'?'active':''; ?>">
                <i class="ri-user-shared-2-line"></i> 管理员代填
            </a>

            <a href="users.php" class="nav-item <?php echo $current_page=='users.php'?'active':''; ?>">
                <i class="ri-team-line"></i> 人员管理
            </a>
            <a href="settings.php" class="nav-item <?php echo $current_page=='settings.php'?'active':''; ?>">
                <i class="ri-settings-3-line"></i> 系统设置
            </a>
        <?php endif; ?>
    </div>

    <div class="user-info">
        <div>当前用户：<?php echo $_SESSION['realname']; ?></div>
        <div style="margin-top:5px;">
            <a href="login.php" style="color:#ff4d4f; text-decoration:none;">退出登录</a>
        </div>
    </div>
</div>

<div class="main-content">