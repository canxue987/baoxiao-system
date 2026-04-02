<script src="https://cdn.staticfile.net/jsQR/1.4.0/jsQR.min.js"></script>
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
            <button onclick="document.getElementById('walletModal').style.display='none'" class="btn btn-ghost btn-sm" style="border:none; font-size:16px; color:#999;"><i class="ri-close-line"></i></button>
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
            <button onclick="document.getElementById('bkModal').style.display='none'" class="btn btn-ghost btn-sm" style="border:none; font-size:16px; color:#999;"><i class="ri-close-line"></i></button>
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
                <button type="button" onclick="document.getElementById('bkModal').style.display='none'" class="btn btn-ghost" style="margin-right:10px;">取消</button>
                <button type="button" onclick="confirmBkSelection()" class="btn btn-primary" style="background:#faad14; border-color:#faad14;">确认导入</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentWalletData = [];
let currentBkData = [];

// ================== 票夹通用逻辑 ==================
async function openWalletModal(uid = null, userName = '') {
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
    let amtInput = document.getElementById(`inv-amt-${currentActiveRowId}`);
    let targetAmt = 0;
    
    if(amtInput && amtInput.value && !isNaN(parseFloat(amtInput.value))) targetAmt = parseFloat(amtInput.value);
    else {
        let baseAmtInput = document.getElementById(`amt-${currentActiveRowId}`);
        if(baseAmtInput && baseAmtInput.value && !isNaN(parseFloat(baseAmtInput.value))) targetAmt = parseFloat(baseAmtInput.value);
    }
    
    if (targetAmt <= 0) {
        targetAmt = parseFloat(prompt('未检测到当前明细行填写的金额。\n请手动输入需要凑出的目标总额：', '2000'));
        if (!targetAmt || isNaN(targetAmt) || targetAmt <= 0) return;
    }
    if (targetAmt > 50000) return alert('目标金额过大，请手动勾选以防卡顿！');

    let usedIds = [];
    document.querySelectorAll('.wallet-ids-input').forEach(input => {
        if (input !== currentActiveRowInput && input.value) usedIds.push(...input.value.split(','));
    });

    let compInput = document.querySelector(`input[name="items[${currentActiveRowId}][company]"]`);
    let targetCompany = compInput ? compInput.value.trim() : '';

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
        
        if(currentBkData.length === 0) {
            document.getElementById('bkList').innerHTML = `<div style="text-align:center; padding:50px 20px;"><i class="ri-inbox-line" style="font-size:48px; color:#eee;"></i><p style="color:#999; margin-top:10px;">没有待报销的垫付记录</p></div>`;
            return;
        }

        let html = '<table class="wallet-table"><thead><tr><th width="50" style="text-align:center">选</th><th>消费日期</th><th>公司主体</th><th>所属项目</th><th>事项明细 (作为备注)</th><th>金额</th></tr></thead><tbody>';
        currentBkData.forEach(item => {
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
</script>