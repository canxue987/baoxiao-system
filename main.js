/* main.js - 前端逻辑 (V3.1 替票逻辑修正版) */

const typeData = {
    "费用报销单": ["招待费", "办公费", "交通费", "停车费", "过路桥费"],
    "差旅费报销单": ["飞机票", "车船票", "住宿费", "交通车票", "汽油费"]
};

let globalRowId = 0;

// --- 1. 增加公司区块 ---
function addCompanySection() {
    const sectionId = Date.now();
    const container = document.getElementById('sections-container');
    if(!container) return;

    let optionsHtml = '';
    if (typeof GLOBAL_COMPANIES !== 'undefined' && GLOBAL_COMPANIES.length > 0) {
        GLOBAL_COMPANIES.forEach(comp => {
            optionsHtml += `<option value="${comp}">${comp}</option>`;
        });
    } else {
        optionsHtml = '<option value="默认公司">默认公司</option>';
    }

    const html = `
    <div class="company-section" id="section-${sectionId}">
        <div class="company-header">
            <div style="display:flex; align-items:center; gap:10px;">
                <strong><i class="ri-building-2-line"></i> 报销主体</strong>
                <select id="comp-select-${sectionId}" class="form-select" style="width:200px;">
                    ${optionsHtml}
                </select>
            </div>
            <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('section-${sectionId}').remove()"><i class="ri-delete-bin-line"></i> 删除此公司栏</button>
        </div>
        <div class="company-body" id="body-${sectionId}">
            </div>
        <div style="padding: 12px 24px; background: #fafafa; border-top: 1px solid #f0f0f0;">
            <button type="button" class="btn btn-ghost btn-sm" onclick="addRow('${sectionId}')"><i class="ri-add-line"></i> 增加明细行</button>
        </div>
    </div>`;
    
    container.insertAdjacentHTML('beforeend', html);
    addRow(sectionId);
}

// --- 2. 增加明细行 (布局重构) ---
function addRow(sectionId) {
    const compSelect = document.getElementById(`comp-select-${sectionId}`);
    const companyName = compSelect.value;
    
    compSelect.addEventListener('change', function() {
        const inputs = document.querySelectorAll(`.comp-input-${sectionId}`);
        inputs.forEach(i => i.value = this.value);
    });

    const today = new Date().toISOString().split('T')[0];
    
    const rowHtml = `
    <div class="row-input" id="row-${globalRowId}" style="display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; padding:12px 0; border-bottom:1px dashed #eee;">
        <input type="hidden" name="items[${globalRowId}][company]" value="${companyName}" class="comp-input-${sectionId}">
        
        <div class="input-group" style="width: 135px; flex:none;">
            <span class="input-label">日期</span>
            <input type="date" name="items[${globalRowId}][date]" id="date-${globalRowId}" required value="${today}" style="height:32px;">
        </div>
        
        <div class="input-group" style="width: 150px; flex:none;">
            <span class="input-label">金额</span>
            <input type="text" name="items[${globalRowId}][amount]" id="amt-${globalRowId}" required placeholder="0.00" onblur="calc(this)" style="height:32px;">
        </div>

        <div class="input-group" style="width: 28px; text-align:center; flex:none;">
            <span class="input-label" style="display:block; width:100%;">替票</span>
            <input type="checkbox" onchange="toggleInv(${globalRowId})" id="chk-${globalRowId}" name="items[${globalRowId}][is_sub]" value="1" style="width:18px; height:18px; cursor:pointer; margin-bottom:7px;">
        </div>
        
        <div class="input-group" id="inv-box-${globalRowId}" style="display:none; width: 150px; flex:none;">
            <span class="input-label" style="color:var(--warning)">票面金额</span>
            <input type="text" name="items[${globalRowId}][inv_amt]" id="inv-amt-${globalRowId}" onblur="calc(this)" style="height:32px;">
        </div>
        
        <div class="input-group" style="width: 150px; flex:none;">
            <span class="input-label">报销大类</span>
            <select name="items[${globalRowId}][category]" id="cat-${globalRowId}" onchange="onCategoryChange(${globalRowId}, this.value)" style="height:32px;">
                <option value="费用报销单">费用报销单</option>
                <option value="差旅费报销单">差旅费报销单</option>
            </select>
        </div>
        
        <div class="input-group" style="width: 100px; flex:none;">
            <span class="input-label">费用项目</span>
            <select name="items[${globalRowId}][type]" id="subtype-${globalRowId}" style="height:32px;"></select>
        </div>

        <div class="input-group" style="width: 130px; flex:none;">
            <span class="input-label">所属项目</span>
            <input type="text" name="items[${globalRowId}][project_name]" placeholder="必填" required style="height:32px;">
        </div>

        <div class="input-group" style="flex: 1; min-width: 150px;">
            <span class="input-label">备注说明</span>
            <input type="text" name="items[${globalRowId}][note]" placeholder="事由" required style="height:32px;">
        </div>

        <div class="input-group" style="width: 200px; position:relative; flex:none;">
             <span class="input-label" style="color:#1677ff">发票</span>
             <input type="file" name="invoice_${globalRowId}[]" multiple accept="image/*,.pdf" onchange="scanInvoiceQR(this, ${globalRowId})" style="font-size:11px; height:32px; padding-top:3px;">
             <div id="scan-msg-${globalRowId}" style="font-size:10px; color:#999; position:absolute; bottom:-16px; left:0; white-space:nowrap;"></div>
        </div>
        
        <div class="input-group" style="width: 150px; flex:none;">
             <span class="input-label">辅证</span>
             <input type="file" name="support_${globalRowId}[]" multiple accept="image/*,.pdf" style="font-size:11px; height:32px; padding-top:3px;">
        </div>
        
        <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('row-${globalRowId}').remove()" style="height:32px; padding:0 10px; margin-bottom:0; flex:none;">
            <i class="ri-close-line"></i>
        </button>

        <div id="travel-group-${globalRowId}" style="display:none; width:100%; background:#f0f7ff; padding:8px; border-radius:4px; margin-top:8px; border:1px dashed #adc6ff;">
            <div style="display:flex; gap:10px; align-items:center;">
                <div style="font-weight:bold; color:#0050b3; font-size:12px; white-space:nowrap;"><i class="ri-plane-line"></i> 差旅详情:</div>
                
                <div class="input-group" style="flex:1;">
                    <span class="input-label" style="color:#0050b3">出差事由</span>
                    <input type="text" name="items[${globalRowId}][travel_reason]" placeholder="如: 北京峰会" style="height:32px;">
                </div>
                
                <div class="input-group" style="width: 100px;">
                    <span class="input-label" style="color:#0050b3">同行人员</span>
                    <input type="text" name="items[${globalRowId}][travelers]" placeholder="姓名" style="height:32px;">
                </div>

                <div class="input-group" style="width: 120px;">
                    <span class="input-label" style="color:#0050b3">开始日期</span>
                    <input type="date" name="items[${globalRowId}][travel_start]" id="t-start-${globalRowId}" onchange="calcDays(${globalRowId})" style="height:32px;">
                </div>
                
                <div class="input-group" style="width: 120px;">
                    <span class="input-label" style="color:#0050b3">结束日期</span>
                    <input type="date" name="items[${globalRowId}][travel_end]" id="t-end-${globalRowId}" onchange="calcDays(${globalRowId})" style="height:32px;">
                </div>
                
                <div class="input-group" style="width: 60px;">
                    <span class="input-label" style="color:#0050b3">天数</span>
                    <input type="number" name="items[${globalRowId}][travel_days]" id="t-days-${globalRowId}" readonly style="background:#eee; height:32px;">
                </div>
            </div>
        </div>

    </div>`;
    
    document.getElementById(`body-${sectionId}`).insertAdjacentHTML('beforeend', rowHtml);
    updateSubTypes(globalRowId, "费用报销单");
    globalRowId++;
}

// --- 逻辑控制：切换类别时处理差旅显示 ---
function onCategoryChange(id, val) {
    updateSubTypes(id, val);
    const travelGroup = document.getElementById(`travel-group-${id}`);

    if (val === '差旅费报销单') {
        // 差旅：显示第二行
        travelGroup.style.display = 'block';
    } else {
        // 费用：隐藏第二行，保持单行
        travelGroup.style.display = 'none';
        document.getElementById(`t-days-${id}`).value = '';
    }
}

// --- 3. 辅助功能 ---

function calc(input) {
    let val = input.value.trim();
    if (!val) return;
    if (/^[0-9\.\+]+$/.test(val) && val.includes('+')) {
        try {
            let sum = val.split('+').reduce((a, b) => parseFloat(a) + parseFloat(b || 0), 0);
            input.value = sum.toFixed(2);
        } catch (e) {}
    } else if (!isNaN(parseFloat(val))) {
        input.value = parseFloat(val).toFixed(2);
    }
}

function toggleInv(id) {
    const chk = document.getElementById(`chk-${id}`);
    document.getElementById(`inv-box-${id}`).style.display = chk.checked ? 'flex' : 'none';
    document.getElementById(`scan-msg-${id}`).innerText = "";
}

function updateSubTypes(id, category) {
    const subSelect = document.getElementById(`subtype-${id}`);
    subSelect.innerHTML = "";
    if(typeData[category]) {
        typeData[category].forEach(item => {
            const opt = document.createElement("option");
            opt.value = item;
            opt.innerText = item;
            subSelect.appendChild(opt);
        });
    }
}

function calcDays(id) {
    const s = document.getElementById(`t-start-${id}`).value;
    const e = document.getElementById(`t-end-${id}`).value;
    if (s && e) {
        const d1 = new Date(s);
        const d2 = new Date(e);
        const diff = d2 - d1;
        if (diff >= 0) {
            const days = diff / (1000 * 60 * 60 * 24) + 1; 
            document.getElementById(`t-days-${id}`).value = days;
        } else {
            document.getElementById(`t-days-${id}`).value = 0;
        }
    }
}

// --- 4. 智能扫描 ---
function scanInvoiceQR(fileInput, rowId) {
    const files = fileInput.files;
    if (!files || files.length === 0) return;

    let totalAmount = 0;
    let foundDate = null;
    let successCount = 0;
    let processedCount = 0;

    const msgBox = document.getElementById(`scan-msg-${rowId}`);
    msgBox.innerText = "分析中...";
    msgBox.style.color = "#1677ff";

    function processCode(code) {
        if (!code) return;
        const parts = code.data.split(',');
        if (parts.length > 5 && (parts[0] === '01' || parts.length >= 7)) {
            let amt = parseFloat(parts[4]);
            let dateStr = parts[5]; 
            
            if (!isNaN(amt)) {
                totalAmount += amt;
                successCount++;
            }
            if (!foundDate && dateStr && dateStr.length === 8) {
                foundDate = dateStr.substring(0,4) + '-' + dateStr.substring(4,6) + '-' + dateStr.substring(6,8);
            }
        }
    }

    function checkDone() {
        if (processedCount === files.length) {
            if (successCount > 0) {
                const isSub = document.getElementById(`chk-${rowId}`).checked;
                let targetInputId = isSub ? `inv-amt-${rowId}` : `amt-${rowId}`;
                document.getElementById(targetInputId).value = totalAmount.toFixed(2);
                
                if (foundDate) document.getElementById(`date-${rowId}`).value = foundDate;

                let targetName = isSub ? "发票" : "报销";
                msgBox.innerHTML = `<i class="ri-checkbox-circle-line"></i> ${successCount}张: ¥${totalAmount.toFixed(2)}`;
                msgBox.style.color = "#28a745"; 
            } else {
                msgBox.innerText = "未识别";
                msgBox.style.color = "#999";
            }
        }
    }

    Array.from(files).forEach(file => {
        const fileReader = new FileReader();

        if (file.type.startsWith('image/')) {
            fileReader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');
                    canvas.width = img.width;
                    canvas.height = img.height;
                    context.drawImage(img, 0, 0, img.width, img.height);
                    
                    const imageData = context.getImageData(0, 0, img.width, img.height);
                    const code = jsQR(imageData.data, imageData.width, imageData.height);
                    processCode(code);
                    
                    processedCount++;
                    checkDone();
                };
                img.src = e.target.result;
            };
            fileReader.readAsDataURL(file);
        } 
        else if (file.type === 'application/pdf') {
            fileReader.onload = function() {
                const typedarray = new Uint8Array(this.result);
                pdfjsLib.getDocument(typedarray).promise.then(function(pdf) {
                    return pdf.getPage(1);
                }).then(function(page) {
                    const viewport = page.getViewport({scale: 3.0});
                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');
                    canvas.height = viewport.height;
                    canvas.width = viewport.width;

                    const renderContext = { canvasContext: context, viewport: viewport };
                    return page.render(renderContext).promise.then(function() {
                        const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
                        const code = jsQR(imageData.data, imageData.width, imageData.height);
                        processCode(code);
                    });
                }).catch(function(err) {
                    processedCount++; 
                    checkDone();
                }).finally(function() {
                    processedCount++;
                    checkDone();
                });
            };
            fileReader.readAsArrayBuffer(file);
        } 
        else {
            processedCount++;
            checkDone();
        }
    });
}

// --- 5. 预览与弹窗逻辑 ---
let currentScale = 1;
let currentX = 0;
let currentY = 0;
let isDraggingImg = false;
let startX, startY;

function previewFile(url, type) {
    const modal = document.getElementById('preview-modal');
    const body = document.getElementById('modal-body');
    const title = document.getElementById('modal-title');
    
    // 重置为图片模式样式
    body.style.background = '';
    body.style.overflow = '';
    body.style.display = '';
    body.style.padding = '';
    
    currentScale = 1;
    currentX = 0;
    currentY = 0;
    
    if (type === 'pdf') {
        title.innerHTML = "<i class='ri-file-pdf-line'></i> PDF预览";
        body.innerHTML = `<iframe src="${url}" class="pdf-viewer"></iframe>`;
    } else {
        title.innerHTML = "<i class='ri-image-line'></i> 图片预览";
        body.innerHTML = `<img src="${url}" class="img-viewer" id="target-img" draggable="false">`;
        
        const img = document.getElementById('target-img');
        
        body.onwheel = function(e) {
            e.preventDefault();
            const delta = e.deltaY > 0 ? 0.9 : 1.1;
            currentScale *= delta;
            if(currentScale < 0.5) currentScale = 0.5;
            if(currentScale > 5) currentScale = 5;
            applyTransform(img);
        };

        img.onmousedown = function(e) {
            isDraggingImg = true;
            startX = e.clientX - currentX;
            startY = e.clientY - currentY;
            img.style.cursor = 'grabbing';
        };
        
        window.onmouseup = function() {
            isDraggingImg = false;
            if(img) img.style.cursor = 'grab';
        };
        
        window.onmousemove = function(e) {
            if (!isDraggingImg) return;
            e.preventDefault();
            currentX = e.clientX - startX;
            currentY = e.clientY - startY;
            applyTransform(img);
        };
    }
    
    modal.style.display = 'flex';
    const box = document.getElementById('modal-box');
    box.style.top = "5%";
    box.style.left = "auto";
}

function applyTransform(img) {
    img.style.transform = `translate(${currentX}px, ${currentY}px) scale(${currentScale})`;
}

function closePreview() {
    document.getElementById('preview-modal').style.display = 'none';
    document.getElementById('modal-body').innerHTML = '';
    window.onmousemove = null;
    window.onmouseup = null;
}

// 弹窗拖拽
document.addEventListener('DOMContentLoaded', function() {
    if(document.getElementById('sections-container')) {
        addCompanySection();
    }
    
    const box = document.getElementById('modal-box');
    const header = document.getElementById('modal-header');
    
    if(!box || !header) return;

    let isDraggingBox = false;
    let boxX, boxY, mouseX, mouseY;

    header.onmousedown = function(e) {
        if(e.target.tagName === 'BUTTON') return;
        isDraggingBox = true;
        mouseX = e.clientX;
        mouseY = e.clientY;
        boxX = box.offsetLeft;
        boxY = box.offsetTop;
        
        box.style.position = 'absolute'; 
        box.style.margin = '0';
        box.style.left = boxX + 'px';
        box.style.top = boxY + 'px';
    };

    document.onmousemove = function(e) {
        if (!isDraggingBox) return;
        const deltaX = e.clientX - mouseX;
        const deltaY = e.clientY - mouseY;
        box.style.left = (boxX + deltaX) + 'px';
        box.style.top = (boxY + deltaY) + 'px';
    };

    document.onmouseup = function() {
        isDraggingBox = false;
    };
});

// 后台一键通过
function approveAll(batchId, userId) {
    if (confirm("确定一键通过本页所有【待审核】单据吗？")) {
        location.href = `action.php?action=approve_all&bid=${batchId}&uid=${userId}`;
    }
}