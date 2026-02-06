<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') die("无权访问");

// 处理上传 (保持不变)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $type = $_POST['type'];
    $tpl_path = "";
    if (isset($_FILES['tpl_file']) && $_FILES['tpl_file']['error'] == 0) {
        $ext = pathinfo($_FILES['tpl_file']['name'], PATHINFO_EXTENSION);
        // 同时支持 docx, xlsx 和 图片
        $allowed = ['docx', 'xlsx', 'jpg', 'jpeg', 'png'];
        if (!in_array(strtolower($ext), $allowed)) die("不支持的文件格式");
        
        $tpl_path = "uploads/template_" . time() . "." . $ext;
        move_uploaded_file($_FILES['tpl_file']['tmp_name'], $tpl_path);
    }
    // 图片模板存 bg_image, 文档模板存 template_file
    $is_img = in_array(strtolower($ext), ['jpg', 'jpeg', 'png']);
    $bg = $is_img ? $tpl_path : '';
    $file = $is_img ? '' : $tpl_path;

    $stmt = $pdo->prepare("INSERT INTO print_templates (name, type, bg_image, config_json, template_file) VALUES (?, ?, ?, '[]', ?)");
    $stmt->execute([$name, $type, $bg, $file]);
    header("Location: templates.php"); exit;
}

// 删除逻辑 (保持不变)
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
.cat-header { background: #fafafa; font-weight: bold; color: #666; }
</style>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3><i class="ri-settings-4-line"></i> 打印/导出模板管理</h3>
        <div>
            <button onclick="showGuide()" class="btn btn-secondary btn-sm"><i class="ri-question-line"></i> 查看占位符说明</button>
            <a href="settings.php" class="btn btn-ghost btn-sm">返回设置</a>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" style="background:#f8f9fa; padding:20px; border-radius:8px; border:1px dashed #ced4da;">
        <h4 style="margin-top:0; font-size:15px;">📤 上传新模板</h4>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input type="text" name="name" placeholder="模板名称 (如: 标准Word费用单)" required class="form-control" style="width:200px;">
            <select name="type" required class="form-select">
                <option value="费用报销单">费用报销单</option>
                <option value="差旅费报销单">差旅费报销单</option>
            </select>
            <input type="file" name="tpl_file" accept=".docx,.xlsx,.jpg,.png" required class="form-control" title="支持 Word(.docx), Excel(.xlsx) 或 图片(.jpg/.png)">
            <button type="submit" class="btn btn-primary">上传</button>
        </div>
        <div style="font-size:12px; color:#666; margin-top:8px;">
            * 上传 <strong>.docx/.xlsx</strong> 用于导出可编辑文件（推荐）。<br>
            * 上传 <strong>.jpg/.png</strong> 用于在线图片打印（旧版模式）。
        </div>
    </form>

    <table class="data-table" style="margin-top:20px;">
        <thead><tr><th>ID</th><th>名称</th><th>类型</th><th>模式</th><th>文件名/预览</th><th>操作</th></tr></thead>
        <tbody>
            <?php foreach($list as $t): ?>
            <tr>
                <td><?php echo $t['id']; ?></td>
                <td><?php echo h($t['name']); ?></td>
                <td><span class="tag"><?php echo h($t['type']); ?></span></td>
                <td>
                    <?php if($t['template_file']): ?>
                        <span style="color:#1890ff;">📄 文档导出</span>
                    <?php else: ?>
                        <span style="color:#52c41a;">🖨️ 图片打印</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if($t['template_file']): ?>
                        <i class="ri-file-word-2-fill"></i> <?php echo basename($t['template_file']); ?>
                    <?php elseif($t['bg_image']): ?>
                        <img src="<?php echo h($t['bg_image']); ?>" style="height:30px; vertical-align:middle; border:1px solid #ddd;">
                    <?php endif; ?>
                </td>
                <td>
                    <?php if(!$t['template_file']): // 如果是图片模板，显示设计按钮 ?>
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
            <h3 style="margin:0;">📖 Word/Excel 模板制作指南</h3>
            <button onclick="document.getElementById('guideModal').style.display='none'" style="border:none; background:none; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <p style="color:#666;">在文档中填入对应的 <strong>${占位符}</strong>，系统导出时会自动替换。</p>
        
        <table class="help-table">
            <thead>
                <tr><th width="30%">占位符</th><th>说明</th><th>示例</th></tr>
            </thead>
            <tbody>
                <tr class="cat-header"><td colspan="3">📌 基础信息</td></tr>
                <tr><td><span class="code-tag">${公司}</span></td><td>公司名称</td><td>xx科技</td></tr>
                <tr><td><span class="code-tag">${部门}</span></td><td>部门名称</td><td>研发部</td></tr>
                <tr><td><span class="code-tag">${姓名}</span></td><td>报销人</td><td>张三</td></tr>
                <tr><td><span class="code-tag">${账号}</span></td><td>银行账号</td><td>6222...</td></tr>
                <tr><td><span class="code-tag">${日期}</span></td><td>导出日期</td><td>2026-02-06</td></tr>
                <tr><td><span class="code-tag">${项目}</span></td><td>所属项目</td><td>AI项目</td></tr>

                <tr class="cat-header"><td colspan="3">💰 金额与附件</td></tr>
                <tr><td><span class="code-tag">${总额}</span></td><td>合计金额</td><td>100.00</td></tr>
                <tr><td><span class="code-tag">${大写}</span></td><td>中文大写</td><td>壹佰元整</td></tr>
                <tr><td><span class="code-tag">${附件数}</span></td><td>发票张数</td><td>2</td></tr>

                <tr class="cat-header"><td colspan="3">📊 自动分类汇总 (推荐)</td></tr>
                <tr>
                    <td><span class="code-tag">${招待费_金额}</span> <span class="code-tag">${招待费_张数}</span></td>
                    <td>自动计算该类别的总额/张数</td>
                    <td>填在对应行</td>
                </tr>
                <tr>
                    <td><span class="code-tag">${办公费_金额}</span> <span class="code-tag">${办公费_张数}</span></td>
                    <td>支持所有常见费用类型</td>
                    <td>...</td>
                </tr>

                <tr class="cat-header"><td colspan="3">✈️ 差旅专属</td></tr>
                <tr><td><span class="code-tag">${出差事由}</span></td><td>事由</td><td>会议</td></tr>
                <tr><td><span class="code-tag">${出差人员}</span></td><td>同行人</td><td>李四</td></tr>
                <tr><td><span class="code-tag">${开始日期}</span> - <span class="code-tag">${结束日期}</span></td><td>起止时间</td><td>2026-02-01</td></tr>
                <tr><td><span class="code-tag">${出差天数}</span></td><td>天数</td><td>3</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function showGuide() {
    document.getElementById('guideModal').style.display = 'block';
}
function hideGuide(e) {
    if(e.target.className === 'modal-overlay') {
        document.getElementById('guideModal').style.display = 'none';
    }
}
</script>
</body>
</html>