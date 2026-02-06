<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') die("无权访问");

// 处理上传
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $type = $_POST['type'];
    
    // 处理模板文件
    $tpl_path = "";
    if (isset($_FILES['tpl_file']) && $_FILES['tpl_file']['error'] == 0) {
        $ext = pathinfo($_FILES['tpl_file']['name'], PATHINFO_EXTENSION);
        if (!in_array($ext, ['xlsx', 'docx'])) die("只支持上传 .xlsx 或 .docx 格式的模板");
        
        $tpl_path = "uploads/template_" . time() . "." . $ext;
        move_uploaded_file($_FILES['tpl_file']['tmp_name'], $tpl_path);
    }

    $stmt = $pdo->prepare("INSERT INTO print_templates (name, type, bg_image, config_json, template_file) VALUES (?, ?, '', '[]', ?)");
    $stmt->execute([$name, $type, $tpl_path]);
    header("Location: templates.php"); exit;
}

// 删除逻辑
if (isset($_GET['del'])) {
    $id = $_GET['del'];
    $stmt = $pdo->prepare("SELECT template_file FROM print_templates WHERE id=?");
    $stmt->execute([$id]);
    $path = $stmt->fetchColumn();
    if ($path && file_exists($path)) unlink($path);
    $pdo->prepare("DELETE FROM print_templates WHERE id=?")->execute([$id]);
    header("Location: templates.php"); exit;
}

$list = $pdo->query("SELECT * FROM print_templates ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
include 'header.php';
?>

<div class="card">
    <h3><i class="ri-file-word-2-line"></i> 报销单导出模板设置</h3>
    
    <div style="background:#fff; border:1px solid #ddd; padding:20px; border-radius:8px; margin-bottom:20px;">
        <h4 style="margin-top:0; color:#333;">📖 模板制作指南</h4>
        <p style="color:#666; font-size:14px;">请在 Word 或 Excel 对应的单元格中，填入下表中的 <strong>${占位符}</strong>。导出时系统会自动计算并替换。</p>
        
        <style>
            .help-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top:10px; }
            .help-table th, .help-table td { border: 1px solid #eee; padding: 8px 12px; text-align: left; }
            .help-table th { background: #f5f7fa; color: #333; font-weight: bold; }
            .code-tag { background: #e6f7ff; color: #1890ff; padding: 2px 6px; border-radius: 4px; font-family: monospace; font-weight: bold; }
            .cat-header { background: #fafafa; font-weight: bold; color: #666; }
        </style>
        
        <table class="help-table">
            <thead>
                <tr>
                    <th width="30%">占位符 (直接填入文档)</th>
                    <th>说明</th>
                    <th>示例值</th>
                </tr>
            </thead>
            <tbody>
                <tr class="cat-header"><td colspan="3">📌 基础信息</td></tr>
                <tr><td><span class="code-tag">${公司}</span></td><td>报销主体公司名称</td><td>青岛海科数字科技有限公司</td></tr>
                <tr><td><span class="code-tag">${部门}</span></td><td>报销人所在部门</td><td>测试部</td></tr>
                <tr><td><span class="code-tag">${姓名}</span></td><td>报销人真实姓名</td><td>张三</td></tr>
                <tr><td><span class="code-tag">${账号}</span></td><td>报销人银行账号</td><td>622202...</td></tr>
                <tr><td><span class="code-tag">${日期}</span></td><td>导出当天的日期</td><td>2026-02-06</td></tr>
                <tr><td><span class="code-tag">${项目}</span></td><td>本次报销涉及的所有项目名称</td><td>百度AIGC项目, 阿里云项目</td></tr>

                <tr class="cat-header"><td colspan="3">💰 金额与附件</td></tr>
                <tr><td><span class="code-tag">${总额}</span></td><td>本单据总报销金额</td><td>1,250.50</td></tr>
                <tr><td><span class="code-tag">${大写}</span></td><td>总金额中文大写</td><td>壹仟贰佰伍拾元伍角</td></tr>
                <tr><td><span class="code-tag">${附件数}</span></td><td>发票及附件总张数</td><td>5</td></tr>

                <tr class="cat-header"><td colspan="3">📊 费用自动分类汇总 (系统会自动把同类费用加在一起)</td></tr>
                <tr>
                    <td>
                        <span class="code-tag">${招待费_金额}</span> <span class="code-tag">${招待费_张数}</span><br>
                        <span class="code-tag">${办公费_金额}</span> <span class="code-tag">${办公费_张数}</span><br>
                        <span class="code-tag">${交通费_金额}</span> <span class="code-tag">${交通费_张数}</span><br>
                        <span class="code-tag">${..._金额}</span> <span class="code-tag">${..._张数}</span>
                    </td>
                    <td>
                        对应填报时的“费用项目”。<br>
                        例如：您在 Word 的“招待费”那一行的金额格子里填 <span class="code-tag">${招待费_金额}</span>。<br>
                        如果没填该类费用，系统会自动留空或填0。
                    </td>
                    <td>
                        850.00<br>
                        3
                    </td>
                </tr>

                <tr class="cat-header"><td colspan="3">✈️ 差旅单专属</td></tr>
                <tr><td><span class="code-tag">${出差事由}</span></td><td>差旅事由</td><td>北京行业峰会</td></tr>
                <tr><td><span class="code-tag">${出差人员}</span></td><td>同行人员</td><td>李四, 王五</td></tr>
                <tr><td><span class="code-tag">${开始日期}</span> / <span class="code-tag">${结束日期}</span></td><td>出差起止时间</td><td>2026-02-01</td></tr>
                <tr><td><span class="code-tag">${出差天数}</span></td><td>共计天数</td><td>5</td></tr>
            </tbody>
        </table>
    </div>

    <form method="post" enctype="multipart/form-data" style="background:#f9f9f9; padding:20px; border-radius:8px;">
        <div style="display:flex; gap:10px; align-items:center;">
            <input type="text" name="name" placeholder="模板名称 (如: 标准费用报销单)" required class="form-control" style="width:200px;">
            <select name="type" required class="form-select">
                <option value="费用报销单">费用报销单</option>
                <option value="差旅费报销单">差旅费报销单</option>
            </select>
            <input type="file" name="tpl_file" accept=".docx,.xlsx" required class="form-control">
            <button type="submit" class="btn btn-primary">上传模板</button>
        </div>
    </form>

    <table class="data-table" style="margin-top:20px;">
        <thead><tr><th>ID</th><th>名称</th><th>类型</th><th>文件名</th><th>操作</th></tr></thead>
        <tbody>
            <?php foreach($list as $t): ?>
            <tr>
                <td><?php echo $t['id']; ?></td>
                <td><?php echo h($t['name']); ?></td>
                <td><span class="tag"><?php echo h($t['type']); ?></span></td>
                <td>
                    <?php if($t['template_file']): ?>
                        <?php $ext = pathinfo($t['template_file'], PATHINFO_EXTENSION); ?>
                        <?php if($ext=='docx'): ?>
                            <i class="ri-file-word-2-fill" style="color:#1890ff;"></i> Word
                        <?php else: ?>
                            <i class="ri-file-excel-2-fill" style="color:#52c41a;"></i> Excel
                        <?php endif; ?>
                        <?php echo basename($t['template_file']); ?>
                    <?php else: ?>
                        <span style="color:#999;">旧版图片</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="?del=<?php echo $t['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('删除？')">删除</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>