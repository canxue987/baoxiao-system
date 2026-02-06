<?php
require_once 'config.php';

// --- 逻辑处理：新建档期 ---
if (isset($_POST['new_batch'])) {
    $name = $_POST['batch_name'];
    $pdo->prepare("UPDATE batches SET status='closed' WHERE status='open'")->execute(); 
    $pdo->prepare("INSERT INTO batches (name, status) VALUES (?, 'open')")->execute([$name]);
    header("Location: index.php"); exit;
}

// --- 数据准备 ---
$batches = $pdo->query("SELECT * FROM batches ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$active_batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : ($batches[0]['id'] ?? 0);
$view_user_id = $_GET['view_user'] ?? null;

// --- 辅助函数：获取数据 ---
function getBatchData($pdo, $batch_id, $uid = null) {
    $sql = "SELECT i.*, u.realname FROM items i LEFT JOIN users u ON i.user_id = u.id WHERE i.batch_id = ? AND i.status != 'rejected'";
    $params = [$batch_id];
    if ($uid) { $sql .= " AND i.user_id = ?"; $params[] = $uid; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include 'header.php';
?>

<div class="card" style="margin-bottom:24px;">
    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div style="flex:1;">
            <h3><i class="ri-calendar-check-line"></i> 档期控制</h3>
            <form method="post" style="display:flex; gap:8px; align-items:center;">
                <input type="text" name="batch_name" placeholder="新档期名称 (如: 2026年3月)" required style="width:240px;">
                <button type="submit" name="new_batch" value="1" class="btn btn-primary">开启新期</button>
            </form>
            <div style="font-size:12px; color:var(--text-sub); margin-top:8px;">* 开启新档期会自动关闭旧档期</div>
        </div>
        
        <div style="display:flex; align-items:center; gap:10px;">
            <span>当前查看：</span>
            <select onchange="location.href='index.php?batch_id='+this.value" style="width:auto; padding:6px;">
                <?php foreach($batches as $b): ?>
                    <option value="<?php echo $b['id']; ?>" <?php if($b['id']==$active_batch_id) echo 'selected'; ?>>
                        <?php echo h($b['name']); ?>(<?php echo $b['status']=='open'?'开启':'关闭'; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<?php if (!$view_user_id): ?>
    
    <?php
        $all_items = getBatchData($pdo, $active_batch_id);
        $total_reimburse = 0; 
        $total_invoice = 0;   
        $comp_stats = [];     
        
        foreach ($all_items as $item) {
            $total_reimburse += $item['amount'];
            $total_invoice += $item['invoice_amount'];
            $c = $item['company']; $t = $item['type'];
            // 计算发票张数
            $sheet_count = count(json_decode($item['invoice_path'] ?: '[]'));
            
            if (!isset($comp_stats[$c])) $comp_stats[$c] = ['total_r'=>0, 'total_i'=>0, 'types'=>[]];
            $comp_stats[$c]['total_r'] += $item['amount'];
            $comp_stats[$c]['total_i'] += $item['invoice_amount'];
            
            if (!isset($comp_stats[$c]['types'][$t])) $comp_stats[$c]['types'][$t] = ['amt'=>0, 'sheets'=>0];
            $comp_stats[$c]['types'][$t]['amt'] += $item['amount'];
            $comp_stats[$c]['types'][$t]['sheets'] += $sheet_count;
        }
    ?>

    <div class="card" style="margin-bottom:24px; background:#f9f9f9; border:1px dashed #d9d9d9;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <strong style="font-size:16px; margin-right:10px;"><i class="ri-file-excel-2-line"></i> 批量操作</strong>
                <span style="color:#666; font-size:13px;">当前档期：<?php echo h($batches[array_search($active_batch_id, array_column($batches, 'id'))]['name']); ?></span>
            </div>
            <div style="display:flex; gap:10px;">
                <a href="download.php?batch_id=<?php echo $active_batch_id; ?>&type=csv" target="_blank" class="btn btn-primary" style="background:#217346; border-color:#217346;">
                    <i class="ri-file-excel-2-line"></i> 导出全员明细表 (Excel)
                </a>
                <a href="download.php?batch_id=<?php echo $active_batch_id; ?>&type=zip" target="_blank" class="btn btn-primary" style="background:#0050b3; border-color:#0050b3;">
                    <i class="ri-folder-zip-line"></i> 打包全员附件 (Zip)
                </a>
            </div>
        </div>
    </div>

    <div class="stat-grid" style="margin-bottom:24px;">
        <div class="stat-item">
            <span class="stat-label">本期报销总额</span>
            <span class="stat-value" style="color:var(--primary-color)">¥<?php echo number_format($total_reimburse, 2); ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">本期发票总额</span>
            <span class="stat-value" style="color:var(--text-sub)">¥<?php echo number_format($total_invoice, 2); ?></span>
        </div>
    </div>

    <div class="stat-grid" style="margin-bottom:24px;">
        <?php foreach($comp_stats as $comp_name => $data): ?>
        <div class="card">
            <h4><i class="ri-building-line"></i> <?php echo h($comp_name); ?></h4>
            <div style="background:#fafafa; padding:15px; border-radius:6px; margin-bottom:15px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span style="color:var(--text-sub)">报销额:</span>
                    <strong style="color:var(--primary-color)">¥<?php echo number_format($data['total_r'], 2); ?></strong>
                </div>
                <div style="display:flex; justify-content:space-between;">
                    <span style="color:var(--text-sub)">发票额:</span>
                    <strong style="color:var(--text-main)">¥<?php echo number_format($data['total_i'], 2); ?></strong>
                </div>
            </div>
            
            <div style="font-size:12px; font-weight:bold; margin-bottom:8px;">项目明细 (金额 / 张数)</div>
            <table class="data-table" style="font-size:12px;">
                <?php foreach($data['types'] as $type => $d): ?>
                <tr>
                    <td style="padding:6px 0;"><?php echo h($type); ?></td>
                    <td style="padding:6px 0; text-align:right; color:var(--text-sub);"><?php echo $d['sheets']; ?>张</td>
                    <td style="padding:6px 0; text-align:right; font-weight:bold;">¥<?php echo number_format($d['amt'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h3><i class="ri-team-line"></i> 员工申报列表</h3>
        <?php
            $stmt = $pdo->prepare("SELECT u.id, u.realname, COUNT(*) as cnt, SUM(amount) as total FROM items i LEFT JOIN users u ON i.user_id = u.id WHERE i.batch_id=? AND i.status!='rejected' GROUP BY u.id");
            $stmt->execute([$active_batch_id]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <table class="data-table">
            <thead><tr><th>姓名</th><th>申报笔数</th><th>申报总额</th><th>操作</th></tr></thead>
            <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                    <td><?php echo h($u['realname']); ?></td>
                    <td><?php echo $u['cnt']; ?> 笔</td>
                    <td style="font-weight:bold;">¥<?php echo number_format($u['total'], 2); ?></td>
                    <td>
                        <div style="display:flex; gap:5px;">
                            <a href="download.php?batch_id=<?php echo $active_batch_id; ?>&user_id=<?php echo $u['id']; ?>&type=csv" class="btn btn-ghost btn-sm" style="color:#217346; border-color:#b7eb8f;" title="导出Excel">
                                <i class="ri-file-excel-2-line"></i>
                            </a>
                            <a href="download.php?batch_id=<?php echo $active_batch_id; ?>&user_id=<?php echo $u['id']; ?>&type=zip" class="btn btn-ghost btn-sm" title="下载附件包">
                                <i class="ri-folder-zip-line"></i>
                            </a>
                            <a href="index.php?batch_id=<?php echo $active_batch_id; ?>&view_user=<?php echo $u['id']; ?>" class="btn btn-primary btn-sm">
                                <i class="ri-eye-line"></i> 详情
                            </a>
                        </div>

                        <div style="margin-top:5px; display:flex; gap:5px;">
                            <a href="export_word.php?batch_id=<?php echo $active_batch_id; ?>&user_id=<?php echo $u['id']; ?>&type=费用报销单" class="btn btn-ghost btn-sm" style="color:#1890ff;">
                                <i class="ri-file-word-2-line"></i> 费(Word)
                            </a>
                            <a href="export_word.php?batch_id=<?php echo $active_batch_id; ?>&user_id=<?php echo $u['id']; ?>&type=差旅费报销单" class="btn btn-ghost btn-sm" style="color:#1890ff;">
                                <i class="ri-file-word-2-line"></i> 差(Word)
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card" style="margin-top:24px;">
        <h3><i class="ri-history-line"></i> 历史档期管理</h3>
        <table class="data-table">
            <thead><tr><th>ID</th><th>名称</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
                <?php foreach($batches as $b): ?>
                <tr style="<?php if($b['id']==$active_batch_id) echo 'background:#e6f7ff'; ?>">
                    <td><?php echo $b['id']; ?></td>
                    <td><?php echo h($b['name']); ?></td>
                    <td>
                        <?php echo $b['status']=='open' ? '<span class="tag tag-green">开启</span>' : '<span class="tag">关闭</span>'; ?>
                    </td>
                    <td>
                        <?php if($b['status']=='open'): ?>
                            <a href="action.php?close_batch=<?php echo $b['id']; ?>" class="btn btn-ghost btn-sm" onclick="return confirm('关闭后员工将无法再提交，确定吗？')"><i class="ri-lock-2-line"></i> 关闭</a>
                        <?php endif; ?>
                        <a href="action.php?del_batch=<?php echo $b['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定要删除这个档期吗？\n所有图片文件和记录都会被永久删除，无法恢复！')"><i class="ri-delete-bin-line"></i> 删除</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>
    
    <?php
        // 获取该员工数据
        $user_items = getBatchData($pdo, $active_batch_id, $view_user_id);
        
        // 获取姓名
        $stmt_u = $pdo->prepare("SELECT realname FROM users WHERE id=?");
        $stmt_u->execute([$view_user_id]);
        $curr_name = $stmt_u->fetchColumn();

        // 个人详细统计逻辑
        $p_stats = []; 
        $user_total_r = 0;
        $user_total_i = 0;
        
        foreach ($user_items as $item) {
            $user_total_r += $item['amount'];
            $user_total_i += $item['invoice_amount'];
            
            $c = $item['company'];
            $t = $item['type'];
            // 计算发票文件张数
            $sheet_count = count(json_decode($item['invoice_path'] ?: '[]'));
            
            if (!isset($p_stats[$c])) $p_stats[$c] = ['total_r'=>0, 'total_i'=>0, 'details'=>[]];
            
            $p_stats[$c]['total_r'] += $item['amount'];
            $p_stats[$c]['total_i'] += $item['invoice_amount'];
            
            if (!isset($p_stats[$c]['details'][$t])) $p_stats[$c]['details'][$t] = ['sheets'=>0, 'amt'=>0];
            $p_stats[$c]['details'][$t]['sheets'] += $sheet_count; 
            $p_stats[$c]['details'][$t]['amt'] += $item['amount'];
        }
    ?>
    
    <div class="card" style="margin-bottom:24px;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f0f0f0; padding-bottom:15px; margin-bottom:15px;">
            <h3><i class="ri-user-star-line"></i> <?php echo h($curr_name); ?> 的报销明细</h3>
            <a href="index.php?batch_id=<?php echo $active_batch_id; ?>" class="btn btn-ghost"><i class="ri-arrow-left-line"></i> 返回列表</a>
        </div>

        <div style="font-size:16px; margin-bottom:20px;">
            <span style="color:var(--text-sub)">个人总计：</span>
            <strong>¥<?php echo number_format($user_total_r, 2); ?></strong>
            <span style="color:var(--text-sub); margin-left:15px; font-size:14px;">(发票总额: ¥<?php echo number_format($user_total_i, 2); ?>)</span>
        </div>

        <div class="stat-grid">
            <?php foreach($p_stats as $comp => $info): ?>
            <div style="background:#fafafa; border:1px solid #eee; border-radius:8px; padding:20px;">
                <h4 style="border-bottom:2px solid #e1e4e8; padding-bottom:10px; margin-bottom:15px;"><?php echo h($comp); ?></h4>
                
                <div style="background:#fff; padding:10px; border-radius:4px; border:1px solid #f0f0f0; margin-bottom:15px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                        <span style="color:var(--text-sub); font-size:13px;">报销合计</span>
                        <strong style="color:var(--primary-color)">¥<?php echo number_format($info['total_r'], 2); ?></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span style="color:var(--text-sub); font-size:13px;">发票合计</span>
                        <strong style="color:var(--text-sub)">¥<?php echo number_format($info['total_i'], 2); ?></strong>
                    </div>
                </div>

                <div style="font-size:12px; font-weight:bold; color:var(--text-main); margin-bottom:8px;">项目分布</div>
                <table style="width:100%; font-size:13px; border-collapse:collapse;">
                    <?php foreach($info['details'] as $type => $d): ?>
                    <tr style="border-bottom:1px dashed #e1e4e8;">
                        <td style="padding:5px 0; color:var(--text-sub);"><?php echo h($type); ?></td>
                        <td style="padding:5px 0; text-align:right; color:var(--text-main);"><?php echo $d['sheets']; ?>张</td>
                        <td style="padding:5px 0; text-align:right; font-weight:bold;">¥<?php echo number_format($d['amt'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
            <h4><i class="ri-file-search-line"></i> 原始单据审核</h4>
            <button onclick="approveAll(<?php echo $active_batch_id; ?>, <?php echo $view_user_id; ?>)" class="btn btn-primary" style="background:#52c41a; border-color:#52c41a;">
                本页一键全部通过
            </button>
        </div>

        <table class="data-table">
            <thead><tr><th>公司</th><th>详情</th><th>金额(报/票)</th><th>备注</th><th>附件</th><th>操作</th></tr></thead>
            <tbody>
                <?php 
                $stmt = $pdo->prepare("SELECT * FROM items WHERE batch_id=? AND user_id=? ORDER BY company, expense_date");
                $stmt->execute([$active_batch_id, $view_user_id]);
                $full_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach($full_list as $item): 
                    $invs = json_decode($item['invoice_path'] ?: '[]');
                    $sups = json_decode($item['support_path'] ?: '[]');
                    
                    // 构建详情数据 JSON
                    $meta_data = [
                        '所属项目' => $item['project_name'],
                        '报销大类' => $item['category'],
                        '费用明细' => $item['type'],
                        '消费日期' => $item['expense_date'],
                        '出差事由' => $item['travel_reason'],
                        '出差人员' => $item['travelers'],
                        '出差时间' => ($item['travel_start'] ? $item['travel_start'] . ' 至 ' . $item['travel_end'] : ''),
                        '出差天数' => ($item['travel_days'] > 0 ? $item['travel_days'].'天' : ''),
                        '备注说明' => $item['note']
                    ];
                    $meta_data = array_filter($meta_data, function($v) { return !empty($v); });
                    $json_str = htmlspecialchars(json_encode($meta_data, JSON_UNESCAPED_UNICODE));
                ?>
                <tr style="<?php if($item['status']=='rejected') echo 'background:#fff1f0; opacity:0.6;'; elseif($item['status']=='approved') echo 'background:#f6ffed;'; ?>">
                    <td><span class="tag tag-blue"><?php echo h($item['company']); ?></span></td>
                    <td>
                        <div><?php echo $item['expense_date']; ?></div>
                        <div style="font-size:12px; color:var(--text-sub);"><?php echo h($item['category']); ?> - <?php echo h($item['type']); ?></div>
                        <?php if(!empty($item['project_name'])): ?>
                            <div style="font-size:11px; background:#f0f7ff; color:#0050b3; display:inline-block; padding:0 4px; border-radius:2px; margin-top:2px;">
                                <?php echo h($item['project_name']); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="font-weight:bold; color:var(--danger)">¥<?php echo $item['amount']; ?></span> / 
                        <span style="color:var(--text-sub); font-size:12px;">¥<?php echo $item['invoice_amount']; ?></span>
                        <div style="font-size:11px; color:var(--text-sub);">(<?php echo count($invs); ?>张票)</div>
                    </td>
                    <td style="max-width:200px; font-size:13px;">
                        <?php echo h($item['note']); ?>
                    </td>
                    <td>
                        <?php 
                        if($invs) { 
                            echo "<div><i class='ri-coupon-2-line'></i> "; 
                            foreach($invs as $k=>$v) {
                                $ext = strtolower(pathinfo($v, PATHINFO_EXTENSION));
                                $type = ($ext == 'pdf') ? 'pdf' : 'img';
                                echo "<a href='javascript:;' onclick=\"previewFile('$v', '$type')\">[".($k+1)."]</a> "; 
                            }
                            echo "</div>"; 
                        }
                        if($sups) { 
                            echo "<div><i class='ri-attachment-line'></i> "; 
                            foreach($sups as $k=>$v) {
                                $ext = strtolower(pathinfo($v, PATHINFO_EXTENSION));
                                $type = ($ext == 'pdf') ? 'pdf' : 'img';
                                echo "<a href='javascript:;' onclick=\"previewFile('$v', '$type')\" style='color:#52c41a'>[".($k+1)."]</a> "; 
                            }
                            echo "</div>"; 
                        }
                        ?>
                    </td>
                    <td>
                        <div style="display:flex; flex-direction:column; gap:4px;">
                            <button onclick='showMeta(<?php echo $json_str; ?>)' class="btn btn-ghost btn-sm" style="font-size:12px; padding:2px 8px;">
                                <i class="ri-article-line"></i> 详情
                            </button>

                            <?php if($item['status']!='rejected'): ?>
                                <button onclick="reject(<?php echo $item['id']; ?>, <?php echo $view_user_id; ?>)" class="btn btn-danger btn-sm" style="font-size:12px; padding:2px 8px;">驳回</button>
                            <?php else: ?>
                                <span style="font-size:12px; color:var(--danger); text-align:center;">已驳回</span>
                            <?php endif; ?>
                            
                            <?php if($item['status']!='approved'): ?>
                                <a href="action.php?action=audit&id=<?php echo $item['id']; ?>&uid=<?php echo $view_user_id; ?>&status=approved" class="btn btn-primary btn-sm" style="font-size:12px; padding:2px 8px; text-align:center;">通过</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="preview-modal" class="modal-overlay">
        <div class="modal-box" id="modal-box">
            <div class="modal-header" id="modal-header">
                <span id="modal-title" style="font-weight:bold;">预览</span>
                <button onclick="closePreview()" class="btn btn-danger btn-sm"><i class="ri-close-line"></i> 关闭</button>
            </div>
            <div class="modal-body" id="modal-body">
            </div>
        </div>
    </div>

    <script>
    function reject(id, uid) {
        let r = prompt("请输入驳回理由:");
        if(r) location.href = "action.php?action=audit&id="+id+"&uid="+uid+"&status=rejected&reason="+encodeURIComponent(r);
    }

    // 新增：详情弹窗逻辑
    function showMeta(data) {
            const modal = document.getElementById('preview-modal');
            const body = document.getElementById('modal-body');
            const title = document.getElementById('modal-title');
            
            // --- 核心修改：强制改为文档阅读模式 (白底、可滚动) ---
            body.style.background = '#fff';      // 白底
            body.style.overflow = 'auto';        // 允许滚动 (防止内容太长看不见)
            body.style.display = 'block';        // 普通块级布局 (防止Flex居中切掉头部)
            body.style.padding = '20px';         // 加点内边距
            // ------------------------------------------------
            
            title.innerHTML = "<i class='ri-file-info-line'></i> 单据详细信息";
            
            let html = '<table class="data-table" style="width:100%; border-collapse: collapse;">';
            for (const key in data) {
                html += `
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="width:100px; color:#666; font-weight:bold; background:#fafafa; padding:12px 10px;">${key}</td>
                        <td style="padding:12px 10px; color:#333;">${data[key]}</td>
                    </tr>
                `;
            }
            html += '</table>';
            
            body.innerHTML = html;
            modal.style.display = 'flex';
            
            const box = document.getElementById('modal-box');
            box.style.width = '600px';   // 稍微宽一点
            box.style.height = 'auto';
            box.style.minHeight = '300px';
            box.style.maxHeight = '80%'; // 防止太高超出屏幕
        }
    </script>
<?php endif; ?>

<script src="main.js?v=<?php echo time(); ?>"></script>
</body>
</html>