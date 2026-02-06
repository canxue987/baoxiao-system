<?php
// header.php
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$current_page = basename($_SERVER['PHP_SELF']);

// 读取公司列表 (用于前端JS自动补全等)
$comp_file = __DIR__ . '/db/companies.json';
$sys_companies = [];
if (file_exists($comp_file)) {
    $sys_companies = json_decode(file_get_contents($comp_file), true);
}
if (empty($sys_companies)) {
    $sys_companies = ["默认公司"];
}

// 读取系统配置 (Logo/名称)
$sys_file = __DIR__ . '/db/sys.json';
$sys_config = ['name' => '企业报销管理系统', 'logo' => ''];
if (file_exists($sys_file)) {
    $sys_config = json_decode(file_get_contents($sys_file), true);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($sys_config['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <script>
        const GLOBAL_COMPANIES = <?php echo json_encode($sys_companies); ?>;
    </script>
</head>
<body>

<div class="sidebar">
    <div class="logo-area" style="overflow:hidden; padding: 20px 10px; text-align: center;">
            <?php if(!empty($sys_config['logo'])): ?>
                <img src="<?php echo htmlspecialchars($sys_config['logo']); ?>" style="height:48px; width:auto; object-fit:contain; margin-bottom: 8px;">
                <div style="line-height:1.2; color:#fff; font-size: 14px; font-weight: bold;">
                    <?php echo htmlspecialchars($sys_config['name']); ?>
                </div>
            <?php else: ?>
                <div style="margin: 0 auto; background:rgba(255,255,255,0.1); width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; margin-bottom: 8px;">
                    <i class="ri-shield-keyhole-line" style="font-size:28px; margin:0; color:#4096ff;"></i>
                </div>
                <div style="line-height:1.2; color:#fff; font-size: 14px; font-weight: bold;">
                    <?php echo htmlspecialchars($sys_config['name']); ?>
                </div>
            <?php endif; ?>
    </div>
    
    <div class="nav-menu">
        <a href="index.php" class="nav-item <?php echo $current_page=='index.php'?'active':''; ?>">
            <i class="ri-file-list-3-line"></i> 我的报销单
        </a>

        <?php if($_SESSION['role'] == 'admin'): ?>
            <div style="font-size:12px; color:#999; margin: 15px 0 5px 15px;">管理后台</div>
            
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

        <div style="font-size:12px; color:#999; margin: 15px 0 5px 15px;">账户</div>
        
        <a href="settings.php" class="nav-item <?php echo $current_page=='settings.php'?'active':''; ?>">
            <i class="ri-settings-3-line"></i> 系统与安全
        </a>
    </div>

    <div class="user-info">
        <div>当前用户：<?php echo htmlspecialchars($_SESSION['realname']); ?></div>
        <div style="margin-top:5px;">
            <a href="login.php?logout=1" style="color:#ff4d4f; text-decoration:none; display: flex; align-items: center; gap: 5px;">
                <i class="ri-logout-box-r-line"></i> 退出登录
            </a>
        </div>
    </div>
</div>

<div class="main-content">