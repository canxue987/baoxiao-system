<?php
// index.php - 路由分发器
require 'config.php';

// 1. 如果没登录，踢回登录页
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 2. 如果是管理员，加载管理员后台
if ($_SESSION['role'] === 'admin') {
    require 'admin.php';
} 
// 3. 否则加载员工填报页
else {
    require 'user.php';
}
?>