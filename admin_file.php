<?php
require_once 'config.php';

// 权限检查
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die("只有管理员可以使用代填功能");
}

// 获取当前开启的档期
$stmt = $pdo->query("SELECT * FROM batches WHERE status='open' ORDER BY id DESC LIMIT 1");
$current_batch = $stmt->fetch(PDO::FETCH_ASSOC);

// 获取所有用户列表 (用于下拉选择)
$users = $pdo->query("SELECT id, realname, username FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3><i class="ri-user-shared-2-line"></i> 管理员代填服务</h3>
        <a href="admin.php" class="btn btn-ghost"><i class="ri-arrow-left-line"></i> 返回仪表盘</a>
    </div>

    <?php if($current_batch): ?>
        <form action="action.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_items">
            <input type="hidden" name="batch_id" value="<?php echo $current_batch['id']; ?>">
            
            <div style="background:#e6f7ff; border:1px solid #91d5ff; padding:20px; border-radius:8px; margin-bottom:24px;">
                <div style="font-weight:bold; color:#0050b3; margin-bottom:10px;">
                    <i class="ri-question-answer-line"></i> 您正在帮谁填报？
                </div>
                <select name="target_user_id" required style="width:300px; border:2px solid #1890ff;">
                    <option value="">-- 请选择员工 --</option>
                    <?php foreach($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>">
                            <?php echo h($u['realname']); ?> (<?php echo h($u['username']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <div style="margin-top:8px; font-size:12px; color:#666;">
                    * 提交后，报销单将直接归入该员工名下，发票也会存入该员工的文件夹中。
                </div>
            </div>

            <div id="sections-container"></div>
            
            <div style="margin-top:24px; padding-top:24px; border-top:1px solid #f0f0f0; text-align:right;">
                <button type="button" class="btn btn-ghost" onclick="addCompanySection()" style="margin-right:12px;"><i class="ri-building-2-line"></i> 增加公司主体</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 40px; font-size:16px; background:#0050b3;">
                    <i class="ri-send-plane-fill"></i> 确认代填提交
                </button>
            </div>
        </form>
    <?php else: ?>
        <div style="text-align:center; padding:50px; color:#999;">
            当前没有开启的档期，无法填报。
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
<script>pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';</script>
<script src="main.js?v=<?php echo time(); ?>"></script>
</body>
</html>