<?php
// download.php
require 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') die("无权访问");

$batch_id = $_GET['batch_id'];
$user_id = $_GET['user_id'];

// 查名字
$stmt = $pdo->prepare("SELECT realname FROM users WHERE id=?");
$stmt->execute([$user_id]);
$username = $stmt->fetchColumn();

// 查有效记录
$stmt = $pdo->prepare("SELECT company, invoice_path, support_path FROM items WHERE batch_id=? AND user_id=? AND status != 'rejected'");
$stmt->execute([$batch_id, $user_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$zip = new ZipArchive();
$zip_name = "{$username}_附件包.zip";
$temp_zip = tempnam(sys_get_temp_dir(), 'Zip');

if ($zip->open($temp_zip, ZipArchive::CREATE) !== TRUE) die("无法创建ZIP");

foreach ($items as $row) {
    $comp = $row['company']; // 海科科技 or 鹏鹏科技
    
    // 解析文件
    $invs = json_decode($row['invoice_path'] ?: '[]');
    $sups = json_decode($row['support_path'] ?: '[]');
    
    // 存入Zip：公司/发票/文件名
    foreach ($invs as $file) {
        if (file_exists($file)) {
            $parts = explode('/', $file);
            $fname = end($parts);
            $zip->addFile($file, "{$comp}/发票/{$fname}");
        }
    }
    
    // 存入Zip：公司/辅证/文件名
    foreach ($sups as $file) {
        if (file_exists($file)) {
            $parts = explode('/', $file);
            $fname = end($parts);
            $zip->addFile($file, "{$comp}/辅证/{$fname}");
        }
    }
}

$zip->close();
header('Content-Type: application/zip');
header('Content-disposition: attachment; filename='.$zip_name);
header('Content-Length: ' . filesize($temp_zip));
readfile($temp_zip);
unlink($temp_zip);
?>