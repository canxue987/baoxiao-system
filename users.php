<?php
require 'config.php';

// 权限检查
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die("无权访问");
}

// --- 逻辑处理 ---

// 1. 添加用户
if (isset($_POST['add_user'])) {
    $u_name = $_POST['new_username'];
    $u_real = $_POST['new_realname'];
    $u_pass = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $hash_pass = password_hash($u_pass, PASSWORD_DEFAULT);
    
    $check = $pdo->prepare("SELECT id FROM users WHERE username=?");
    $check->execute([$u_name]);
    if ($check->fetch()) {
        echo "<script>alert('账号已存在');</script>";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, realname, password, role) VALUES (?, ?, ?, 'user')");
        $stmt->execute([$u_name, $u_real, $u_pass]);
        header("Location: users.php"); exit;
    }
}

// 2. 删除用户
if (isset($_GET['del_user'])) {
    $del_id = $_GET['del_user'];
    if ($del_id != $_SESSION['user_id']) { // 不能删自己
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$del_id]);
    }
    header("Location: users.php"); exit;
}

// 3. 修改密码 (新增功能)
if (isset($_POST['reset_password'])) {
    $target_id = $_POST['target_id'];
    $raw_pass = $_POST['new_pass'];
    if (!empty($raw_pass)) {
        // 修改：加密新密码
        $safe_pass = password_hash($raw_pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$safe_pass, $target_id]);
        echo "<script>alert('密码修改成功！'); window.location.href='users.php';</script>";
    }
}

// 读取列表
$all_users = $pdo->query("SELECT * FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3>👥 人员管理</h3>
        <a href="admin.php" class="btn btn-ghost">← 返回仪表盘</a>
    </div>
    
    <div style="background:#fafafa; padding:20px; border-radius:8px; border:1px solid #f0f0f0; margin-bottom:24px;">
        <h4 style="margin-top:0;">➕ 添加新员工</h4>
        <form method="post" style="display:flex; gap:10px; align-items:center;">
            <input type="text" name="new_username" placeholder="登录账号 (如: user08)" required style="width:200px;">
            <input type="text" name="new_realname" placeholder="真实姓名 (如: 王小二)" required style="width:200px;">
            <input type="text" name="new_password" placeholder="初始密码" value="123456" required style="width:200px;">
            <button type="submit" name="add_user" value="1" class="btn btn-primary">确认添加</button>
        </form>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width:50px;">ID</th>
                <th>登录账号</th>
                <th>真实姓名</th>
                <th>角色权限</th>
                <th style="width:200px;">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($all_users as $u): ?>
            <tr>
                <td><?php echo $u['id']; ?></td>
                <td><strong><?php echo $u['username']; ?></strong></td>
                <td><?php echo $u['realname']; ?></td>
                <td>
                    <?php if($u['role']=='admin'): ?>
                        <span class="tag tag-blue">管理员</span>
                    <?php else: ?>
                        <span class="tag">普通员工</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button" class="btn btn-ghost btn-sm" style="color:#faad14; border-color:#faad14;" onclick="resetPwd(<?php echo $u['id']; ?>, '<?php echo $u['realname']; ?>')">
                        🔑 改密
                    </button>
                    
                    <?php if($u['role'] != 'admin'): ?>
                        <a href="?del_user=<?php echo $u['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('⚠️ 确定要删除该用户吗？\n删除后该用户的历史报销记录会变成孤儿数据。')">删除</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<form id="reset-form" method="post" style="display:none;">
    <input type="hidden" name="reset_password" value="1">
    <input type="hidden" name="target_id" id="target_id">
    <input type="hidden" name="new_pass" id="new_pass">
</form>

<script>
function resetPwd(id, name) {
    let newP = prompt("请输入 [" + name + "] 的新密码:", "");
    if (newP) {
        document.getElementById('target_id').value = id;
        document.getElementById('new_pass').value = newP;
        document.getElementById('reset-form').submit();
    }
}
</script>

</body>
</html>