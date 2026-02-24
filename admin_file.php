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

<style>
    .modal-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none; align-items: center; justify-content: center;
        z-index: 9999;
        backdrop-filter: blur(2px);
    }
    .modal-box {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        width: 700px;
        max-width: 95%;
        display: flex;
        flex-direction: column;
        max-height: 85vh;
        border: 1px solid #e8e8e8;
    }
    .modal-header {
        padding: 16px 24px;
        border-bottom: 1px solid #f0f0f0;
        display: flex; justify-content: space-between; align-items: center;
        background: #fafafa;
        border-radius: 8px 8px 0 0;
    }
    .modal-header h3 { margin: 0; font-size: 16px; color: #333; font-weight: 600; }
    .modal-body { padding: 0; overflow-y: auto; flex: 1; background: #fff; }
    .modal-footer { padding: 12px 24px; border-top: 1px solid #f0f0f0; text-align: right; background: #fff; border-radius: 0 0 8px 8px; }

    .wallet-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .wallet-table th { background: #fafafa; color: #666; font-weight: 600; padding: 12px 16px; text-align: left; border-bottom: 1px solid #eee; position: sticky; top: 0; z-index: 1; }
    .wallet-table td { padding: 12px 16px; border-bottom: 1px solid #f5f5f5; color: #333; vertical-align: middle; cursor: pointer; }
    .wallet-table tr:hover { background: #f0f7ff; }
    .wallet-table tr.selected { background: #e6f7ff; }
</style>

<div id="walletModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="wallet-modal-title"><i class="ri-wallet-3-line" style="color:#1890ff; margin-right:5px;"></i> 从员工票夹选择发票</h3>
            <button onclick="document.getElementById('walletModal').style.display='none'" class="btn btn-ghost btn-sm" style="border:none; font-size:16px; color:#999;">
                <i class="ri-close-line"></i>
            </button>
        </div>
        
        <div class="modal-body" id="walletList">
            <div style="text-align:center; padding: 40px; color:#999;">
                <i class="ri-loader-4-line ri-spin" style="font-size:24px;"></i><br>正在加载票夹...
            </div>
        </div>
        
        <div class="modal-footer">
            <span style="float:left; font-size:12px; color:#999; line-height:30px;">
                * 仅显示该员工“未使用”的发票
            </span>
            <button type="button" onclick="document.getElementById('walletModal').style.display='none'" class="btn btn-ghost" style="margin-right:10px;">取消</button>
            <button type="button" onclick="confirmWalletSelection()" class="btn btn-primary">确认选择</button>
        </div>
    </div>
</div>

<script>
// --- 全局变量 ---
let currentActiveRowInput = null; 
let currentActiveBadge = null;    
let currentActiveRowId = null;    

// 1. 被 main.js 里的按钮调用 (代填页面专用逻辑)
function selectFromWallet(btn, rowIndex) {
    // ✨ 拦截检查：必须先选择代填员工
    let userSelect = document.querySelector('select[name="target_user_id"]');
    let targetUid = userSelect ? userSelect.value : '';
    
    if(!targetUid) {
        alert("⚠️ 请先在页面最上方选择您要帮哪位员工填报！");
        userSelect.focus();
        // 给个背景闪烁提示
        userSelect.parentElement.style.backgroundColor = '#fff1f0';
        setTimeout(() => userSelect.parentElement.style.backgroundColor = '#e6f7ff', 800);
        return;
    }

    let targetUserName = userSelect.options[userSelect.selectedIndex].text.split(' ')[0]; // 提取姓名
    let container = btn.closest('.input-group');
    
    if (container) {
        currentActiveRowInput = container.querySelector('.wallet-ids-input');
        currentActiveBadge = document.getElementById(`wallet-badge-${rowIndex}`);
        currentActiveRowId = rowIndex;
        
        // 传递 uid 给弹窗
        openWalletModal(targetUid, targetUserName);
    }
}

// 2. 打开弹窗加载员工私有数据
async function openWalletModal(uid, userName) {
    document.getElementById('walletModal').style.display = 'flex';
    document.getElementById('wallet-modal-title').innerHTML = `<i class="ri-wallet-3-line" style="color:#1890ff; margin-right:5px;"></i> 选择【${userName}】的发票`;
    document.getElementById('walletList').innerHTML = '<div style="text-align:center; padding: 40px; color:#999;"><i class="ri-loader-4-line ri-spin"></i> 加载中...</div>';
    
    try {
        // 调用代填专用接口，传入 uid
        let res = await fetch('api_admin_wallet.php?uid=' + uid); 
        let list = await res.json();
        
        if(list.length === 0) {
            document.getElementById('walletList').innerHTML = `
                <div style="text-align:center; padding:50px 20px;">
                    <i class="ri-inbox-line" style="font-size:48px; color:#eee;"></i>
                    <p style="color:#999; margin-top:10px;">该员工的票夹空空如也，无票可用</p>
                </div>`;
            return;
        }

        let html = '<table class="wallet-table"><thead><tr><th width="50" style="text-align:center">选</th><th>金额</th><th>开票日期</th><th>类别</th><th>备注</th></tr></thead><tbody>';
        
        list.forEach(item => {
            html += `<tr onclick="toggleRow(this)">
                <td style="text-align:center">
                    <input type="checkbox" class="wallet-check" value="${item.id}" data-amount="${item.amount}" onclick="event.stopPropagation()">
                </td>
                <td style="font-weight:bold; color:#f5222d; font-family:Verdana;">¥${item.amount}</td>
                <td>${item.invoice_date}</td>
                <td><span class="tag" style="background:#f5f5f5; border:1px solid #d9d9d9; padding:2px 6px; border-radius:4px; font-size:12px;">${item.invoice_type}</span></td>
                <td style="color:#666; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${item.note || '-'}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('walletList').innerHTML = html;
    } catch(e) {
        document.getElementById('walletList').innerHTML = '<div style="padding:20px; color:red; text-align:center;">数据加载失败</div>';
    }
}

// 辅助：点击行也能选中
function toggleRow(tr) {
    let checkbox = tr.querySelector('input[type="checkbox"]');
    checkbox.checked = !checkbox.checked;
    if(checkbox.checked) tr.classList.add('selected');
    else tr.classList.remove('selected');
}

// 监听 checkbox 变化来切换高亮
document.addEventListener('change', function(e) {
    if(e.target.classList.contains('wallet-check')) {
        let tr = e.target.closest('tr');
        if(e.target.checked) tr.classList.add('selected');
        else tr.classList.remove('selected');
    }
});

// 3. 确认选择
function confirmWalletSelection() {
    let checks = document.querySelectorAll('.wallet-check:checked');
    if(checks.length === 0) return alert('未选择任何发票');

    let ids = [];
    let totalAmount = 0;

    checks.forEach(c => {
        ids.push(c.value);
        totalAmount += parseFloat(c.getAttribute('data-amount') || 0);
    });

    if(currentActiveRowInput) {
        currentActiveRowInput.value = ids.join(',');
        
        if(currentActiveBadge) {
            currentActiveBadge.style.display = 'block';
            currentActiveBadge.innerHTML = `<i class="ri-check-line"></i> 已关联${checks.length}张 (¥${totalAmount.toFixed(2)})`;
        }
        
        // 智能填金额
        let amtInput = document.getElementById(`amt-${currentActiveRowId}`);
        if(amtInput && (amtInput.value == '' || parseFloat(amtInput.value) == 0)) {
            amtInput.value = totalAmount.toFixed(2);
            amtInput.style.backgroundColor = '#e6f7ff';
            setTimeout(() => amtInput.style.backgroundColor = '', 800);
        }
    }

    document.getElementById('walletModal').style.display = 'none';
}
</script>
</body>
</html>