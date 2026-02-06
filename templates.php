<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') die("无权访问");

// --- 1. 处理上传 (逻辑完全保留，增加了对新类型的兼容) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $type = $_POST['type'];
    
    // 支持 docx, xlsx (用于文档/报表) 和 jpg/png (用于图片打印)
    $tpl_path = "";
    if (isset($_FILES['tpl_file']) && $_FILES['tpl_file']['error'] == 0) {
        $ext = pathinfo($_FILES['tpl_file']['name'], PATHINFO_EXTENSION);
        $allowed = ['docx', 'xlsx', 'jpg', 'jpeg', 'png'];
        if (!in_array(strtolower($ext), $allowed)) die("不支持的文件格式");
        
        $tpl_path = "uploads/template_" . time() . "." . $ext;
        move_uploaded_file($_FILES['tpl_file']['tmp_name'], $tpl_path);
    }
    
    // 判断是图片模式还是文件模式
    $is_img = in_array(strtolower($ext), ['jpg', 'jpeg', 'png']);
    $bg = $is_img ? $tpl_path : '';      // 图片存这里
    $file = $is_img ? '' : $tpl_path;    // 文档/Excel存这里

    $stmt = $pdo->prepare("INSERT INTO print_templates (name, type, bg_image, config_json, template_file) VALUES (?, ?, ?, '[]', ?)");
    $stmt->execute([$name, $type, $bg, $file]);
    header("Location: templates.php"); exit;
}

// --- 2. 删除逻辑 (完全保留) ---
if (isset($_GET['del'])) {
    $id = $_GET['del'];
    $stmt = $pdo->prepare("SELECT bg_image, template_file FROM print_templates WHERE id=?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if ($r['bg_image'] && file_exists($r['bg_image'])) unlink($r['bg_image']);
    if ($r['template_file'] && file_exists($r['template_file'])) unlink($r['template_file']);
    $pdo->prepare("DELETE FROM print_templates WHERE id=?")->execute([$id]);
    header("Location: templates.php"); exit;
}

$list = $pdo->query("SELECT * FROM print_templates ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
include 'header.php';
?>

<style>
.modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
.modal-box { 
    position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    background: #fff; padding: 25px; border-radius: 8px; width: 80%; max-width: 800px;
    max-height: 90vh; overflow-y: auto; box-shadow: 0 4px 20px rgba(0,0,0,0.2);
}
.help-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top:10px; }
.help-table th, .help-table td { border: 1px solid #eee; padding: 8px 12px; text-align: left; }
.help-table th { background: #f5f7fa; color: #333; font-weight: bold; }
.code-tag { background: #e6f7ff; color: #1890ff; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-weight: bold; }
</style>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3><i class="ri-settings-4-line"></i> 打印与导出模板管理</h3>
        <div>
            <button onclick="showGuide()" class="btn btn-secondary btn-sm"><i class="ri-question-line"></i> 查看占位符说明</button>
            <a href="settings.php" class="btn btn-ghost btn-sm">返回设置</a>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" style="background:#f8f9fa; padding:20px; border-radius:8px; border:1px dashed #ced4da;">
        <h4 style="margin-top:0; font-size:15px;">📤 上传新模板</h4>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input type="text" name="name" placeholder="模板名称 (如: 财务月度汇总表)" required class="form-control" style="width:200px;">
            
            <select name="type" required class="form-select">
                <option value="费用报销单">单据：费用报销单 (Word/Excel/图片)</option>
                <option value="差旅费报销单">单据：差旅费报销单 (Word/Excel/图片)</option>
                <option value="批量明细清单" style="color:#722ed1; font-weight:bold;">报表：批量明细清单 (仅限Excel)</option>
            </select>
            
            <input type="file" name="tpl_file" accept=".docx,.xlsx,.jpg,.jpeg,.png" required class="form-control">
            <button type="submit" class="btn btn-primary">上传</button>
        </div>
        <div style="font-size:12px; color:#666; margin-top:8px;">
            * 上传 <strong>.docx/.xlsx</strong> 用于导出文档（推荐）；上传 <strong>.jpg</strong> 用于在线打印。<br>
            * 若制作 <strong>“批量明细清单”</strong>，请务必上传 .xlsx 格式。
        </div>
    </form>

    <table class="data-table" style="margin-top:20px;">
        <thead><tr><th>ID</th><th>名称</th><th>类型</th><th>模式</th><th>文件名</th><th>操作</th></tr></thead>
        <tbody>
            <?php foreach($list as $t): ?>
            <tr>
                <td><?php echo $t['id']; ?></td>
                <td><?php echo h($t['name']); ?></td>
                <td><span class="tag"><?php echo h($t['type']); ?></span></td>
                <td>
                    <?php if($t['type'] == '批量明细清单'): ?>
                        <span style="color:#722ed1; font-weight:bold;">📊 列表报表</span>
                    <?php elseif($t['template_file']): ?>
                        <span style="color:#1890ff;">📄 单据导出</span>
                    <?php else: ?>
                        <span style="color:#52c41a;">🖨️ 图片打印</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if($t['template_file']): ?>
                        <?php 
                            $icon = strpos($t['template_file'], '.docx') !== false ? 'ri-file-word-2-fill' : 'ri-file-excel-2-fill';
                            $color = strpos($t['template_file'], '.docx') !== false ? '#1890ff' : '#52c41a';
                        ?>
                        <i class="<?php echo $icon; ?>" style="color:<?php echo $color; ?>;"></i>
                        <?php echo basename($t['template_file']); ?>
                    <?php elseif($t['bg_image']): ?>
                        <img src="<?php echo h($t['bg_image']); ?>" style="height:30px; vertical-align:middle; border:1px solid #ddd;">
                    <?php endif; ?>
                </td>
                <td>
                    <?php if(!$t['template_file']): ?>
                        <a href="template_design.php?id=<?php echo $t['id']; ?>" class="btn btn-secondary btn-sm">设计坐标</a>
                    <?php endif; ?>
                    <a href="?del=<?php echo $t['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定删除？')">删除</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div id="guideModal" class="modal-overlay" onclick="hideGuide(event)">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0;">📖 模板制作指南</h3>
            <button onclick="document.getElementById('guideModal').style.display='none'" style="border:none; background:none; font-size:20px; cursor:pointer;">&times;</button>
        </div>

        <h4 style="border-left:4px solid #1890ff; padding-left:10px; margin-top:0;">1. 单据类 (Word/Excel)</h4>
        <p style="font-size:13px; color:#666; margin-bottom:5px;">用于导出“单个报销单”。请在对应格子里填入占位符：</p>
        <div style="background:#f9f9f9; padding:10px; border-radius:4px; margin-bottom:20px;">
            <span class="code-tag">${公司}</span> <span class="code-tag">${部门}</span> <span class="code-tag">${姓名}</span> 
            <span class="code-tag">${账号}</span> <span class="code-tag">${日期}</span> <span class="code-tag">${总额}</span>
            <span class="code-tag">${大写}</span> <span class="code-tag">${附件数}</span> <span class="code-tag">${招待费_金额}</span>
        </div>

        <h4 style="border-left:4px solid #722ed1; padding-left:10px;">2. 批量明细清单 (Excel)</h4>
        <p style="font-size:13px; color:#666;">用于导出“几百条数据的汇总表”。请在 Excel 的<strong>数据行（如第2行）</strong>中填入以下占位符，系统会自动向下复制。</p>
        <table class="help-table">
            <thead><tr><th>Excel列占位符</th><th>对应内容</th></tr></thead>
            <tbody>
                <tr><td><span class="code-tag">${date}</span></td><td>发生日期</td></tr>
                <tr><td><span class="code-tag">${realname}</span></td><td>报销人姓名</td></tr>
                <tr><td><span class="code-tag">${department}</span></td><td>部门</td></tr>
                <tr><td><span class="code-tag">${type}</span></td><td>费用类别 (招待费/办公费...)</td></tr>
                <tr><td><span class="code-tag">${project}</span></td><td>项目名称</td></tr>
                <tr><td><span class="code-tag">${amount}</span></td><td>报销金额</td></tr>
                <tr><td><span class="code-tag">${note}</span></td><td>备注说明</td></tr>
                <tr><td><span class="code-tag">${company}</span></td><td>所属公司主体</td></tr>
                <tr><td><span class="code-tag">${status}</span></td><td>单据状态</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function showGuide() { document.getElementById('guideModal').style.display = 'block'; }
function hideGuide(e) { if(e.target.className === 'modal-overlay') document.getElementById('guideModal').style.display = 'none'; }
</script>
</body>
</html>