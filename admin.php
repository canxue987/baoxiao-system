<?php
require_once 'config.php';

// --- 逻辑处理：新建档期 ---
if (isset($_POST['new_batch'])) {
    $name = $_POST['batch_name'];
    $pdo->prepare("UPDATE batches SET status='closed' WHERE status='open'")->execute(); 
    $pdo->prepare("INSERT INTO batches (name, status) VALUES (?, 'open')")->execute([$name]);
    header("Location: admin.php"); exit;
}

// --- 数据准备 ---
$batches = $pdo->query("SELECT * FROM batches ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
// 默认选中最新一个档期
$active_batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : ($batches[0]['id'] ?? 0);
// 当前查看的员工ID (如果有)
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
    <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:20px;">
        
        <div style="flex:1; min-width:300px;">
            <h3 style="margin-top:0;"><i class="ri-calendar-check-line"></i> 档期管理</h3>
            <form method="post" style="display:flex; gap:10px; align-items:center;">
                <input type="text" name="batch_name" placeholder="新档期名称 (如: 2026年4月)" required class="form-control" style="width:220px;">
                <button type="submit" name="new_batch" value="1" class="btn btn-primary">开启新档期</button>
            </form>
            <div style="font-size:12px; color:#999; margin-top:8px;">* 注意：开启新档期会自动关闭旧档期，员工将只能在新档期申报。</div>
        </div>
        
        <div style="display:flex; align-items:center; gap:10px; background:#f9f9f9; padding:15px; border-radius:8px; border:1px solid #eee;">
            <span style="font-weight:bold; color:#555;">当前查看：</span>
            <select onchange="location.href='admin.php?batch_id='+this.value" class="form-select" style="width:auto; min-width:200px;">
                <?php foreach($batches as $b): ?>
                    <option value="<?php echo $b['id']; ?>" <?php if($b['id']==$active_batch_id) echo 'selected'; ?>>
                        <?php echo h($b['name']); ?> <?php echo $b['status']=='open'?'(🟢 开启中)':'(🔴 已关闭)'; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<?php if (!$view_user_id): ?>
    <?php
        // 统计逻辑
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
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h3 style="margin:0;"><i class="ri-team-line"></i> 员工申报列表</h3>
            
            <a href="export_list.php?batch_id=<?php echo $active_batch_id; ?>" target="_blank" class="btn btn-success btn-sm" style="display:flex; align-items:center; gap:5px; padding: 6px 12px; font-weight: 500;">
                <i class="ri-file-excel-2-line"></i> 导出财务明细总表
            </a>
        </div>

        <?php
            // ✨ 优化：在 SQL 中加上 pending_cnt 的统计，用来判断是否还有未审核的明细
            $stmt = $pdo->prepare("SELECT u.id, u.realname, COUNT(*) as cnt, SUM(amount) as total, SUM(CASE WHEN i.status='pending' THEN 1 ELSE 0 END) as pending_cnt FROM items i LEFT JOIN users u ON i.user_id = u.id WHERE i.batch_id=? AND i.status!='rejected' GROUP BY u.id");
            $stmt->execute([$active_batch_id]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <table class="data-table">
            <thead><tr><th>姓名</th><th>申报笔数</th><th>申报总额</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                    <td><?php echo h($u['realname']); ?></td>
                    <td><?php echo $u['cnt']; ?> 笔</td>
                    <td style="font-weight:bold; color:#1890ff;">¥<?php echo number_format($u['total'], 2); ?></td>
                    <td>
                        <?php if($u['pending_cnt'] > 0): ?>
                            <span class="tag" style="background:#e6f7ff; color:#1890ff; border:1px solid #91d5ff;">审核中</span>
                        <?php else: ?>
                            <span class="tag" style="background:#f6ffed; color:#52c41a; border:1px solid #b7eb8f;"><i class="ri-check-line"></i> 已通过</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex; flex-direction:column; gap:8px;">
                            <div style="display:flex; gap:5px;">
                                <a href="admin.php?batch_id=<?php echo $active_batch_id; ?>&view_user=<?php echo $u['id']; ?>" class="btn btn-primary btn-sm" style="flex:1; text-align:center;">
                                    <i class="ri-eye-line"></i> 审核详情
                                </a>
                                <a href="download.php?batch_id=<?php echo $active_batch_id; ?>&user_id=<?php echo $u['id']; ?>&type=zip" class="btn btn-ghost btn-sm" title="下载附件包">
                                    <i class="ri-folder-zip-line"></i>
                                </a>
                                <a href="print_invoices.php?batch_id=<?php echo $active_batch_id; ?>&user_id=<?php echo $u['id']; ?>" target="_blank" class="btn btn-ghost btn-sm" title="A4排版打印发票附件 (一页两张)" style="color:#52c41a; border-color:#b7eb8f; background:#f6ffed;">
                                    <i class="ri-printer-cloud-line"></i>
                                </a>
                            </div>

                            <div class="btn-group" style="display:flex; gap:2px;">
                                <a href="export_word.php?batch_id=<?php echo $active_batch_id; ?>&user_id=<?php echo $u['id']; ?>&type=费用报销单" class="btn btn-ghost btn-sm" title="导出费用报销单(Word)" style="flex:1; text-align:center; border:1px solid #e8e8e8; color:#1890ff;">
                                    <i class="ri-file-word-2-line"></i> 费
                                </a>
                                <a href="export_word.php?batch_id=<?php echo $active_batch_id; ?>&user_id=<?php echo $u['id']; ?>&type=差旅费报销单" class="btn btn-ghost btn-sm" title="导出差旅报销单(Word)" style="flex:1; text-align:center; border:1px solid #e8e8e8; color:#1890ff;">
                                    <i class="ri-file-word-2-line"></i> 差
                                </a>
                            </div>

                            <div class="btn-group" style="display:flex; gap:2px;">
                                <a href="print.php?batch_id=<?php echo $active_batch_id; ?>&user_id=<?php echo $u['id']; ?>&type=费用报销单" target="_blank" class="btn btn-ghost btn-sm" title="图片打印：费用单" style="flex:1; text-align:center; font-size:12px; color:#999; border:1px solid #f0f0f0;">
                                    <i class="ri-printer-line"></i> 费图
                                </a>
                                <a href="print.php?batch_id=<?php echo $active_batch_id; ?>&user_id=<?php echo $u['id']; ?>&type=差旅费报销单" target="_blank" class="btn btn-ghost btn-sm" title="图片打印：差旅单" style="flex:1; text-align:center; font-size:12px; color:#999; border:1px solid #f0f0f0;">
                                    <i class="ri-printer-line"></i> 差图
                                </a>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($users)): ?>
                    <tr><td colspan="5" style="text-align:center; padding:30px; color:#999;">本期暂无申报数据</td></tr>
                <?php endif; ?>
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
            <a href="admin.php?batch_id=<?php echo $active_batch_id; ?>" class="btn btn-ghost"><i class="ri-arrow-left-line"></i> 返回列表</a>
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
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h4 style="margin:0;"><i class="ri-file-search-line"></i> 原始单据审核</h4>
            
            <?php
                // ✨ 智能检测：看看该员工当前是否还有处于“审核中”的单据
                $check_pending = $pdo->prepare("SELECT COUNT(*) FROM items WHERE batch_id=? AND user_id=? AND status='pending'");
                $check_pending->execute([$active_batch_id, $view_user_id]);
                $has_pending = $check_pending->fetchColumn() > 0;
            ?>
            
            <?php if($has_pending): ?>
                <a href="action.php?action=approve_all&bid=<?php echo $active_batch_id; ?>&uid=<?php echo $view_user_id; ?>" 
                   class="btn btn-primary btn-sm" 
                   onclick="return confirm('确定要一键通过该员工所有【待审核】的单据吗？')" 
                   style="background:#52c41a; border-color:#52c41a; box-shadow: 0 2px 6px rgba(82,196,26,0.3);">
                    <i class="ri-check-double-line"></i> 一键全部通过
                </a>
            <?php endif; ?>
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
                    // ✨ 终极护盾：防止奇葩备注或明细破坏管理员的审核按钮
                    $json_str = htmlspecialchars(json_encode($meta_data, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
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
                            // 将所有发票路径打包成 JSON，并处理引号转义
                            $invs_json = htmlspecialchars(json_encode($invs, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                            echo "<div style='margin-bottom:6px;'>
                                    <button onclick=\"previewGallery($invs_json, '发票')\" class='btn btn-ghost btn-sm' style='color:#1890ff; border:1px solid #91d5ff; background:#e6f7ff; font-size:12px; padding:4px 8px; width:100%; display:flex; justify-content:space-between; align-items:center;'>
                                        <span><i class='ri-coupon-2-line'></i> 发票清单</span>
                                        <span style='background:#1890ff; color:#fff; padding:0 6px; border-radius:10px; font-size:11px;'>" . count($invs) . "</span>
                                    </button>
                                  </div>"; 
                        }
                        if($sups) { 
                            $sups_json = htmlspecialchars(json_encode($sups, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                            echo "<div>
                                    <button onclick=\"previewGallery($sups_json, '辅证')\" class='btn btn-ghost btn-sm' style='color:#52c41a; border:1px solid #b7eb8f; background:#f6ffed; font-size:12px; padding:4px 8px; width:100%; display:flex; justify-content:space-between; align-items:center;'>
                                        <span><i class='ri-attachment-line'></i> 辅助证明</span>
                                        <span style='background:#52c41a; color:#fff; padding:0 6px; border-radius:10px; font-size:11px;'>" . count($sups) . "</span>
                                    </button>
                                  </div>"; 
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
            <div class="modal-body" id="modal-body"></div>
        </div>
    </div>

    <style>
    /* ✨ 修复 PDF 内联预览冲突：当弹窗开启了 PDF 预览模式时，让外部 body 的滚动条消失 */
    body:has(#preview-modal[style*="display: flex"]):has(embed) {
        overflow: hidden !important;
    }
    /* 确保预览 Body 在切换模式时足够平滑 */
    #modal-body {
        transition: background 0.2s, padding 0.2s;
    }
    </style>

    <script>
    function reject(id, uid) {
        let r = prompt("请输入驳回理由:");
        if(r) location.href = "action.php?action=audit&id="+id+"&uid="+uid+"&status=rejected&reason="+encodeURIComponent(r);
    }

    // 显示详情弹窗
    function showMeta(data) {
        const modal = document.getElementById('preview-modal');
        const body = document.getElementById('modal-body');
        const title = document.getElementById('modal-title');
        
        // 样式重置为文档模式
        body.style.background = '#fff';      
        body.style.overflow = 'auto';        
        body.style.display = 'block';        
        body.style.padding = '20px';         
        
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
        box.style.width = '600px';
        box.style.height = 'auto';
        box.style.minHeight = '300px';
        box.style.maxHeight = '80%';
    }

    // ✨ 终极版：长卷阅读模式 (无需任何点击，直接往下滚就能看完全部)
    function previewGallery(files, titleName) {
        const modal = document.getElementById('preview-modal');
        const body = document.getElementById('modal-body');
        const title = document.getElementById('modal-title');
        const safeFiles = Array.isArray(files) ? files.map(normalizePreviewUrl).filter(Boolean) : [];
        const safeTitle = escapePreviewHtml(titleName);
        if (!safeFiles.length) return;
        
        title.innerHTML = `<i class='ri-slideshow-line' style='color:#1890ff;'></i> ${safeTitle}长卷预览 (${safeFiles.length}份)`;
        
        // 样式重置为长卷滚动模式
        body.style.background = '#e2e5e9'; // 深灰底色，像专业的 PDF 阅读器
        body.style.overflow = 'auto';
        body.style.display = 'block';
        body.style.padding = '20px';
        
        let html = '';
        safeFiles.forEach((src, index) => {
            let ext = src.split('.').pop().toLowerCase();
            let type = (ext === 'pdf') ? 'pdf' : 'img';
            let fileName = escapePreviewHtml(decodeURIComponent(src.substring(src.lastIndexOf('/') + 1)));
            let safeSrc = escapePreviewHtml(src);
            
            html += `<div style="background: #fff; margin-bottom: 25px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.15); overflow: hidden; border: 1px solid #ccc;">`;
            
            // 附件标题栏 (带一个备用的外链打开按钮)
            html += `<div style="background: #f5f5f5; padding: 10px 15px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: bold; color: #333; font-size: 14px;">
                            <i class="${type==='pdf'?'ri-file-pdf-line':'ri-image-line'}" style="color:${type==='pdf'?'#ff4d4f':'#52c41a'}; margin-right:5px;"></i>
                            附件 ${index + 1} / ${safeFiles.length} : ${fileName}
                        </span>
                        <a href="${safeSrc}" target="_blank" rel="noopener noreferrer" class="btn btn-ghost btn-sm" style="font-size:12px; color:#1890ff; padding:2px 8px; border:1px solid #91d5ff; background:#e6f7ff; text-decoration:none;"><i class="ri-external-link-line"></i> 单独打开</a>
                     </div>`;
                     
            // 附件内容区 (不再是缩略图，直接上原图/完整PDF)
            if (type === 'pdf') {
                // PDF 直接嵌入，给一个足够高的高度 (800px)
                html += `<embed src="${safeSrc}" type="application/pdf" width="100%" height="800px" style="border:none; display:block;"></embed>`;
            } else {
                // 图片直接展示原大图，最大宽度不超过容器
                html += `<div style="padding: 15px; display:flex; justify-content:center; background:#fafafa;">
                            <img src="${safeSrc}" style="max-width: 100%; height: auto; object-fit: contain;">
                         </div>`;
            }
            
            html += `</div>`;
        });
        
        // 底部提示
        html += `<div style="text-align:center; color:#999; padding-bottom:20px;">— 到底啦 —</div>`;
        
        body.innerHTML = html;
        modal.style.display = 'flex';
        
        // 放大画廊面板尺寸，为了看 PDF 和大图更加舒服
        const box = document.getElementById('modal-box');
        box.style.width = '900px'; 
        box.style.height = 'auto';
        box.style.maxHeight = '92vh'; // 占据屏幕的大部分高度
    }

    // ================== ✨ 全新的“内联查看器”引擎 ==================
    function viewFileInline(src, type) {
        const safeUrl = normalizePreviewUrl(src);
        if (!safeUrl) return;
        const body = document.getElementById('modal-body');
        const title = document.getElementById('modal-title');
        
        // 1. 将弹窗标题变更为“查看附件”
        let cleanName = escapePreviewHtml(decodeURIComponent(safeUrl.substring(safeUrl.lastIndexOf('/') + 1)));
        let safeSrc = escapePreviewHtml(safeUrl);
        title.innerHTML = `
            <div style="display:flex; align-items:center; width:100%; justify-content:space-between; width:740px;">
                <span style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:400px;"><i class='ri-eye-line' style='color:#52c41a;'></i> 附件详情: ${cleanName}</span>
                <button onclick="previewGallery(window.currentPreviewGalleryFiles, window.currentPreviewTitleName)" class="btn btn-ghost btn-sm" style="border:1px solid #ddd; padding:2px 8px; font-size:12px; color:#666; cursor:pointer; background:#f9f9f9;"><i class="ri-arrow-left-line"></i> 返回清单</button>
            </div>
        `;
        
        // 2. 重置 Body 样式，变为全屏撑开模式
        body.style.background = '#000';      // 图片看大图底色变黑
        body.style.overflow = 'hidden';     // 大图模式不让外面有滚动条
        body.style.display = 'block';
        body.style.padding = '0';
        
        let viewerHtml = '';
        if (type === 'pdf') {
             body.style.background = '#525659'; // PDF 阅读器底色
             // ✨ PDF 核心解决方案：直接在弹窗内塞入一个 full-size 的 PDF 嵌入对象
             viewerHtml = `<embed src="${safeSrc}" type="application/pdf" width="100%" height="calc(85vh - 56px)" style="border:none;"></embed>`;
        } else {
             // ✨ 图片大图解决方案：居中显示大图，允许点击关闭或滑轮缩放
             viewerHtml = `<div id="imgViewer" style="width:100%; height:calc(85vh - 56px); display:flex; align-items:center; justify-content:center; overflow:auto; cursor:zoom-out;" onclick="previewGallery(window.currentPreviewGalleryFiles, window.currentPreviewTitleName)">
                                <img src="${safeSrc}" style="max-width:100%; max-height:100%; object-fit:contain; border:2px solid #333; box-shadow:0 10px 30px rgba(0,0,0,0.3);">
                            </div>`;
        }
        
        body.innerHTML = viewerHtml;
        
        // 如果是图片，尝试注入一个滚轮缩放的小体验（可选，防大票据看不清）
        if(type !== 'pdf'){
            setTimeout(() => {
                const imgViewer = document.getElementById('imgViewer');
                const img = imgViewer.querySelector('img');
                imgViewer.onwheel = function(e){
                    e.preventDefault();
                    let scale = parseFloat(img.getAttribute('data-scale') || 1);
                    if(e.deltaY < 0) scale += 0.1; // 放大
                    else scale = Math.max(0.3, scale - 0.1); // 缩小
                    img.style.maxWidth = (scale * 100) + '%';
                    img.style.maxHeight = (scale * 100) + '%';
                    img.setAttribute('data-scale', scale);
                }
            }, 100);
        }
    }
    
    </script>

<?php endif; ?>

<script src="main.js?v=<?php echo time(); ?>"></script>
</body>
</html>
