<?php
// header.php
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>企业报销管理系统</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
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