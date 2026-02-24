<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$comp_file = __DIR__ . '/db/companies.json';
$companies = [];
if (file_exists($comp_file)) {
    $companies = json_decode(file_get_contents($comp_file), true);
}
if (!is_array($companies)) $companies = [];

$sys_file = __DIR__ . '/db/sys.json';
$sys_config = ['name' => '企业报销管理系统', 'logo' => '', 'ocr_provider' => 'local', 'baidu_ak' => '', 'baidu_sk' => ''];
if (file_exists($sys_file)) {
    $loaded = json_decode(file_get_contents($sys_file), true);
    if (is_array($loaded)) $sys_config = array_merge($sys_config, $loaded);
}

// ✨ 读取 API 调用统计数据
$usage_file = __DIR__ . '/db/api_usage.json';
$current_month = date('Y-m');
$usage_data = ['vat' => 0, 'receipt' => 0];
if (file_exists($usage_file)) {
    $all_usage = json_decode(file_get_contents($usage_file), true);
    if (isset($all_usage[$current_month])) {
        $usage_data = $all_usage[$current_month];
    }
}
$vat_used = $usage_data['vat'] ?? 0;
$receipt_used = $usage_data['receipt'] ?? 0;
// 计算进度条百分比 (最多显示 100%)
$vat_percent = min(100, round(($vat_used / 1000) * 100, 1));
$receipt_percent = min(100, round(($receipt_used / 1000) * 100, 1));

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['change_pass'])) {
        $new_pass = trim($_POST['new_password']);
        if (!empty($new_pass)) {
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($stmt->execute([$new_pass, $_SESSION['user_id']])) {
                $msg = "密码修改成功！下次登录请使用新密码。"; $msg_type = "success";
            } else {
                $msg = "修改失败，请重试。"; $msg_type = "error";
            }
        }
    }

    if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
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
            file_put_contents($sys_file, json_encode($sys_config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $msg = "系统设置已更新"; $msg_type = "success";
        }

        if (isset($_POST['save_ocr_settings'])) {
            $sys_config['ocr_provider'] = $_POST['ocr_provider'] ?? 'local';
            $sys_config['baidu_ak'] = trim($_POST['baidu_ak'] ?? '');
            $sys_config['baidu_sk'] = trim($_POST['baidu_sk'] ?? '');
            
            if (!is_dir('db')) mkdir('db', 0777, true);
            file_put_contents($sys_file, json_encode($sys_config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $msg = "OCR 引擎配置已成功保存！"; $msg_type = "success";
        }

        if (isset($_POST['add_comp'])) {
            $new_comp = trim($_POST['new_name']);
            if ($new_comp && !in_array($new_comp, $companies)) {
                $companies[] = $new_comp;
                file_put_contents($comp_file, json_encode($companies, JSON_UNESCAPED_UNICODE));
                $msg = "主体添加成功"; $msg_type = "success";
            }
        }

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
    .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; align-items: start; }
    .setting-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.05); border: 1px solid #eee; overflow: hidden; height: 100%; display: flex; flex-direction: column; }
    .card-header { padding: 15px 20px; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; gap: 10px; background: #fafafa; }
    .card-header h3 { margin: 0; font-size: 16px; color: #333; }
    .card-body { padding: 20px; flex: 1; }
    .comp-tag { background: #f0f5ff; border: 1px solid #d6e4ff; color: #2f54eb; padding: 6px 12px; border-radius: 4px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; margin: 0 8px 8px 0; transition: all 0.2s; }
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
                            <button type="submit" name="save_settings" value="1" class="btn btn-primary btn-sm">保存系统信息</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="setting-card" style="border-top: 3px solid #722ed1;">
                <div class="card-header">
                    <i class="ri-brain-line" style="color:#722ed1; font-size:18px;"></i>
                    <h3 style="font-size:16px;">发票智能识别 (OCR) 引擎</h3>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div style="margin-bottom:15px;">
                            <select name="ocr_provider" class="form-control" style="font-weight:bold; color:#0050b3; background:#f0f7ff;">
                                <option value="local" <?php if(($sys_config['ocr_provider'] ?? 'local') == 'local') echo 'selected'; ?>>🖥️ 本地离线识别 (基础精度，完全免费)</option>
                                <option value="baidu" <?php if(($sys_config['ocr_provider'] ?? '') == 'baidu') echo 'selected'; ?>>☁️ 百度云大厂 API (极高精度，智能路由)</option>
                            </select>
                        </div>

                        <div style="border-top: 1px dashed #e8e8e8; margin-top: 15px; padding-top: 15px;">
                            <div style="margin-bottom:15px;">
                                <label style="display:block; margin-bottom:5px; color:#666;">Baidu API Key (AK)</label>
                                <input type="text" name="baidu_ak" class="form-control" value="<?php echo h($sys_config['baidu_ak'] ?? ''); ?>" placeholder="例: xvB8s... (选择百度引擎时必填)" style="font-family: monospace;">
                            </div>
                            <div style="margin-bottom:15px;">
                                <label style="display:block; margin-bottom:5px; color:#666;">Baidu Secret Key (SK)</label>
                                <input type="password" name="baidu_sk" class="form-control" value="<?php echo h($sys_config['baidu_sk'] ?? ''); ?>" placeholder="例: A92xL... (选择百度引擎时必填)" style="font-family: monospace;">
                            </div>
                        </div>

                        <div style="text-align:right; margin-bottom:15px;">
                            <button type="submit" name="save_ocr_settings" value="1" class="btn btn-primary btn-sm" style="background:#722ed1; border-color:#722ed1;">保存引擎配置</button>
                        </div>
                    </form>

                    <?php if(($sys_config['ocr_provider'] ?? '') == 'baidu'): ?>
                    <div style="padding: 15px; background: #fafafa; border-radius: 8px; border: 1px solid #eee;">
                        <div style="margin-bottom: 12px; font-weight: bold; color: #333; font-size: 13px; display:flex; justify-content:space-between;">
                            <span><i class="ri-bar-chart-box-line" style="color:#722ed1;"></i> 本月 API 消耗 (<?php echo $current_month; ?>)</span>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <div style="display:flex; justify-content:space-between; font-size:12px; color:#666; margin-bottom:6px;">
                                <span>增值税发票识别 (免费 1000次/月)</span>
                                <span><strong style="color:#1890ff;"><?php echo $vat_used; ?></strong> / 1000</span>
                            </div>
                            <div style="width:100%; background:#e8e8e8; border-radius:4px; height:6px; overflow:hidden;">
                                <div style="width:<?php echo $vat_percent; ?>%; background:#1890ff; height:100%; border-radius:4px;"></div>
                            </div>
                        </div>

                        <div>
                            <div style="display:flex; justify-content:space-between; font-size:12px; color:#666; margin-bottom:6px;">
                                <span>通用票据识别 (免费 1000次/月)</span>
                                <span><strong style="color:#52c41a;"><?php echo $receipt_used; ?></strong> / 1000</span>
                            </div>
                            <div style="width:100%; background:#e8e8e8; border-radius:4px; height:6px; overflow:hidden;">
                                <div style="width:<?php echo $receipt_percent; ?>%; background:#52c41a; height:100%; border-radius:4px;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

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