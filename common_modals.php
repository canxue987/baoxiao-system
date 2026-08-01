<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script src="https://cdn.staticfile.net/pdf.js/2.16.105/pdf.min.js"></script>
<script>pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.staticfile.net/pdf.js/2.16.105/pdf.worker.min.js';</script>
<script src="main.js?v=<?php echo time(); ?>"></script>

<style>
    /* 公用弹窗遮罩 */
    .modal-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none; align-items: center; justify-content: center;
        z-index: 9999; backdrop-filter: blur(2px);
    }
    /* 公用弹窗主体 */
    .modal-box {
        background: #fff; border-radius: 8px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        width: 950px; max-width: 95%; max-height: 85vh;
        display: flex; flex-direction: column; border: 1px solid #e8e8e8;
    }
    .modal-header {
        padding: 16px 24px; border-bottom: 1px solid #f0f0f0;
        display: flex; justify-content: space-between; align-items: center;
        background: #fafafa; border-radius: 8px 8px 0 0;
    }
    .modal-header h3 { margin: 0; font-size: 16px; color: #333; font-weight: 600; }
    .modal-body {
        padding: 0; overflow-y: auto; flex: 1;
        background: #fff; display: block !important; min-height: 300px;
    }
    .modal-footer {
        padding: 12px 24px; border-top: 1px solid #f0f0f0;
        text-align: right; background: #fff; border-radius: 0 0 8px 8px;
    }
    /* 弹窗内的表格样式 */
    .wallet-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .wallet-table th { 
        background: #fafafa; color: #666; font-weight: 600; 
        padding: 12px 16px; text-align: left; border-bottom: 1px solid #eee;
        position: sticky; top: 0; z-index: 1;
    }
    .wallet-table td { padding: 12px 16px; border-bottom: 1px solid #f5f5f5; color: #333; vertical-align: middle; cursor: pointer; }
    .wallet-table tr:hover { background: #f0f7ff; }
    .wallet-table tr.selected { background: #e6f7ff; }
</style>

<div id="walletModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <div style="display:flex; align-items:center; gap:15px;">
                <h3 id="wallet-modal-title" style="margin:0;"><i class="ri-wallet-3-line" style="color:#1890ff; margin-right:5px;"></i> 从我的票夹选择</h3>
                <button onclick="triggerAutoMatch()" class="btn btn-sm" style="background:#e6f7ff; color:#1890ff; border:1px solid #91d5ff; padding:2px 10px; font-weight:bold;"><i class="ri-robot-line"></i> 智能凑票</button>
            </div>
            <button onclick="document.getElementById('walletModal').style.display='none'; window.isSubstituteSplitMode=false;" class="btn btn-ghost btn-sm" style="border:none; font-size:16px; color:#999;"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body" id="walletList"></div>
        <div class="modal-footer">
            <span style="float:left; font-size:12px; color:#999; line-height:30px;">* 仅显示“未使用”的闲置发票</span>
            <button type="button" onclick="document.getElementById('walletModal').style.display='none'" class="btn btn-ghost" style="margin-right:10px;">取消</button>
            <button type="button" onclick="confirmWalletSelection()" class="btn btn-primary">确认选择</button>
        </div>
    </div>
</div>

<div id="bkModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="bk-modal-title"><i class="ri-book-read-line" style="color:#faad14; margin-right:5px;"></i> 从记账本导入支出</h3>
            <button onclick="document.getElementById('bkModal').style.display='none'; window.isSubstituteSplitBkMode=false;" class="btn btn-ghost btn-sm" style="border:none; font-size:16px; color:#999;"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body" id="bkList"></div>
        <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="display:flex; gap:5px;">
                    <button type="button" onclick="toggleBkAll()" class="btn btn-sm" style="background:#fafafa; border:1px solid #d9d9d9; padding:2px 10px; cursor:pointer; font-size:13px; color:#333;">全选</button>
                    <button type="button" onclick="toggleBkInvert()" class="btn btn-sm" style="background:#fafafa; border:1px solid #d9d9d9; padding:2px 10px; cursor:pointer; font-size:13px; color:#333;">反选</button>
                </div>
                <span style="font-size:12px; color:#999;">* 勾选后，系统会自动为您生成明细行</span>
            </div>
            <div>
                <button type="button" onclick="document.getElementById('bkModal').style.display='none'; window.isSubstituteSplitBkMode=false;" class="btn btn-ghost" style="margin-right:10px;">取消</button>
                <button type="button" onclick="confirmBkSelection()" class="btn btn-primary" style="background:#faad14; border-color:#faad14;">确认导入</button>
            </div>
        </div>
    </div>
</div>

<div id="previewInvModal" class="modal-overlay">
    <div class="modal-box" style="width: 800px; max-height: 85vh;">
        <div class="modal-header">
            <h3 style="margin:0;"><i class="ri-eye-line" style="color:#1890ff; margin-right:5px;"></i> 已选发票预览</h3>
            <button type="button" onclick="document.getElementById('previewInvModal').style.display='none'" class="btn btn-ghost btn-sm" style="border:none; font-size:20px; color:#999; cursor:pointer;"><i class="ri-close-line"></i></button>
        </div>
        <div class="modal-body" id="previewInvList" style="padding: 20px; background: #f0f2f5; display: flex; gap: 15px; flex-wrap: wrap; overflow-y: auto; align-content: flex-start;">
            </div>
    </div>
</div>

<script>
let currentWalletData = [];
let currentBkData = [];

// === 替票拆分模式全局变量 ===
window.isSubstituteSplitMode = false;
window.substituteSplitTargetAmt = 0;
window.substituteSplitSectionId = null;
window.substituteSplitCompany = '';

// ✨ 新增：记账本接力专用上下文
window.isSubstituteSplitBkMode = false;
window.substituteSplitBkIds = [];
window.substituteSplitProject = '';
window.substituteSplitNote = '';

// ✨ 启动替票拆分模式 (支持双模式)
function startSubstituteSplit() {
    let uid = null;
    let userName = '';
    
    let userSelect = document.querySelector('select[name="target_user_id"]');
    if (userSelect) {
        uid = userSelect.value;
        if(!uid) return alert("⚠️ 请先在页面最上方选择您要帮哪位员工填报！");
        userName = userSelect.options[userSelect.selectedIndex].text.split(' ')[0];
    }

    // ✨ 智能交互：询问用户选择哪种模式
    if (confirm("💡 您希望如何确定需要拆分的【目标金额】？\n\n👉 点击【确定】：从记账本勾选账单 (自动计算总额，并绑定主体)\n👉 点击【取消】：手动输入公司主体与金额")) {
        // 进入记账本接力模式
        window.isSubstituteSplitBkMode = true;
        if (uid) showBkModal(uid, userName);
        else showBkModal();
        return; // 中断当前流程，等待记账本回调
    }

    // ✨ 优化：手动输入模式下，先确认公司主体，再确认金额
    let targetCompany = prompt("【步骤 1/2】请输入本次替票对应的【公司主体名称】\n(例如：海科科技。如果留空将使用默认公司):", "默认公司");
    if (targetCompany === null) return; // 用户点击了取消
    if (targetCompany.trim() === '') targetCompany = '默认公司';

    let targetAmt = prompt(`【步骤 2/2】 当前主体: ${targetCompany}\n请输入您想要凑出的报销总金额（如: 2000）：`);
    if (!targetAmt || isNaN(parseFloat(targetAmt)) || parseFloat(targetAmt) <= 0) return;

    let sections = document.querySelectorAll('select[id^="comp-select-"]');
    let targetSectionId = null;
    sections.forEach(sel => { if (sel.value === targetCompany) targetSectionId = sel.id.replace('comp-select-', ''); });
    if (!targetSectionId) targetSectionId = addCompanySection(targetCompany, false); 

    window.isSubstituteSplitMode = true;
    window.substituteSplitTargetAmt = parseFloat(targetAmt);
    window.substituteSplitSectionId = targetSectionId;
    window.substituteSplitCompany = targetCompany;
    window.substituteSplitBkIds = [];
    window.substituteSplitProject = '';
    window.substituteSplitNote = '';

    if (uid) openWalletModal(uid, userName);
    else openWalletModal();
}

// ================== 票夹通用逻辑 ==================
async function openWalletModal(uid = null, userName = '', filterCompany = '') {
    document.getElementById('walletModal').style.display = 'flex';
    document.getElementById('wallet-modal-title').innerHTML = uid ? `<i class="ri-wallet-3-line" style="color:#1890ff; margin-right:5px;"></i> 选择【${userName}】的发票` : `<i class="ri-wallet-3-line" style="color:#1890ff; margin-right:5px;"></i> 从我的票夹选择`;
    document.getElementById('walletList').innerHTML = '<div style="text-align:center; padding: 40px; color:#999;"><i class="ri-loader-4-line ri-spin"></i> 加载中...</div>';
    
    try {
        let fetchUrl = uid ? 'api_wallet_list.php?uid=' + uid : 'api_wallet_list.php';
        let res = await fetch(fetchUrl);
        let list = await res.json();
        
        if(list.length === 0) {
            document.getElementById('walletList').innerHTML = `<div style="text-align:center; padding:50px 20px;"><i class="ri-inbox-line" style="font-size:48px; color:#eee;"></i><p style="color:#999; margin-top:10px;">该票夹空空如也</p></div>`;
            return;
        }

        // ✨ 通用过滤：按公司抬头过滤发票（优先使用传入的公司名，其次替票模式的公司）
        let targetCmp = filterCompany || (window.isSubstituteSplitMode ? window.substituteSplitCompany : '');
        if (targetCmp) {
            list = list.filter(i => {
                // 如果发票没有购方名称（比如打车票、火车票等交通费），视为通用发票，全部放行
                if (!i.buyer_name || i.buyer_name.trim() === '') return true;
                // 如果有购方名称，必须包含目标公司名，或者目标公司名包含它
                if (i.buyer_name.indexOf(targetCmp) !== -1 || targetCmp.indexOf(i.buyer_name) !== -1) return true;
                return false;
            });
            
            if (list.length === 0) {
                document.getElementById('walletList').innerHTML = `<div style="text-align:center; padding:50px 20px;"><i class="ri-filter-off-line" style="font-size:48px; color:#eee;"></i><p style="color:#999; margin-top:10px;">票夹中没有匹配【${targetCmp}】或无抬头的闲置发票</p></div>`;
                return;
            }
            
            // 在标题栏增加一个过滤提示
            let filterLabel = window.isSubstituteSplitMode ? '替票过滤' : '抬头过滤';
            document.getElementById('wallet-modal-title').innerHTML += ` <span style="font-size:12px; color:#1890ff; background:#e6f7ff; border:1px solid #91d5ff; padding:2px 6px; border-radius:4px; font-weight:normal; margin-left:10px;"><i class="ri-filter-3-line"></i> ${filterLabel}: ${targetCmp}</span>`;
        }

        currentWalletData = list;
        
        let usedIds = [];
        document.querySelectorAll('.wallet-ids-input').forEach(input => {
            if (input !== currentActiveRowInput && input.value) usedIds.push(...input.value.split(','));
        });

        let html = '<table class="wallet-table"><thead><tr><th width="50" style="text-align:center">选</th><th>金额</th><th>开票日期</th><th>类别</th><th>开票内容</th><th>备注</th></tr></thead><tbody>';
        list.forEach(item => {
            let isUsed = usedIds.includes(item.id.toString());
            let disabledAttr = isUsed ? 'disabled' : '';
            let usedTag = isUsed ? '<span style="background:#f5f5f5; color:#bfbfbf; border:1px solid #d9d9d9; padding:0 4px; font-size:10px; border-radius:2px; margin-left:4px;">已被占用</span>' : '';
            let resTag = item.is_reserved == 1 ? '<span style="background:#fffbe6; color:#faad14; border:1px solid #ffe58f; padding:0 4px; font-size:10px; border-radius:2px; margin-left:4px;">禁凑</span>' : '';
            let trAction = isUsed ? '' : 'onclick="toggleRow(this)"';
            let rowOpacity = isUsed ? 'opacity:0.5; background:#fafafa; cursor:not-allowed;' : '';
            let amountColor = isUsed ? '#bfbfbf' : '#f5222d';

            html += `<tr ${trAction} style="${rowOpacity}">
                <td style="text-align:center"><input type="checkbox" class="wallet-check" value="${item.id}" data-amount="${item.amount}" onclick="event.stopPropagation()" ${disabledAttr}></td>
                <td style="font-weight:bold; color:${amountColor}; font-family:Verdana;">¥${item.amount} ${resTag} ${usedTag}</td>
                <td>${item.invoice_date}</td>
                <td><span class="tag" style="background:#f5f5f5; border:1px solid #d9d9d9; padding:2px 6px; border-radius:4px; font-size:12px;">${item.invoice_type}</span></td>
                <td style="color:#1890ff; max-width:130px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${item.invoice_detail || '-'}">${item.invoice_detail || '-'}</td>
                <td style="color:#666; max-width:130px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${item.note || '-'}">${item.note || '-'}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('walletList').innerHTML = html;
    } catch(e) { document.getElementById('walletList').innerHTML = '<div style="padding:20px; color:red; text-align:center;">数据加载失败</div>'; }
}

function toggleRow(tr) {
    let checkbox = tr.querySelector('input[type="checkbox"]');
    checkbox.checked = !checkbox.checked;
    if(checkbox.checked) tr.classList.add('selected'); else tr.classList.remove('selected');
}
document.addEventListener('change', function(e) {
    if(e.target.classList.contains('wallet-check')) {
        let tr = e.target.closest('tr');
        if(e.target.checked) tr.classList.add('selected'); else tr.classList.remove('selected');
    }
});

function confirmWalletSelection() {
    let checks = document.querySelectorAll('.wallet-check:checked');
    if(checks.length === 0) return alert('未选择任何发票');

    // ✨ 魔法引擎：替票拆分模式！
    if (window.isSubstituteSplitMode) {
        let selectedInvoices = [];
        checks.forEach(c => {
            selectedInvoices.push({
                id: c.value,
                amount: parseFloat(c.getAttribute('data-amount') || 0),
                note: c.closest('tr').querySelector('td:nth-child(5)').innerText // 提取开票内容做备注
            });
        });

        // ⚠️ 核心修复 1：这里必须是 (inv, index) 才能获取到循环的序号！
        selectedInvoices.forEach((inv, index) => {
            let newRowId = globalRowId; // 预获取新行 ID
            addRow(window.substituteSplitSectionId); // 生成新行
            
            setTimeout(() => {
                // 1. 填入金额与绑定发票
                let amtInput = document.getElementById(`amt-${newRowId}`);
                if(amtInput) amtInput.value = inv.amount;
                let wInput = document.querySelector(`input[name="items[${newRowId}][wallet_ids]"]`);
                if(wInput) wInput.value = inv.id;
                
                // 2. 贴上金黄色的替票徽章
                let badge = document.getElementById(`wallet-badge-${newRowId}`);
                if(badge) {
                    badge.style.display = 'block';
                    badge.innerHTML = `<i class="ri-blaze-line"></i> 拆分替票 (¥${inv.amount})`;
                    badge.style.color = '#faad14';
                    badge.style.backgroundColor = '#fffbe6';
                    badge.style.border = '1px solid #ffe58f';
                }

                // 3. 自动勾选“替票”多选框，并填入票面金额
                let subCheck = document.getElementById(`substitute-${newRowId}`);
                if(subCheck) {
                    subCheck.checked = true;
                    subCheck.dispatchEvent(new Event('change')); 
                    setTimeout(() => {
                        let invAmtInput = document.getElementById(`inv-amt-${newRowId}`);
                        if(invAmtInput) invAmtInput.value = inv.amount;
                    }, 50);
                }
                
                // 4. 备注拼接 (如果从记账本导入，带上记账本事由)
                let noteInput = document.getElementById(`note-${newRowId}`);
                if(noteInput) {
                    let cleanNote = inv.note !== '-' ? inv.note : '发票代替';
                    let prefix = window.substituteSplitNote ? `[${window.substituteSplitNote}] ` : '';
                    noteInput.value = `${prefix}替票明细: ${cleanNote}`;
                }

                // 5. 项目名称 (如果记账本有项目，自动填入)
                let projInput = document.querySelector(`input[name="items[${newRowId}][project_name]"]`);
                if (projInput && window.substituteSplitProject) {
                    projInput.value = window.substituteSplitProject;
                }

                // 6. ✨ 核心修复 2：将所有参与拼凑的账单 ID 用逗号连起来传给后端！
                let bkIdInput = document.getElementById(`bk-id-${newRowId}`);
                if (bkIdInput && index === 0 && window.substituteSplitBkIds.length > 0) {
                    bkIdInput.value = window.substituteSplitBkIds.join(',');
                }
                
                // 7. 行高亮视觉反馈
                let rowEl = document.getElementById(`row-${newRowId}`);
                if(rowEl) {
                    rowEl.style.backgroundColor = '#fffbe6';
                    setTimeout(() => rowEl.style.backgroundColor = 'transparent', 1500);
                }
            }, 50);// 给 HTML 一点点渲染的时间
        });

        document.getElementById('walletModal').style.display = 'none';
        window.isSubstituteSplitMode = false; // 完毕后重置状态
        return;
    }

    // ⬇️ 以下是原本的单行选择逻辑 (保持不变) ⬇️
    let ids = []; let totalAmount = 0;
    checks.forEach(c => { ids.push(c.value); totalAmount += parseFloat(c.getAttribute('data-amount') || 0); });

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
    }
    document.getElementById('walletModal').style.display = 'none';
}

function triggerAutoMatch() {
    let targetAmt = 0;
    let targetCompany = '';
    
    // ✨ 智能判断：如果是拆分模式，读取刚才弹窗输入的金额；否则读取当前行的金额
    if (window.isSubstituteSplitMode) {
        targetAmt = window.substituteSplitTargetAmt;
        targetCompany = window.substituteSplitCompany;
    } else {
        let amtInput = document.getElementById(`inv-amt-${currentActiveRowId}`);
        if(amtInput && amtInput.value && !isNaN(parseFloat(amtInput.value))) targetAmt = parseFloat(amtInput.value);
        else {
            let baseAmtInput = document.getElementById(`amt-${currentActiveRowId}`);
            if(baseAmtInput && baseAmtInput.value && !isNaN(parseFloat(baseAmtInput.value))) targetAmt = parseFloat(baseAmtInput.value);
        }
        let compInput = document.querySelector(`input[name="items[${currentActiveRowId}][company]"]`);
        targetCompany = compInput ? compInput.value.trim() : '';
    }
    
    if (targetAmt <= 0) {
        targetAmt = parseFloat(prompt('未检测到金额，请输入需要凑出的目标总额：', '2000'));
        if (!targetAmt || isNaN(targetAmt) || targetAmt <= 0) return;
    }
    if (targetAmt > 50000) return alert('目标金额过大，请手动勾选以防卡顿！');
    
    let usedIds = [];
    document.querySelectorAll('.wallet-ids-input').forEach(input => {
        if (input !== currentActiveRowInput && input.value) usedIds.push(...input.value.split(','));
    });

    // ⚠️ 修复点：就在这里！去掉了之前不小心重复声明的 let targetCompany 和 let compInput 
    // 因为我们在上面的 if/else 里已经获取过 targetCompany 了！

    let available = currentWalletData.filter(i => {
        if (i.is_reserved == 1) return false;
        if (usedIds.includes(i.id.toString())) return false;
        if (targetCompany && i.buyer_name && i.buyer_name.trim() !== '') {
            if (i.buyer_name.indexOf(targetCompany) === -1 && targetCompany.indexOf(i.buyer_name) === -1) return false; 
        }
        return true;
    });

    if(available.length === 0) return alert('票夹中没有可用于该公司的“闲置发票”！\n(发票可能已被标为专属，或发票抬头不匹配)');

    let target = Math.round(targetAmt * 100);
    let limit = target + 1500; 

    let dp = new Array(limit + 1).fill(null);
    dp[0] = []; 
    let bestSum = 0; let bestDiff = Infinity;

    for (let inv of available) {
        let val = Math.round(parseFloat(inv.amount) * 100);
        for (let v = limit; v >= val; v--) {
            if (dp[v - val] !== null && dp[v] === null) {
                dp[v] = [...dp[v - val], inv.id];
                let diff = Math.abs(v - target);
                if (diff < bestDiff) { bestDiff = diff; bestSum = v; }
            }
        }
        if (bestDiff === 0) break; 
    }

    if (bestDiff <= 1500 && bestSum > 0) {
        document.querySelectorAll('.wallet-check').forEach(cb => { cb.checked = false; cb.closest('tr').classList.remove('selected'); });
        let matchedIds = dp[bestSum];
        matchedIds.forEach(id => {
            let cb = document.querySelector(`.wallet-check[value="${id}"]`);
            if(cb) { cb.checked = true; cb.closest('tr').classList.add('selected'); }
        });
        alert(`🎉 凑票成功！\n\n目标金额: ¥${targetAmt}\n凑出金额: ¥${(bestSum/100).toFixed(2)}\n误差范围: ¥${(bestDiff/100).toFixed(2)}`);
    } else {
        alert(`😔 凑票失败...\n\n当前票夹中的闲置发票，无法拼凑出接近 ¥${targetAmt} 的金额。\n(系统最大允许上下误差为15元)`);
    }
}

// ================== 记账本通用逻辑 ==================
async function showBkModal(uid = null, userName = '') {
    document.getElementById('bkModal').style.display = 'flex';
    document.getElementById('bk-modal-title').innerHTML = uid ? `<i class="ri-book-read-line" style="color:#faad14; margin-right:5px;"></i> 导入【${userName}】的记账记录` : `<i class="ri-book-read-line" style="color:#faad14; margin-right:5px;"></i> 从记账本导入支出`;
    document.getElementById('bkList').innerHTML = '<div style="text-align:center; padding: 40px; color:#999;"><i class="ri-loader-4-line ri-spin"></i> 加载中...</div>';

    try {
        let fetchUrl = uid ? 'api_bookkeeping.php?uid=' + uid : 'api_bookkeeping.php';
        let res = await fetch(fetchUrl); 
        currentBkData = await res.json();
        let usedBkIds = [];
        document.querySelectorAll('input[id^="bk-id-"]').forEach(input => {
            if(input.value) {
                // 拆分模式下可能是逗号分隔的多个ID，所以要 split
                input.value.split(',').forEach(id => usedBkIds.push(id.trim()));
            }
        });
        
        if(currentBkData.length === 0) {
            document.getElementById('bkList').innerHTML = `<div style="text-align:center; padding:50px 20px;"><i class="ri-inbox-line" style="font-size:48px; color:#eee;"></i><p style="color:#999; margin-top:10px;">没有待报销的垫付记录</p></div>`;
            return;
        }

        let html = '<table class="wallet-table"><thead><tr><th width="50" style="text-align:center">选</th><th>消费日期</th><th>公司主体</th><th>所属项目</th><th>事项明细 (作为备注)</th><th>金额</th></tr></thead><tbody>';
        currentBkData.forEach(item => {
            // ✨ 如果这个账单已经被当前页面占用了，直接跳过不显示
            if (usedBkIds.includes(item.id.toString())) return;
            html += `<tr onclick="toggleRow(this)">
                <td style="text-align:center"><input type="checkbox" class="bk-check" value="${item.id}" onclick="event.stopPropagation()"></td>
                <td>${item.record_date}</td>
                <td style="white-space: nowrap;"><span class="tag tag-blue" style="margin:0;">${item.company || '默认公司'}</span></td>
                <td style="color:#0050b3;">${item.project_name || '-'}</td>
                <td style="font-weight:bold; color:#333;">${item.item_name}</td>
                <td style="color:#f5222d; font-weight:bold; font-family:Verdana;">¥${item.amount}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        document.getElementById('bkList').innerHTML = html;
    } catch(e) { document.getElementById('bkList').innerHTML = '<div style="padding:20px; color:red; text-align:center;">数据加载失败</div>'; }
}

function toggleBkAll() {
    document.querySelectorAll('.bk-check').forEach(cb => { cb.checked = true; cb.closest('tr').classList.add('selected'); });
}
function toggleBkInvert() {
    document.querySelectorAll('.bk-check').forEach(cb => {
        cb.checked = !cb.checked;
        if(cb.checked) cb.closest('tr').classList.add('selected'); else cb.closest('tr').classList.remove('selected');
    });
}

function confirmBkSelection() {
    let checks = document.querySelectorAll('.bk-check:checked');
    if(checks.length === 0) return alert('未勾选任何记录');

    // ✨ 新增：拦截器！如果是替票拆分模式点进来的，走专门的计算逻辑并接力票夹！
    if (window.isSubstituteSplitBkMode) {
        let totalAmt = 0;
        let compSet = new Set();
        let targetProject = '';
        let targetNoteArr = [];
        
        checks.forEach(c => {
            let bkItem = currentBkData.find(i => i.id == c.value);
            if (bkItem) {
                totalAmt += parseFloat(bkItem.amount);
                compSet.add(bkItem.company || '默认公司');
                if(bkItem.project_name) targetProject = bkItem.project_name;
                targetNoteArr.push(bkItem.item_name);
            }
        });
        
        if (compSet.size > 1) {
            return alert("⚠️ 智能替票拆分暂不支持同时跨多个公司主体！\n请只勾选同一家公司的记账记录。");
        }
        
        let targetCompany = Array.from(compSet)[0];
        let sections = document.querySelectorAll('select[id^="comp-select-"]');
        let targetSectionId = null;
        sections.forEach(sel => { if (sel.value === targetCompany) targetSectionId = sel.id.replace('comp-select-', ''); });
        if (!targetSectionId) targetSectionId = addCompanySection(targetCompany, false); 
        
        // 1. 将记账本的数据存入拆分模式的全局变量
        window.isSubstituteSplitMode = true;
        window.substituteSplitTargetAmt = totalAmt;
        window.substituteSplitSectionId = targetSectionId;
        window.substituteSplitCompany = targetCompany;
        window.substituteSplitBkIds = Array.from(checks).map(c => c.value);
        window.substituteSplitProject = targetProject;
        window.substituteSplitNote = targetNoteArr.join(' + ');

        // 2. 关闭记账本弹窗，重置拦截器
        document.getElementById('bkModal').style.display = 'none';
        window.isSubstituteSplitBkMode = false; 

        // 3. 完美接力：自动唤起票夹弹窗，直接让用户凑票！
        let uid = null, userName = '';
        let userSelect = document.querySelector('select[name="target_user_id"]');
        if (userSelect) {
            uid = userSelect.value;
            userName = userSelect.options[userSelect.selectedIndex].text.split(' ')[0];
        }
        if (uid) openWalletModal(uid, userName);
        else openWalletModal();
        
        return; // 结束拦截
    }

    // ⬇️ 下面是被你不小心删掉的【原本普通的记账本导入逻辑】，现在补回来了 ⬇️
    let grouped = {};
    checks.forEach(c => {
        let bkItem = currentBkData.find(i => i.id == c.value);
        if (bkItem) {
            let comp = bkItem.company || '默认公司';
            if (!grouped[comp]) grouped[comp] = [];
            grouped[comp].push(bkItem);
        }
    });

    for (let comp in grouped) {
        let targetSectionId = null;
        let selects = document.querySelectorAll('select[id^="comp-select-"]');
        selects.forEach(sel => { if (sel.value === comp) targetSectionId = sel.id.replace('comp-select-', ''); });
        if (!targetSectionId) targetSectionId = addCompanySection(comp, false); 

        grouped[comp].forEach(bkItem => {
            let newRowId = globalRowId;
            addRow(targetSectionId); 
            setTimeout(() => {
                document.getElementById(`date-${newRowId}`).value = bkItem.record_date;
                document.getElementById(`amt-${newRowId}`).value = bkItem.amount;
                document.getElementById(`note-${newRowId}`).value = bkItem.item_name; 
                let projInput = document.querySelector(`input[name="items[${newRowId}][project_name]"]`);
                if (projInput && bkItem.project_name) projInput.value = bkItem.project_name;
                
                if (bkItem.wallet_ids) {
                    let wInput = document.querySelector(`input[name="items[${newRowId}][wallet_ids]"]`);
                    if(wInput) wInput.value = bkItem.wallet_ids;
                    let badge = document.getElementById(`wallet-badge-${newRowId}`);
                    if(badge) {
                        let cnt = bkItem.wallet_ids.split(',').length;
                        badge.style.display = 'block';
                        badge.innerHTML = `<i class="ri-check-line"></i> 随账本导入 ${cnt} 张发票`;
                    }
                }

                let bkIdInput = document.getElementById(`bk-id-${newRowId}`);
                if(bkIdInput) bkIdInput.value = bkItem.id;
                
                let rowEl = document.getElementById(`row-${newRowId}`);
                if(rowEl) { rowEl.style.backgroundColor = '#fffbe6'; setTimeout(() => rowEl.style.backgroundColor = 'transparent', 1500); }
            }, 50);
        });
    }
    document.getElementById('bkModal').style.display = 'none';
}

// ================== ✨ 全新发票预览画廊功能 ==================
async function previewRowInvoices(rowId) {
    let modal = document.getElementById('previewInvModal');
    let listDiv = document.getElementById('previewInvList');
    modal.style.display = 'flex';
    listDiv.innerHTML = '<div style="width:100%; text-align:center; padding: 40px; color:#999;"><i class="ri-loader-4-line ri-spin"></i> 正在读取发票...</div>';

    let html = '';
    let hasFiles = false;

    // 1. 读取本地刚上传的文件 (直接拦截 input 里的 File 对象)
    let fileInputs = document.querySelectorAll(`input[name="items[${rowId}][invoice_file][]"], input[name="items[${rowId}][invoice_file]"]`);
    let localFiles = [];
    fileInputs.forEach(fi => {
        if (fi.files) {
            for(let i=0; i<fi.files.length; i++) localFiles.push(fi.files[i]);
        }
    });

    if (localFiles.length > 0) {
        hasFiles = true;
        html += `<div style="width:100%; font-weight:bold; color:#333; margin-bottom:10px; padding-bottom:5px; border-bottom:1px solid #ddd;"><i class="ri-folder-upload-line"></i> 本地直接上传的文件 (${localFiles.length}个)</div>`;
        for (let file of localFiles) {
            let url = URL.createObjectURL(file);
            let isImg = file.type.startsWith('image/');
            html += renderPreviewCard(url, isImg ? 'img' : 'pdf', file.name, '本地文件', true);
        }
    }

    // 2. 读取从票夹关联的文件
    let walletInput = document.querySelector(`input[name="items[${rowId}][wallet_ids]"]`);
    if (walletInput && walletInput.value) {
        hasFiles = true;
        html += `<div style="width:100%; font-weight:bold; color:#333; margin-top:15px; margin-bottom:10px; padding-bottom:5px; border-bottom:1px solid #ddd;"><i class="ri-wallet-3-line"></i> 从票夹关联的发票</div>`;
        try {
            let res = await fetch(`api_preview_invoices.php?ids=${walletInput.value}`);
            let data = await res.json();
            if(data.length === 0) html += `<div style="color:#999; width:100%; font-size:13px;">无详细数据</div>`;
            data.forEach(inv => {
                html += renderPreviewCard(inv.file_path, inv.file_type, `¥${inv.amount}`, inv.seller_name || '票夹发票', false);
            });
        } catch (e) {
            html += `<div style="color:red; width:100%;">票夹数据加载失败</div>`;
        }
    }

    if (!hasFiles) {
        html = '<div style="width:100%; text-align:center; padding: 40px; color:#999;"><i class="ri-inbox-line" style="font-size:48px; color:#ddd;"></i><br><br>该行尚未上传或关联任何发票</div>';
    }

    listDiv.innerHTML = html;
}

function renderPreviewCard(src, type, title, subtitle, isLocal) {
    let content = '';
    if (type === 'pdf') {
        content = `<div style="height:120px; display:flex; align-items:center; justify-content:center; background:#fff1f0; border-radius:6px; margin-bottom:8px;">
                       <i class="ri-file-pdf-line" style="font-size:48px; color:#ff4d4f;"></i>
                   </div>`;
    } else {
        content = `<div style="height:120px; background:#f9f9f9; border-radius:6px; margin-bottom:8px; overflow:hidden; display:flex; align-items:center; justify-content:center;">
                       <img src="${src}" style="max-width:100%; max-height:100%; object-fit:contain;">
                   </div>`;
    }
    
    let actionBtn = '';
    if (isLocal && type !== 'pdf') {
         actionBtn = `<button type="button" onclick="window.open('${src}')" class="btn btn-sm" style="margin-top:8px; width:100%; font-size:12px; color:#52c41a; background:#f6ffed; border:1px solid #b7eb8f; cursor:pointer;">查看大图</button>`;
    } else if (isLocal && type === 'pdf') {
         actionBtn = `<button type="button" onclick="window.open('${src}')" class="btn btn-sm" style="margin-top:8px; width:100%; font-size:12px; color:#1890ff; background:#e6f7ff; border:1px solid #91d5ff; cursor:pointer;">预览 PDF</button>`;
    } else {
         actionBtn = `<a href="${src}" target="_blank" class="btn btn-sm" style="margin-top:8px; display:block; text-align:center; width:100%; box-sizing:border-box; font-size:12px; color:${type==='pdf'?'#1890ff':'#52c41a'}; background:${type==='pdf'?'#e6f7ff':'#f6ffed'}; border:1px solid ${type==='pdf'?'#91d5ff':'#b7eb8f'}; text-decoration:none; cursor:pointer;">${type==='pdf'?'预览 PDF':'查看大图'}</a>`;
    }

    return `<div style="width: 155px; background: #fff; border: 1px solid #e8e8e8; border-radius: 8px; padding: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); display:flex; flex-direction:column;">
                ${content}
                <div style="font-weight:bold; color:#333; font-size:14px; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${title}">${title}</div>
                <div style="color:#999; font-size:12px; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${subtitle}">${subtitle}</div>
                ${actionBtn}
            </div>`;
}

// ✨ 黑科技：不修改 main.js，使用 DOM 监听器自动给发票栏装上“预览”按钮
document.addEventListener('DOMContentLoaded', () => {
    const injectPreviewButtons = () => {
        document.querySelectorAll('.wallet-ids-input').forEach(input => {
            let container = input.closest('div'); 
            if (container && !container.querySelector('.btn-preview-inv')) {
                let match = input.name.match(/items\[(\d+)\]/);
                if (match) {
                    let rowId = match[1];
                    let btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-ghost btn-sm btn-preview-inv';
                    // 漂亮的浅蓝色样式
                    btn.style.cssText = 'color:#1890ff; border:1px solid #91d5ff; background:#e6f7ff; margin-left:5px; padding:2px 8px; font-size:12px; border-radius:4px; cursor:pointer; vertical-align: middle;';
                    btn.innerHTML = '<i class="ri-eye-line"></i> 预览';
                    btn.onclick = () => previewRowInvoices(rowId);
                    
                    let walletBtn = container.querySelector('button[onclick*="selectFromWallet"]');
                    if (walletBtn) {
                        walletBtn.insertAdjacentElement('afterend', btn); // 插在票夹按钮后面
                    } else {
                        container.appendChild(btn); 
                    }
                }
            }
        });
    };
    
    injectPreviewButtons(); // 页面初始加载时执行一次
    // 监听后续用户点击“增加明细行”时的 DOM 变化，自动继续装配！
    const observer = new MutationObserver(injectPreviewButtons);
    observer.observe(document.body, { childList: true, subtree: true });
});

</script>
