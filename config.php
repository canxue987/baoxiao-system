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

function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>