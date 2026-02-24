<?php
require_once 'config.php';

// 权限检查
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die("无权访问");
}

// --- 逻辑处理 ---

// 1. 添加用户
if (isset($_POST['add_user'])) {
    $u_name = $_POST['username'];
    $u_real = $_POST['realname'];
    $u_dept = $_POST['department'] ?? '';
    $u_bank = $_POST['bank_account'] ?? '';
    $u_pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    $check = $pdo->prepare("SELECT id FROM users WHERE username=?");
    $check->execute([$u_name]);
    if ($check->fetch()) {
        echo "<script>alert('账号已存在');</script>";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (username, realname, department, bank_account, password, role) VALUES (?, ?, ?, ?, ?, 'user')");
        $stmt->execute([$u_name, $u_real, $u_dept, $u_bank, $u_pass]);
        header("Location: users.php"); exit;
    }
}

// 2. 编辑用户 (新增)
if (isset($_POST['edit_user'])) {
    $uid = $_POST['edit_user_id'];
    $u_name = $_POST['username'];
    $u_real = $_POST['realname'];
    $u_dept = $_POST['department'];
    $u_bank = $_POST['bank_account'];
    
    // 检查用户名是否和其他人重复
    $check = $pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?");
    $check->execute([$u_name, $uid]);
    if ($check->fetch()) {
        echo "<script>alert('修改失败：用户名已存在'); window.history.back();</script>"; exit;
    }

    // 更新基本信息
    $sql = "UPDATE users SET username=?, realname=?, department=?, bank_account=? WHERE id=?";
    $params = [$u_name, $u_real, $u_dept, $u_bank, $uid];
    
    // 如果填了密码，则更新密码
    if (!empty($_POST['password'])) {
        $sql = "UPDATE users SET username=?, realname=?, department=?, bank_account=?, password=? WHERE id=?";
        $params = [$u_name, $u_real, $u_dept, $u_bank, password_hash($_POST['password'], PASSWORD_DEFAULT), $uid];
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo "<script>alert('用户信息已更新'); window.location.href='users.php';</script>"; exit;
}

// 3. 删除用户
if (isset($_GET['del_user'])) {
    $del_id = $_GET['del_user'];
    if ($del_id != $_SESSION['user_id']) { 
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$del_id]);
    }
    header("Location: users.php"); exit;
}

// 读取列表
$all_users = $pdo->query("SELECT * FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3><i class="ri-team-line"></i> 人员管理</h3>
        <a href="admin.php" class="btn btn-ghost"><i class="ri-arrow-left-line"></i> 返回仪表盘</a>
    </div>
    
    <div style="background:#fafafa; padding:20px; border-radius:8px; border:1px solid #f0f0f0; margin-bottom:24px;">
        <h4 style="margin-top:0;" id="form-title"><i class="ri-user-add-line"></i> 添加新员工</h4>
        
        <form method="post" id="user-form" style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;">
            <input type="hidden" name="edit_user_id" id="edit_user_id" value="">
            
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:12px; color:#666;">登录账号</label>
                <input type="text" name="username" id="f_username" placeholder="如: user01" required style="width:140px;">
            </div>
            
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:12px; color:#666;">真实姓名</label>
                <input type="text" name="realname" id="f_realname" placeholder="如: 王小二" required style="width:140px;">
            </div>

            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:12px; color:#666;">所属部门</label>
                <input type="text" name="department" id="f_department" placeholder="部门" style="width:140px;">
            </div>

            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:12px; color:#666;">银行卡号/支付宝</label>
                <input type="text" name="bank_account" id="f_bank_account" placeholder="收款账号" style="width:200px;">
            </div>
            
            <div style="display:flex; flex-direction:column; gap:4px;">
                <label style="font-size:12px; color:#666;">密码 (留空不改)</label>
                <input type="text" name="password" id="f_password" placeholder="初始123456" value="123456" style="width:140px;">
            </div>
            
            <div style="padding-bottom:1px;">
                <button type="submit" name="add_user" value="1" id="btn-submit" class="btn btn-primary"><i class="ri-add-line"></i> 添加</button>
                <button type="button" id="btn-cancel" onclick="resetForm()" class="btn btn-ghost" style="display:none; margin-left:5px;">取消</button>
            </div>
        </form>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width:50px;">ID</th>
                <th>账号</th>
                <th>姓名</th>
                <th>部门</th>
                <th>收款账号</th>
                <th>角色</th>
                <th style="width:160px;">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($all_users as $u): ?>
            <tr>
                <td><?php echo $u['id']; ?></td>
                <td><strong><?php echo h($u['username']); ?></strong></td>
                <td><?php echo h($u['realname']); ?></td>
                <td><span class="tag"><?php echo h($u['department']); ?></span></td>
                <td style="font-size:12px; color:#999;"><?php echo h($u['bank_account']); ?></td>
                <td>
                    <?php echo $u['role']=='admin' ? '<span class="tag tag-blue">管理员</span>' : '<span class="tag">员工</span>'; ?>
                </td>
                <td>
                    <?php 
                        $u_data = htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8');
                    ?>
                    <button type="button" class="btn btn-ghost btn-sm" onclick='editUser(<?php echo $u_data; ?>)'>
                        <i class="ri-edit-line"></i> 编辑
                    </button>
                    
                    <?php if($u['role'] != 'admin'): ?>
                        <a href="?del_user=<?php echo $u['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('⚠️ 确定删除？')"><i class="ri-delete-bin-line"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function editUser(u) {
    // 填充表单
    document.getElementById('edit_user_id').value = u.id;
    document.getElementById('f_username').value = u.username;
    document.getElementById('f_realname').value = u.realname;
    document.getElementById('f_department').value = u.department || '';
    document.getElementById('f_bank_account').value = u.bank_account || '';
    document.getElementById('f_password').value = ''; // 编辑时密码留空
    document.getElementById('f_password').placeholder = '留空则不修改';

    // 改变界面状态
    document.getElementById('form-title').innerHTML = "<i class='ri-edit-circle-line'></i> 编辑员工: " + u.realname;
    document.getElementById('btn-submit').innerHTML = "<i class='ri-save-line'></i> 保存修改";
    document.getElementById('btn-submit').name = "edit_user"; // 改变提交动作
    document.getElementById('btn-cancel').style.display = "inline-block";
    
    // 滚到顶部
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    // 重置表单
    document.getElementById('user-form').reset();
    document.getElementById('edit_user_id').value = '';
    document.getElementById('f_password').value = '123456';
    document.getElementById('f_password').placeholder = '初始123456';
    
    // 恢复界面
    document.getElementById('form-title').innerHTML = "<i class='ri-user-add-line'></i> 添加新员工";
    document.getElementById('btn-submit').innerHTML = "<i class='ri-add-line'></i> 添加";
    document.getElementById('btn-submit').name = "add_user";
    document.getElementById('btn-cancel').style.display = "none";
}
</script>

</body>
</html>