/* main.js - 前端逻辑 (V3.1 替票逻辑修正版) */

const typeData = {
    "费用报销单": ["招待费", "办公费", "交通费", "停车费", "过路桥费"],
    "差旅费报销单": ["飞机票", "车船票", "住宿费", "交通车票", "汽油费"]
};

let globalRowId = 0;

// --- 1. 增加公司区块 ---
// --- 1. 增加公司区块 (动态读取配置版) ---
function addCompanySection() {
    const sectionId = Date.now();
    const container = document.getElementById('sections-container');
    if(!container) return;

    // 动态生成下拉选项
    let optionsHtml = '';
    // GLOBAL_COMPANIES 来自 header.php 的注入
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

// --- 2. 增加明细行 ---
function addRow(sectionId) {
    const compSelect = document.getElementById(`comp-select-${sectionId}`);
    const companyName = compSelect.value;
    
    compSelect.addEventListener('change', function() {
        const inputs = document.querySelectorAll(`.comp-input-${sectionId}`);
        inputs.forEach(i => i.value = this.value);
    });

    const today = new Date().toISOString().split('T')[0];
    
    const rowHtml = `
    <div class="row-input" id="row-${globalRowId}">
        <input type="hidden" name="items[${globalRowId}][company]" value="${companyName}" class="comp-input-${sectionId}">
        
        <div class="input-group" style="flex: 0 0 140px;">
            <span class="input-label">消费日期</span>
            <input type="date" name="items[${globalRowId}][date]" id="date-${globalRowId}" required value="${today}">
        </div>
        
        <div class="input-group" style="flex: 0 0 120px;">
            <span class="input-label">报销金额</span>
            <input type="text" name="items[${globalRowId}][amount]" id="amt-${globalRowId}" required placeholder="0.00" onblur="calc(this)">
        </div>

        <div class="input-group" style="flex: 0 0 60px; align-items:center;">
            <span class="input-label">替票</span>
           <input type="checkbox" onchange="toggleInv(${globalRowId})" id="chk-${globalRowId}" name="items[${globalRowId}][is_sub]" value="1">
        </div>
        
        <div class="input-group" id="inv-box-${globalRowId}" style="display:none; flex: 0 0 100px;">
            <span class="input-label" style="color:var(--warning)">发票面额</span>
            <input type="text" name="items[${globalRowId}][inv_amt]" id="inv-amt-${globalRowId}" onblur="calc(this)">
        </div>
        
        <div class="input-group" style="flex: 0 0 130px;">
            <span class="input-label">报销大类</span>
            <select name="items[${globalRowId}][category]" onchange="updateSubTypes(${globalRowId}, this.value)">
                <option value="费用报销单">费用报销单</option>
                <option value="差旅费报销单">差旅费报销单</option>
            </select>
        </div>
        
        <div class="input-group" style="flex: 0 0 130px;">
            <span class="input-label">费用项目</span>
            <select name="items[${globalRowId}][type]" id="subtype-${globalRowId}"></select>
        </div>
        
        <div class="input-group" style="flex: 1;">
            <span class="input-label">备注说明</span>
            <input type="text" name="items[${globalRowId}][note]" placeholder="事由" required>
        </div>

        <div class="input-group" style="flex: 0 0 180px;">
             <span class="input-label" style="color:#1677ff">发票 (支持图片/PDF)</span>
             <input type="file" name="invoice_${globalRowId}[]" multiple accept="image/*,.pdf" onchange="scanInvoiceQR(this, ${globalRowId})">
             <div id="scan-msg-${globalRowId}" style="font-size:10px; color:#999; margin-top:2px;"></div>
        </div>
        
        <div class="input-group" style="flex: 0 0 180px;">
             <span class="input-label">辅证</span>
             <input type="file" name="support_${globalRowId}[]" multiple accept="image/*,.pdf">
        </div>
        
        <div class="input-group" style="flex: 0 0 40px; justify-content: flex-end;">
            <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('row-${globalRowId}').remove()">×</button>
        </div>
    </div>`;
    
    document.getElementById(`body-${sectionId}`).insertAdjacentHTML('beforeend', rowHtml);
    updateSubTypes(globalRowId, "费用报销单");
    globalRowId++;
}

// --- 3. 计算器功能 ---
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

// --- 4. 智能扫描 (含替票逻辑修正) ---
function scanInvoiceQR(fileInput, rowId) {
    const files = fileInput.files;
    if (!files || files.length === 0) return;

    let totalAmount = 0;
    let foundDate = null;
    let successCount = 0;
    let processedCount = 0;

    const msgBox = document.getElementById(`scan-msg-${rowId}`);
    msgBox.innerText = "正在分析...";
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
                // 判断是否勾选了“替票”
                const isSub = document.getElementById(`chk-${rowId}`).checked;
                
                // 核心逻辑：如果是替票，填入【发票面额】；否则填入【报销金额】
                let targetInputId = isSub ? `inv-amt-${rowId}` : `amt-${rowId}`;
                document.getElementById(targetInputId).value = totalAmount.toFixed(2);
                
                // 填入日期 (日期总是可以自动填的)
                if (foundDate) document.getElementById(`date-${rowId}`).value = foundDate;

                // 提示文案
                let targetName = isSub ? "发票面额" : "报销金额";
                msgBox.innerText = `✅ 已识别${successCount}张, 填入${targetName}: ¥${totalAmount.toFixed(2)}`;
                msgBox.style.color = "#28a745"; 
            } else {
                msgBox.innerText = "未识别到二维码";
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
                    console.error("PDF解析失败", err);
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

// --- 5. 辅助功能 ---
function toggleInv(id) {
    const chk = document.getElementById(`chk-${id}`);
    document.getElementById(`inv-box-${id}`).style.display = chk.checked ? 'flex' : 'none';
    
    // 切换时清空提示信息，避免误导
    document.getElementById(`scan-msg-${id}`).innerText = "";
}

function updateSubTypes(id, category) {
    const subSelect = document.getElementById(`subtype-${id}`);
    subSelect.innerHTML = "";
    typeData[category].forEach(item => {
        const opt = document.createElement("option");
        opt.value = item;
        opt.innerText = item;
        subSelect.appendChild(opt);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    if(document.getElementById('sections-container')) {
        addCompanySection();
    }
});

/* --- 后台审核增强功能 --- */

// 1. 一键通过
function approveAll(batchId, userId) {
    if (confirm("⚠️ 确定要一键通过该员工本期所有【待审核】单据吗？\n(已驳回的单据不会被改变)")) {
        location.href = `action.php?action=approve_all&bid=${batchId}&uid=${userId}`;
    }
}

// 2. 预览查看器逻辑
let currentScale = 1;
let currentX = 0;
let currentY = 0;
let isDraggingImg = false;
let startX, startY;

// 打开预览
function previewFile(url, type) {
    const modal = document.getElementById('preview-modal');
    const body = document.getElementById('modal-body');
    const title = document.getElementById('modal-title');
    
    // 重置状态
    currentScale = 1;
    currentX = 0;
    currentY = 0;
    
    // 根据类型渲染
    if (type === 'pdf') {
        title.innerText = "📄 PDF 预览";
        body.innerHTML = `<iframe src="${url}" class="pdf-viewer"></iframe>`;
    } else {
        title.innerText = "🖼️ 图片预览 (滚轮缩放，拖拽移动)";
        // 图片支持缩放和拖拽
        body.innerHTML = `<img src="${url}" class="img-viewer" id="target-img" draggable="false">`;
        
        // 绑定图片的事件
        const img = document.getElementById('target-img');
        
        // 滚轮缩放
        body.onwheel = function(e) {
            e.preventDefault();
            const delta = e.deltaY > 0 ? 0.9 : 1.1; // 缩小 or 放大
            currentScale *= delta;
            // 限制缩放范围
            if(currentScale < 0.5) currentScale = 0.5;
            if(currentScale > 5) currentScale = 5;
            applyTransform(img);
        };

        // 鼠标拖拽图片 (只有放大了才能拖，或者随意拖)
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
    
    // 居中弹窗窗口 (防止上次拖偏了)
    const box = document.getElementById('modal-box');
    box.style.top = "5%";
    box.style.left = "auto";
}

function applyTransform(img) {
    img.style.transform = `translate(${currentX}px, ${currentY}px) scale(${currentScale})`;
}

function closePreview() {
    document.getElementById('preview-modal').style.display = 'none';
    document.getElementById('modal-body').innerHTML = ''; // 清空内容停止PDF加载
    // 解绑事件防止内存泄漏
    window.onmousemove = null;
    window.onmouseup = null;
}

// 3. 弹窗窗口拖拽 (拖动 Header)
document.addEventListener('DOMContentLoaded', function() {
    const box = document.getElementById('modal-box');
    const header = document.getElementById('modal-header');
    
    if(!box || !header) return;

    let isDraggingBox = false;
    let boxX, boxY, mouseX, mouseY;

    header.onmousedown = function(e) {
        if(e.target.tagName === 'BUTTON') return; // 点关闭按钮时不拖动
        isDraggingBox = true;
        mouseX = e.clientX;
        mouseY = e.clientY;
        boxX = box.offsetLeft;
        boxY = box.offsetTop;
        
        // 设为 absolute 以便拖动，原来可能是 flex 居中
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