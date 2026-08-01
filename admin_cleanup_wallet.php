<?php
// admin_cleanup_wallet.php - 管理员票夹清理工具
// 用于处理档期误删后发票回流到票夹的混乱问题
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die("无权访问");
}

$msg = '';
$msg_type = '';

// --- 处理清理操作 ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // 批量删除选中的发票
    if ($_POST['action'] === 'delete_selected' && !empty($_POST['inv_ids'])) {
        $ids = array_map('intval', $_POST['inv_ids']);
        $id_str = implode(',', $ids);
        
        // 先获取文件路径用于物理删除
        $stmt = $pdo->query("SELECT id, file_path FROM invoices WHERE id IN ($id_str) AND status='unused'");
        $to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $deleted_files = 0;
        foreach ($to_delete as $inv) {
            $absolutePath = resolveProjectUploadPath($inv['file_path'] ?? '');
            if ($absolutePath !== false) {
                @unlink($absolutePath);
                $deleted_files++;
            }
        }
        
        $pdo->exec("DELETE FROM invoices WHERE id IN ($id_str) AND status='unused'");
        $count = count($to_delete);
        $msg = "已成功清理 {$count} 张发票（同时删除 {$deleted_files} 个物理文件）";
        $msg_type = 'success';
    }
    
    // 清理所有孤儿发票（文件不存在的）
    if ($_POST['action'] === 'clean_orphans') {
        $stmt = $pdo->query("SELECT id, file_path FROM invoices WHERE status='unused'");
        $orphan_count = 0;
        $orphan_ids = [];
        while ($inv = $stmt->fetch()) {
            if (resolveProjectUploadPath($inv['file_path'] ?? '') === false) {
                $orphan_ids[] = $inv['id'];
                $orphan_count++;
            }
        }
        if (!empty($orphan_ids)) {
            $id_str = implode(',', $orphan_ids);
            $pdo->exec("DELETE FROM invoices WHERE id IN ($id_str)");
        }
        $msg = "已清理 {$orphan_count} 条孤儿发票记录";
        $msg_type = 'success';
    }
    
    // 按用户清理所有 unused 发票
    if ($_POST['action'] === 'clean_user' && !empty($_POST['target_uid'])) {
        $uid = intval($_POST['target_uid']);
        
        // 获取文件路径
        $stmt = $pdo->prepare("SELECT id, file_path FROM invoices WHERE user_id=? AND status='unused'");
        $stmt->execute([$uid]);
        $to_delete = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $deleted_files = 0;
        foreach ($to_delete as $inv) {
            $absolutePath = resolveProjectUploadPath($inv['file_path'] ?? '');
            if ($absolutePath !== false) {
                @unlink($absolutePath);
                $deleted_files++;
            }
        }
        
        $pdo->prepare("DELETE FROM invoices WHERE user_id=? AND status='unused'")->execute([$uid]);
        $count = count($to_delete);
        
        // 获取用户名
        $uname = $pdo->prepare("SELECT realname FROM users WHERE id=?");
        $uname->execute([$uid]);
        $realname = $uname->fetchColumn();
        
        $msg = "已清理 {$realname} 名下 {$count} 张未使用发票（同时删除 {$deleted_files} 个物理文件）";
        $msg_type = 'success';
    }
}

// --- 获取数据 ---
// 按用户分组统计 unused 发票
$user_stats = $pdo->query("
    SELECT u.id, u.realname, u.username, COUNT(i.id) as cnt, SUM(i.amount) as total_amount
    FROM invoices i 
    LEFT JOIN users u ON i.user_id = u.id 
    WHERE i.status = 'unused'
    GROUP BY i.user_id
    ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 全局统计
$total_unused = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='unused'")->fetchColumn();
$total_orphan = 0;
$orphan_check = $pdo->query("SELECT id, file_path FROM invoices WHERE status='unused'");
while ($row = $orphan_check->fetch()) {
    if (resolveProjectUploadPath($row['file_path'] ?? '') === false) {
        $total_orphan++;
    }
}

// --- 按用户查看详情 ---
$view_uid = isset($_GET['view_uid']) ? intval($_GET['view_uid']) : null;
$user_invoices = [];
if ($view_uid) {
    $stmt = $pdo->prepare("
        SELECT i.*, u.realname 
        FROM invoices i 
        LEFT JOIN users u ON i.user_id = u.id 
        WHERE i.user_id = ? AND i.status = 'unused'
        ORDER BY i.id DESC
    ");
    $stmt->execute([$view_uid]);
    $user_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $view_user = $pdo->prepare("SELECT realname FROM users WHERE id=?");
    $view_user->execute([$view_uid]);
    $view_uname = $view_user->fetchColumn();
}

include 'header.php';
?>

<style>
.cleanup-container { max-width: 1200px; }
.stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card { background: #fff; border-radius: 8px; padding: 20px; border: 1px solid #e8e8e8; }
.stat-card .stat-num { font-size: 28px; font-weight: bold; }
.stat-card .stat-label { font-size: 13px; color: #999; margin-top: 4px; }
.stat-card.warn { border-color: #faad14; background: #fffbe6; }
.stat-card.warn .stat-num { color: #d48806; }
.stat-card.danger { border-color: #ff4d4f; background: #fff2f0; }
.stat-card.danger .stat-num { color: #cf1322; }
.user-row { cursor: pointer; }
.user-row:hover { background: #f0f7ff !important; }
.selected-row { background: #e6f7ff !important; }
.batch-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
.alert-box { padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; }
.alert-info { background: #e6f7ff; border: 1px solid #91d5ff; color: #0050b3; }
.alert-warn { background: #fffbe6; border: 1px solid #ffe58f; color: #ad6800; }
.alert-success { background: #f6ffed; border: 1px solid #b7eb8f; color: #389e0d; }
</style>

<div class="cleanup-container">

<h2 style="margin-bottom: 8px;"><i class="ri-tools-line"></i> 票夹数据清理工具</h2>
<p style="color: #999; margin-bottom: 24px;">用于管理删除档期后回流到用户票夹的发票数据，以及清理孤儿记录。</p>

<?php if ($msg): ?>
<div class="alert-box alert-<?php echo $msg_type === 'success' ? 'success' : 'warn'; ?>">
    <i class="ri-<?php echo $msg_type === 'success' ? 'checkbox-circle' : 'error-warning'; ?>-line"></i>
    <?php echo h($msg); ?>
</div>
<?php endif; ?>

<div class="alert-box alert-info">
    <strong><i class="ri-information-line"></i> 使用说明：</strong><br>
    1. 下方列出了所有状态为 <b>"未使用"</b> 的发票（含被误删档期回流的发票）。<br>
    2. 点击用户可查看其名下的全部未使用发票详情，勾选后批量删除。<br>
    3. 也可以一键清理某个用户的所有未使用发票，或清理文件不存在的孤儿记录。<br>
    4. <b style="color:#cf1322;">⚠ 删除操作不可逆，请谨慎确认！</b>
</div>

<!-- 统计卡片 -->
<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-num" style="color:#1677ff;"><?php echo $total_unused; ?></div>
        <div class="stat-label">全部未使用发票</div>
    </div>
    <div class="stat-card warn">
        <div class="stat-num"><?php echo $total_orphan; ?></div>
        <div class="stat-label">孤儿记录 (文件不存在)</div>
    </div>
    <div class="stat-card">
        <div class="stat-num" style="color:#52c41a;"><?php echo count($user_stats); ?></div>
        <div class="stat-label">涉及用户数</div>
    </div>
</div>

<!-- 快速操作按钮 -->
<div class="batch-actions">
    <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="clean_orphans">
        <button type="submit" class="btn btn-warning" onclick="return confirm('确定要清理所有文件不存在的孤儿发票记录吗？')" <?php if($total_orphan==0) echo 'disabled'; ?>>
            <i class="ri-delete-bin-line"></i> 清理孤儿记录 (<?php echo $total_orphan; ?>条)
        </button>
    </form>
</div>

<!-- 用户列表 -->
<div class="card">
    <h3><i class="ri-group-line"></i> 按用户查看</h3>
    <table class="data-table">
        <thead>
            <tr>
                <th>用户</th>
                <th>账号</th>
                <th>未使用发票数</th>
                <th>发票总额</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($user_stats as $u): ?>
            <tr class="user-row" style="<?php echo ($view_uid == $u['id']) ? 'background:#e6f7ff;' : ''; ?>">
                <td>
                    <a href="?view_uid=<?php echo $u['id']; ?>" style="font-weight:bold; color:#1677ff;">
                        <?php echo h($u['realname']); ?>
                    </a>
                </td>
                <td><?php echo h($u['username']); ?></td>
                <td>
                    <span style="<?php echo $u['cnt'] > 10 ? 'color:#cf1322;font-weight:bold;' : ''; ?>">
                        <?php echo $u['cnt']; ?> 张
                    </span>
                </td>
                <td style="font-weight:bold;">¥<?php echo number_format($u['total_amount'], 2); ?></td>
                <td>
                    <a href="?view_uid=<?php echo $u['id']; ?>" class="btn btn-ghost btn-sm">
                        <i class="ri-eye-line"></i> 查看详情
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($user_stats)): ?>
            <tr><td colspan="5" style="text-align:center; padding:30px; color:#999;">
                <i class="ri-checkbox-circle-line" style="color:#52c41a; font-size:20px;"></i> 没有未使用的发票，票夹非常干净！
            </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 用户发票明细 -->
<?php if ($view_uid && !empty($user_invoices)): ?>
<div class="card" style="margin-top:24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <h3 style="margin:0;">
            <i class="ri-user-star-line"></i> <?php echo h($view_uname); ?> 的未使用发票 
            <span style="font-size:14px; color:#999;">(共 <?php echo count($user_invoices); ?> 张)</span>
        </h3>
        <div style="display:flex; gap:10px;">
            <button type="button" class="btn btn-ghost btn-sm" onclick="toggleSelectAll()">
                <i class="ri-checkbox-multiple-line"></i> 全选/取消
            </button>
            <form method="post" id="deleteForm" style="display:inline;">
                <input type="hidden" name="action" value="delete_selected">
                <div id="selectedIdsContainer"></div>
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirmDelete()">
                    <i class="ri-delete-bin-line"></i> 删除选中发票
                </button>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('确定要删除 <?php echo h($view_uname); ?> 名下全部 <?php echo count($user_invoices); ?> 张未使用发票吗？此操作不可恢复！')">
                <input type="hidden" name="action" value="clean_user">
                <input type="hidden" name="target_uid" value="<?php echo $view_uid; ?>">
                <button type="submit" class="btn btn-danger btn-sm" style="background:#cf1322;">
                    <i class="ri-delete-bin-2-line"></i> 一键清空此用户
                </button>
            </form>
        </div>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                <th>ID</th>
                <th>发票号码</th>
                <th>金额</th>
                <th>发票日期</th>
                <th>购买方</th>
                <th>销售方</th>
                <th>类型</th>
                <th>文件状态</th>
                <th>录入时间</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($user_invoices as $inv): 
                $file_exists = resolveProjectUploadPath($inv['file_path'] ?? '') !== false;
            ?>
            <tr style="<?php echo !$file_exists ? 'background:#fff2f0;' : ''; ?>">
                <td>
                    <input type="checkbox" name="inv_select" value="<?php echo $inv['id']; ?>" 
                           class="inv-checkbox" onchange="updateSelectedIds()">
                </td>
                <td><?php echo $inv['id']; ?></td>
                <td style="font-family:monospace; font-size:12px;"><?php echo h($inv['invoice_number'] ?: '-'); ?></td>
                <td style="font-weight:bold;">¥<?php echo number_format($inv['amount'], 2); ?></td>
                <td><?php echo $inv['invoice_date'] ?: '-'; ?></td>
                <td style="max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" 
                    title="<?php echo h($inv['buyer_name']); ?>">
                    <?php echo h($inv['buyer_name'] ?: '-'); ?>
                </td>
                <td style="max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                    title="<?php echo h($inv['seller_name']); ?>">
                    <?php echo h($inv['seller_name'] ?: '-'); ?>
                </td>
                <td>
                    <span class="tag"><?php echo h($inv['invoice_special_type']); ?></span>
                </td>
                <td>
                    <?php if ($file_exists): ?>
                        <span style="color:#52c41a;"><i class="ri-file-line"></i> 文件正常</span>
                    <?php else: ?>
                        <span style="color:#ff4d4f;"><i class="ri-error-warning-line"></i> 文件丢失</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px; color:#999;"><?php echo $inv['created_at']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif ($view_uid): ?>
<div class="card" style="margin-top:24px; text-align:center; padding:40px; color:#999;">
    <i class="ri-checkbox-circle-line" style="font-size:40px; color:#52c41a;"></i>
    <p><?php echo h($view_uname); ?> 名下没有未使用的发票。</p>
</div>
<?php endif; ?>

</div>

<script>
function toggleSelectAll() {
    const checkboxes = document.querySelectorAll('.inv-checkbox');
    const selectAll = document.getElementById('selectAll');
    const newState = !selectAll.checked;
    checkboxes.forEach(cb => {
        cb.checked = newState;
    });
    selectAll.checked = newState;
    updateSelectedIds();
}

function updateSelectedIds() {
    const container = document.getElementById('selectedIdsContainer');
    const checkboxes = document.querySelectorAll('.inv-checkbox:checked');
    container.innerHTML = '';
    checkboxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'inv_ids[]';
        input.value = cb.value;
        container.appendChild(input);
    });
    
    // 同步全选框状态
    const allBoxes = document.querySelectorAll('.inv-checkbox');
    const checkedBoxes = document.querySelectorAll('.inv-checkbox:checked');
    document.getElementById('selectAll').checked = 
        allBoxes.length > 0 && allBoxes.length === checkedBoxes.length;
}

function confirmDelete() {
    const count = document.querySelectorAll('.inv-checkbox:checked').length;
    if (count === 0) {
        alert('请先勾选要删除的发票');
        return false;
    }
    return confirm('确定要删除选中的 ' + count + ' 张发票吗？\n此操作不可恢复！');
}
</script>

<?php include 'common_modals.php'; ?>
</body>
</html>
