<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) die("未登录");

$batch_id = $_GET['batch_id'];
$user_id = $_GET['user_id'];
$type = $_GET['type']; 

// --- AJAX保存接口 ---
if (isset($_POST['action']) && $_POST['action'] == 'save_scale') {
    if ($_SESSION['role'] != 'admin') die("无权操作");
    $tpl_id = $_POST['tpl_id'];
    $scale = floatval($_POST['scale']);
    // 更新数据库
    $stmt = $pdo->prepare("UPDATE print_templates SET calibration_scale=? WHERE id=?");
    $stmt->execute([$scale, $tpl_id]);
    echo "ok";
    exit;
}

// 1. 获取模板
$stmt = $pdo->prepare("SELECT * FROM print_templates WHERE type=? ORDER BY is_default DESC, id DESC LIMIT 1");
$stmt->execute([$type]);
$tpl = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tpl) die("未找到类型为 [{$type}] 的打印模板。");

$config = json_decode($tpl['config_json'], true);

// 获取数据库存的默认系数 (如果没有则为 1.0)
$db_scale = floatval($tpl['calibration_scale'] ?: 1.0);

// 动态计算图片宽高比
$img_path = $tpl['bg_image'];
$page_width_mm = 210; 
$page_height_mm = 148; 

if (file_exists($img_path)) {
    list($w, $h) = getimagesize($img_path);
    if ($w > 0) {
        $ratio = $h / $w; 
        $page_height_mm = $page_width_mm * $ratio;
    }
}

// 2. 获取数据 
$stmt = $pdo->prepare("SELECT i.*, u.realname, u.department, u.bank_account 
                       FROM items i 
                       LEFT JOIN users u ON i.user_id = u.id 
                       WHERE i.batch_id=? AND i.user_id=? AND i.category=? AND i.status!='rejected'");
$stmt->execute([$batch_id, $user_id, $type]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) die("没有找到该类型的数据");

// 3. 数据聚合
$data = [];
$first = $rows[0];

// 基础信息
$data['{公司主体}'] = $first['company'];
$data['{报销部门}'] = $first['department'];
$data['{报销人姓名}'] = $first['realname'];
$data['{报销账号}'] = $first['bank_account'];
$data['{填报日期}'] = date('Y-m-d');
$projects = array_unique(array_column($rows, 'project_name'));
$data['{所属项目}'] = implode(',', array_filter($projects));

// 差旅专属
$data['{出差事由}'] = $first['travel_reason'];
$data['{出差人员}'] = $first['travelers'];
$data['{开始日期}'] = $first['travel_start'];
$data['{结束日期}'] = $first['travel_end'];
$data['{出差天数}'] = $first['travel_days'];

// 统计
$total_money = 0;
$total_files = 0;
$type_stats = []; 

foreach ($rows as $r) {
    $amt = $r['amount'];
    $total_money += $amt;
    $invs = json_decode($r['invoice_path'] ?: '[]');
    $count = count($invs);
    $total_files += $count;
    $t = $r['type'];
    if (!isset($type_stats[$t])) $type_stats[$t] = ['amt' => 0, 'cnt' => 0];
    $type_stats[$t]['amt'] += $amt;
    $type_stats[$t]['cnt'] += $count;
}

if (!function_exists('num2rmb')) { function num2rmb($number) { return "请更新config"; } }

$data['{报销总额_小写}'] = number_format($total_money, 2);
$data['{报销总额_大写}'] = num2rmb($total_money);
$data['{附件总张数}'] = $total_files;

foreach ($type_stats as $typeName => $stat) {
    $data["{{$typeName}_金额}"] = number_format($stat['amt'], 2);
    $data["{{$typeName}_张数}"] = $stat['cnt'] > 0 ? $stat['cnt'] : ''; 
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>打印报销单</title>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@2.5.0/fonts/remixicon.css" rel="stylesheet">
    <style>
        body { margin: 0; padding: 0; background: #555; font-family: sans-serif; }
        .page {
            width: <?php echo $page_width_mm; ?>mm;
            height: <?php echo $page_height_mm; ?>mm;
            background: #fff;
            margin: 20px auto;
            position: relative;
            box-shadow: 0 0 15px rgba(0,0,0,0.5);
            overflow: hidden;
        }
        .bg-img { width: 100%; height: 100%; position: absolute; z-index: 0; }
        .data-text {
            position: absolute;
            z-index: 1;
            font-family: "SimHei", "Microsoft YaHei", sans-serif; 
            color: #000;
            white-space: nowrap;
            font-weight: bold; 
            padding: 4px 5px 2px 5px; 
            line-height: 1.0;
            transform-origin: top left;
            transition: top 0.1s; /* 让调整过程有丝滑动画 */
        }
        /* 悬浮工具栏 */
        .toolbar {
            position: fixed; top: 20px; right: 20px; z-index: 999;
            display: flex; gap: 10px;
        }
        .btn-float {
            background: #fff; color: #333; border: none; 
            padding: 10px 15px; border-radius: 50px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.2); cursor: pointer;
            font-size: 14px; font-weight: bold; display: flex; align-items: center; gap: 5px;
            transition: transform 0.2s;
        }
        .btn-float:hover { transform: translateY(-2px); }
        .btn-primary { background: #1890ff; color: #fff; }
        
        /* 校准面板 */
        .calibration-panel {
            display: none; 
            position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%);
            background: rgba(0,0,0,0.85); color: #fff; padding: 20px;
            border-radius: 12px; z-index: 999; text-align: center;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            backdrop-filter: blur(10px);
            min-width: 320px;
        }
        .cal-input {
            background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);
            color: #fff; padding: 5px 10px; border-radius: 4px; width: 80px; text-align: center;
            font-size: 16px; font-weight: bold;
        }
        .cal-btn {
            background: rgba(255,255,255,0.1); border: none; color: #fff;
            width: 30px; height: 30px; border-radius: 4px; cursor: pointer;
            font-weight: bold; font-size: 16px;
        }
        .cal-btn:hover { background: rgba(255,255,255,0.3); }

        @media print {
            body { background: #fff; margin: 0; }
            .page { margin: 0; box-shadow: none; page-break-after: always; }
            .toolbar, .calibration-panel { display: none !important; }
        }
    </style>
</head>
<body>

<div class="page">
    <img src="<?php echo h($tpl['bg_image']); ?>" class="bg-img">
    
    <?php foreach($config as $item): ?>
        <?php 
            $key = $item['key'];
            $val = $data[$key] ?? ''; 
            if ($val === '') continue;
            // 注意：这里 PHP 只输出原始坐标 data-origin-y，不进行计算
            // 具体的计算交给下面的 JS 实时处理
        ?>
        <div class="data-text" 
             data-origin-y="<?php echo $item['y']; ?>" 
             style="left: <?php echo $item['x']; ?>%; top: <?php echo $item['y']; ?>%; font-size: <?php echo $item['size']; ?>px;">
            <?php echo h($val); ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="toolbar">
    <button class="btn-float" onclick="toggleCalibration()">
        <i class="ri-settings-3-line"></i> 位置校准
    </button>
    <button class="btn-float btn-primary" onclick="window.print()">
        <i class="ri-printer-line"></i> 立即打印
    </button>
</div>

<div class="calibration-panel" id="calPanel">
    <div style="margin-bottom:15px; font-size:14px; color:#ccc; display:flex; justify-content:space-between; align-items:center;">
        <span>📐 打印位置校准 (纵向)</span>
        <i class="ri-close-line" onclick="toggleCalibration()" style="cursor:pointer; font-size:18px;"></i>
    </div>
    
    <div style="display:flex; gap:10px; justify-content:center; align-items:center; margin-bottom:20px;">
        <button class="cal-btn" onclick="adjust(-0.01)">-</button>
        <input type="number" id="scaleInput" class="cal-input" step="0.01" value="<?php echo $db_scale; ?>" oninput="applyScale(this.value)">
        <button class="cal-btn" onclick="adjust(0.01)">+</button>
    </div>

    <div style="font-size:12px; color:#aaa; margin-bottom:15px;">
        数值越大 = 文字越靠下 (拉伸)<br>
        数值越小 = 文字越靠上 (压缩)
    </div>

    <div style="display:flex; gap:10px; justify-content:center;">
        <button onclick="applyScale(1.0)" style="background:transparent; border:1px solid #666; color:#ccc; padding:8px 15px; border-radius:4px; cursor:pointer;">重置</button>
        <button onclick="saveScale()" style="background:#1890ff; color:#fff; border:none; padding:8px 20px; border-radius:4px; cursor:pointer;">
            💾 保存配置
        </button>
    </div>
</div>

<script>
// 初始化：应用数据库里的默认值
document.addEventListener('DOMContentLoaded', function() {
    applyScale(<?php echo $db_scale; ?>);
});

function toggleCalibration() {
    const p = document.getElementById('calPanel');
    p.style.display = p.style.display === 'block' ? 'none' : 'block';
}

// 增减按钮逻辑
function adjust(delta) {
    const input = document.getElementById('scaleInput');
    let newVal = parseFloat(input.value) + delta;
    // 保留3位小数避免浮点数精度问题
    newVal = Math.round(newVal * 1000) / 1000;
    applyScale(newVal);
}

// 核心逻辑：JS 实时更新所有元素位置，不刷新页面
function applyScale(val) {
    const scale = parseFloat(val);
    if(isNaN(scale)) return;
    
    // 更新输入框显示
    document.getElementById('scaleInput').value = scale;

    // 遍历所有文字元素，重新计算 top
    const elements = document.querySelectorAll('.data-text');
    elements.forEach(el => {
        const originY = parseFloat(el.getAttribute('data-origin-y'));
        if (!isNaN(originY)) {
            // 新坐标 = 原始坐标 * 系数
            const newY = originY * scale;
            el.style.top = newY + '%';
        }
    });
}

// AJAX 保存
function saveScale() {
    const input = document.getElementById('scaleInput');
    const scale = input.value;
    const tplId = <?php echo $tpl['id']; ?>;
    
    const formData = new FormData();
    formData.append('action', 'save_scale');
    formData.append('tpl_id', tplId);
    formData.append('scale', scale);
    
    // 显示保存中状态
    const btn = event.target;
    const orgText = btn.innerText;
    btn.innerText = '保存中...';
    
    fetch('print.php', { method: 'POST', body: formData })
    .then(res => res.text())
    .then(txt => {
        if(txt === 'ok') {
            btn.innerText = '✅ 已保存';
            setTimeout(() => { btn.innerText = orgText; }, 2000);
        } else {
            alert('保存失败: ' + txt);
            btn.innerText = orgText;
        }
    });
}
</script>

</body>
</html>