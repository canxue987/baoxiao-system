<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) die("未登录");

$batch_id = intval($_GET['batch_id'] ?? 0);
$user_id = intval($_GET['user_id'] ?? 0);

// 获取该员工在该档期下所有通过或待审的报销单发票
$stmt = $pdo->prepare("SELECT invoice_path FROM items WHERE batch_id=? AND user_id=? AND status!='rejected'");
$stmt->execute([$batch_id, $user_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$all_files = [];
foreach ($items as $item) {
    $paths = json_decode($item['invoice_path'] ?: '[]', true);
    if (is_array($paths)) {
        foreach ($paths as $p) {
            if (file_exists($p)) {
                $all_files[] = $p; // 收集所有真实存在的文件路径
            }
        }
    }
}

if (empty($all_files)) {
    die("<script>alert('该员工在此档期下没有上传任何发票附件！'); window.close();</script>");
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>发票附件排版打印</title>
    <link href="https://cdn.staticfile.net/remixicon/3.5.0/remixicon.min.css" rel="stylesheet">
    <style>
        body { margin: 0; padding: 20px; background: #525659; font-family: sans-serif; text-align: center; }
        
        /* 工具栏 */
        .toolbar {
            position: fixed; top: 20px; right: 20px; z-index: 999;
            display: flex; gap: 10px;
        }
        .btn {
            background: #1890ff; color: #fff; border: none; 
            padding: 10px 20px; border-radius: 6px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.2); cursor: pointer;
            font-size: 15px; font-weight: bold; display: flex; align-items: center; gap: 5px;
            transition: 0.2s;
        }
        .btn:hover { background: #0050b3; transform: translateY(-2px); }
        .btn-green { background: #52c41a; }
        .btn-green:hover { background: #389e0d; }

        /* A4 纸张模拟 */
        .page-container {
            width: 210mm; 
            margin: 0 auto; 
            background: #fff; 
            box-shadow: 0 0 10px rgba(0,0,0,0.3);
            padding: 10mm; /* A4 页边距 */
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            gap: 10mm; /* 两张发票之间的间距 */
            margin-bottom: 20px;
        }

        /* 单个发票框 (带明显的裁剪线) */
        .invoice-box {
            width: 100%;
            height: 132mm; 
            border: 1.5px dashed #888; /* 加深虚线颜色并加粗 */
            display: flex;
            justify-content: center;
            align-items: center;
            box-sizing: border-box;
            page-break-inside: avoid;
            position: relative;
        }
        .invoice-box img {
            max-width: 96%; /* 留出一点内边距，避免发票图片贴死在边框上 */
            max-height: 96%;
            object-fit: contain; 
        }
        
        /* ✨ 新增：裁剪线上的小剪刀提示 */
        .cut-label {
            position: absolute;
            top: -10px; /* 向上偏移，正好压在边框上 */
            left: 30mm;
            background: #fff;
            padding: 0 10px;
            color: #666;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        /* 正在处理的加载动画 */
        #loading { color: #fff; font-size: 18px; margin-top: 20vh; }

        /* 真实的打印机样式规则 */
        @media print {
            body { background: #fff; margin: 0; padding: 0; }
            .toolbar, #loading { display: none !important; }
            .page-container { 
                box-shadow: none; 
                margin: 0; 
                padding: 10mm; 
                page-break-after: always; 
            }
            /* ✨ 强制打印机印出深色虚线，拒绝被省墨模式过滤 */
            .invoice-box { border: 1.5px dashed #666 !important; }
            .cut-label { color: #333 !important; }
        }
    </style>
</head>
<body>

<div id="loading">
    <i class="ri-loader-4-line ri-spin" style="font-size: 30px;"></i>
    <p>正在解析并发票排版中，请稍候... (<span id="progress">0</span>/<?php echo count($all_files); ?>)</p>
    <p style="font-size: 12px; color: #ccc;">* PDF文件将自动被提取为高清图片</p>
</div>

<div class="toolbar" id="toolbar" style="display: none;">
    <button class="btn btn-green" onclick="window.print()">
        <i class="ri-printer-line"></i> 立即打印排版
    </button>
</div>

<div id="render-area"></div>

<script src="https://cdn.staticfile.net/pdf.js/2.16.105/pdf.min.js"></script>
<script>
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.staticfile.net/pdf.js/2.16.105/pdf.worker.min.js';
    
    const files = <?php echo json_encode($all_files); ?>;
    const renderArea = document.getElementById('render-area');
    const progressEl = document.getElementById('progress');
    
    // 页面加载完开始疯狂渲染
    window.onload = async function() {
        let allImages = []; // 存放所有最终的图片 (Base64 或 URL)
        
        for (let i = 0; i < files.length; i++) {
            let fileUrl = files[i];
            let ext = fileUrl.split('.').pop().toLowerCase();
            
            if (ext === 'pdf') {
                // 如果是 PDF，智能解析其所有页面为图片
                try {
                    let pdf = await pdfjsLib.getDocument(fileUrl).promise;
                    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                        let page = await pdf.getPage(pageNum);
                        // scale: 2.0 保证转出的图片在打印时足够高清
                        let viewport = page.getViewport({ scale: 2.0 }); 
                        
                        let canvas = document.createElement('canvas');
                        let ctx = canvas.getContext('2d');
                        canvas.width = viewport.width;
                        canvas.height = viewport.height;
                        
                        await page.render({ canvasContext: ctx, viewport: viewport }).promise;
                        // 转成 JPEG 图片格式塞入数组
                        allImages.push(canvas.toDataURL('image/jpeg', 0.9));
                    }
                } catch (e) {
                    console.error("PDF 解析失败:", fileUrl);
                }
            } else {
                // 如果本来就是图片，直接塞入数组
                allImages.push(fileUrl);
            }
            progressEl.innerText = i + 1;
        }
        
        // --- 开始拼装成“一页两图”的 A4 排版 ---
        let currentContainer = null;
        
        for (let j = 0; j < allImages.length; j++) {
            // 每两张图，新建一个 A4 纸容器
            if (j % 2 === 0) {
                currentContainer = document.createElement('div');
                currentContainer.className = 'page-container';
                renderArea.appendChild(currentContainer);
            }
            
            let box = document.createElement('div');
            box.className = 'invoice-box';
            
            // ✨ 动态植入小剪刀和裁剪提示文字
            let cutLabel = document.createElement('div');
            cutLabel.className = 'cut-label';
            cutLabel.innerHTML = '<i class="ri-scissors-cut-line"></i> 沿此虚线裁剪';
            
            let img = document.createElement('img');
            img.src = allImages[j];
            
            box.appendChild(cutLabel);
            box.appendChild(img);
            currentContainer.appendChild(box);
        }
        
        // 隐藏加载动画，显示打印按钮
        document.getElementById('loading').style.display = 'none';
        document.getElementById('toolbar').style.display = 'flex';
        
        // 贴心地自动唤起打印机
        setTimeout(() => { window.print(); }, 500);
    }
</script>

</body>
</html>