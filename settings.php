<?php
require_once 'config.php';

// 权限检查
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 1. 加载数据 (保留原有逻辑)
$comp_file = __DIR__ . '/db/companies.json';
$companies = [];
if (file_exists($comp_file)) {
    $companies = json_decode(file_get_contents($comp_file), true);
}
if (!is_array($companies)) $companies = [];

$sys_file = __DIR__ . '/db/sys.json';
$sys_config = ['name' => '企业报销管理系统', 'logo' => ''];
if (file_exists($sys_file)) {
    $sys_config = json_decode(file_get_contents($sys_file), true);
}

$msg = '';
$msg_type = '';

// --- 处理表单提交 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // A. 修改密码
    if (isset($_POST['change_pass'])) {
        $new_pass = trim($_POST['new_password']);
        if (!empty($new_pass)) {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$new_pass, $_SESSION['user_id']])) {
                $msg = "密码修改成功！下次登录请使用新密码。";
                $msg_type = "success";
            } else {
                $msg = "修改失败，请重试。";
                $msg_type = "error";
            }
        }
    }

    // B. 管理员操作
    if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
        // B1. 系统设置
        if (isset($_POST['save_settings'])) {
            $new_name = trim($_POST['sys_name']);
            if ($new_name) $sys_config['name'] = $new_name;
            if (isset($_FILES['sys_logo']) && $_FILES['sys_logo']['error'] == 0) {
                $ext = strtolower(pathinfo($_FILES['sys_logo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg'])) {
                    if (!is_dir('uploads')) mkdir('uploads', 0777, true);
                    $target = 'uploads/site_logo.' . $ext;
                    move_uploaded_file($_FILES['sys_logo']['tmp_name'], $target);
                    $sys_config['logo'] = $target . '?v=' . time();
                }
            }
            if (!is_dir('db')) mkdir('db', 0777, true);
            file_put_contents($sys_file, json_encode($sys_config, JSON_UNESCAPED_UNICODE));
            $msg = "系统设置已更新"; $msg_type = "success";
        }

        // B2. 添加公司
        if (isset($_POST['add_comp'])) {
            $new_comp = trim($_POST['new_name']);
            if ($new_comp && !in_array($new_comp, $companies)) {
                $companies[] = $new_comp;
                file_put_contents($comp_file, json_encode($companies, JSON_UNESCAPED_UNICODE));
                $msg = "主体添加成功"; $msg_type = "success";
            }
        }

        // B3. 删除公司
        if (isset($_POST['del_comp'])) {
            $del_name = $_POST['del_comp'];
            $key = array_search($del_name, $companies);
            if ($key !== false) {
                unset($companies[$key]);
                $companies = array_values($companies);
                file_put_contents($comp_file, json_encode($companies, JSON_UNESCAPED_UNICODE));
                $msg = "已删除主体"; $msg_type = "success";
            }
        }
        
        // B4. 重命名公司
        if (isset($_POST['rename_comp'])) {
            $old = $_POST['old_name'];
            $new = trim($_POST['new_name']);
            $key = array_search($old, $companies);
            if ($key !== false && $new && !in_array($new, $companies)) {
                $companies[$key] = $new;
                file_put_contents($comp_file, json_encode($companies, JSON_UNESCAPED_UNICODE));
                $msg = "主体已重命名";
                $msg_type = "success";
            }
        }
    }
}

include 'header.php';
?>

<style>
    /* 页面布局网格 */
    .settings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); /* 自适应双列 */
        gap: 20px;
        align-items: start;
    }
    
    /* 专门的卡片样式优化 */
    .setting-card {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        border: 1px solid #eee;
        overflow: hidden;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .card-header {
        padding: 15px 20px;
        border-bottom: 1px solid #f0f0f0;
        display: flex;
        align-items: center;
        gap: 10px;
        background: #fafafa;
    }
    .card-header h3 { margin: 0; font-size: 16px; color: #333; }
    .card-body { padding: 20px; flex: 1; }
    
    /* 公司标签样式 */
    .comp-tag {
        background: #f0f5ff; border: 1px solid #d6e4ff; color: #2f54eb;
        padding: 6px 12px; border-radius: 4px; font-size: 13px;
        display: inline-flex; align-items: center; gap: 8px; margin: 0 8px 8px 0;
        transition: all 0.2s;
    }
    .comp-tag:hover { background: #d6e4ff; }
</style>

<?php if($msg): ?>
<div style="padding:15px; margin-bottom:20px; border-radius:4px; 
     background: <?php echo $msg_type=='success'?'#f6ffed':'#fff2f0'; ?>; 
     border:1px solid <?php echo $msg_type=='success'?'#b7eb8f':'#ffccc7'; ?>;
     color: <?php echo $msg_type=='success'?'#52c41a':'#ff4d4f'; ?>;">
    <i class="<?php echo $msg_type=='success'?'ri-checkbox-circle-fill':'ri-close-circle-fill'; ?>"></i>
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<div style="margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center;">
    <h2 style="margin:0; font-size:20px; color:#333;"><i class="ri-settings-line"></i> 系统设置</h2>
</div>

<?php if(isset($_SESSION['role']) && $_SESSION['role'] == 'admin'): ?>
    <div class="settings-grid">
        
        <div style="display:flex; flex-direction:column; gap:20px;">
            
            <div class="setting-card" style="border-top: 3px solid #1890ff;">
                <div class="card-header">
                    <i class="ri-printer-line" style="color:#1890ff; font-size:18px;"></i>
                    <h3>打印与导出模板</h3>
                </div>
                <div class="card-body">
                    <p style="color:#666; font-size:13px; margin-bottom:15px; line-height:1.6;">
                        在此配置 <strong>Word/Excel</strong> 报销单导出模板，或设置在线打印的背景底图。
                    </p>
                    <a href="templates.php" class="btn btn-primary" style="display:block; text-align:center;">
                        <i class="ri-file-settings-line"></i> 进入模板设置
                    </a>
                </div>
            </div>

            <div class="setting-card">
                <div class="card-header">
                    <i class="ri-computer-line" style="color:#1890ff;"></i>
                    <h3>系统基础信息</h3>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data">
                        <div style="margin-bottom:15px;">
                            <label style="display:block; margin-bottom:5px; color:#666;">系统名称</label>
                            <input type="text" name="sys_name" class="form-control" value="<?php echo h($sys_config['name'] ?? '企业报销管理系统'); ?>">
                        </div>
                        <div style="margin-bottom:15px;">
                            <label style="display:block; margin-bottom:5px; color:#666;">Logo (高度约40px)</label>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <?php if(!empty($sys_config['logo'])): ?>
                                    <img src="<?php echo h($sys_config['logo']); ?>" style="height:35px; border:1px solid #eee; padding:2px; border-radius:4px;">
                                <?php endif; ?>
                                <input type="file" name="sys_logo" accept="image/*" class="form-control" style="font-size:12px;">
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <button type="submit" name="save_settings" value="1" class="btn btn-primary btn-sm">保存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div style="display:flex; flex-direction:column; gap:20px;">
            
            <div class="setting-card">
                <div class="card-header">
                    <i class="ri-building-line" style="color:#fa8c16;"></i>
                    <h3>报销主体公司</h3>
                </div>
                <div class="card-body">
                    <div style="margin-bottom:15px;">
                        <?php foreach($companies as $c): ?>
                            <div class="comp-tag">
                                <span onclick="renameComp('<?php echo h($c); ?>')" style="cursor:pointer;" title="点击重命名"><?php echo h($c); ?></span>
                                <form method="post" style="display:inline;">
                                    <button type="submit" name="del_comp" value="<?php echo h($c); ?>" style="background:none; border:none; color:#ff7875; cursor:pointer; padding:0;" onclick="return confirm('确定删除?')">
                                        <i class="ri-close-line"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <?php if(empty($companies)): ?>
                            <div style="text-align:center; color:#999; padding:10px; font-size:12px;">暂无主体，请添加</div>
                        <?php endif; ?>
                    </div>
                    <form method="post" style="display:flex; gap:8px;">
                        <input type="text" name="new_name" placeholder="新公司名称" required class="form-control" style="flex:1;">
                        <button type="submit" name="add_comp" value="1" class="btn btn-secondary btn-sm"><i class="ri-add-line"></i> 添加</button>
                    </form>
                </div>
            </div>

            <div class="setting-card">
                <div class="card-header">
                    <i class="ri-lock-password-line" style="color:#52c41a;"></i>
                    <h3>管理员密码修改</h3>
                </div>
                <div class="card-body">
                    <form method="post" style="display:flex; gap:10px;">
                        <input type="password" name="new_password" required class="form-control" placeholder="输入新密码" style="flex:1;">
                        <button type="submit" name="change_pass" value="1" class="btn btn-secondary">确认</button>
                    </form>
                </div>
            </div>

        </div>
    </div>

<?php else: ?>
    
    <div style="max-width: 500px; margin: 40px auto;">
        <div class="setting-card" style="border-top: 3px solid #52c41a;">
            <div class="card-header">
                <i class="ri-shield-user-line" style="color:#52c41a; font-size:20px;"></i>
                <h3 style="font-size:18px;">个人安全中心</h3>
            </div>
            <div class="card-body" style="padding: 30px;">
                <p style="margin-bottom:20px; color:#666;">为了您的账号安全，建议定期更换登录密码。</p>
                <form method="post">
                    <div style="margin-bottom:20px;">
                        <label style="display:block; margin-bottom:8px; font-weight:bold;">新密码</label>
                        <input type="password" name="new_password" required class="form-control" placeholder="在此输入新的登录密码" style="padding:10px;">
                    </div>
                    <button type="submit" name="change_pass" value="1" class="btn btn-primary" style="width:100%; padding:10px;">
                        <i class="ri-check-line"></i> 确认修改密码
                    </button>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>

<form id="rename-form" method="post" style="display:none;">
    <input type="hidden" name="rename_comp" value="1">
    <input type="hidden" name="old_name" id="old_name_input">
    <input type="hidden" name="new_name" id="new_name_input">
</form>

<script>
function renameComp(oldName) {
    let newName = prompt("重命名 [" + oldName + "]:", oldName);
    if (newName && newName !== oldName) {
        document.getElementById('old_name_input').value = oldName;
        document.getElementById('new_name_input').value = newName;
        document.getElementById('rename-form').submit();
    }
}
</script>

</body>
</html>