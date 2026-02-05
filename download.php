<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) die("未登录");

$batch_id = $_GET['batch_id'] ?? 0;
$user_id = $_GET['user_id'] ?? null; // 如果有则是单人，没有则是全体
$type = $_GET['type'] ?? 'zip';       // 'zip' 或 'csv'

// 权限检查：如果是下载全体，必须是管理员
if (!$user_id && $_SESSION['role'] != 'admin') {
    die("无权操作");
}
// 权限检查：如果是下载单人，必须是管理员或者是自己
if ($user_id && $_SESSION['role'] != 'admin' && $_SESSION['user_id'] != $user_id) {
    die("无权操作");
}

// 获取档期名称（用于文件名）
$stmt = $pdo->prepare("SELECT name FROM batches WHERE id=?");
$stmt->execute([$batch_id]);
$batch_name = $stmt->fetchColumn() ?: '未知档期';

// 获取目标用户姓名（如果是单人）
$user_name = "全体";
if ($user_id) {
    $stmt = $pdo->prepare("SELECT realname FROM users WHERE id=?");
    $stmt->execute([$user_id]);
    $user_name = $stmt->fetchColumn();
}

// ==========================================
//  功能 A: 导出 CSV 表格 (Excel)
// ==========================================
if ($type == 'csv') {
    // 1. 准备数据
    $sql = "SELECT i.*, u.realname 
            FROM items i 
            LEFT JOIN users u ON i.user_id = u.id 
            WHERE i.batch_id = ?";
    $params = [$batch_id];
    
    if ($user_id) {
        $sql .= " AND i.user_id = ?";
        $params[] = $user_id;
    }
    
    $sql .= " ORDER BY u.id, i.company, i.expense_date";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. 设置头信息，告诉浏览器这是个 CSV 文件
    $filename = "{$batch_name}_{$user_name}_报销明细.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // 3. 打开输出流
    $fp = fopen('php://output', 'w');
    
    // 4. 写入 BOM 头，防止 Excel 打开中文乱码 (关键!)
    fwrite($fp, "\xEF\xBB\xBF");
    
    // 5. 写入表头
    fputcsv($fp, ['报销人', '报销主体', '消费日期', '报销大类', '费用项目', '报销金额', '发票面额', '是否替票', '备注说明', '状态', '驳回理由']);
    
    // 6. 写入数据
    foreach ($rows as $row) {
        $status_map = ['pending'=>'审核中', 'approved'=>'已通过', 'rejected'=>'已驳回'];
        fputcsv($fp, [
            $row['realname'],
            $row['company'],
            $row['expense_date'],
            $row['category'],
            $row['type'],
            $row['amount'],
            $row['invoice_amount'],
            $row['is_substitute'] ? '是' : '否',
            $row['note'],
            $status_map[$row['status']] ?? $row['status'],
            $row['reject_reason']
        ]);
    }
    
    fclose($fp);
    exit;
}

// ==========================================
//  功能 B: 下载附件包 (Zip)
// ==========================================
if ($type == 'zip') {
    // 确定要打包的源目录
    if ($user_id) {
        // 单人：uploads/Batch_X/张三
        $source_dir = __DIR__ . "/uploads/Batch_{$batch_id}/{$user_name}";
        $zip_filename = "附件_{$batch_name}_{$user_name}.zip";
    } else {
        // 全体：uploads/Batch_X
        $source_dir = __DIR__ . "/uploads/Batch_{$batch_id}";
        $zip_filename = "附件汇总_{$batch_name}.zip";
    }

    if (!is_dir($source_dir)) {
        die("没有找到可下载的附件文件 (目录不存在)");
    }

    // 初始化 Zip
    $zip = new ZipArchive();
    $tmp_file = tempnam(sys_get_temp_dir(), 'zip');
    
    if ($zip->open($tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        die("无法创建 ZIP 文件");
    }

    // 递归添加文件
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            // 计算相对路径，保持文件夹结构
            // 如果是单人下载，包里直接是 "海科科技/..."
            // 如果是全体下载，包里是 "张三/海科科技/..."
            $relativePath = substr($filePath, strlen(realpath($source_dir)) + 1);
            $zip->addFile($filePath, $relativePath);
        }
    }

    $zip->close();

    // 发送文件给浏览器
    if (file_exists($tmp_file)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($tmp_file));
        readfile($tmp_file);
        unlink($tmp_file); // 删除临时文件
    } else {
        die("压缩文件生成失败，可能是没有文件可供下载");
    }
    exit;
}
?>