<?php
// config.php
session_start();
date_default_timezone_set('PRC');

$db_dir = __DIR__ . '/db';
$db_file = $db_dir . '/reimburse.db';

if (!file_exists($db_dir)) mkdir($db_dir, 0777, true);

try {
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 表结构初始化
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE,
        password TEXT,
        realname TEXT,
        role TEXT DEFAULT 'user' 
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS batches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        status TEXT DEFAULT 'open', 
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        batch_id INTEGER,
        company TEXT,         -- 新增: 公司主体
        category TEXT,        -- 新增: 报销大类(差旅/费用)
        expense_date DATE,
        amount REAL,
        invoice_amount REAL,
        type TEXT,            -- 子类(飞机票/招待费等)
        note TEXT,
        is_substitute INTEGER DEFAULT 0,
        invoice_path TEXT,    -- 现改为存 JSON 字符串
        support_path TEXT,    -- 现改为存 JSON 字符串
        status TEXT DEFAULT 'pending',
        reject_reason TEXT
    )");

    // --- 自动升级字段 (静默执行) ---
    $cols = $pdo->query("PRAGMA table_info(items)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('company', $cols)) $pdo->exec("ALTER TABLE items ADD COLUMN company TEXT DEFAULT '海科科技'");
    if (!in_array('category', $cols)) $pdo->exec("ALTER TABLE items ADD COLUMN category TEXT DEFAULT '费用报销'");
    
    // V2.0 新增字段
    if (!in_array('project_name', $cols)) $pdo->exec("ALTER TABLE items ADD COLUMN project_name TEXT DEFAULT ''");
    if (!in_array('travel_reason', $cols)) $pdo->exec("ALTER TABLE items ADD COLUMN travel_reason TEXT DEFAULT ''");
    if (!in_array('travelers', $cols)) $pdo->exec("ALTER TABLE items ADD COLUMN travelers TEXT DEFAULT ''");
    if (!in_array('travel_start', $cols)) $pdo->exec("ALTER TABLE items ADD COLUMN travel_start DATE");
    if (!in_array('travel_end', $cols)) $pdo->exec("ALTER TABLE items ADD COLUMN travel_end DATE");
    if (!in_array('travel_days', $cols)) $pdo->exec("ALTER TABLE items ADD COLUMN travel_days REAL DEFAULT 0");

    // 用户表升级
    $u_cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('department', $u_cols)) $pdo->exec("ALTER TABLE users ADD COLUMN department TEXT DEFAULT ''");
    if (!in_array('bank_account', $u_cols)) $pdo->exec("ALTER TABLE users ADD COLUMN bank_account TEXT DEFAULT ''");
    
    // 初始化用户
    $stmt = $pdo->query("SELECT count(*) FROM users WHERE username='admin'");
    if ($stmt->fetchColumn() == 0) {
        $default_pass = password_hash('123456', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, password, realname, role) VALUES ('admin', '$default_pass', '管理员', 'admin')");
        $pdo->exec("INSERT INTO users (username, password, realname, role) VALUES ('user01', '$default_pass', '测试员', 'user')");
    }

} catch (PDOException $e) {
    die("DB Error: " . $e->getMessage());
}

// --- 新增：加载系统配置 (名称 & Logo) ---
$settings_file = __DIR__ . '/db/settings.json';
// 默认配置
$sys_config = [
    'name' => '企业报销管理系统', 
    'logo' => ''
];

if (file_exists($settings_file)) {
    $loaded = json_decode(file_get_contents($settings_file), true);
    if (is_array($loaded)) {
        $sys_config = array_merge($sys_config, $loaded);
    }
}

// --- 新增：打印模板表 ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS print_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,              -- 模板名称
        type TEXT,              -- 适用类型 (费用报销单 / 差旅费报销单)
        bg_image TEXT,          -- 背景图路径
        config_json TEXT,       -- 坐标配置
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
// --- 自动升级字段 (print_templates 表补丁) ---
    $pt_cols = $pdo->query("PRAGMA table_info(print_templates)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('is_default', $pt_cols)) {
        $pdo->exec("ALTER TABLE print_templates ADD COLUMN is_default INTEGER DEFAULT 0");
    }
    if (!in_array('calibration_scale', $pt_cols)) {
        // 默认值为 1.0
        $pdo->exec("ALTER TABLE print_templates ADD COLUMN calibration_scale REAL DEFAULT 1.0");
    }
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// --- 新增：数字转中文大写 (财务专用) ---
function num2rmb($number) {
    $c1 = "零壹贰叁肆伍陆柒捌玖";
    $c2 = "分角元拾佰仟万拾佰仟亿";
    $num = round($number, 2);
    $num = $num * 100;
    if (strlen($num) > 10) return "金额过大";
    $i = 0; $c = "";
    while (1) {
        if ($i == 0) { $n = substr($num, strlen($num)-1, 1); } 
        else { $n = $num % 10; }
        $p1 = substr($c1, 3 * $n, 3);
        $p2 = substr($c2, 3 * $i, 3);
        if ($n != '0' || ($n == '0' && ($p2 == '亿' || $p2 == '万' || $p2 == '元'))) {
            $c = $p1 . $p2 . $c;
        } else {
            $c = $p1 . $c;
        }
        $i = $i + 1;
        $num = $num / 10;
        $num = (int)$num;
        if ($num == 0) break;
    }
    $j = 0; $slen = strlen($c);
    while ($j < $slen) {
        $m = substr($c, $j, 6);
        if ($m == '零元' || $m == '零万' || $m == '零亿' || $m == '零零') {
            $left = substr($c, 0, $j);
            $right = substr($c, $j + 3);
            $c = $left . $right;
            $j = $j - 3; $slen = $slen - 3;
        }
        $j = $j + 3;
    }
    if (substr($c, strlen($c)-3, 3) == '零') {
        $c = substr($c, 0, strlen($c)-3);
    }
    if (empty($c)) return "零元整";
    return $c . "整";
}

?>