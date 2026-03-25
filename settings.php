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

    // 保存个人邮箱配置
    if (isset($_POST['save_email_settings'])) {
        $i_server = trim($_POST['imap_server']);
        $i_user = trim($_POST['imap_user']);
        $i_pass = trim($_POST['imap_pass']); 
        // 简单混淆加密一下授权码，防止在数据库中明文裸奔
        $i_pass_encrypted = $i_pass ? base64_encode($i_pass) : '';

        $stmt = $pdo->prepare("UPDATE users SET imap_server=?, imap_user=?, imap_pass=? WHERE id=?");
        if ($stmt->execute([$i_server, $i_user, $i_pass_encrypted, $_SESSION['user_id']])) {
            $msg = "邮箱发票收取配置已保存！"; $msg_type = "success";
        } else {
            $msg = "邮箱配置保存失败。"; $msg_type = "error";
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
// 获取当前用户的邮箱配置用于回显
    $stmt_u = $pdo->prepare("SELECT imap_server, imap_user, imap_pass FROM users WHERE id=?");
    $stmt_u->execute([$_SESSION['user_id']]);
    $curr_user_settings = $stmt_u->fetch(PDO::FETCH_ASSOC);
    $curr_imap_pass = $curr_user_settings['imap_pass'] ? base64_decode($curr_user_settings['imap_pass']) : '';
    
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
            <div class="setting-card" style="border-top: 3px solid #fa8c16;">
                <div class="card-header">
                    <i class="ri-mail-download-line" style="color:#fa8c16; font-size:18px;"></i>
                    <h3>自动收取发票邮箱</h3>
                </div>
                <div class="card-body">
                    <p style="color:#666; font-size:12px; margin-bottom:15px; line-height:1.5;">
                        配置您的工作邮箱，系统可直接从中提取发票存入票夹。
                    </p>
                    <form method="post">
                        <div style="margin-bottom:12px;">
                            <label style="display:block; margin-bottom:4px; color:#666; font-size:13px;">IMAP 服务器</label>
                            <input type="text" name="imap_server" class="form-control" placeholder="例: imap.qq.com" value="<?php echo h($curr_user_settings['imap_server'] ?? ''); ?>">
                        </div>
                        <div style="margin-bottom:12px;">
                            <label style="display:block; margin-bottom:4px; color:#666; font-size:13px;">邮箱账号</label>
                            <input type="text" name="imap_user" class="form-control" placeholder="完整邮箱地址" value="<?php echo h($curr_user_settings['imap_user'] ?? ''); ?>">
                        </div>
                        <div style="margin-bottom:15px;">
                            <label style="display:flex; justify-content:space-between; margin-bottom:4px; color:#666; font-size:13px;">
                                <span>IMAP 授权码</span>
                                <a href="javascript:;" onclick="showEmailGuide()" style="color:#1890ff; text-decoration:none;"><i class="ri-question-line"></i> 如何获取？</a>
                            </label>
                            <input type="password" name="imap_pass" class="form-control" placeholder="填入邮箱后台生成的授权码" value="<?php echo h($curr_imap_pass); ?>">
                        </div>
                        <div style="text-align:right;">
                            <button type="submit" name="save_email_settings" value="1" class="btn btn-secondary btn-sm" style="background:#fa8c16; border-color:#fa8c16; color:#fff;">保存邮箱配置</button>
                        </div>
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
            <div class="setting-card" style="border-top: 3px solid #fa8c16; margin-top: 20px;">
            <div class="card-header">
                <i class="ri-mail-download-line" style="color:#fa8c16; font-size:20px;"></i>
                <h3 style="font-size:18px;">自动收取发票邮箱</h3>
            </div>
            <div class="card-body" style="padding: 30px;">
                <p style="margin-bottom:20px; color:#666; font-size:13px;">配置后，在“我的票夹”中点击收取，即可自动读取邮箱中的发票附件。</p>
                <form method="post">
                    <div style="margin-bottom:15px;">
                        <label style="display:block; margin-bottom:8px; font-weight:bold;">IMAP 服务器</label>
                        <input type="text" name="imap_server" class="form-control" placeholder="例: imap.163.com" value="<?php echo h($curr_user_settings['imap_server'] ?? ''); ?>">
                    </div>
                    <div style="margin-bottom:15px;">
                        <label style="display:block; margin-bottom:8px; font-weight:bold;">邮箱账号</label>
                        <input type="text" name="imap_user" class="form-control" placeholder="完整的邮箱地址" value="<?php echo h($curr_user_settings['imap_user'] ?? ''); ?>">
                    </div>
                    <div style="margin-bottom:20px;">
                        <label style="display:flex; justify-content:space-between; margin-bottom:8px; font-weight:bold;">
                            <span>IMAP 授权码</span>
                            <a href="javascript:;" onclick="showEmailGuide()" style="color:#1890ff; text-decoration:none; font-weight:normal; font-size:13px;"><i class="ri-question-line"></i> 如何获取？</a>
                        </label>
                        <input type="password" name="imap_pass" class="form-control" placeholder="请填入邮箱安全设置中的授权码" value="<?php echo h($curr_imap_pass); ?>">
                    </div>
                    <button type="submit" name="save_email_settings" value="1" class="btn btn-primary" style="width:100%; padding:10px; background:#fa8c16; border-color:#fa8c16;">
                        <i class="ri-save-line"></i> 保存邮箱配置
                    </button>
                </form>
            </div>
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
<div id="emailGuideModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:8px; width:650px; max-width:95%; display:flex; flex-direction:column; box-shadow:0 10px 30px rgba(0,0,0,0.2);">
        
        <div style="padding:16px 24px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:#fafafa; border-radius:8px 8px 0 0;">
            <h3 style="margin:0; font-size:16px; color:#333;"><i class="ri-book-read-line" style="color:#fa8c16; margin-right:5px;"></i> QQ邮箱 IMAP 授权码获取教程</h3>
            <button onclick="document.getElementById('emailGuideModal').style.display='none'" style="border:none; background:none; font-size:24px; color:#999; cursor:pointer;">&times;</button>
        </div>
        
        <div style="padding:24px; line-height:1.6; color:#444; font-size:14px; overflow-y:auto; max-height:60vh;">
            
            <div style="margin-bottom:20px;">
                <strong style="color:#1890ff; font-size:15px;"><i class="ri-settings-2-line"></i> 1. 系统参数填写说明：</strong>
                <ul style="margin-top:10px; padding-left:20px; background:#f0f7ff; padding:12px 12px 12px 30px; border-radius:6px; border:1px solid #bae0ff;">
                    <li style="margin-bottom:6px;"><strong>IMAP 服务器：</strong> <span style="font-family:monospace; color:#cf1322; font-weight:bold; background:#fff; padding:2px 6px; border-radius:4px; border:1px solid #ffccc7;">imap.qq.com</span></li>
                    <li><strong>邮箱账号：</strong> 填写您自己的完整QQ邮箱地址（如: 123456@qq.com）</li>
                </ul>
            </div>

            <div style="margin-bottom:15px;">
                <strong style="color:#1890ff; font-size:15px;"><i class="ri-key-2-line"></i> 2. 授权码获取步骤（电脑网页版）：</strong>
            </div>
            
            <div style="display:flex; flex-direction:column; gap:16px;">
                <div style="display:flex; align-items:flex-start; gap:10px;">
                    <span style="background:#fa8c16; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:13px; font-weight:bold; flex-shrink:0;">1</span>
                    <div>登录网页版QQ邮箱，在页面顶部右上角点击 <strong>“设置”</strong>。</div>
                </div>
                <div style="display:flex; align-items:flex-start; gap:10px;">
                    <span style="background:#fa8c16; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:13px; font-weight:bold; flex-shrink:0;">2</span>
                    <div>在进入的设置页面中，查看左侧边栏，滑动到最下方点击 <strong>“账号安全”</strong>。</div>
                </div>
                <div style="display:flex; align-items:flex-start; gap:10px;">
                    <span style="background:#fa8c16; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:13px; font-weight:bold; flex-shrink:0;">3</span>
                    <div>在新弹出的网页窗口中，点击左侧菜单的 <strong>“安全设置”</strong>。</div>
                </div>
                <div style="display:flex; align-items:flex-start; gap:10px;">
                    <span style="background:#fa8c16; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:13px; font-weight:bold; flex-shrink:0;">4</span>
                    <div>找到“POP3/IMAP/SMTP/Exchange/CardDAV 服务”一栏，选择 <strong>“开启服务”</strong>（若已开启请忽略）。</div>
                </div>
                <div style="display:flex; align-items:flex-start; gap:10px;">
                    <span style="background:#fa8c16; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:13px; font-weight:bold; flex-shrink:0;">5</span>
                    <div>服务开启后，点击下方的 <strong>“生成授权码”</strong> 按钮，按提示使用绑定的手机号发送短信验证。</div>
                </div>
                <div style="display:flex; align-items:flex-start; gap:10px;">
                    <span style="background:#fa8c16; color:#fff; width:22px; height:22px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:13px; font-weight:bold; flex-shrink:0;">6</span>
                    <div>验证成功后，屏幕上会显示一串字母密码。<strong>将其复制并粘贴到本系统的“IMAP 授权码”输入框中</strong>，点击保存即可！</div>
                </div>
            </div>
        </div>
        
        <div style="padding:16px 24px; border-top:1px solid #f0f0f0; background:#fafafa; text-align:right; border-radius:0 0 8px 8px;">
            <button onclick="document.getElementById('emailGuideModal').style.display='none'" class="btn btn-primary" style="padding:8px 24px; background:#fa8c16; border-color:#fa8c16;">我学会了</button>
        </div>
    </div>
</div>

<script>
// 控制弹窗显示的函数
function showEmailGuide() {
    document.getElementById('emailGuideModal').style.display = 'flex';
}
</script>
</body>
</html>