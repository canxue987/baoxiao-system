<?php
// ocr.php - 百度云智能路由 (生产模式：增加统计，关闭强显错)
require_once 'config.php';
if (file_exists('vendor/autoload.php')) require 'vendor/autoload.php';

use Smalot\PdfParser\Parser;

// 生产模式：关闭报错输出，防止破坏 JSON 结构
error_reporting(0);

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => '请先登录']);
    exit;
}

// ✨ API 调用计数器
function recordApiUsage($apiName) {
    $usageFile = __DIR__ . '/db/api_usage.json';
    $data = file_exists($usageFile) ? json_decode(file_get_contents($usageFile), true) : [];
    if (!is_array($data)) $data = [];
    $month = date('Y-m');
    if(!isset($data[$month])) $data[$month] = ['vat' => 0, 'receipt' => 0];
    if(!isset($data[$month][$apiName])) $data[$month][$apiName] = 0;
    $data[$month][$apiName]++;
    @file_put_contents($usageFile, json_encode($data, JSON_PRETTY_PRINT));
}

$sys_file = __DIR__ . '/db/sys.json';
$sys_config = ['ocr_provider' => 'local', 'baidu_ak' => '', 'baidu_sk' => ''];
if (file_exists($sys_file)) {
    $loaded = json_decode(file_get_contents($sys_file), true);
    if (is_array($loaded)) $sys_config = array_merge($sys_config, $loaded);
}

$is_baidu = ($sys_config['ocr_provider'] === 'baidu' && !empty($sys_config['baidu_ak']) && !empty($sys_config['baidu_sk']));
$ocr_api_url = 'http://192.168.1.27:8082/api/tr-run/'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => '上传错误码: ' . $file['error']]);
        exit;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    $wallet_root = 'uploads/wallet';
    if (!is_dir(__DIR__ . '/' . $wallet_root)) @mkdir(__DIR__ . '/' . $wallet_root, 0777, true);
    $user_dir_rel = $wallet_root . '/' . $_SESSION['user_id'];
    $user_dir_abs = __DIR__ . '/' . $user_dir_rel;
    if (!is_dir($user_dir_abs)) @mkdir($user_dir_abs, 0777, true);

    $final_name = 'inv_' . date('Ymd_His') . '_' . rand(100,999) . '.' . $ext;
    $rel_path = $user_dir_rel . '/' . $final_name; 
    $abs_path = $user_dir_abs . '/' . $final_name; 

    if (!move_uploaded_file($file['tmp_name'], $abs_path)) {
        echo json_encode(['error' => '文件保存失败']);
        exit;
    }

    $info = [
        'amount' => 0.00, 'date' => date('Y-m-d'), 'type' => '未分类', 'note' => 'OCR自动导入',
        'invoice_number' => '', 'buyer_name' => '', 'seller_name' => '', 
        'tax_amount' => 0.00, 'pre_tax_amount' => 0.00, 'invoice_detail' => '', 'invoice_special_type' => '普票' 
    ];
    $raw_text = "";
    $debug_text = "";
    $use_baidu_structured = false;

    // ==========================================
    // ☁️ 百度云大模型 API 路由
    // ==========================================
    if ($is_baidu) {
        $token_file = __DIR__ . '/db/baidu_token.json';
        $access_token = '';
        if (file_exists($token_file)) {
            $cache = json_decode(file_get_contents($token_file), true);
            if ($cache && isset($cache['expires_in']) && $cache['expires_in'] > time()) {
                $access_token = $cache['access_token'];
            }
        }
        if (!$access_token) {
            $url = "https://aip.baidubce.com/oauth/2.0/token?grant_type=client_credentials&client_id={$sys_config['baidu_ak']}&client_secret={$sys_config['baidu_sk']}";
            $res = @file_get_contents($url);
            $res_json = json_decode($res ?: '[]', true);
            if (isset($res_json['access_token'])) {
                $access_token = $res_json['access_token'];
                $res_json['expires_in'] = time() + $res_json['expires_in'] - 3600; 
                file_put_contents($token_file, json_encode($res_json));
            } else {
                echo json_encode(['error' => '百度API认证失败，请检查 AK/SK 设置']);
                exit;
            }
        }

        $b64 = base64_encode(file_get_contents($abs_path));
        $post_data = ($ext === 'pdf') ? ['pdf_file' => $b64] : ['image' => $b64];
        $post_str = http_build_query($post_data);
        $opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $post_str, 'timeout' => 30]];
        $context = stream_context_create($opts);

        // 📍 路由 1: 增值税发票识别
        $vat_url = "https://aip.baidubce.com/rest/2.0/ocr/v1/vat_invoice?access_token={$access_token}";
        $vat_res = @file_get_contents($vat_url, false, $context);
        recordApiUsage('vat'); // 记录增值税接口调用次数

        $vat_json = json_decode($vat_res ?: '[]', true);

        if (isset($vat_json['words_result']) && !empty($vat_json['words_result']['TotalAmount'])) {
            $use_baidu_structured = true;
            $wr = $vat_json['words_result'];
            
            $info['invoice_number'] = $wr['InvoiceNum'] ?? '';
            $info['buyer_name'] = $wr['PurchaserName'] ?? '';
            $info['seller_name'] = $wr['SellerName'] ?? '';
            $info['amount'] = $wr['AmountInFiguers'] ?? '0.00';
            $info['tax_amount'] = $wr['TotalTax'] ?? '0.00';
            $info['pre_tax_amount'] = $wr['TotalAmount'] ?? '0.00';
            
            $info['invoice_special_type'] = $wr['InvoiceType'] ?? '普票';
            if (strpos($info['invoice_special_type'], '专用') !== false) $info['invoice_special_type'] = '专票';
            elseif (strpos($info['invoice_special_type'], '普通') !== false) $info['invoice_special_type'] = '普票';

            $date_raw = $wr['InvoiceDate'] ?? '';
            $date_clean = preg_replace('/[^\d]/', '', $date_raw); 
            if (strlen($date_clean) >= 8) {
                $info['date'] = substr($date_clean, 0, 4) . '-' . substr($date_clean, 4, 2) . '-' . substr($date_clean, 6, 2);
            }

            $details = [];
            if (!empty($wr['CommodityName'])) {
                foreach ($wr['CommodityName'] as $c) {
                    if (!empty($c['word'])) $details[] = $c['word'];
                }
            }
            $info['invoice_detail'] = implode('; ', $details);
            $debug_text = "【百度 API - 增值税发票】\n" . json_encode($wr, JSON_UNESCAPED_UNICODE);
            
        } else {
            // 📍 路由 2: 通用票据降级
            $receipt_url = "https://aip.baidubce.com/rest/2.0/ocr/v1/receipt?access_token={$access_token}";
            $receipt_res = @file_get_contents($receipt_url, false, $context);
            recordApiUsage('receipt'); // 记录通用票据接口调用次数

            $receipt_json = json_decode($receipt_res ?: '[]', true);
            
            if (isset($receipt_json['words_result'])) {
                $lines = [];
                array_walk_recursive($receipt_json['words_result'], function($val) use (&$lines) {
                    if (is_string($val) && strlen(trim($val)) > 0) {
                        $lines[] = str_replace([" ", "\t"], "", $val);
                    }
                });
                $raw_text = implode("\n", array_unique($lines));
                $debug_text = "【百度 API - 通用票据降级】\n" . $raw_text;
            } else {
                $debug_text = "【百度 API - 识别失败】\n" . ($receipt_json['error_msg'] ?? '未知错误');
            }
        }
    } else {
        // 本地引擎
        try {
            if ($ext === 'pdf' && class_exists('Smalot\PdfParser\Parser')) {
                $parser = new Parser();
                $pdf = $parser->parseFile($abs_path);
                $raw_text = str_replace(["\t"], " ", $pdf->getText()); 
            }
            if (empty($raw_text)) {
                if ($ext === 'pdf') throw new Exception("不支持扫描版 PDF");
                $ch = curl_init();
                $cfile = new CURLFile($abs_path, $file['type'], $file['name']);
                curl_setopt($ch, CURLOPT_URL, $ocr_api_url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $cfile, 'compress' => '0']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                $res = json_decode(curl_exec($ch), true);
                curl_close($ch);
                if (isset($res['data']['raw_out'])) {
                    $clean_lines = [];
                    foreach (array_column($res['data']['raw_out'], 1) as $line) {
                        $clean_lines[] = str_replace(" ", "", $line); 
                    }
                    $raw_text = implode("\n", $clean_lines);
                }
            }
            $debug_text = "【本地 OCR】\n" . $raw_text;
        } catch (Throwable $e) { $debug_text = "本地OCR出错"; }
    }

    // ==========================================
    // 🧠 降级正则与数学碰撞兜底 (针对通用票据/本地)
    // ==========================================
    if (!$use_baidu_structured && !empty($raw_text)) {
        if (preg_match('/(?:发票号码|号码|NO|No)[\s:：]*([0-9]{8,20})/i', $raw_text, $m)) $info['invoice_number'] = $m[1];
        elseif (preg_match('/(?<!\d)(2\d{14,19})(?!\d)/', $raw_text, $m)) $info['invoice_number'] = $m[1];
        elseif (preg_match('/(?<!\d)(\d{8})(?!\d)/', $raw_text, $m)) $info['invoice_number'] = $m[1];

        preg_match_all('/([\x{4e00}-\x{9fa5}A-Za-z()（）\s]{2,40}(?:公司|厂|中心|院|局|集团|所|大学|委员会))/u', $raw_text, $comp_matches);
        $comps = [];
        if (!empty($comp_matches[1])) {
            foreach($comp_matches[1] as $c) {
                $c = str_replace([' ', "\t", "\n", "\r"], '', $c);
                if (strpos($c, '系统') === false && strpos($c, '开票') === false && strpos($c, '发票') === false && strpos($c, '代开') === false) {
                    $comps[] = $c;
                }
            }
            $comps = array_values(array_unique($comps));
        }
        if (count($comps) >= 2) {
            $info['buyer_name'] = $comps[0]; $info['seller_name'] = $comps[1];
        } elseif (count($comps) == 1) {
            $info['buyer_name'] = $comps[0];
        }

        if (preg_match_all('/\*[\x{4e00}-\x{9fa5}]+\*[\x{4e00}-\x{9fa5}a-zA-Z0-9]+/u', $raw_text, $m)) $info['invoice_detail'] = implode('; ', $m[0]);
        $clean_raw = str_replace([' ', "\t", "\n", "\r"], '', $raw_text); 
        if (strpos($clean_raw, '专用发票') !== false || strpos($clean_raw, '专票') !== false) $info['invoice_special_type'] = '专票';
        elseif (strpos($clean_raw, '普通发票') !== false || strpos($clean_raw, '普票') !== false) $info['invoice_special_type'] = '普票';
        elseif (strpos($clean_raw, '通行费') !== false) $info['invoice_special_type'] = '通行费';
        elseif (strpos($clean_raw, '行程单') !== false || strpos($clean_raw, '客票') !== false) $info['invoice_special_type'] = '行程单';
        elseif (strpos($clean_raw, '火车票') !== false) $info['invoice_special_type'] = '火车票';

        preg_match_all('/(?:¥|￥)\s*([0-9]{1,10}(?:,[0-9]{3})*\.[0-9]{2})/', $raw_text, $yuan_matches);
        $y_amounts = [];
        if (!empty($yuan_matches[1])) {
            foreach ($yuan_matches[1] as $a) $y_amounts[] = (float)str_replace(',', '', $a);
            $y_amounts = array_values(array_unique($y_amounts));
            rsort($y_amounts);
        }
        preg_match_all('/(?<!\d)([0-9]{1,10}(?:,[0-9]{3})*\.[0-9]{2})(?!\d)/', $raw_text, $all_matches);
        $all_amounts = [];
        if (!empty($all_matches[1])) {
            foreach ($all_matches[1] as $a) $all_amounts[] = (float)str_replace(',', '', $a);
            $all_amounts = array_values(array_unique($all_amounts));
            rsort($all_amounts);
        }

        $found_math = false;
        $best_total = 0; $best_pre = 0; $best_tax = 0;
        $find_math = function($arr) use (&$best_total, &$best_pre, &$best_tax) {
            if (count($arr) >= 3) {
                for ($i=0; $i<count($arr); $i++) {
                    for ($j=$i+1; $j<count($arr); $j++) {
                        for ($k=$j+1; $k<count($arr); $k++) {
                            if (abs($arr[$i] - ($arr[$j] + $arr[$k])) <= 0.05) {
                                $best_total = $arr[$i]; $best_pre = $arr[$j]; $best_tax = $arr[$k];
                                return true;
                            }
                        }
                    }
                }
            }
            return false;
        };

        if ($find_math($y_amounts) || $find_math($all_amounts)) {
            $info['amount'] = number_format($best_total, 2, '.', '');
            $info['pre_tax_amount'] = number_format($best_pre, 2, '.', '');
            $info['tax_amount'] = number_format($best_tax, 2, '.', '');
        } else {
            if (!empty($y_amounts)) $info['amount'] = number_format($y_amounts[0], 2, '.', ''); 
            elseif (preg_match('/(?:价税合计|小写)[^\d]*([0-9,]+\.[0-9]{2})/', $raw_text, $m)) $info['amount'] = str_replace(',', '', $m[1]);
        }

        if (preg_match('/(20[1-3]\d)\s*年\s*([01]?\d)\s*月\s*([0-3]?\d)\s*日?/', $raw_text, $m)) $info['date'] = sprintf("%04d-%02d-%02d", $m[1], $m[2], $m[3]);
        elseif (preg_match('/(?:开票日期|日期|时间)[\s:：]*(\d{4})[\-\/\.](\d{1,2})[\-\/\.](\d{1,2})/', $raw_text, $m)) $info['date'] = sprintf("%04d-%02d-%02d", $m[1], $m[2], $m[3]);
        elseif (preg_match('/(?:开票日期|日期)[\s:：]*(\d{8})/', $raw_text, $m)) $info['date'] = sprintf("%04d-%02d-%02d", substr($m[1],0,4), substr($m[1],4,2), substr($m[1],6,2));
    }

    $type_test_str = $info['invoice_detail'] . " " . $info['seller_name'];
    if (!$use_baidu_structured) $type_test_str .= " " . $raw_text;
    
    if (strpos($type_test_str, '餐饮') !== false || strpos($type_test_str, '餐') !== false || strpos($type_test_str, '饭') !== false) $info['type'] = '餐饮费';
    elseif (strpos($type_test_str, '通行费') !== false || strpos($type_test_str, '交通') !== false || strpos($type_test_str, '客运') !== false || strpos($type_test_str, '车票') !== false) $info['type'] = '交通费';
    elseif (strpos($type_test_str, '住宿') !== false || strpos($type_test_str, '酒店') !== false || strpos($type_test_str, '客房') !== false) $info['type'] = '住宿费';
    elseif (strpos($type_test_str, '油') !== false && strpos($type_test_str, '号') !== false) $info['type'] = '加油费';
    elseif (strpos($type_test_str, '办公') !== false || strpos($type_test_str, '耗材') !== false) $info['type'] = '办公用品';
    // ==========================================
    // ✨ 新增：发票去重检测逻辑
    // ==========================================
    if (!empty($info['invoice_number'])) {
        // 如果提取到了发票号码，直接检测号码是否在当前用户票夹中存在
        $check = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number=? AND user_id=?");
        $check->execute([$info['invoice_number'], $_SESSION['user_id']]);
        if ($check->fetch()) {
            @unlink($abs_path); // 物理删除刚才上传的临时图片
            echo json_encode(['error' => "发票号码 [{$info['invoice_number']}] 已存在，请勿重复上传"]);
            exit;
        }
    } else {
        // 如果是无编号的小票(如餐饮小票、部分通行费)，通过“金额 + 日期 + 销方”联合查重
        $check = $pdo->prepare("SELECT id FROM invoices WHERE amount=? AND invoice_date=? AND seller_name=? AND user_id=?");
        $check->execute([$info['amount'], $info['date'], $info['seller_name'], $_SESSION['user_id']]);
        if ($check->fetch()) {
            @unlink($abs_path); // 物理删除
            echo json_encode(['error' => "发现相同金额({$info['amount']})、日期和商户的票据，疑似重复"]);
            exit;
        }
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO invoices (
            user_id, file_path, file_type, amount, invoice_date, invoice_type, note, status,
            invoice_number, buyer_name, seller_name, tax_amount, pre_tax_amount, invoice_detail, invoice_special_type
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'unused', ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $_SESSION['user_id'], $rel_path, $ext, $info['amount'], $info['date'], $info['type'], $info['note'],
            $info['invoice_number'], $info['buyer_name'], $info['seller_name'], $info['tax_amount'], 
            $info['pre_tax_amount'], $info['invoice_detail'], $info['invoice_special_type']
        ]);
        
        echo json_encode(['success' => true, 'extracted_data' => $info, 'debug_text' => $debug_text]);
    } catch (Exception $e) {
        echo json_encode(['error' => '入库失败: ' . $e->getMessage()]);
    }

} else {
    echo json_encode(['error' => '无效请求']);
}
?>