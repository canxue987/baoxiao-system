<?php
require_once 'config.php';

// 权限检查
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die("无权访问");
}

// 1. 公司列表文件
$comp_file = __DIR__ . '/db/companies.json';
$companies = [];
if (file_exists($comp_file)) {
    $companies = json_decode(file_get_contents($comp_file), true);
}

// --- 处理表单提交 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // A. 保存系统设置
    if (isset($_POST['save_settings'])) {
        $new_name = trim($_POST['sys_name']);
        if ($new_name) $sys_config['name'] = $new_name;

        if (isset($_FILES['sys_logo']) && $_FILES['sys_logo']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['sys_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg'])) {
                if (!is_dir('uploads')) mkdir('uploads', 0777, true);
                $target = 'uploads/site_logo.' . $ext;
                if (move_uploaded_file($_FILES['sys_logo']['tmp_name'], $target)) {
                    $sys_config['logo'] = $target . '?v=' . time();
                }
            }
        }
        file_put_contents($settings_file, json_encode($sys_config, JSON_UNESCAPED_UNICODE));
        header("Location: settings.php"); exit;
    }

    // B. 添加公司
    if (isset($_POST['add_comp'])) {
        $new_name = trim($_POST['new_name']);
        if ($new_name && !in_array($new_name, $companies)) {
            $companies[] = $new_name;
            file_put_contents($comp_file, json_encode($companies, JSON_UNESCAPED_UNICODE));
        }
        header("Location: settings.php"); exit;
    }
    
    // C. 删除公司
    if (isset($_POST['del_comp'])) {
        $del_name = $_POST['del_comp'];
        $key = array_search($del_name, $companies);
        if ($key !== false) {
            unset($companies[$key]);
            $companies = array_values($companies);
            file_put_contents($comp_file, json_encode($companies, JSON_UNESCAPED_UNICODE));
        }
        header("Location: settings.php"); exit;
    }

    // D. 重命名公司 (新增核心逻辑)
    if (isset($_POST['rename_comp'])) {
        $old_name = $_POST['old_name'];
        $new_name = trim($_POST['new_name']);
        
        if ($old_name && $new_name && $old_name !== $new_name) {
            // 1. 更新 JSON 列表
            $key = array_search($old_name, $companies);
            if ($key !== false) {
                $companies[$key] = $new_name;
                file_put_contents($comp_file, json_encode($companies, JSON_UNESCAPED_UNICODE));
            }

            // 2. 更新数据库中的历史记录 (保持统计数据连续性)
            // 注意：我们只更新 items 表里的 company 字段，不重命名 uploads 里的物理文件夹
            // 这样能确保发票路径(invoice_path)依然有效，不会因为文件夹改名而找不到图片
            $stmt = $pdo->prepare("UPDATE items SET company = ? WHERE company = ?");
            $stmt->execute([$new_name, $old_name]);
            
            echo "<script>alert('更名成功！\\n历史报销记录已同步更新为新名称。\\n(注: 为了系统安全，物理存档文件夹名称未变更)'); window.location.href='settings.php';</script>"; exit;
        }
    }
}

include 'header.php';
?>

<div class="card" style="margin-bottom:24px;">
    <h3><i class="ri-settings-3-line"></i> 系统基本设置</h3>
    <form method="post" enctype="multipart/form-data" style="background:#fafafa; padding:20px; border-radius:8px;">
        <div style="margin-bottom:15px;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;">系统名称</label>
            <input type="text" name="sys_name" value="<?php echo h($sys_config['name']); ?>" required style="max-width:400px;">
        </div>
        
        <div style="margin-bottom:15px;">
            <label style="display:block; margin-bottom:5px; font-weight:bold;">系统 Logo (可选)</label>
            <div style="display:flex; align-items:center; gap:15px;">
                <?php if($sys_config['logo']): ?>
                    <img src="<?php echo h($sys_config['logo']); ?>" style="height:40px; border:1px solid #ddd; padding:2px; background:#fff;">
                <?php endif; ?>
                <input type="file" name="sys_logo" accept="image/*" style="max-width:300px;">
            </div>
            <div style="font-size:12px; color:#999; margin-top:5px;">建议使用高度 64px 左右的透明 PNG 图片</div>
        </div>
        
        <button type="submit" name="save_settings" value="1" class="btn btn-primary"><i class="ri-save-line"></i> 保存设置</button>
    </form>
</div>

<div class="card">
    <div style="background:#fff7e6; border:1px solid #ffd591; padding:15px; border-radius:6px; margin-bottom:24px; color:#d48806; font-size:13px;">
        <i class="ri-error-warning-line"></i> <strong>关于更名：</strong> 
        修改公司名称后，历史报销记录中的公司名会自动更新，统计报表将合并计算。
        <br>
        <span style="opacity:0.8">* 为防止图片丢失，服务器上的物理文件夹名称不会更改，这不影响正常使用。</span>
    </div>

    <h4>🏢 公司主体管理</h4>
    
    <div style="margin-bottom:20px;">
        <?php if(empty($companies)): ?>
            <div style="color:#999; padding:10px;">暂无公司主体，请添加。</div>
        <?php else: ?>
            <div style="display:flex; flex-wrap:wrap; gap:10px;">
                <?php foreach($companies as $c): ?>
                    <div style="background:#f0f2f5; padding:8px 16px; border-radius:20px; border:1px solid #d9d9d9; display:flex; align-items:center; gap:8px;">
                        <span style="font-weight:bold; color:#333;"><?php echo h($c); ?></span>
                        
                        <button type="button" onclick="renameComp('<?php echo h($c); ?>')" style="background:none; border:none; color:#1677ff; cursor:pointer; padding:0 5px;" title="重命名">
                            <i class="ri-edit-line"></i>
                        </button>
                        
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="del_comp" value="<?php echo h($c); ?>">
                            <button type="submit" style="background:none; border:none; color:#ff4d4f; cursor:pointer; padding:0 0 0 5px;" onclick="return confirm('确定从下拉选项中移除吗？')">
                                <i class="ri-close-circle-fill"></i>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <form method="post" style="display:flex; gap:10px; align-items:center; background:#fafafa; padding:15px; border-radius:8px;">
        <input type="text" name="new_name" placeholder="输入新公司名称" required style="width:240px;">
        <button type="submit" name="add_comp" value="1" class="btn btn-primary"><i class="ri-add-line"></i> 添加主体</button>
    </form>
</div>

<form id="rename-form" method="post" style="display:none;">
    <input type="hidden" name="rename_comp" value="1">
    <input type="hidden" name="old_name" id="old_name_input">
    <input type="hidden" name="new_name" id="new_name_input">
</form>

<script>
function renameComp(oldName) {
    let newName = prompt("请输入 [" + oldName + "] 的新名称:", oldName);
    if (newName && newName !== oldName) {
        document.getElementById('old_name_input').value = oldName;
        document.getElementById('new_name_input').value = newName;
        document.getElementById('rename-form').submit();
    }
}
</script>

</body>
</html>