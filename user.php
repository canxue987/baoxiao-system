<?php
require_once 'config.php';

// 1. 获取当前开启的档期
$stmt = $pdo->query("SELECT * FROM batches WHERE status='open' ORDER BY id DESC LIMIT 1");
$current_batch = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. 获取历史报销记录
$my_items = [];
if ($current_batch) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE user_id = ? AND batch_id = ? ORDER BY id DESC");
    $stmt->execute([$_SESSION['user_id'], $current_batch['id']]);
    $my_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 引入公共头部 (请确保 header.php 也更新了)
include 'header.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
    <h2 style="margin: 0;"><i class="ri-file-user-line"></i> 我的报销</h2>
    <button type="button" onclick="document.getElementById('guideModal').style.display='flex'" class="btn" style="background:#e6f7ff; color:#1890ff; border:1px solid #91d5ff; border-radius:6px; font-weight:bold;">
        <i class="ri-book-read-line"></i> 新手填报指南
    </button>
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
                <button type="button" class="btn btn-ghost" onclick="addCompanySection()" style="margin-right:12px;"><i class="ri-building-2-line"></i> 增加公司主体</button>
                <button type="submit" class="btn btn-primary" style="padding: 10px 40px; font-size:16px; background:#0050b3;">
                    <i class="ri-send-plane-fill"></i> 提交报销单
                </button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3><i class="ri-history-line"></i> 已提交记录</h3>
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
                            <a href="action.php?action=delete&id=<?php echo $item['id']; ?>" class="btn btn-ghost btn-sm" onclick="return confirm('确定撤回？')"><i class="ri-arrow-go-back-line"></i> 撤回</a>
                        <?php elseif($item['status']=='rejected'): ?>
                            <div style="color:var(--danger)">已驳回</div>
                            <div style="font-size:11px; color:var(--text-sub);"><?php echo h($item['reject_reason']); ?></div>
                            <a href="action.php?action=delete&id=<?php echo $item['id']; ?>" style="font-size:12px; text-decoration:underline; color:var(--danger);"><i class="ri-delete-bin-line"></i> 删除重填</a>
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
<script>pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';</script>

<script src="main.js?v=<?php echo time(); ?>"></script>

<style>
    /* 弹窗遮罩 */
    .modal-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none; align-items: center; justify-content: center;
        z-index: 9999;
        backdrop-filter: blur(2px);
    }
    /* 弹窗主体 */
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
    
    .modal-body {
        padding: 0; 
        overflow-y: auto;
        flex: 1;
        background: #fff;
    }
    
    .modal-footer {
        padding: 12px 24px;
        border-top: 1px solid #f0f0f0;
        text-align: right;
        background: #fff;
        border-radius: 0 0 8px 8px;
    }

    /* 弹窗内的表格样式 */
    .wallet-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .wallet-table th { 
        background: #fafafa; color: #666; font-weight: 600; 
        padding: 12px 16px; text-align: left; border-bottom: 1px solid #eee;
        position: sticky; top: 0; z-index: 1;
    }
    .wallet-table td { 
        padding: 12px 16px; border-bottom: 1px solid #f5f5f5; color: #333; 
        vertical-align: middle;
        cursor: pointer;
    }
    .wallet-table tr:hover { background: #f0f7ff; }
    .wallet-table tr.selected { background: #e6f7ff; }

    /* 指南弹窗专属排版 */
    .guide-content { line-height: 1.6; color: #333; font-size: 14px; }
    .guide-content h4 { color: #1890ff; margin-top: 20px; margin-bottom: 10px; font-size: 15px; border-bottom: 1px dashed #eee; padding-bottom: 5px; }
    .guide-content ul { padding-left: 20px; margin-top: 0; }
    .guide-content li { margin-bottom: 8px; }
    .guide-highlight { background: #fff7e6; color: #d46b08; padding: 2px 6px; border-radius: 4px; font-weight: bold; }
</style>

<div id="guideModal" class="modal-overlay">
    <div style="background:#fff; border-radius:8px; width:750px; max-width:95%; height:85vh; max-height:800px; display:flex; flex-direction:column; box-shadow:0 10px 25px rgba(0,0,0,0.2); overflow:hidden;">
        
        <div style="padding:16px 24px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:#fafafa; flex-shrink:0;">
            <h3 style="margin:0; font-size:16px; color:#333; font-weight:bold;"><i class="ri-book-read-line" style="color:#1890ff; margin-right:5px;"></i> 员工报销操作指南</h3>
            <button type="button" onclick="document.getElementById('guideModal').style.display='none'" style="border:none; background:none; font-size:24px; color:#999; cursor:pointer;">
                &times;
            </button>
        </div>
        
        <div class="guide-content" style="padding:24px; overflow-y:auto; flex:1; background:#fff;">
            <p style="font-size: 15px; color: #666; margin-top: 0;">欢迎使用本系统！为了让您快速上手，请花1分钟阅读以下填报规则：</p>

            <h4>1. 🌟 强烈建议：先存票夹，后报销！</h4>
            <ul>
                <li>点击左侧菜单的 <strong>“我的票夹”</strong>，将您的发票（图片或 PDF）统一上传。</li>
                <li>系统拥有强大的 AI 大脑，会自动为您提取发票的<span class="guide-highlight">金额、日期、购销方、明细</span>等信息，免去您手动填写的烦恼。</li>
            </ul>

            <h4>2. 📝 填写报销明细行</h4>
            <ul>
                <li><strong>选择发票</strong>：在报销行的“发票”栏，点击蓝色小图标 <i class="ri-wallet-3-line" style="color:#1890ff;"></i>，即可直接从“我的票夹”中勾选已上传的发票。勾选后，系统会<span class="guide-highlight">自动为您填入报销总金额</span>！</li>
                <li><strong>增加/删除行</strong>：如果有多笔不同事由的报销，请点击下方的 <strong>“增加明细行”</strong>；如果涉及多家不同的公司抬头，请点击 <strong>“增加公司主体”</strong>。</li>
            </ul>

            <h4>3. ✈️ 差旅费特殊规则</h4>
            <ul>
                <li>当您在“报销大类”中选择 <span class="guide-highlight">差旅费报销单</span> 时，下方会自动展开蓝色的“差旅详情”填写框。</li>
                <li>请如实填写出差事由、同行人员以及起止日期，系统会自动为您计算出差天数。</li>
            </ul>

            <h4>4. 🔄 关于“替票” (发票内容与实际事由不符)</h4>
            <ul>
                <li>如果您实际发生的是“招待费”（如 100 元），但商家只开具了“办公用品”发票（如 120 元）。</li>
                <li>请在明细行勾选 <strong>“替票”</strong> 复选框。</li>
                <li>此时会多出一个“票面金额”输入框。请在 <strong>金额</strong> 处填写您实际要报销的金额（100），在 <strong>票面金额</strong> 处填写发票上的真实金额（120）。</li>
            </ul>

            <h4>5. 撤回与修改记录</h4>
            <ul>
                <li>提交后，单据进入 <strong>“审核中”</strong> 状态。如果发现填错了，只要管理员还没审核，您可以随时点击列表中的 <strong>“撤回”</strong>。撤回后，占用的发票会自动释放回“未使用”状态。</li>
                <li>如果单据被管理员 <strong>驳回</strong>，您可以查看驳回原因，点击“删除重填”后重新发起报销。</li>
            </ul>
            
            <div style="background: #f6ffed; border: 1px solid #b7eb8f; padding: 12px; border-radius: 6px; margin-top: 20px; color: #389e0d;">
                <i class="ri-lightbulb-flash-line"></i> <strong>提示：</strong> 只要养成了“拿到发票就丢进票夹”的好习惯，每月底的报销只需要点点鼠标勾选即可，绝不加班！
            </div>
        </div>
        
        <div style="padding:16px 24px; border-top:1px solid #f0f0f0; background:#fff; text-align:center; flex-shrink:0; z-index:10;">
            <button type="button" onclick="document.getElementById('guideModal').style.display='none'" class="btn btn-primary" style="padding: 10px 40px; font-size: 15px; border-radius: 6px;">我已了解，开始填报</button>
        </div>
    </div>
</div>

<div id="walletModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3><i class="ri-wallet-3-line" style="color:#1890ff; margin-right:5px;"></i> 从我的票夹选择发票</h3>
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
                * 仅显示“未使用”的发票
            </span>
            <button type="button" onclick="document.getElementById('walletModal').style.display='none'" class="btn btn-ghost" style="margin-right:10px;">取消</button>
            <button type="button" onclick="confirmWalletSelection()" class="btn btn-primary">确认选择</button>
        </div>
    </div>
</div>

<script>
// --- 全局变量：记录当前正在操作哪一行 ---
let currentActiveRowInput = null; // 对应的隐藏域 input
let currentActiveBadge = null;    // 对应的显示徽标 div
let currentActiveRowId = null;    // 行 ID (用于找金额框)

// 1. 被 main.js 里的按钮调用 (点击行内小图标)
function selectFromWallet(btn, rowIndex) {
    let container = btn.closest('.input-group');
    
    if (container) {
        currentActiveRowInput = container.querySelector('.wallet-ids-input');
        currentActiveBadge = document.getElementById(`wallet-badge-${rowIndex}`);
        currentActiveRowId = rowIndex;
        
        openWalletModal();
    } else {
        console.error('无法找到 .input-group 容器，请检查 HTML 结构');
    }
}

// 2. 打开弹窗加载数据
async function openWalletModal() {
    document.getElementById('walletModal').style.display = 'flex';
    document.getElementById('walletList').innerHTML = '<div style="text-align:center; padding: 40px; color:#999;"><i class="ri-loader-4-line ri-spin"></i> 加载中...</div>';
    
    try {
        let res = await fetch('get_my_invoices.php'); 
        let list = await res.json();
        
        if(list.length === 0) {
            document.getElementById('walletList').innerHTML = `
                <div style="text-align:center; padding:50px 20px;">
                    <i class="ri-inbox-line" style="font-size:48px; color:#eee;"></i>
                    <p style="color:#999; margin-top:10px;">票夹空空如也</p>
                    <a href="invoice_wallet.php" target="_blank" class="btn btn-primary btn-sm">去上传发票</a>
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

// 辅助：点击行也能选中 checkbox 并高亮
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
        
        let amtInput = document.getElementById(`amt-${currentActiveRowId}`);
        if(amtInput && (amtInput.value == '' || parseFloat(amtInput.value) == 0)) {
            amtInput.value = totalAmount.toFixed(2);
            amtInput.style.backgroundColor = '#e6f7ff';
            setTimeout(() => amtInput.style.backgroundColor = '', 800);
        }
    } else {
        alert("错误：无法定位到当前行的输入框，请刷新页面重试。");
    }

    document.getElementById('walletModal').style.display = 'none';
}
</script>
</body>
</html>