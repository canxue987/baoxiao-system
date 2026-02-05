<?php
require 'config.php';

// 权限检查
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die("无权访问");
}

$comp_file = __DIR__ . '/db/companies.json';
$companies = [];
if (file_exists($comp_file)) {
    $companies = json_decode(file_get_contents($comp_file), true);
}

// --- 处理表单提交 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 添加
    if (isset($_POST['add_comp'])) {
        $new_name = trim($_POST['new_name']);
        if ($new_name && !in_array($new_name, $companies)) {
            $companies[] = $new_name;
            file_put_contents($comp_file, json_encode($companies, JSON_UNESCAPED_UNICODE));
        }
    }
    // 删除
    if (isset($_POST['del_comp'])) {
        $del_name = $_POST['del_comp'];
        // 从数组中移除
        $key = array_search($del_name, $companies);
        if ($key !== false) {
            unset($companies[$key]);
            // 重建索引，否则变成对象而不是数组
            $companies = array_values($companies);
            file_put_contents($comp_file, json_encode($companies, JSON_UNESCAPED_UNICODE));
        }
    }
    // 刷新页面
    header("Location: settings.php");
    exit;
}

include 'header.php';
?>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3><i class="ri-settings-3-line"></i> 系统设置</h3>
        <a href="admin.php" class="btn btn-ghost"><i class="ri-arrow-left-line"></i> 返回仪表盘</a>
    </div>

    <div style="background:#fff7e6; border:1px solid #ffd591; padding:15px; border-radius:6px; margin-bottom:24px; color:#d48806; font-size:13px;">
        <i class="ri-error-warning-line"></i> <strong>提示：</strong> 
        这里的修改仅影响“填报页面”的下拉选项。已生成的历史报销记录中的公司名不会被改变。
        <br>配置文件路径：<code>/db/companies.json</code> (不会被上传到 git)
    </div>

    <h4>🏢 公司主体管理</h4>
    
    <div style="margin-bottom:20px;">
        <?php if(empty($companies)): ?>
            <div style="color:#999; padding:10px;">暂无公司主体，请添加。</div>
        <?php else: ?>
            <div style="display:flex; flex-wrap:wrap; gap:10px;">
                <?php foreach($companies as $c): ?>
                    <div style="background:#f0f2f5; padding:8px 16px; border-radius:20px; border:1px solid #d9d9d9; display:flex; align-items:center; gap:8px;">
                        <span style="font-weight:bold;"><?php echo $c; ?></span>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="del_comp" value="<?php echo $c; ?>">
                            <button type="submit" style="background:none; border:none; color:#ff4d4f; cursor:pointer; font-size:16px; padding:0;" onclick="return confirm('确定从下拉选项中移除吗？')">
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

</body>
</html>