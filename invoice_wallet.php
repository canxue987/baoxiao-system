<?php
require_once 'config.php';
include 'header.php';

// --- 处理 AJAX 修改 (金额和日期改为静态，不再需要更新它们) ---
if (isset($_POST['action']) && $_POST['action'] == 'update') {
    $stmt = $pdo->prepare("UPDATE invoices SET invoice_type=?, note=? WHERE id=? AND user_id=? AND status='unused'");
    $stmt->execute([$_POST['type'], $_POST['note'], $_POST['id'], $_SESSION['user_id']]);
    echo "ok"; exit;
}

// --- 处理单条删除 ---
if (isset($_GET['del'])) {
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id=? AND user_id=?");
    $stmt->execute([$_GET['del'], $_SESSION['user_id']]);
    $inv = $stmt->fetch();
    if ($inv && $inv['status'] == 'unused') {
        @unlink($inv['file_path']);
        $pdo->prepare("DELETE FROM invoices WHERE id=?")->execute([$_GET['del']]);
        header("Location: invoice_wallet.php"); exit;
    }
}

// --- ✨ 处理批量删除 ---
if (isset($_POST['action']) && $_POST['action'] == 'batch_delete') {
    $ids = array_map('intval', explode(',', $_POST['ids']));
    if (!empty($ids)) {
        $id_str = implode(',', $ids);
        // 安全起见：只允许删除状态为 unused 的发票
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id IN ($id_str) AND user_id=? AND status='unused'");
        $stmt->execute([$_SESSION['user_id']]);
        $invs = $stmt->fetchAll();
        foreach($invs as $inv) {
            @unlink($inv['file_path']);
        }
        $pdo->prepare("DELETE FROM invoices WHERE id IN ($id_str) AND user_id=? AND status='unused'")->execute([$_SESSION['user_id']]);
    }
    echo "ok"; exit;
}

// --- ✨ 处理批量打包下载 (ZIP) ---
if (isset($_POST['action']) && $_POST['action'] == 'download_zip') {
    if (!class_exists('ZipArchive')) {
        die("<script>alert('服务器未开启 ZipArchive 扩展，无法打包下载！请在群晖 PHP 设置中勾选 zip 扩展。'); history.back();</script>");
    }

    $ids = array_map('intval', explode(',', $_POST['ids']));
    if (empty($ids)) die("未选择发票");
    
    $id_str = implode(',', $ids);
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id IN ($id_str) AND user_id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $invs = $stmt->fetchAll();

    if(empty($invs)) die("<script>alert('未找到发票文件！'); history.back();</script>");

    $zip = new ZipArchive();
    $zip_name = "发票打包_" . date('Ymd_His') . ".zip";
    
    // 放在 uploads 目录下以确保有读写权限
    if(!is_dir(__DIR__ . '/uploads/temp')) @mkdir(__DIR__ . '/uploads/temp', 0777, true);
    $zip_path = __DIR__ . '/uploads/temp/' . $zip_name;

    if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
        die("<script>alert('无法创建ZIP压缩包，请检查 uploads 目录权限！'); history.back();</script>");
    }

    foreach($invs as $inv) {
        if(file_exists($inv['file_path'])) {
            $ext = pathinfo($inv['file_path'], PATHINFO_EXTENSION);
            // 优化文件名：日期_购方_发票号码.pdf
            $safe_buyer = preg_replace('/[\\\\\\/:\*\?"<>\|]/', '_', $inv['buyer_name'] ?: '未知单位');
            $new_name = $inv['invoice_date'] . "_" . $safe_buyer . "_" . ($inv['invoice_number']?:'无编号') . "." . $ext;
            $zip->addFile($inv['file_path'], $new_name);
        }
    }
    $zip->close();

    // 抛出给浏览器下载
    header('Content-Type: application/zip');
    header('Content-disposition: attachment; filename='. rawurlencode($zip_name)); // 中文名转码
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);
    
    // 下载后无痕销毁文件
    @unlink($zip_path);
    exit;
}

// --- 获取筛选选项数据 (只查购方) ---
$all_companies = $pdo->query("SELECT DISTINCT buyer_name FROM invoices WHERE user_id={$_SESSION['user_id']} AND buyer_name != ''")->fetchAll(PDO::FETCH_COLUMN);
sort($all_companies);

$all_sp_types = $pdo->query("SELECT DISTINCT invoice_special_type FROM invoices WHERE user_id={$_SESSION['user_id']} AND invoice_special_type != ''")->fetchAll(PDO::FETCH_COLUMN);
sort($all_sp_types);

// --- 获取列表数据 ---
$filter = $_GET['status'] ?? 'unused';
$filter_comp = $_GET['comp'] ?? '';
$filter_sp = $_GET['sp'] ?? '';

// ✨ 接收排序参数，默认降序(desc)
$sort_order = isset($_GET['sort']) && strtolower($_GET['sort']) == 'asc' ? 'ASC' : 'DESC';

$sql = "SELECT * FROM invoices WHERE user_id=? ";
$params = [$_SESSION['user_id']];

if ($filter != 'all') {
    $sql .= " AND status = ? ";
    $params[] = $filter;
}
if ($filter_comp) {
    $sql .= " AND buyer_name = ? "; // 只筛选购方
    $params[] = $filter_comp;
}
if ($filter_sp) {
    $sql .= " AND invoice_special_type = ? ";
    $params[] = $filter_sp;
}

// ✨ 应用排序参数
$sql .= " ORDER BY id {$sort_order}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✨ 计算当前列表的总金额
$total_list_amount = 0;
foreach($list as $item) {
    $total_list_amount += floatval($item['amount']);
}
?>

<style>
/* 切换标签样式 */
.modern-tabs { display: inline-flex; background-color: #f1f2f5; padding: 4px; border-radius: 8px; margin-bottom: 20px; }
.modern-tab { padding: 6px 20px; color: #5c6b7f; text-decoration: none !important; font-size: 14px; border-radius: 6px; transition: all 0.3s ease; display: flex; align-items: center; gap: 6px; cursor: pointer; }
.modern-tab:hover { color: #1890ff; }
.modern-tab.active { background-color: #ffffff; color: #1890ff; font-weight: bold; box-shadow: 0 2px 5px rgba(0,0,0,0.08); }

/* 票夹专属表格样式 */
.rich-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; }

/* ✨ 强化表头背景色，与数据行明显区分 */
.rich-table th { 
    background: #e6f0fa !important; /* 淡蓝灰色 */
    color: #333; 
    font-weight: 600; 
    padding: 10px 16px; 
    text-align: left; 
    border-bottom: 2px solid #cce0f5; /* 加深底边框 */
    white-space: nowrap; 
}

.rich-table td { padding: 8px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; color: #333; transition: background 0.3s; }

/* ✨ 强化斑马线底色 */
.rich-table tbody tr.active-row:nth-child(even) { background-color: #f0f3f8; } /* 颜色比 fafafa 更深一点的灰蓝色 */
.rich-table tbody tr.active-row:nth-child(odd) { background-color: #ffffff; }
.rich-table tbody tr.locked-row { background-color: #eef0f4; opacity: 0.8; }
.rich-table tbody tr:hover { background-color: #dcf0ff !important; } /* hover时更明显的蓝色 */

/* ✨ 复选框美化 */
.row-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; }

/* 隐形式输入框 */
.hidden-input { border: 1px solid transparent; background: transparent; padding: 2px 4px; color: inherit; font-family: inherit; font-size: inherit; transition: all 0.2s; border-radius: 4px; }
.hidden-input:focus { border: 1px solid #1890ff; background: #fff; outline: none; box-shadow: 0 0 0 2px rgba(24,144,255,0.2); }
.hidden-input:disabled { color: inherit; background: transparent; }

/* ✨ 彻底隐藏 number 输入框的上下调整箭头 */
input[type=number]::-webkit-inner-spin-button,
input[type=number]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
input[type=number] {
    -moz-appearance: textfield; 
}

/* 文字分层 */
.text-main { font-weight: 500; color: #262626; margin-bottom: 4px; line-height: 1.4; }
.text-sub { font-size: 12px; color: #8c8c8c; line-height: 1.4; }
.text-price { font-weight: bold; font-family: Verdana, sans-serif; }

/* 发票类型小标签 */
.inv-tag { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: 11px; border: 1px solid #d9d9d9; color: #666; background: #fafafa; }
.inv-tag.zp { border-color: #ffadd2; color: #cf1322; background: #fff1f0; } 
.inv-tag.pp { border-color: #87e8de; color: #096dd9; background: #e6f7ff; } 

.edit-icon { color: #1890ff; cursor: pointer; padding: 4px; border-radius: 4px; transition: background 0.2s; }
.edit-icon:hover { background: #e6f7ff; }

/* 详情弹窗专属样式 */
.detail-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.detail-table th { width: 100px; text-align: right; padding: 10px 15px; color: #8c8c8c; font-weight: normal; border-bottom: 1px solid #f0f0f0; background: #fafafa; }
.detail-table td { padding: 10px 15px; color: #333; font-weight: 500; border-bottom: 1px solid #f0f0f0; }
</style>

<div class="card" style="padding: 24px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3 style="margin:0;"><i class="ri-wallet-3-line"></i> 我的票夹</h3>
        <div>
            <button onclick="document.getElementById('uploadFile').click()" class="btn btn-primary" style="border-radius: 6px;">
                <i class="ri-upload-cloud-line"></i> 批量/拖拽上传发票
            </button>
            <input type="file" id="uploadFile" multiple style="display:none" accept="image/*,.pdf" onchange="uploadInvoice(this.files)">
        </div>
    </div>

    <div id="dragOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(24,144,255,0.1); border:4px dashed #1890ff; z-index:9999; align-items:center; justify-content:center; pointer-events:none;">
        <h2 style="color:#1890ff; background:#fff; padding:20px; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,0.1);"><i class="ri-upload-cloud-2-line"></i> 松开鼠标，立即上传</h2>
    </div>

    <div class="modern-tabs">
        <a href="?status=unused" class="modern-tab <?php echo $filter=='unused'?'active':''; ?>"><i class="ri-inbox-line"></i> 未使用</a>
        <a href="?status=locked" class="modern-tab <?php echo $filter=='locked'?'active':''; ?>"><i class="ri-lock-2-line"></i> 审核中</a>
        <a href="?status=reimbursed" class="modern-tab <?php echo $filter=='reimbursed'?'active':''; ?>"><i class="ri-checkbox-circle-line"></i> 已报销</a>
        <a href="?status=all" class="modern-tab <?php echo $filter=='all'?'active':''; ?>"><i class="ri-function-line"></i> 全部</a>
    </div>

    <div style="background: #f0f7ff; border: 1px solid #bae0ff; padding: 12px 20px; border-radius: 8px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
            <span style="color: #333; font-size: 14px; margin-right: 25px;">
                当前列表总额：<strong style="color: #0050b3; font-size: 18px; font-family: Verdana;">¥<?php echo number_format($total_list_amount, 2); ?></strong>
            </span>
            <span style="color: #333; font-size: 14px; background: #fff; padding: 4px 10px; border-radius: 4px; border: 1px solid #d9d9d9; display: inline-block;">
                已勾选金额：<strong id="selected-amount-display" style="color: #f5222d; font-size: 18px; font-family: Verdana;">¥0.00</strong>
                <span style="color:#8c8c8c; font-size:12px; margin-left: 5px;">(共 <span id="selected-count-display">0</span> 张)</span>
            </span>
        </div>
        <div style="display:flex; gap:10px;">
            <button onclick="batchDownload()" class="btn btn-primary btn-sm" style="background:#1890ff; border-color:#1890ff;"><i class="ri-download-2-line"></i> 打包下载</button>
            <?php if($filter == 'unused' || $filter == 'all'): ?>
                <button onclick="batchDelete()" class="btn btn-ghost btn-sm" style="color:#ff4d4f; border-color:#ffa39e; background:#fff2f0;"><i class="ri-delete-bin-line"></i> 批量删除</button>
            <?php endif; ?>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table class="rich-table">
            <thead>
                <tr>
                    <th width="40" style="text-align:center;">
                        <input type="checkbox" id="selectAll" class="row-check" onclick="toggleAll(this)" title="全选/全不选">
                    </th>
                    <?php 
                        $next_sort = ($sort_order == 'DESC') ? 'asc' : 'desc'; 
                        $sort_icon = ($sort_order == 'DESC') ? 'ri-sort-desc' : 'ri-sort-asc';
                        $sort_title = ($sort_order == 'DESC') ? '当前最新在前，点击切换' : '当前最旧在前，点击切换';
                    ?>
                    <th width="100">
                        <div style="display:flex; align-items:center; gap:4px;">
                            序号/编号
                            <a href="javascript:;" onclick="toggleSort('<?php echo $next_sort; ?>')" style="color:#1890ff; background:#fff; border:1px solid #bae0ff; border-radius:4px; padding:2px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none;" title="<?php echo $sort_title; ?>">
                                <i class="<?php echo $sort_icon; ?>"></i>
                            </a>
                        </div>
                    </th>

                    <th width="180">
                        <div style="margin-bottom: 2px; color: #555; font-size:13px;">购方名称</div>
                        <select id="filter-comp" onchange="applyFilter()" style="padding:0px 2px; height:20px; border-radius:3px; border:1px solid #ccc; width:100%; max-width:140px; font-size:11px; outline:none; background:#fff; color:#333; cursor:pointer;">
                            <option value="">全部购方</option>
                            <?php foreach($all_companies as $c): ?>
                                <option value="<?php echo h($c); ?>" <?php if($filter_comp==$c) echo 'selected'; ?>><?php echo h(mb_substr($c,0,10)).(mb_strlen($c)>10?'...':''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>

                    <th width="140">总金额(¥)</th>
                    <th width="130">开票内容</th>
                    <th width="130">日期 (开票/入库)</th>

                    <th width="90">
                        <div style="margin-bottom: 2px; color: #555; font-size:13px;">发票类型</div>
                        <select id="filter-sp" onchange="applyFilter()" style="padding:0px 2px; height:20px; border-radius:3px; border:1px solid #ccc; width:100%; max-width:85px; font-size:11px; outline:none; background:#fff; color:#333; cursor:pointer;">
                            <option value="">全部类型</option>
                            <?php foreach($all_sp_types as $s): ?>
                                <option value="<?php echo h($s); ?>" <?php if($filter_sp==$s) echo 'selected'; ?>><?php echo h($s); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>
                    <th width="100">报销分类</th>
                    <th width="80">状态</th>
                    <th width="120">备注</th>
                    <th width="140">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($list as $item): 
                    $disabled = ($item['status'] != 'unused') ? 'disabled' : '';
                    $is_zp = (strpos($item['invoice_special_type'], '专') !== false);
                    $tag_class = $is_zp ? 'zp' : 'pp';
                    
                    $item['status_text'] = ($item['status']=='unused')?'未使用' : (($item['status']=='locked')?'审核中':'已报销');
                    $item['created_date'] = date('Y-m-d', strtotime($item['created_at']));
                    $json_data = htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE));
                ?>
                <tr id="row-<?php echo $item['id']; ?>" class="<?php echo $item['status'] == 'unused' ? 'active-row' : 'locked-row'; ?>">
                    
                    <td style="text-align:center;">
                        <input type="checkbox" class="row-check invoice-checkbox" value="<?php echo $item['id']; ?>" data-amount="<?php echo $item['amount']; ?>" onchange="calcSelected()">
                    </td>

                    <td>
                        <div class="text-main">ID: <?php echo $item['id']; ?></div>
                        <div class="text-sub" style="font-family: monospace;" title="<?php echo h($item['invoice_number']); ?>">
                            <?php echo h($item['invoice_number'] ?: '暂无编号'); ?>
                        </div>
                    </td>

                    <td style="max-width: 180px;">
                        <div class="text-main" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo h($item['buyer_name']); ?>">
                            <?php echo h($item['buyer_name'] ?: '未知购方'); ?>
                        </div>
                        <div class="text-sub" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo h($item['seller_name']); ?>">
                            <?php echo h($item['seller_name'] ?: '未知销方'); ?>
                        </div>
                    </td>

                    <td>
                        <div style="display:flex; align-items:center; color:#1890ff;">
                            <span style="font-weight:bold; font-family:Verdana; font-size: 15px;">
                                ¥<?php echo number_format($item['amount'], 2); ?>
                            </span>
                        </div>
                        <div class="text-sub" style="font-size:11px; transform:scale(0.9); transform-origin:left;">
                            不含税: ¥<?php echo $item['pre_tax_amount']; ?>
                        </div>
                    </td>

                    <td style="max-width: 130px;">
                        <div class="text-sub" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo h($item['invoice_detail']); ?>">
                            <?php echo h($item['invoice_detail'] ?: '-'); ?>
                        </div>
                    </td>

                    <td>
                        <div class="text-main" style="font-family:monospace; margin-bottom:2px; font-size:13px;">
                            <?php echo $item['invoice_date']; ?>
                        </div>
                        <div class="text-sub" title="入库日期">
                            <i class="ri-download-cloud-2-line"></i> <?php echo $item['created_date']; ?>
                        </div>
                    </td>

                    <td>
                        <span class="inv-tag <?php echo $tag_class; ?>"><?php echo h($item['invoice_special_type'] ?: '普票'); ?></span>
                    </td>

                    <td>
                        <select id="type-<?php echo $item['id']; ?>" onchange="saveRow(<?php echo $item['id']; ?>)" class="hidden-input text-main" style="width:100px; padding:2px;" <?php echo $disabled; ?>>
                            <?php foreach(['未分类','餐饮费','交通费','住宿费','加油费','办公用品','其他'] as $t): ?>
                                <option value="<?php echo $t; ?>" <?php if($item['invoice_type']==$t) echo 'selected'; ?>><?php echo $t; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>

                    <td>
                        <?php if($item['status']=='unused'): ?>
                            <span class="tag tag-green" style="margin:0;">未使用</span>
                        <?php elseif($item['status']=='locked'): ?>
                            <span class="tag tag-blue" style="margin:0;">审核中</span>
                        <?php else: ?>
                            <span class="tag" style="margin:0; background:#f5f5f5; color:#bfbfbf; border-color:#d9d9d9;">已报销</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div style="display:flex; align-items:center; gap:5px;">
                            <input type="hidden" id="note-<?php echo $item['id']; ?>" value="<?php echo h($item['note']); ?>">
                            <span id="note-text-<?php echo $item['id']; ?>" class="text-sub" style="flex:1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:80px;" title="<?php echo h($item['note']); ?>">
                                <?php echo h($item['note']) ?: '-'; ?>
                            </span>
                            <?php if($item['status']=='unused'): ?>
                                <i class="ri-edit-2-line edit-icon" title="修改备注" onclick="editNote(<?php echo $item['id']; ?>)"></i>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td style="text-align:center;">
                        <div style="display:flex; justify-content:center; gap:6px;">
                            <button onclick='openDetail(<?php echo $json_data; ?>)' class="btn btn-primary btn-sm" style="padding:4px 8px; border-radius:4px;" title="查看详情">
                                <i class="ri-article-line"></i> 详情
                            </button>
                            <?php if($item['status']=='unused'): ?>
                                <a href="?del=<?php echo $item['id']; ?>" onclick="return confirm('确定永久删除这张发票吗？')" class="btn btn-danger btn-sm" style="padding:4px 8px; border-radius:4px;" title="删除">
                                    <i class="ri-delete-bin-line"></i> 删除
                                </a>
                            <?php else: ?>
                                <button class="btn btn-ghost btn-sm" style="padding:4px 8px; border-radius:4px; opacity:0.5; cursor:not-allowed;" disabled title="发票已锁定">
                                    <i class="ri-lock-2-line"></i> 锁定
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if(empty($list)): ?>
                    <tr>
                        <td colspan="11" style="text-align:center; padding:60px 20px; color:#bfbfbf;">
                            <i class="ri-inbox-archive-line" style="font-size:48px; color:#e8e8e8;"></i>
                            <p style="margin-top:10px;">当前分类下没有发票</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="invoiceDetailModal" class="modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div class="modal-box" style="background:#fff; border-radius:8px; width:850px; max-width:95%; display:flex; flex-direction:column; overflow:hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
        
        <div style="padding:15px 24px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:#fafafa;">
            <h3 style="margin:0; font-size:16px; color:#333;"><i class="ri-file-info-line" style="color:#1890ff;"></i> 发票详情信息</h3>
            <button onclick="document.getElementById('invoiceDetailModal').style.display='none'" style="background:none; border:none; font-size:24px; color:#999; cursor:pointer;">&times;</button>
        </div>
        
        <div style="display:flex; padding:20px; gap:20px; max-height:60vh; overflow-y:auto;">
            <div style="flex:1.2; border:1px solid #eee; border-radius:6px; display:flex; align-items:center; justify-content:center; background:#f9f9f9; min-height:300px; padding:10px;">
                <img id="detail-img" src="" style="max-width:100%; max-height:100%; object-fit:contain; display:none;">
                <iframe id="detail-pdf" src="" style="width:100%; height:400px; border:none; display:none;"></iframe>
            </div>
            
            <div style="flex:1;">
                <table class="detail-table">
                    <tbody id="detail-info-tbody">
                        </tbody>
                </table>
            </div>
        </div>
        
        <div style="padding:15px 24px; border-top:1px solid #f0f0f0; background:#fff; display:flex; justify-content:flex-end; gap:10px;">
            <a id="detail-download-btn" href="" download class="btn btn-ghost" style="border:1px solid #d9d9d9;"><i class="ri-download-2-line"></i> 下载源文件</a>
            <a id="detail-delete-btn" href="" class="btn btn-danger" onclick="return confirm('确定永久删除这张发票吗？')"><i class="ri-delete-bin-line"></i> 永久删除</a>
        </div>
    </div>
</div>

<script>
// --- ✨ 新增：多选与统计逻辑 ---
function toggleAll(source) {
    let checkboxes = document.querySelectorAll('.invoice-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = source.checked;
    });
    calcSelected();
}

function calcSelected() {
    let total = 0;
    let count = 0;
    document.querySelectorAll('.invoice-checkbox:checked').forEach(cb => {
        total += parseFloat(cb.getAttribute('data-amount') || 0);
        count++;
    });
    
    document.getElementById('selected-amount-display').innerText = '¥' + total.toFixed(2);
    document.getElementById('selected-count-display').innerText = count;
}

// 批量打包下载
function batchDownload() {
    let ids = Array.from(document.querySelectorAll('.invoice-checkbox:checked')).map(cb => cb.value);
    if(ids.length === 0) return alert('请先勾选需要下载的发票！');
    
    // 动态创建表单进行下载 (规避 AJAX 无法直接触发下载窗口的问题)
    let form = document.createElement('form');
    form.method = 'POST';
    form.action = 'invoice_wallet.php';
    
    let actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'download_zip';
    form.appendChild(actionInput);
    
    let idsInput = document.createElement('input');
    idsInput.type = 'hidden';
    idsInput.name = 'ids';
    idsInput.value = ids.join(',');
    form.appendChild(idsInput);
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
    
    // 取消全选状态，给用户视觉反馈
    document.getElementById('selectAll').checked = false;
    toggleAll(document.getElementById('selectAll'));
}

// 批量删除
function batchDelete() {
    let ids = Array.from(document.querySelectorAll('.invoice-checkbox:checked')).map(cb => cb.value);
    if(ids.length === 0) return alert('请先勾选需要删除的发票！');
    
    if(!confirm(`⚠️ 确定要永久删除这 ${ids.length} 张发票吗？\n注意：正在审核或已报销的发票会被系统自动跳过保护。`)) return;

    let data = new FormData();
    data.append('action', 'batch_delete');
    data.append('ids', ids.join(','));

    fetch('invoice_wallet.php', { method: 'POST', body: data })
    .then(res => res.text())
    .then(text => {
        if(text.trim() === 'ok') {
            location.reload();
        } else {
            alert('部分或全部删除失败！');
        }
    });
}

// --- 拖拽上传监听事件 ---
document.addEventListener('dragover', function(e) {
    e.preventDefault();
    document.getElementById('dragOverlay').style.display = 'flex';
});
document.addEventListener('dragleave', function(e) {
    e.preventDefault();
    // 确保鼠标移出浏览器窗口才隐藏，而不是移过子元素
    if (e.relatedTarget === null || e.relatedTarget === document.documentElement) {
        document.getElementById('dragOverlay').style.display = 'none';
    }
});
document.addEventListener('drop', function(e) {
    e.preventDefault();
    document.getElementById('dragOverlay').style.display = 'none';
    if (e.dataTransfer.files.length > 0) {
        uploadInvoice(e.dataTransfer.files);
    }
});

// --- ✨ 新增：切换排序方向 ---
function toggleSort(order) {
    let url = new URL(window.location.href);
    url.searchParams.set('sort', order);
    window.location.href = url.toString();
}

// --- ✨ 更新：下拉筛选时，保留当前的排序状态 ---
function applyFilter() {
    let comp = document.getElementById('filter-comp').value;
    let sp = document.getElementById('filter-sp').value;
    let url = new URL(window.location.href);
    
    if(comp) url.searchParams.set('comp', comp); else url.searchParams.delete('comp');
    if(sp) url.searchParams.set('sp', sp); else url.searchParams.delete('sp');
    
    // 不干预 url 中已有的 sort 参数，直接跳转
    window.location.href = url.toString();
}

// --- 批量上传逻辑重构 ---
async function uploadInvoice(filesInput) {
    let files = filesInput || document.getElementById('uploadFile').files;
    if(!files || files.length === 0) return;

    let btn = document.querySelector('.btn-primary');
    let oldHTML = btn.innerHTML;
    btn.disabled = true;

    let successCnt = 0;
    let failCnt = 0;
    let failMsgs = [];

    // 逐个文件处理，显示进度
    for(let i = 0; i < files.length; i++) {
        btn.innerHTML = `<i class="ri-loader-4-line ri-spin"></i> 正在上传识别 (${i+1}/${files.length})...`;
        
        let formData = new FormData();
        formData.append('file', files[i]);

        try {
            let res = await fetch('ocr.php', { method: 'POST', body: formData });
            let json = await res.json();
            
            if (json.success) {
                successCnt++;
            } else {
                failCnt++;
                failMsgs.push(`[${files[i].name}]: ${json.error}`);
            }
        } catch(e) {
            failCnt++;
            failMsgs.push(`[${files[i].name}]: 网络异常或服务器错误`);
        }
    }

    btn.innerHTML = oldHTML;
    btn.disabled = false;
    document.getElementById('uploadFile').value = ''; 

    // 上传完毕后的提示
    if (failCnt > 0) {
        alert(`上传完成：成功 ${successCnt} 张，失败 ${failCnt} 张。\n\n失败原因：\n` + failMsgs.join('\n'));
    }
    
    // 只要有成功的，就刷新页面查看
    if (successCnt > 0) {
        location.reload(); 
    }
}

// --- 自动保存逻辑 (去掉了 date 和 amount) ---
function saveRow(id) {
    let data = new FormData();
    data.append('action', 'update');
    data.append('id', id);
    data.append('type', document.getElementById('type-'+id).value);
    data.append('note', document.getElementById('note-'+id).value);

    fetch('invoice_wallet.php', { method: 'POST', body: data })
    .then(res => res.text())
    .then(text => {
        if(text.trim() === 'ok') {
            let noteInput = document.getElementById('note-'+id);
            if(noteInput) {
                noteInput.style.backgroundColor = '#f6ffed';
                setTimeout(() => noteInput.style.backgroundColor = 'transparent', 800);
            }
            calcSelected();
        }
    });
}

// --- 备注弹窗编辑逻辑 ---
function editNote(id) {
    let hiddenInput = document.getElementById('note-' + id);
    let textDisplay = document.getElementById('note-text-' + id);
    let currentVal = hiddenInput.value;
    let newVal = prompt("请输入发票备注信息：", currentVal);
    
    if (newVal !== null) {
        hiddenInput.value = newVal; 
        textDisplay.innerText = newVal.trim() === '' ? '-' : newVal; 
        textDisplay.title = newVal; 
        saveRow(id); 
    }
}

// --- 详情弹窗逻辑 ---
function openDetail(data) {
    let isPdf = data.file_type === 'pdf' || data.file_path.toLowerCase().endsWith('.pdf');
    document.getElementById('detail-img').style.display = isPdf ? 'none' : 'block';
    document.getElementById('detail-pdf').style.display = isPdf ? 'block' : 'none';
    if(isPdf) document.getElementById('detail-pdf').src = data.file_path;
    else document.getElementById('detail-img').src = data.file_path;

    let tbody = document.getElementById('detail-info-tbody');
    tbody.innerHTML = `
        <tr><th>发票标题</th><td style="font-weight:bold;">${data.invoice_special_type || '普通发票'}</td></tr>
        <tr><th>发票号码</th><td style="font-family:monospace;">${data.invoice_number || '-'}</td></tr>
        <tr><th>开票日期</th><td>${data.invoice_date}</td></tr>
        <tr><th>购买方名称</th><td>${data.buyer_name || '-'}</td></tr>
        <tr><th>销售方名称</th><td>${data.seller_name || '-'}</td></tr>
        <tr><th>开票内容</th><td>${data.invoice_detail || '-'}</td></tr>
        <tr><th>合计税额</th><td>¥${data.tax_amount || '0.00'}</td></tr>
        <tr><th>税前金额</th><td>¥${data.pre_tax_amount || '0.00'}</td></tr>
        <tr><th>价税合计</th><td style="color:#f5222d; font-weight:bold; font-size:16px;">¥${data.amount}</td></tr>
        <tr><th>当前状态</th><td>${data.status_text}</td></tr>
    `;

    document.getElementById('detail-download-btn').href = data.file_path;
    let delBtn = document.getElementById('detail-delete-btn');
    if (data.status === 'unused') {
        delBtn.style.display = 'inline-flex';
        delBtn.href = '?del=' + data.id;
    } else {
        delBtn.style.display = 'none'; 
    }

    document.getElementById('invoiceDetailModal').style.display = 'flex';
}
</script>