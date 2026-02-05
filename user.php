<?php
require_once 'config.php';
// 逻辑处理
$stmt = $pdo->query("SELECT * FROM batches WHERE status='open' ORDER BY id DESC LIMIT 1");
$current_batch = $stmt->fetch(PDO::FETCH_ASSOC);

// 获取历史
$my_items = [];
if ($current_batch) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE user_id = ? AND batch_id = ? ORDER BY id DESC");
    $stmt->execute([$_SESSION['user_id'], $current_batch['id']]);
    $my_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 引入公共头部
include 'header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center;">
    <h2>我的报销</h2>
</div>

<?php if($current_batch): ?>
    <div class="card">
        <h3 style="border-bottom:1px solid #f0f0f0; padding-bottom:15px; margin-bottom:20px;">
            当前档期：<span style="color:var(--primary-color)"><?php echo h($current_batch['name']); ?></span>
        </h3>
        
        <form action="action.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_items">
            <input type="hidden" name="batch_id" value="<?php echo $current_batch['id']; ?>">
            
            <div id="sections-container"></div>
            
            <div style="margin-top:24px; padding-top:24px; border-top:1px solid #f0f0f0; text-align:right;">
                <button type="button" class="btn btn-ghost" onclick="addCompanySection()" style="margin-right:12px;">+ 增加公司主体</button>
                <button type="submit" class="btn btn-primary" style="padding: 8px 32px; font-size:16px;">提交报销单</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>已提交记录</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>主体</th>
                    <th>详情</th>
                    <th>金额 (报/票)</th>
                    <th>备注</th>
                    <th>附件</th>
                    <th>状态</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($my_items as $item): ?>
                <tr>
                    <td>
                        <span class="tag tag-blue"><?php echo h($item['company']); ?></span>
                            <?php echo $item['company']; ?>
                        </span>
                    </td>
                    <td>
                        <div><?php echo $item['expense_date']; ?></div>
                        <div style="font-size:12px; color:var(--text-sub); margin-top:4px;">
                            <?php echo h($item['category']); ?> - <?php echo h($item['type']); ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:bold;">¥<?php echo $item['amount']; ?></div>
                        <div style="font-size:12px; color:var(--text-sub);">票: ¥<?php echo $item['invoice_amount']; ?></div>
                        <?php if($item['is_substitute']) echo "<span class='tag' style='background:#fff7e6; color:#faad14; border:none;'>替</span>"; ?>
                    </td>
                    <td style="max-width:200px;"><?php echo h($item['note']); ?></td>
                    <td>
                        <?php 
                        $invs = json_decode($item['invoice_path'] ?: '[]');
                        $sups = json_decode($item['support_path'] ?: '[]');
                        if(count($invs)) echo "<div>发票: ".count($invs)."张</div>";
                        if(count($sups)) echo "<div>辅证: ".count($sups)."张</div>";
                        ?>
                    </td>
                    <td>
                        <?php if($item['status']=='pending'): ?>
                            <span style="color:var(--warning)">审核中</span>
                            <a href="action.php?action=delete&id=<?php echo $item['id']; ?>" class="btn btn-ghost btn-sm" onclick="return confirm('确定撤回？')">撤回</a>
                        <?php elseif($item['status']=='rejected'): ?>
                            <div style="color:var(--danger)">已驳回</div>
                            <div style="font-size:11px; color:var(--text-sub);"><?php echo h($item['reject_reason']); ?></div>
                            <a href="action.php?action=delete&id=<?php echo $item['id']; ?>" style="font-size:12px; text-decoration:underline; color:var(--danger);">删除重填</a>
                        <?php else: ?>
                            <span style="color:var(--success)">已通过</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="card" style="text-align:center; padding:50px;">
        <h3 style="color:var(--text-sub);">当前没有开启的报销档期</h3>
        <p>请联系管理员开启</p>
    </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
<script>
    // 设置 PDF Worker 路径
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
</script>

<script src="main.js?v=<?php echo time(); ?>"></script>
</body>
</html>