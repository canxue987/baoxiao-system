<?php
require_once 'config.php';
require 'vendor/autoload.php'; 

use PhpOffice\PhpWord\TemplateProcessor;

if (!isset($_SESSION['user_id'])) die("未登录");

$batch_id = $_GET['batch_id'];
$user_id = $_GET['user_id'];
$type = $_GET['type']; 

// 1. 获取 Word 模板
$stmt = $pdo->prepare("SELECT * FROM print_templates WHERE type=? AND template_file LIKE '%.docx' ORDER BY id DESC LIMIT 1");
$stmt->execute([$type]);
$tpl = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tpl || !file_exists($tpl['template_file'])) {
    die("未找到该类型的 Word (.docx) 模板，请先去后台上传");
}

// 2. 获取数据
$stmt = $pdo->prepare("SELECT i.*, u.realname, u.department, u.bank_account 
                       FROM items i 
                       LEFT JOIN users u ON i.user_id = u.id 
                       WHERE i.batch_id=? AND i.user_id=? AND i.category=? AND i.status!='rejected'");
$stmt->execute([$batch_id, $user_id, $type]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) die("没有数据");

// 3. 核心：计算汇总 & 分类统计
$first = $rows[0];
$total_money = 0;
$total_files = 0;

// 定义我们需要支持的所有潜在费用类型 (防止模板里写了${xx}但数据里没有，导致没被替换)
// 这里的列表要包含您 main.js 里所有的类型，以及您 Word 模板里可能用到的类型
$all_possible_types = [
    "招待费", "办公费", "交通费", "停车费", "过路桥费", "团建费", "福利费",
    "飞机票", "火车票", "车船票", "住宿费", "市内交通", "差旅补贴", "汽油费", "其他"
];

// 初始化统计数组
$type_stats = [];
foreach ($all_possible_types as $t) {
    $type_stats[$t] = ['amount' => 0, 'count' => 0];
}

// 遍历数据进行累计
foreach($rows as $r) {
    $amt = floatval($r['amount']);
    $total_money += $amt;
    
    // 计算单条明细的附件数
    $files = json_decode($r['invoice_path'] ?: '[]');
    $cnt = count($files);
    $total_files += $cnt;
    
    // 分类汇总
    $typeName = $r['type']; // 例如 "招待费"
    
    // 如果是数据库里有但上面列表里没有的新类型，动态补上
    if (!isset($type_stats[$typeName])) {
        $type_stats[$typeName] = ['amount' => 0, 'count' => 0];
    }
    
    $type_stats[$typeName]['amount'] += $amt;
    $type_stats[$typeName]['count'] += $cnt;
}

// 4. 加载模板处理器
$templateProcessor = new TemplateProcessor($tpl['template_file']);

// 5. 替换基础变量
$templateProcessor->setValue('公司', $first['company']);
$templateProcessor->setValue('部门', $first['department']);
$templateProcessor->setValue('姓名', $first['realname']);
$templateProcessor->setValue('账号', $first['bank_account']);
$templateProcessor->setValue('日期', date('Y-m-d'));
$templateProcessor->setValue('总额', number_format($total_money, 2));
$templateProcessor->setValue('大写', num2rmb($total_money)); // 需确保 config.php 有此函数
$templateProcessor->setValue('附件数', $total_files);
$projects = implode(',', array_unique(array_column($rows, 'project_name')));
$templateProcessor->setValue('项目', $projects);

// 差旅字段
$templateProcessor->setValue('出差事由', $first['travel_reason']);
$templateProcessor->setValue('出差人员', $first['travelers']);
$templateProcessor->setValue('开始日期', $first['travel_start']);
$templateProcessor->setValue('结束日期', $first['travel_end']);
$templateProcessor->setValue('出差天数', $first['travel_days']);

// 6. 替换分类汇总变量 (核心改动)
// 循环将 ${招待费_金额}, ${招待费_张数} 等替换为实际数字
foreach ($type_stats as $typeName => $stat) {
    $amtVal = $stat['amount'] > 0 ? number_format($stat['amount'], 2) : ''; // 0元留空
    $cntVal = $stat['count'] > 0 ? $stat['count'] : ''; // 0张留空
    
    $templateProcessor->setValue("{$typeName}_金额", $amtVal);
    $templateProcessor->setValue("{$typeName}_张数", $cntVal);
}

// 7. 保留之前的明细行逻辑 (以防万一模板里还想打印流水账)
// 支持 ${明细1_金额} 到 ${明细10_金额}
for ($i = 0; $i < 10; $i++) {
    $idx = $i + 1;
    if (isset($rows[$i])) {
        $row = $rows[$i];
        $templateProcessor->setValue("明细{$idx}_日期", $row['date']);
        $templateProcessor->setValue("明细{$idx}_金额", $row['amount']);
        $templateProcessor->setValue("明细{$idx}_类别", $row['type']);
        $templateProcessor->setValue("明细{$idx}_备注", $row['note']);
        $templateProcessor->setValue("明细{$idx}_项目", $row['project_name']);
    } else {
        $templateProcessor->setValue("明细{$idx}_日期", '');
        $templateProcessor->setValue("明细{$idx}_金额", '');
        $templateProcessor->setValue("明细{$idx}_类别", '');
        $templateProcessor->setValue("明细{$idx}_备注", '');
        $templateProcessor->setValue("明细{$idx}_项目", '');
    }
}

// 8. 输出下载
$filename = $first['realname'] . "_" . $type . "_" . date('Ymd') . ".docx";

header("Content-Description: File Transfer");
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');

$templateProcessor->saveAs('php://output');
exit;