<?php
// action.php - 核心逻辑处理 (V4.1 修复票夹状态流转 BUG)
require_once 'config.php';
if (!isset($_SESSION['user_id'])) die("未登录");

$action = $_REQUEST['action'] ?? '';

// =================================================================
// ✨ 核心辅助器：从文件路径中反向提取“票夹发票 ID”
// 因为文件命名规则为: 票夹_{type}_{amount}_{index}_{wallet_id}.{ext}
// =================================================================
function getWalletIdsFromPaths($json_paths) {
    $ids = [];
    $paths = json_decode($json_paths ?: '[]');
    if (is_array($paths)) {
        foreach ($paths as $f) {
            $basename = basename($f);
            if (strpos($basename, '票夹_') === 0) {
                // 去掉后缀名，然后按下划线分割
                $chunks = explode('_', pathinfo($basename, PATHINFO_FILENAME));
                $w_id = end($chunks); // 最后一个元素就是 ID
                if (is_numeric($w_id)) $ids[] = (int)$w_id;
            }
        }
    }
    return array_unique($ids);
}

// --- 1. 提交报销 ---
if ($action == 'add_items' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $batch_id = $_POST['batch_id'];
    $items = $_POST['items']; 

    $final_user_id = $_SESSION['user_id'];
    $final_realname = $_SESSION['realname'];

    // 代填逻辑
    if ($_SESSION['role'] == 'admin' && !empty($_POST['target_user_id'])) {
        $target_id = $_POST['target_user_id'];
        $stmt = $pdo->prepare("SELECT realname FROM users WHERE id=?");
        $stmt->execute([$target_id]);
        $target_name = $stmt->fetchColumn();
        if ($target_name) {
            $final_user_id = $target_id;
            $final_realname = $target_name;
        }
    }

    $root_dir = "uploads/Batch_{$batch_id}/{$final_realname}";
    
    foreach ($items as $index => $item) {
        $amount = floatval($item['amount']);
        $company = $item['company'];
        
        if (!preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9_\-]+$/u', $company)) {
            die("错误：公司名称包含非法字符");
        }
        
        $comp_dir = "$root_dir/$company"; 
        if (!is_dir("$comp_dir/Invoices")) mkdir("$comp_dir/Invoices", 0777, true);
        if (!is_dir("$comp_dir/Supports")) mkdir("$comp_dir/Supports", 0777, true);

        $is_sub = isset($item['is_sub']) ? 1 : 0;
        $inv_amt = ($is_sub && !empty($item['inv_amt'])) ? floatval($item['inv_amt']) : $amount;

        $inv_paths = [];

        // 普通上传
        $f_key = 'invoice_' . $index; 
        if (isset($_FILES[$f_key])) {
            $count = count($_FILES[$f_key]['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES[$f_key]['error'][$i] == 0) {
                    $ext = pathinfo($_FILES[$f_key]['name'][$i], PATHINFO_EXTENSION);
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf', 'gif'];
                    if (!in_array(strtolower($ext), $allowed_exts)) continue;
                    
                    $fname = "{$item['type']}_{$amount}_{$item['date']}_{$i}.{$ext}";
                    $target = "$comp_dir/Invoices/$fname";
                    if (move_uploaded_file($_FILES[$f_key]['tmp_name'][$i], $target)) {
                        $inv_paths[] = $target;
                    }
                }
            }
        }

        // 票夹关联
        if (!empty($item['wallet_ids'])) {
            $w_ids_arr = array_map('intval', explode(',', $item['wallet_ids']));
            $w_in_str = implode(',', $w_ids_arr);
            
            if (!empty($w_in_str)) {
                $stmt_w = $pdo->query("SELECT id, file_path, amount FROM invoices WHERE id IN ($w_in_str) AND user_id = $final_user_id");
                $wallet_files = $stmt_w->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($wallet_files as $k => $wf) {
                    if (file_exists($wf['file_path'])) {
                        $ext = pathinfo($wf['file_path'], PATHINFO_EXTENSION);
                        // 命名规则: 票夹_类型_金额_行号_票ID.ext (这就是上面函数能提取ID的依据)
                        $fname = "票夹_{$item['type']}_{$amount}_{$index}_{$wf['id']}.{$ext}";
                        $dest_path = "$comp_dir/Invoices/$fname";
                        
                        if (copy($wf['file_path'], $dest_path)) {
                            $inv_paths[] = $dest_path;
                        }
                    }
                }
                // 锁定状态
                $pdo->exec("UPDATE invoices SET status='locked' WHERE id IN ($w_in_str)");
            }
        }

        // 辅证
        $sup_paths = [];
        $s_key = 'support_' . $index;
        if (isset($_FILES[$s_key])) {
            $count = count($_FILES[$s_key]['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES[$s_key]['error'][$i] == 0) {
                    $ext = pathinfo($_FILES[$s_key]['name'][$i], PATHINFO_EXTENSION);
                    $fname = "辅证_{$item['type']}_{$item['date']}_{$i}.{$ext}";
                    $target = "$comp_dir/Supports/$fname";
                    if (move_uploaded_file($_FILES[$s_key]['tmp_name'][$i], $target)) {
                        $sup_paths[] = $target;
                    }
                }
            }
        }

        // 入库
        $sql = "INSERT INTO items (
            user_id, batch_id, company, category, expense_date, amount, invoice_amount, 
            type, note, is_substitute, invoice_path, support_path,
            project_name, travel_reason, travelers, travel_start, travel_end, travel_days
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $final_user_id, $batch_id, $company, $item['category'], 
            $item['date'], $amount, $inv_amt, $item['type'], $item['note'], $is_sub, 
            json_encode($inv_paths), json_encode($sup_paths),
            $item['project_name']??'', $item['travel_reason']??'', $item['travelers']??'', 
            $item['travel_start']??null, $item['travel_end']??null, floatval($item['travel_days']??0)
        ]);
    }
    
    if ($_SESSION['role'] == 'admin' && isset($_POST['target_user_id'])) {
        echo "<script>alert('代填报成功！'); location.href='admin_file.php';</script>";
    } else {
        echo "<script>alert('提交成功！'); location.href='index.php';</script>";
    }
}

// --- 2. 删除单条 (撤回/删除重填) ---
if ($action == 'delete') {
    $id = $_GET['id'];
    $stmt = $pdo->prepare("SELECT invoice_path, support_path, user_id, status FROM items WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    
    if ($row) {
        if ($_SESSION['role'] == 'admin' || ($row['user_id'] == $_SESSION['user_id'] && $row['status'] != 'approved')) {
            
            // ✨ 修复点 1：撤回时，释放该条目占用的所有票夹发票，恢复为“未使用”
            $w_ids = getWalletIdsFromPaths($row['invoice_path']);
            if (!empty($w_ids)) {
                $w_in = implode(',', $w_ids);
                $pdo->exec("UPDATE invoices SET status='unused' WHERE id IN ($w_in)");
            }

            // 物理删除文件
            $invs = json_decode($row['invoice_path'] ?: '[]');
            foreach ($invs as $f) { if (file_exists($f)) unlink($f); }
            $sups = json_decode($row['support_path'] ?: '[]');
            foreach ($sups as $f) { if (file_exists($f)) unlink($f); }
            
            // 删记录
            $pdo->prepare("DELETE FROM items WHERE id=?")->execute([$id]);
        }
    }
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
}

// --- 3. 审核 (通过/驳回) ---
if ($action == 'audit' && $_SESSION['role'] == 'admin') {
    $status = $_GET['status'];
    $item_id = $_GET['id'];
    
    $stmt = $pdo->prepare("UPDATE items SET status = ?, reject_reason = ? WHERE id = ?");
    $stmt->execute([$status, $_GET['reason']??'', $item_id]);

    // ✨ 修复点 2：如果审核通过，将票夹状态变更为“已报销”
    if ($status == 'approved') {
        $stmt_path = $pdo->prepare("SELECT invoice_path FROM items WHERE id=?");
        $stmt_path->execute([$item_id]);
        $w_ids = getWalletIdsFromPaths($stmt_path->fetchColumn());
        
        if (!empty($w_ids)) {
            $w_in = implode(',', $w_ids);
            $pdo->exec("UPDATE invoices SET status='reimbursed' WHERE id IN ($w_in)");
        }
    }
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
}

// --- 4. 档期管理 (关闭/删除) ---
if (isset($_GET['del_batch']) && $_SESSION['role'] == 'admin') {
    $bid = $_GET['del_batch'];
    
    // ✨ 修复点 3：删除档期时，将该档期内所有的票夹发票打回“未使用”状态
    $stmt = $pdo->prepare("SELECT invoice_path FROM items WHERE batch_id=?");
    $stmt->execute([$bid]);
    $all_w_ids = [];
    while($row = $stmt->fetch()) {
        $all_w_ids = array_merge($all_w_ids, getWalletIdsFromPaths($row['invoice_path']));
    }
    if (!empty($all_w_ids)) {
        $w_in = implode(',', array_unique($all_w_ids));
        $pdo->exec("UPDATE invoices SET status='unused' WHERE id IN ($w_in)");
    }

    $pdo->prepare("DELETE FROM items WHERE batch_id=?")->execute([$bid]);
    $pdo->prepare("DELETE FROM batches WHERE id=?")->execute([$bid]);
    
    $dir = "uploads/Batch_{$bid}";
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($dir);
    }
    header("Location: admin.php");
}

if (isset($_GET['close_batch']) && $_SESSION['role'] == 'admin') {
    $pdo->prepare("UPDATE batches SET status='closed' WHERE id=?")->execute([$_GET['close_batch']]);
    header("Location: admin.php");
}

// --- 5. 一键全部通过 ---
if ($action == 'approve_all' && $_SESSION['role'] == 'admin') {
    $target_uid = $_GET['uid'];
    $batch_id = $_GET['bid'];

    // ✨ 修复点 4：一键通过时，将对应的票夹发票集体变为“已报销”
    $stmt = $pdo->prepare("SELECT invoice_path FROM items WHERE user_id = ? AND batch_id = ? AND status = 'pending'");
    $stmt->execute([$target_uid, $batch_id]);
    $all_w_ids = [];
    while($row = $stmt->fetch()) {
        $all_w_ids = array_merge($all_w_ids, getWalletIdsFromPaths($row['invoice_path']));
    }

    $sql = "UPDATE items SET status = 'approved' WHERE user_id = ? AND batch_id = ? AND status = 'pending'";
    $pdo->prepare($sql)->execute([$target_uid, $batch_id]);

    if (!empty($all_w_ids)) {
        $w_in = implode(',', array_unique($all_w_ids));
        $pdo->exec("UPDATE invoices SET status='reimbursed' WHERE id IN ($w_in)");
    }

    header("Location: admin.php?batch_id=$batch_id&view_user=$target_uid");
}
?>