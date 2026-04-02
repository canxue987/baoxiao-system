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
    // 新增：让报销明细记住对应的记账本 ID
    if (!in_array('bookkeeping_id', $cols)) $pdo->exec("ALTER TABLE items ADD COLUMN bookkeeping_id INTEGER DEFAULT 0");

    // 用户表升级
    $u_cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('department', $u_cols)) $pdo->exec("ALTER TABLE users ADD COLUMN department TEXT DEFAULT ''");
    if (!in_array('bank_account', $u_cols)) $pdo->exec("ALTER TABLE users ADD COLUMN bank_account TEXT DEFAULT ''");
    
    // --- 邮箱自动收票字段升级 ---
    if (!in_array('imap_server', $u_cols)) $pdo->exec("ALTER TABLE users ADD COLUMN imap_server TEXT DEFAULT ''");
    if (!in_array('imap_user', $u_cols)) $pdo->exec("ALTER TABLE users ADD COLUMN imap_user TEXT DEFAULT ''");
    if (!in_array('imap_pass', $u_cols)) $pdo->exec("ALTER TABLE users ADD COLUMN imap_pass TEXT DEFAULT ''");
    
    // 创建邮件拉取记录表，防止重复拉取发票
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        uid TEXT,
        processed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

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

// --- 加载系统配置 (名称 & Logo) ---
$settings_file = __DIR__ . '/db/settings.json';
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

// --- 打印模板表 ---
$pdo->exec("CREATE TABLE IF NOT EXISTS print_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT,              
    type TEXT,              
    bg_image TEXT,          
    config_json TEXT,       
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$pt_cols = $pdo->query("PRAGMA table_info(print_templates)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('is_default', $pt_cols)) {
    $pdo->exec("ALTER TABLE print_templates ADD COLUMN is_default INTEGER DEFAULT 0");
}
if (!in_array('calibration_scale', $pt_cols)) {
    $pdo->exec("ALTER TABLE print_templates ADD COLUMN calibration_scale REAL DEFAULT 1.0");
}

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// --- 数字转中文大写 (财务专用) ---
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

// ==========================================
// ✨ 发票表 (invoices) 及 字段自动升级逻辑
// ==========================================
$pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    file_path TEXT,          
    file_type TEXT,          
    amount DECIMAL(10,2),    
    invoice_date DATE,       
    seller_name TEXT,        
    invoice_type TEXT,       
    note TEXT,               
    status TEXT DEFAULT 'unused', 
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// 静默添加新字段 (为了富文本展示)
$inv_cols = $pdo->query("PRAGMA table_info(invoices)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('invoice_number', $inv_cols)) $pdo->exec("ALTER TABLE invoices ADD COLUMN invoice_number TEXT DEFAULT ''"); // 发票号码
if (!in_array('buyer_name', $inv_cols)) $pdo->exec("ALTER TABLE invoices ADD COLUMN buyer_name TEXT DEFAULT ''"); // 购买方
if (!in_array('tax_amount', $inv_cols)) $pdo->exec("ALTER TABLE invoices ADD COLUMN tax_amount DECIMAL(10,2) DEFAULT 0.00"); // 税额
if (!in_array('pre_tax_amount', $inv_cols)) $pdo->exec("ALTER TABLE invoices ADD COLUMN pre_tax_amount DECIMAL(10,2) DEFAULT 0.00"); // 税前金额 (不含税)
if (!in_array('invoice_detail', $inv_cols)) $pdo->exec("ALTER TABLE invoices ADD COLUMN invoice_detail TEXT DEFAULT ''"); // 开票内容 (*餐饮费*餐费等)
if (!in_array('is_reserved', $inv_cols)) $pdo->exec("ALTER TABLE invoices ADD COLUMN is_reserved INTEGER DEFAULT 0"); // 新增: 0=闲置可凑, 1=专属禁凑
if (!in_array('invoice_special_type', $inv_cols)) $pdo->exec("ALTER TABLE invoices ADD COLUMN invoice_special_type TEXT DEFAULT '普票'"); // 专票/普票/电票
// ==========================================
// ✨ 个人记账本表 (bookkeeping)
// ==========================================
$pdo->exec("CREATE TABLE IF NOT EXISTS bookkeeping (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    type TEXT DEFAULT '支出',       -- 类型：'支出'(员工垫付) 或 '借款'(向公司借入备用金)
    amount DECIMAL(10,2) DEFAULT 0.00,
    record_date DATE,             -- 消费/借款日期
    item_name TEXT,               -- 事项明细 (如: 买A4纸、打车费)
    status TEXT DEFAULT '未报销',  -- 状态：'未报销' 或 '已报销' (仅针对支出)
    note TEXT,                    -- 补充备注
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
// ✨ 自动升级：为记账本加入“报销主体”和“所属项目”
$bk_cols = $pdo->query("PRAGMA table_info(bookkeeping)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('company', $bk_cols)) $pdo->exec("ALTER TABLE bookkeeping ADD COLUMN company TEXT DEFAULT '默认公司'");
if (!in_array('project_name', $bk_cols)) $pdo->exec("ALTER TABLE bookkeeping ADD COLUMN project_name TEXT DEFAULT ''");
if (!in_array('wallet_ids', $bk_cols)) $pdo->exec("ALTER TABLE bookkeeping ADD COLUMN wallet_ids TEXT DEFAULT ''"); // 新增：绑定的发票ID集合
?>