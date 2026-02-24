<?php
// export_list.php - 批量导出详细清单 (终极防白屏版)

// 1. 开启错误显示 (调试用)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M'); // 给大一点内存

// 2. 加载配置
require_once 'config.php';

// 3. 智能加载 Composer 库 (防白屏第一道保险)
if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    die("❌ 错误：找不到 vendor/autoload.php 文件。请确保您已上传 Composer 库。");
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// 4. 权限检查
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die("❌ 无权访问");
}

try {
    // 5. 获取模板
    $stmt = $pdo->prepare("SELECT * FROM print_templates WHERE type='批量明细清单' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $tpl = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tpl || !file_exists($tpl['template_file'])) {
        die("❌ 错误：未找到 [批量明细清单] 类型的 Excel 模板。请去【系统设置】上传。");
    }

    // 6. 获取数据
    // 注意：这里使用的是 expense_date (消费日期)
    $sql = "SELECT i.*, u.realname, u.department, u.bank_account 
            FROM items i 
            LEFT JOIN users u ON i.user_id = u.id 
            WHERE i.status != 'rejected' 
            ORDER BY i.expense_date DESC, i.id DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        die("⚠️ 提示：当前没有可导出的有效数据。");
    }

    // 7. 加载模板
    $spreadsheet = IOFactory::load($tpl['template_file']);
    $sheet = $spreadsheet->getActiveSheet();

    // 8. 寻找占位符行
    $tplRowIndex = 0;
    $colMap = []; 

    foreach ($sheet->getRowIterator() as $row) {
        $rowIndex = $row->getRowIndex();
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);
        
        foreach ($cellIterator as $cell) {
            $val = $cell->getValue();
            if (is_string($val) && strpos($val, '${') !== false) {
                $tplRowIndex = $rowIndex;
                // 提取字段名
                $field = str_replace(['${', '}'], '', $val);
                $colMap[$cell->getColumn()] = $field;
            }
        }
        if ($tplRowIndex > 0) break;
    }

    if ($tplRowIndex == 0) {
        die("❌ 模板格式错误：在 Excel 中未找到类似 \${date} 的占位符行。");
    }

    // 9. 插入新行
    $dataCount = count($rows);
    if ($dataCount > 1) {
        $sheet->insertNewRowBefore($tplRowIndex + 1, $dataCount - 1);
    }

    // 10. 填充数据
    $currentRow = $tplRowIndex;
    foreach ($rows as $item) {
        // 计算附件数
        $files = json_decode($item['invoice_path'] ?: '[]');
        $inv_count = is_array($files) ? count($files) : 0;
        
        // 拼接时间
        $travel_str = '';
        if (!empty($item['travel_start']) && !empty($item['travel_end'])) {
            $travel_str = $item['travel_start'] . ' ~ ' . $item['travel_end'];
        }

        // 状态翻译
        $status_str = '未知';
        if ($item['status'] == 'approved') $status_str = '已通过';
        if ($item['status'] == 'pending') $status_str = '审核中';

        // 数据映射
        $map = [
            'id'            => $item['id'],
            'created_at'    => substr($item['created_at'], 0, 10),
            'date'          => $item['expense_date'], // 对应 ${date}
            'realname'      => $item['realname'],
            'department'    => $item['department'],
            'company'       => $item['company'],
            'category'      => $item['category'],
            'type'          => $item['type'],
            'project'       => $item['project_name'],
            'amount'        => $item['amount'],
            'inv_count'     => $inv_count,
            'status'        => $status_str,
            'travel_reason' => $item['travel_reason'],
            'travelers'     => $item['travelers'],
            'travel_time'   => $travel_str,
            'travel_days'   => $item['travel_days'] > 0 ? $item['travel_days'] : '',
            'bank_account'  => $item['bank_account'],
            'note'          => $item['note'],
        ];

        foreach ($colMap as $col => $field) {
            $val = isset($map[$field]) ? $map[$field] : '';
            $sheet->setCellValue($col . $currentRow, $val);
        }
        $currentRow++;
    }

    // ==========================================
    // 关键修复：防白屏/防乱码核心区
    // ==========================================
    
    // A. 清空之前的任何输出 (空格、HTML等)
    if (ob_get_length()) ob_end_clean();
    
    // B. 设置 Header
    $filename = "财务明细总表_" . date('Ymd_His') . ".xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="'.$filename.'"');
    header('Cache-Control: max-age=0');
    
    // C. 写入输出流
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    
    // D. 终止脚本，防止后续代码污染文件
    exit;

} catch (Throwable $e) {
    // 捕获所有严重错误并打印
    die("❌ 程序执行异常: " . $e->getMessage() . "<br>位置: " . $e->getFile() . " 第 " . $e->getLine() . " 行");
}
?>