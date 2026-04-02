<?php
// fetch_email.php - 智能抓取邮箱发票附件与链接 (防超时 + 智能优先排序版)
require_once 'config.php';

// ✨ 核心防线：将脚本最大执行时间延长到 3 分钟，防止多封复杂邮件导致闪退
set_time_limit(180); 

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => '请先登录']);
    exit;
}

if (!function_exists('imap_open')) {
    echo json_encode(['error' => '服务器未开启 IMAP 扩展']);
    exit;
}

// 开启调试日志
$debug_file = __DIR__ . '/debug_email.txt';
file_put_contents($debug_file, "=== 邮件抓取 Debug 日志 (" . date('Y-m-d H:i:s') . ") ===\n\n");
function write_debug($msg) {
    global $debug_file;
    file_put_contents($debug_file, $msg . "\n", FILE_APPEND);
}

function decodeMimeStr($string) {
    if (!$string) return '';
    $elements = imap_mime_header_decode($string);
    $decoded = '';
    foreach ($elements as $el) {
        $text = $el->text;
        if ($el->charset != 'default' && $el->charset != 'UTF-8') {
            $text = @mb_convert_encoding($text, 'UTF-8', $el->charset);
        }
        $decoded .= $text;
    }
    return $decoded;
}

function getHtmlBody($imap, $mailNum, $structure, $partNum = "") {
    $html = "";
    if ($structure->type == 1 && isset($structure->parts)) {
        foreach ($structure->parts as $index => $subpart) {
            $prefix = empty($partNum) ? ($index + 1) : $partNum . "." . ($index + 1);
            $html .= getHtmlBody($imap, $mailNum, $subpart, $prefix);
        }
    } else {
        $body = imap_fetchbody($imap, $mailNum, empty($partNum) ? "1" : $partNum, FT_PEEK);
        if ($structure->encoding == 3) $body = base64_decode($body);
        elseif ($structure->encoding == 4) $body = quoted_printable_decode($body);
        $html .= $body;
    }
    return $html;
}

function scrapeInvoiceFromUrl($url, $temp_dir) {
    $files = [];
    write_debug("  [cURL 请求] 目标URL: " . $url);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $content = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if (!$content) return $files;

    // A: PDF 直链
    if (strpos(strtolower($content_type), 'pdf') !== false || substr($content, 0, 4) === '%PDF') {
        write_debug("  [嗅探成功] 确认为 PDF 直链！");
        $fname = time() . '_' . rand(1000,9999) . '.pdf';
        file_put_contents($temp_dir . '/' . $fname, $content);
        
        // ✨ 优化：从链接中提取真实的京东发票名，方便前端识别
        $parsed_path = parse_url($url, PHP_URL_PATH);
        $real_name = basename($parsed_path);
        if (empty($real_name) || !preg_match('/\.pdf$/i', $real_name)) {
            $real_name = '智能抓取发票_' . time() . '.pdf';
        } else {
            $real_name = urldecode($real_name);
        }
        
        return [['name' => $real_name, 'path' => $temp_dir . '/' . $fname]];
    }

    // B: ZIP 压缩包
    if (strpos(strtolower($content_type), 'zip') !== false || substr($content, 0, 4) === "PK\x03\x04") {
        write_debug("  [嗅探成功] 确认为 ZIP，开始拆包...");
        $zip_path = $temp_dir . '/' . time() . '_' . rand(1000,9999) . '.zip';
        file_put_contents($zip_path, $content);
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive;
            if ($zip->open($zip_path) === TRUE) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                        $fname = time() . '_' . rand(1000,9999) . '.' . $ext;
                        file_put_contents($temp_dir . '/' . $fname, $zip->getFromIndex($i));
                        $files[] = ['name' => basename($name), 'path' => $temp_dir . '/' . $fname];
                    }
                }
                $zip->close();
            }
        }
        @unlink($zip_path);
        return $files;
    }

    // C: 落地页跳转
    if (strpos(strtolower($content_type), 'html') !== false) {
        $redirect_url = '';
        if (preg_match('/<meta[^>]+http-equiv=["\']?refresh["\']?[^>]+content=["\']?\d+;\s*url=([^"\'>]+)["\']?/i', $content, $m)) {
            $redirect_url = html_entity_decode($m[1]);
        } elseif (preg_match('/location\.(?:replace|href)\s*\(?\s*[\'"]([^\'"]+)[\'"]/i', $content, $m)) {
            $redirect_url = $m[1];
        }

        if ($redirect_url) {
            if (strpos($redirect_url, 'http') !== 0) {
                $parsed = parse_url($url);
                $redirect_url = $parsed['scheme'] . '://' . $parsed['host'] . (strpos($redirect_url, '/') === 0 ? '' : '/') . $redirect_url;
            }
            return scrapeInvoiceFromUrl($redirect_url, $temp_dir);
        }

        if (preg_match('/href=["\']([^"\']+(?:\.pdf|downloadUrl=|downloadMailInvoice|invoiceInfo\/download)[^"\']*)["\']/i', $content, $m) || 
            preg_match('/(https?:\/\/[a-zA-Z0-9\-\.]+\/[^"\'\s>]+(?:downloadUrl=|downloadMailInvoice|invoiceInfo\/download)[^"\'\s>]+)/i', $content, $m)) {
            $next_url = html_entity_decode($m[1]);
            if (strpos($next_url, 'http') !== 0) {
                $parsed = parse_url($url);
                $next_url = $parsed['scheme'] . '://' . $parsed['host'] . (strpos($next_url, '/') === 0 ? '' : '/') . $next_url;
            }
            
            $ch2 = curl_init($next_url);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch2, CURLOPT_USERAGENT, 'Mozilla/5.0');
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
            $pdf_content = curl_exec($ch2);
            curl_close($ch2);
            
            if (substr($pdf_content, 0, 4) === '%PDF') {
                $fname = time() . '_' . rand(1000,9999) . '.pdf';
                file_put_contents($temp_dir . '/' . $fname, $pdf_content);
                $files[] = ['name' => 'parsed_invoice.pdf', 'path' => $temp_dir . '/' . $fname];
            }
        }
    }
    return $files;
}

function getAttachments($imap, $mailNum, $part, $partNum) {
    $attachments = [];
    $is_attachment = false;
    $filename = '';
    if ($part->ifdparameters) foreach ($part->dparameters as $o) if (strtolower($o->attribute) == 'filename') { $is_attachment = true; $filename = $o->value; }
    if ($part->ifparameters) foreach ($part->parameters as $o) if (strtolower($o->attribute) == 'name') { $is_attachment = true; $filename = $o->value; }

    if ($is_attachment && $filename) {
        $filename = decodeMimeStr($filename);
        $data = imap_fetchbody($imap, $mailNum, $partNum, FT_PEEK);
        if ($part->encoding == 3) $data = base64_decode($data);
        elseif ($part->encoding == 4) $data = quoted_printable_decode($data);
        $attachments[] = ['filename' => $filename, 'data' => $data];
    }
    if (isset($part->parts)) {
        foreach ($part->parts as $key => $subpart) {
            $subPartNum = ($partNum == "") ? ($key + 1) : $partNum . "." . ($key + 1);
            $attachments = array_merge($attachments, getAttachments($imap, $mailNum, $subpart, $subPartNum));
        }
    }
    return $attachments;
}

// ==========================================
// 🚀 主程序开始
// ==========================================
$stmt = $pdo->prepare("SELECT imap_server, imap_user, imap_pass FROM users WHERE id=?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (empty($user['imap_server']) || empty($user['imap_user']) || empty($user['imap_pass'])) {
    echo json_encode(['error' => '请先配置邮箱']); exit;
}

$server = trim($user['imap_server']);
$username = trim($user['imap_user']);
$password = base64_decode($user['imap_pass']);
$mailbox = "{".$server.":993/imap/ssl}INBOX";
$inbox = @imap_open($mailbox, $username, $password);

if (!$inbox) { echo json_encode(['error' => '邮箱连接失败']); exit; }

$date = date("j-M-Y", strtotime("-35 days"));
$emails = imap_search($inbox, 'SINCE "'.$date.'"');

if (!$emails) { echo json_encode(['success' => true, 'files' => []]); exit; }

$temp_dir = 'uploads/temp_email_' . $_SESSION['user_id'];
if (!is_dir($temp_dir)) @mkdir($temp_dir, 0777, true);
$old_files = glob($temp_dir . '/*');
foreach($old_files as $f){ if(is_file($f)) @unlink($f); }
$downloaded_files = [];

foreach ($emails as $email_number) {
    $overview = imap_fetch_overview($inbox, $email_number, 0);
    $uid = $overview[0]->uid;
    
    $stmt_chk = $pdo->prepare("SELECT id FROM email_logs WHERE user_id=? AND uid=?");
    $stmt_chk->execute([$_SESSION['user_id'], $uid]);
    if ($stmt_chk->fetch()) continue; 

    $subject = decodeMimeStr($overview[0]->subject ?? '');
    $from = decodeMimeStr($overview[0]->from ?? '');
    
    write_debug("\n✉️ [检查邮件] UID: {$uid} | 发件人: {$from}");
    $is_invoice_mail = preg_match('/(发票|行程单|客票|电子票|jd\.com|jdcloud|nuonuo|baiwang|jss\.com\.cn|taobao|sendcloud|滴滴|aliyun)/i', $subject . $from);

    $structure = imap_fetchstructure($inbox, $email_number);
    $attachments = [];
    if (isset($structure->parts)) {
        foreach ($structure->parts as $key => $subpart) {
            $attachments = array_merge($attachments, getAttachments($inbox, $email_number, $subpart, $key + 1));
        }
    }

    $has_valid = false;
    foreach ($attachments as $at) {
        $ext = strtolower(pathinfo($at['filename'], PATHINFO_EXTENSION));
        if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
            $safe_name = time() . '_' . rand(1000,9999) . '.' . $ext;
            file_put_contents($temp_dir . '/' . $safe_name, $at['data']);
            $downloaded_files[] = ['url' => $temp_dir . '/' . $safe_name, 'name' => $at['filename']];
            $has_valid = true;
        }
    }

    if (!$has_valid && $is_invoice_mail) {
        $body = getHtmlBody($inbox, $email_number, $structure);
        $body = str_replace(["\r", "\n", "=\r\n", "=\n"], "", $body);
        
        $target_urls = [];
        if (preg_match_all('/href=["\']?(https?:\/\/[a-zA-Z0-9\-\.]*(?:jdcloud-oss|taobao|sendcloud|jss\.com\.cn|nnfp|baiwang|nuonuo|jd\.com|aliyun|xiaojukeji)[^\s"\'>]+)/i', $body, $m)) { $target_urls = array_merge($target_urls, $m[1]); }
        if (preg_match_all('/<a[^>]+href=["\']?(https?:\/\/[^\s"\'>]+)["\']?[^>]*>([^<]*(?:发票|下载|PDF|版式)[^<]*)<\/a>/iu', $body, $m)) { $target_urls = array_merge($target_urls, $m[1]); }
        if (preg_match_all('/(https?:\/\/[^\s"\'<>]+(?:\.pdf|\.PDF)[^\s"\'<>]*)/i', $body, $m)) { $target_urls = array_merge($target_urls, $m[1]); }
        
        $target_urls = array_unique($target_urls);
        
        // ✨ 核心优化：智能权重排序！优先抓取极大概率是发票的链接，跳过无用跳转
        usort($target_urls, function($a, $b) {
            $scoreA = 0; $scoreB = 0;
            // 提分项：直击灵魂的特征
            if (stripos($a, '.pdf') !== false) $scoreA += 100;
            if (stripos($a, 'jdcloud-oss') !== false) $scoreA += 80;
            if (stripos($a, 'taobao') !== false) $scoreA += 80;
            if (stripos($a, 'download') !== false) $scoreA += 50;
            
            if (stripos($b, '.pdf') !== false) $scoreB += 100;
            if (stripos($b, 'jdcloud-oss') !== false) $scoreB += 80;
            if (stripos($b, 'taobao') !== false) $scoreB += 80;
            if (stripos($b, 'download') !== false) $scoreB += 50;
            
            // 降分项：一眼假的花里胡哨追踪链接
            if (preg_match('/(jump|transfer|help|about|vip|m\.jd\.com)/i', $a)) $scoreA -= 100;
            if (preg_match('/(jump|transfer|help|about|vip|m\.jd\.com)/i', $b)) $scoreB -= 100;
            
            return $scoreB <=> $scoreA; // 降序，分高（发票）在前
        });
        
        foreach ($target_urls as $url) {
            $url = html_entity_decode($url);
            $url = str_replace('&amp;', '&', $url);
            
            if (preg_match('/\.(gif|png|jpg|css|js|ico)(?:\?.*)?$/i', $url)) continue;
            
            $scraped = scrapeInvoiceFromUrl($url, $temp_dir);
            foreach ($scraped as $sf) {
                $downloaded_files[] = ['url' => $sf['path'], 'name' => $sf['name']];
                $has_valid = true;
            }
            if ($has_valid) break; // 第一个就命中了 PDF，后续 14 个垃圾链接直接跳过！
        }
    }

    if ($has_valid) {
        imap_setflag_full($inbox, $uid, "\\Seen", ST_UID);
    }
    $stmt_log = $pdo->prepare("INSERT INTO email_logs (user_id, uid) VALUES (?, ?)");
    $stmt_log->execute([$_SESSION['user_id'], $uid]);
}

imap_close($inbox);
write_debug("\n=== 抓取结束。共提取有效发票数: " . count($downloaded_files) . " ===");
echo json_encode(['success' => true, 'files' => $downloaded_files]);
?>