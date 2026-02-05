<?php
// action.php
require 'config.php';
if (!isset($_SESSION['user_id'])) die("未登录");

$action = $_REQUEST['action'] ?? '';

// --- 1. 提交报销 (支持管理员代填) ---
if ($action == 'add_items' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $batch_id = $_POST['batch_id'];
    $items = $_POST['items'];
    
    // 默认是自己
    $final_user_id = $_SESSION['user_id'];
    $final_realname = $_SESSION['realname'];

    // 核心修改：如果是管理员，且指定了目标用户
    if ($_SESSION['role'] == 'admin' && !empty($_POST['target_user_id'])) {
        $target_id = $_POST['target_user_id'];
        // 查一下目标用户的真名（用于建文件夹）
        $stmt = $pdo->prepare("SELECT realname FROM users WHERE id=?");
        $stmt->execute([$target_id]);
        $target_name = $stmt->fetchColumn();
        
        if ($target_name) {
            $final_user_id = $target_id;
            $final_realname = $target_name;
        }
    }

    // 目录结构：uploads/Batch_X/目标姓名
    $root_dir = "uploads/Batch_{$batch_id}/{$final_realname}";
    
    foreach ($items as $index => $item) {
        $amount = floatval($item['amount']);
        if ($amount <= 0) continue;

        $company = $item['company'];
        // --- 安全检查 1: 过滤公司名称特殊字符 ---
        if (!preg_match('/^[\x{4e00}-\x{9fa5}A-Za-z0-9_\-]+$/u', $company)) {
            die("错误：公司名称包含非法字符，可能存在安全风险。");
        }
        
        // 建立公司文件夹
        $comp_dir = "$root_dir/$company"; 
        if (!is_dir("$comp_dir/Invoices")) mkdir("$comp_dir/Invoices", 0777, true);
        if (!is_dir("$comp_dir/Supports")) mkdir("$comp_dir/Supports", 0777, true);

        $is_sub = isset($item['is_sub']) ? 1 : 0;
        $inv_amt = ($is_sub && !empty($item['inv_amt'])) ? floatval($item['inv_amt']) : $amount;

        // --- 保存发票 ---
        $inv_paths = [];
        $f_key = 'invoice_' . $index;
        if (isset($_FILES[$f_key])) {
            $count = count($_FILES[$f_key]['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES[$f_key]['error'][$i] == 0) {
                    $ext = pathinfo($_FILES[$f_key]['name'][$i], PATHINFO_EXTENSION);
                    // --- 安全检查 2: 限制上传文件类型 ---
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf', 'gif'];
                    if (!in_array(strtolower($ext), $allowed_exts)) {
                        continue; // 如果不是允许的类型，直接跳过，不保存
                    }
                    $fname = "{$item['type']}_{$amount}_{$item['date']}_{$i}.{$ext}";
                    $target = "$comp_dir/Invoices/$fname";
                    if (move_uploaded_file($_FILES[$f_key]['tmp_name'][$i], $target)) {
                        $inv_paths[] = $target;
                    }
                }
            }
        }

        // --- 保存辅证 ---
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

        // 入库 (注意这里用的是 final_user_id)
        $stmt = $pdo->prepare("INSERT INTO items (user_id, batch_id, company, category, expense_date, amount, invoice_amount, type, note, is_substitute, invoice_path, support_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $final_user_id, $batch_id, $company, $item['category'], 
            $item['date'], $amount, $inv_amt, $item['type'], $item['note'], $is_sub, 
            json_encode($inv_paths), json_encode($sup_paths)
        ]);
    }
    
    // 如果是代填，填完跳回代填页，方便继续填；否则跳回首页
    if ($_SESSION['role'] == 'admin' && isset($_POST['target_user_id'])) {
        echo "<script>alert('代填报成功！'); location.href='admin_file.php';</script>";
    } else {
        header("Location: index.php");
    }
}

// --- 2. 删除单条 (物理删除文件) ---
if ($action == 'delete') {
    $id = $_GET['id'];
    $force = $_GET['force'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT invoice_path, support_path, user_id, status FROM items WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    
    if ($row) {
        if ($_SESSION['role'] == 'admin' || ($row['user_id'] == $_SESSION['user_id'] && $row['status'] != 'approved')) {
            $invs = json_decode($row['invoice_path'] ?: '[]');
            foreach ($invs as $f) { if (file_exists($f)) unlink($f); }
            $sups = json_decode($row['support_path'] ?: '[]');
            foreach ($sups as $f) { if (file_exists($f)) unlink($f); }
            $pdo->prepare("DELETE FROM items WHERE id=?")->execute([$id]);
        }
    }
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
}

// --- 3. 审核 (通过/驳回) ---
if ($action == 'audit' && $_SESSION['role'] == 'admin') {
    $stmt = $pdo->prepare("UPDATE items SET status = ?, reject_reason = ? WHERE id = ?");
    $stmt->execute([$_GET['status'], $_GET['reason']??'', $_GET['id']]);
    header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
}

// --- 4. 档期管理 (关闭/删除) ---
if (isset($_GET['del_batch']) && $_SESSION['role'] == 'admin') {
    $bid = $_GET['del_batch'];
    // 删库
    $pdo->prepare("DELETE FROM items WHERE batch_id=?")->execute([$bid]);
    $pdo->prepare("DELETE FROM batches WHERE id=?")->execute([$bid]);
    // 删文件夹
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

// --- 5. 一键全部通过 (新增) ---
if ($action == 'approve_all' && $_SESSION['role'] == 'admin') {
    $target_uid = $_GET['uid'];
    $batch_id = $_GET['bid'];
    
    // 只更新 'pending' 状态的，驳回的不动，已通过的不动
    $sql = "UPDATE items SET status = 'approved' WHERE user_id = ? AND batch_id = ? AND status = 'pending'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$target_uid, $batch_id]);
    
    // 跳回该用户的审核页
    header("Location: admin.php?batch_id=$batch_id&view_user=$target_uid");
}
?>