<?php
require_once 'config.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

   // 修改：只根据用户名查询，然后验证哈希密码
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // 使用 password_verify 验证密码
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['realname'] = $user['realname'];
        $_SESSION['role'] = $user['role'];
        header("Location: index.php");
        exit;
    } else {
        $error = "账号或密码错误";
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 企业报销系统</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <style>
        /* 登录页专用样式覆盖 */
        body {
            background-color: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-image: url('https://gw.alipayobjects.com/zos/rmsportal/TVYTbAXWheQpRcWDaDMu.svg'); /* 加上一个科技感的背景纹理，可选 */
            background-repeat: no-repeat;
            background-position: center 110px;
            background-size: 100%;
        }
        .login-card {
            background: #fff;
            width: 360px;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 6px 16px -8px rgba(0,0,0,0.08), 0 9px 28px 0 rgba(0,0,0,0.05), 0 12px 48px 16px rgba(0,0,0,0.03);
            text-align: center;
        }
        .logo { font-size: 24px; font-weight: bold; color: #1677ff; margin-bottom: 30px; display: block; }
        .form-item { margin-bottom: 24px; text-align: left; }
        .login-btn { width: 100%; padding: 10px; font-size: 16px; margin-top: 10px; }
        .err-msg { 
            color: #ff4d4f; 
            background: #fff2f0; 
            border: 1px solid #ffccc7; 
            padding: 8px; 
            border-radius: 4px; 
            font-size: 12px; 
            margin-bottom: 20px;
            text-align: left;
        }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="logo">报销管理系统</div>
        
        <?php if($error): ?>
            <div class="err-msg">⚠️ <?php echo $error; ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="form-item">
                <input type="text" name="username" placeholder="请输入账号" required>
            </div>
            <div class="form-item">
                <input type="password" name="password" placeholder="请输入密码" required>
            </div>
            <button type="submit" class="btn btn-primary login-btn">登 录</button>
        </form>
        
        <div style="margin-top: 20px; font-size: 12px; color: #999;">
            内部系统，请勿外传
        </div>
    </div>

</body>
</html>