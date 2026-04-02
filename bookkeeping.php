<?php
// bookkeeping.php - 个人记账本与备用金管理 (UI美化与交互升级版)
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// ==========================================
// 🚀 AJAX 接口处理 (增删改查)
// ==========================================

// 1. 处理 POST 请求 (添加、更新、删除、切换状态)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $uid = $_SESSION['user_id'];

    // 添加记录
    if ($action == 'add') {
        $type = $_POST['type']; 
        $amount = floatval($_POST['amount']);
        $date = $_POST['date'];
        $item_name = trim($_POST['item_name']);
        $company = trim($_POST['company'] ?? '默认公司');   
        $project_name = trim($_POST['project_name'] ?? ''); 
        $status = ($type == '借款') ? '-' : $_POST['status'];
        $wallet_ids = $_POST['wallet_ids'] ?? ''; 

        // 修复：补全了第9个问号，并在数组最后加入了 $wallet_ids
        $stmt = $pdo->prepare("INSERT INTO bookkeeping (user_id, type, amount, record_date, item_name, company, project_name, status, wallet_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if($stmt->execute([$uid, $type, $amount, $date, $item_name, $company, $project_name, $status, $wallet_ids])) echo "ok"; else echo "err";
        exit;
    }
    
    // 更新记录
    if ($action == 'update') {
        $id = intval($_POST['id']);
        $type = $_POST['type']; 
        $amount = floatval($_POST['amount']);
        $date = $_POST['date'];
        $item_name = trim($_POST['item_name']);
        $company = trim($_POST['company'] ?? '默认公司');   
        $project_name = trim($_POST['project_name'] ?? ''); 
        $status = ($type == '借款') ? '-' : $_POST['status'];
        $wallet_ids = $_POST['wallet_ids'] ?? ''; 

        // 修复：去掉了 WHERE 前面多余的逗号，并在数组里加入了 $wallet_ids
        $stmt = $pdo->prepare("UPDATE bookkeeping SET type=?, amount=?, record_date=?, item_name=?, company=?, project_name=?, status=?, wallet_ids=? WHERE id=? AND user_id=?");
        if($stmt->execute([$type, $amount, $date, $item_name, $company, $project_name, $status, $wallet_ids, $id, $uid])) echo "ok"; else echo "err";
        exit;
    }

    // 删除记录
    if ($action == 'delete') {
        $id = intval($_POST['id']);
        $pdo->prepare("DELETE FROM bookkeeping WHERE id=? AND user_id=?")->execute([$id, $uid]);
        echo "ok"; exit;
    }

    // 快捷切换报销状态
    if ($action == 'toggle_status') {
        $id = intval($_POST['id']);
        $pdo->prepare("UPDATE bookkeeping SET status = CASE WHEN status='未报销' THEN '已报销' ELSE '未报销' END WHERE id=? AND user_id=?")->execute([$id, $uid]);
        echo "ok"; exit;
    }
}

// 2. 处理 GET 请求 (获取数据)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];
    $uid = $_SESSION['user_id'];

    if ($action == 'get_month') {
        $ym = $_GET['ym']; 
        $stmt = $pdo->prepare("SELECT record_date, type, SUM(amount) as total FROM bookkeeping WHERE user_id=? AND record_date LIKE ? GROUP BY record_date, type");
        $stmt->execute([$uid, $ym . '%']);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
    }

    if ($action == 'get_day') {
        $date = $_GET['date'];
        $stmt = $pdo->prepare("SELECT * FROM bookkeeping WHERE user_id=? AND record_date=? ORDER BY id DESC");
        $stmt->execute([$uid, $date]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
    }
    
    // 获取单条记录用于编辑回显
    if ($action == 'get_one') {
        $id = intval($_GET['id']);
        $stmt = $pdo->prepare("SELECT * FROM bookkeeping WHERE id=? AND user_id=?");
        $stmt->execute([$id, $uid]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC)); exit;
    }
}

// ==========================================
// 📊 页面渲染与数据汇总 (当月)
// ==========================================
include 'header.php';

$comp_file = __DIR__ . '/db/companies.json';
$sys_companies = file_exists($comp_file) ? json_decode(file_get_contents($comp_file), true) : ["默认公司"];

$current_ym = date('Y-m');
$uid = $_SESSION['user_id'];

$stats = ['total_expense' => 0, 'total_borrow' => 0, 'unreimbursed' => 0];

$stmt = $pdo->prepare("SELECT type, status, SUM(amount) as total FROM bookkeeping WHERE user_id=? AND record_date LIKE ? GROUP BY type, status");
$stmt->execute([$uid, $current_ym . '%']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    if ($r['type'] == '支出') {
        $stats['total_expense'] += $r['total'];
        if ($r['status'] == '未报销') $stats['unreimbursed'] += $r['total'];
    } elseif ($r['type'] == '借款') {
        $stats['total_borrow'] += $r['total'];
    }
}

$balance = $stats['total_expense'] - $stats['total_borrow'];
$balance_color = $balance >= 0 ? '#52c41a' : '#ff4d4f'; 
?>

<style>
/* 现代化记账本专属样式 */
.book-layout { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start; }
.calendar-panel { flex: 1; min-width: 450px; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid #f0f0f0; }
.detail-panel { width: 360px; background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); border: 1px solid #f0f0f0; position: sticky; top: 20px; }

/* 顶部流光卡片 */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card { padding: 20px; border-radius: 12px; display: flex; flex-direction: column; transition: transform 0.2s; }
.stat-card:hover { transform: translateY(-2px); }
.stat-card.blue { background: linear-gradient(135deg, #e6f7ff 0%, #ffffff 100%); border: 1px solid #91d5ff; }
.stat-card.orange { background: linear-gradient(135deg, #fffbe6 0%, #ffffff 100%); border: 1px solid #ffe58f; }
.stat-card.green { background: linear-gradient(135deg, #f6ffed 0%, #ffffff 100%); border: 1px solid #b7eb8f; }
.stat-card.red { background: linear-gradient(135deg, #fff1f0 0%, #ffffff 100%); border: 1px solid #ffa39e; }
.stat-label { font-size: 13px; color: #555; margin-bottom: 8px; font-weight: bold; }
.stat-value { font-size: 26px; font-weight: 900; font-family: Verdana; }
.stat-sub { font-size: 12px; color: #888; margin-top: 6px; }

/* 日历格子：取消正方形限制，压缩高度 */
.cal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.cal-btn { background: #fff; border: 1px solid #d9d9d9; padding: 6px 14px; border-radius: 6px; cursor: pointer; transition: 0.2s; font-size: 13px; }
.cal-btn:hover { color: #1890ff; border-color: #1890ff; background: #e6f7ff; }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; }
.cal-day-head { text-align: center; font-size: 13px; color: #8c8c8c; font-weight: bold; margin-bottom: 10px; }
.cal-day { 
    min-height: 105px; /* 核心修改：固定最小高度，防止大屏被拉得过高 */
    background: #fafafa; border: 1px solid #f0f0f0; border-radius: 8px; 
    display: flex; flex-direction: column; padding: 8px; cursor: pointer; transition: all 0.2s;
    position: relative;
}
.cal-day:hover { background: #fff; border-color: #1890ff; box-shadow: 0 4px 12px rgba(24,144,255,0.12); z-index: 1; transform: scale(1.02); }
.cal-day.active { background: #e6f7ff; border-color: #1890ff; box-shadow: inset 0 0 0 1px #1890ff; }
.cal-day.today { background: #fff1f0; border-color: #ffa39e; }
.cal-day.empty { background: transparent; border: none; cursor: default; }
.cal-day.empty:hover { transform: none; box-shadow: none; z-index: 0; }
.day-num { font-size: 14px; font-weight: bold; color: #555; margin-bottom: 6px; }

/* 日历上的小标 */
.dot-row { display: flex; flex-direction: column; gap: 4px; font-size: 11px; }
.dot-exp { color: #cf1322; background: #fff1f0; border: 1px solid #ffa39e; padding: 2px 6px; border-radius: 4px; font-weight:bold; }
.dot-bor { color: #389e0d; background: #f6ffed; border: 1px solid #b7eb8f; padding: 2px 6px; border-radius: 4px; font-weight:bold; }

/* 明细列表 */
.record-item { border: 1px solid #f0f0f0; padding: 12px; border-radius: 8px; margin-bottom: 10px; background: #fff; transition: 0.2s; }
.record-item:hover { border-color: #d9d9d9; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
.record-act-btn { color: #999; cursor: pointer; font-size: 16px; padding: 4px; transition: 0.2s; border-radius: 4px; }
.record-act-btn.edit:hover { color: #1890ff; background: #e6f7ff; }
.record-act-btn.del:hover { color: #ff4d4f; background: #fff1f0; }

.status-badge { font-size:11px; padding:2px 6px; border-radius:4px; border:1px solid; cursor:pointer; user-select:none; }
.badge-ok { background:#f6ffed; border-color:#b7eb8f; color:#52c41a; }
.badge-no { background:#fff1f0; border-color:#ffa39e; color:#ff4d4f; }
</style>

<div class="stat-grid">
    <div class="stat-card blue">
        <span class="stat-label">当月垫付 (支出)</span>
        <span class="stat-value" style="color:#0050b3;">¥<?php echo number_format($stats['total_expense'], 2); ?></span>
    </div>
    <div class="stat-card orange">
        <span class="stat-label">当月备用金 (借入)</span>
        <span class="stat-value" style="color:#d46b08;">¥<?php echo number_format($stats['total_borrow'], 2); ?></span>
    </div>
    <div class="stat-card <?php echo $balance >= 0 ? 'green' : 'red'; ?>">
        <span class="stat-label">当月结余 (支出 - 借入)</span>
        <span class="stat-value" style="color:<?php echo $balance_color; ?>;">
            <?php echo $balance >= 0 ? '+' : ''; ?>¥<?php echo number_format($balance, 2); ?>
        </span>
        <span class="stat-sub"><?php echo $balance >= 0 ? '目前公司需补给您' : '目前您需退还给公司'; ?></span>
    </div>
    <div class="stat-card red">
        <span class="stat-label">急需报销 (未报账金额)</span>
        <span class="stat-value" style="color:#cf1322;">¥<?php echo number_format($stats['unreimbursed'], 2); ?></span>
    </div>
</div>

<div class="book-layout">
    <div class="calendar-panel">
        <div class="cal-header">
            <h3 style="margin:0; font-size:18px;"><i class="ri-calendar-todo-fill" style="color:#1890ff;"></i> <span id="cal-title">2026年3月</span></h3>
            <div style="display:flex; gap:8px;">
                <button class="cal-btn" onclick="changeMonth(-1)"><i class="ri-arrow-left-s-line"></i> 上月</button>
                <button class="cal-btn" onclick="goToday()">本月</button>
                <button class="cal-btn" onclick="changeMonth(1)">下月 <i class="ri-arrow-right-s-line"></i></button>
            </div>
        </div>
        
        <div class="cal-grid">
            <div class="cal-day-head">周一</div><div class="cal-day-head">周二</div><div class="cal-day-head">周三</div>
            <div class="cal-day-head">周四</div><div class="cal-day-head">周五</div><div class="cal-day-head" style="color:#ff4d4f;">周六</div><div class="cal-day-head" style="color:#ff4d4f;">周日</div>
        </div>
        <div class="cal-grid" id="cal-body">
            </div>
    </div>

    <div class="detail-panel">
        <div style="border-bottom: 2px solid #f0f0f0; padding-bottom: 12px; margin-bottom: 15px; display:flex; justify-content:space-between; align-items:center;">
            <h4 style="margin:0; font-size:16px; color:#333;"><span id="detail-date-title">选择日期</span> 的明细</h4>
            <span id="detail-total" style="font-size:14px; font-weight:bold; color:#1890ff;"></span>
        </div>

        <div id="record-list" style="min-height:100px; max-height:480px; overflow-y:auto; margin-bottom:20px; padding-right:5px;">
            </div>

        <div style="background:#fafafa; padding:16px; border-radius:8px; border:1px solid #e8e8e8; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
            <div id="form-title-bar" style="font-weight:bold; font-size:14px; margin-bottom:12px; color:#1890ff; display:flex; justify-content:space-between;">
                <span><i class="ri-pencil-fill"></i> 记一笔</span>
            </div>
            
            <form id="add-record-form" onsubmit="saveRecord(event)">
                <input type="hidden" id="f-id" value="">
                <input type="hidden" id="f-date" required>
                
                <div style="display:flex; gap:10px; margin-bottom:12px;">
                    <select id="f-type" class="form-control" style="width:90px; font-weight:bold; padding:0 8px;">
                        <option value="支出">垫付支出</option>
                        <option value="借款">借入款项</option>
                    </select>
                    <input type="text" id="f-amount" class="form-control" placeholder="金额 ¥ (支持 10+15)" required style="flex:1; font-family:Verdana; font-weight:bold;">
                </div>
                
                <div style="margin-bottom:12px;">
                    <select id="f-company" class="form-control" style="font-size:13px; color:#1890ff; font-weight:bold; background:#e6f7ff;">
                        <?php foreach($sys_companies as $c): ?>
                            <option value="<?php echo h($c); ?>"><?php echo h($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="margin-bottom:12px;">
                    <input type="text" id="f-item" class="form-control" placeholder="输入事项明细 (导入报销时作为备注)" required>
                </div>
                
                <div style="margin-bottom:12px;">
                    <input type="text" id="f-project" class="form-control" placeholder="报销所属项目 (选填)" style="font-size:13px;">
                </div>

                <div style="margin-bottom:12px; border:1px dashed #1890ff; padding:8px; border-radius:6px; background:#e6f7ff; text-align:center; cursor:pointer; transition:0.2s;" onclick="openBkWalletModal()" id="bk-wallet-btn">
                    <i class="ri-wallet-3-line" style="color:#1890ff;"></i> <span id="bk-wallet-text" style="color:#1890ff; font-weight:bold;">关联票夹发票 (选填)</span>
                    <input type="hidden" id="f-wallet-ids" value="">
                </div>

                <div id="status-container" style="margin-bottom:15px; display:flex; align-items:center; gap:10px; background:#fff; padding:6px 10px; border-radius:6px; border:1px solid #d9d9d9;">
                    <label style="font-size:13px; color:#666; margin:0;">报销状态:</label>
                    <select id="f-status" class="form-control" style="height:28px; padding:0 8px; flex:1; font-size:13px;">
                        <option value="未报销">❌ 未报销</option>
                        <option value="已报销">✅ 已报销</option>
                    </select>
                </div>
                
                <div style="display:flex; gap:10px;">
                    <button type="submit" id="btn-submit" class="btn btn-primary" style="flex:1;"><i class="ri-check-line"></i> 确认保存</button>
                    <button type="button" id="btn-cancel" class="btn btn-ghost" style="display:none;" onclick="cancelEdit()">取消</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="assets/lunar.js"></script>
<script>


// ==================== 日历核心逻辑 ====================
let currentDate = new Date();
let selectedDateStr = '';
let currentMonthData = [];

function formatDate(date) {
    let y = date.getFullYear();
    let m = String(date.getMonth() + 1).padStart(2, '0');
    let d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function formatMonth(date) {
    let y = date.getFullYear();
    let m = String(date.getMonth() + 1).padStart(2, '0');
    return `${y}-${m}`;
}

function goToday() { currentDate = new Date(); renderCalendar(); }
function changeMonth(delta) { currentDate.setMonth(currentDate.getMonth() + delta); renderCalendar(); }

async function renderCalendar() {
    let year = currentDate.getFullYear();
    let month = currentDate.getMonth();
    document.getElementById('cal-title').innerText = `${year}年${month + 1}月`;
    
    let firstDay = new Date(year, month, 1).getDay();
    let daysInMonth = new Date(year, month + 1, 0).getDate();
    firstDay = firstDay === 0 ? 7 : firstDay;

    let ym = formatMonth(currentDate);
    let res = await fetch(`bookkeeping.php?action=get_month&ym=${ym}`);
    currentMonthData = await res.json();

    let html = '';
    for (let i = 1; i < firstDay; i++) html += `<div class="cal-day empty"></div>`;

    let todayStr = formatDate(new Date());
    
    for (let i = 1; i <= daysInMonth; i++) {
        let dDate = new Date(year, month, i);
        let dStr = formatDate(dDate);
        let isToday = (dStr === todayStr) ? 'today' : '';
        let isActive = (dStr === selectedDateStr) ? 'active' : '';
        
        // ✨ 增强版：获取公历、农历和法定节假日 (HolidayUtil)
        let solar = Solar.fromDate(dDate);
        let lunar = Lunar.fromDate(dDate);
        let holiday = HolidayUtil.getHoliday(year, month + 1, i);

        // 1. 处理“休”和“班”的小角标
        let holidayBadge = '';
        if (holiday) {
            if (holiday.isWork()) {
                // 调休上班：红色“班”字
                holidayBadge = `<span style="background:#ff4d4f; color:#fff; font-size:10px; padding:0 3px; border-radius:2px; margin-left:4px; font-weight:normal; display:inline-block; line-height:1.2;">班</span>`;
            } else {
                // 法定休息：绿色“休”字
                holidayBadge = `<span style="background:#52c41a; color:#fff; font-size:10px; padding:0 3px; border-radius:2px; margin-left:4px; font-weight:normal; display:inline-block; line-height:1.2;">休</span>`;
            }
        }

        // 2. 节日名称智能优先级 (法定假名 > 农历节 > 公历节 > 节气 > 农历日期)
        let festName = '';
        if (holiday && !holiday.isWork()) festName = holiday.getName(); 
        else festName = lunar.getFestivals()[0] || solar.getFestivals()[0] || lunar.getJieQi();

        let lunarDay = lunar.getDayInChinese();
        if (lunarDay === '初一') lunarDay = lunar.getMonthInChinese() + '月'; // 初一显示月份

        let displayLunar = festName || lunarDay;
        // 如果是节日，字体变蓝加粗；否则是浅灰色
        let lunarStyle = festName ? 'color:#1890ff; font-weight:bold;' : 'color:#bfbfbf;';

        let dayDots = '';
        let dayRecords = currentMonthData.filter(item => item.record_date === dStr);
        dayRecords.forEach(rec => {
            if (rec.type === '支出') dayDots += `<div class="dot-exp">-¥${rec.total}</div>`;
            if (rec.type === '借款') dayDots += `<div class="dot-bor">+¥${rec.total}</div>`;
        });

        html += `
            <div class="cal-day ${isToday} ${isActive}" onclick="selectDate('${dStr}', this)">
                <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:4px;">
                    <div style="display:flex; align-items:center;">
                        <span class="day-num" style="${(dDate.getDay()===0||dDate.getDay()===6)?'color:#ff4d4f;':''} margin:0;">${i}</span>
                        ${holidayBadge}
                    </div>
                    <span style="font-size:11px; ${lunarStyle} white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:55px; text-align:right;" title="${displayLunar}">${displayLunar}</span>
                </div>
                <div class="dot-row">${dayDots}</div>
            </div>
        `;
    }

    document.getElementById('cal-body').innerHTML = html;
    
    if (!selectedDateStr || selectedDateStr.substring(0,7) !== ym) {
        selectDate(ym === todayStr.substring(0,7) ? todayStr : `${ym}-01`);
    } else {
        selectDate(selectedDateStr);
    }
}

// ==================== 交互与表单逻辑 ====================

// 联动：类型切换时，隐藏/显示状态栏
document.getElementById('f-type').addEventListener('change', function() {
    document.getElementById('status-container').style.display = (this.value === '借款') ? 'none' : 'flex';
});

async function selectDate(dateStr, element = null) {
    selectedDateStr = dateStr;
    document.getElementById('f-date').value = dateStr;
    
    // 顺手修复一下苹果/部分浏览器时区导致日期少一天的小 Bug (加上了 replace)
    let dObj = new Date(dateStr.replace(/-/g, '/'));
    let weekDays = ['日','一','二','三','四','五','六'];
    document.getElementById('detail-date-title').innerText = `${dObj.getMonth()+1}月${dObj.getDate()}日 (周${weekDays[dObj.getDay()]})`;

    // ✨ 核心修复：只在手动点击格子时切换高亮，拒绝死循环
    if (element) {
        document.querySelectorAll('.cal-day').forEach(el => el.classList.remove('active'));
        element.classList.add('active');
    }
    
    cancelEdit(); // 切日期时重置表单 // 切日期时重置表单

    let res = await fetch(`bookkeeping.php?action=get_day&date=${dateStr}`);
    let records = await res.json();

    let listHtml = '';
    let dayTotal = 0;

    if (records.length === 0) {
        listHtml = `<div style="text-align:center; padding:30px; color:#ccc;"><i class="ri-file-text-line" style="font-size:32px;"></i><p style="margin-top:10px;">今日无流水</p></div>`;
    } else {
        records.forEach(r => {
            let isExp = r.type === '支出';
            let color = isExp ? '#ff4d4f' : '#52c41a';
            let sign = isExp ? '-' : '+';
            if(isExp) dayTotal -= parseFloat(r.amount); else dayTotal += parseFloat(r.amount);
            
            let statusTag = '';
            if (isExp) {
                let badgeClass = r.status === '已报销' ? 'badge-ok' : 'badge-no';
                statusTag = `<span class="status-badge ${badgeClass}" onclick="toggleStatus(${r.id})" title="点击快捷切换状态">${r.status}</span>`;
            }

            listHtml += `
            <div class="record-item">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:6px;">
                    <div style="font-weight:bold; color:#333; font-size:14px;">${r.item_name}</div>
                    <div style="font-weight:900; color:${color}; font-family:Verdana;">${sign}¥${r.amount}</div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:flex-end;">
                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <div>${statusTag}</div>
                        <div style="font-size:12px; color:#999; margin-top:4px; display:flex; gap:6px; align-items:center;">
                            <span style="background:#e6f7ff; color:#1890ff; padding:1px 6px; border-radius:4px; font-size:10px;">${r.company || '默认公司'}</span>
                            ${r.project_name ? `<span><i class="ri-folder-2-line"></i> ${r.project_name}</span>` : ''}
                        </div>
                    </div>
                    <div style="display:flex; gap:2px;">
                        <i class="ri-edit-box-line record-act-btn edit" title="编辑" onclick="editRecord(${r.id})"></i>
                        <i class="ri-delete-bin-line record-act-btn del" title="删除" onclick="deleteRecord(${r.id})"></i>
                    </div>
                </div>
            </div>`;
        });
    }

    document.getElementById('record-list').innerHTML = listHtml;
    document.getElementById('detail-total').innerText = dayTotal === 0 ? '' : `小计: ${dayTotal > 0 ? '+' : ''}${dayTotal.toFixed(2)}`;
}

// 进入编辑模式
async function editRecord(id) {
    let res = await fetch(`bookkeeping.php?action=get_one&id=${id}`);
    let r = await res.json();
    if(r) {
        document.getElementById('f-id').value = r.id;
        document.getElementById('f-type').value = r.type;
        document.getElementById('f-amount').value = r.amount;
        document.getElementById('f-item').value = r.item_name;
        
        // 核心修复：填入新的公司和项目，去掉了已删除的 note
        document.getElementById('f-company').value = r.company || '默认公司';
        document.getElementById('f-project').value = r.project_name || '';
        document.getElementById('f-wallet-ids').value = r.wallet_ids || '';
        if(r.wallet_ids) {
            document.getElementById('bk-wallet-text').innerHTML = `已关联 ${r.wallet_ids.split(',').length} 张发票`;
            document.getElementById('bk-wallet-btn').style.background = '#f6ffed';
            document.getElementById('bk-wallet-btn').style.borderColor = '#b7eb8f';
        }
        
        let sGroup = document.getElementById('status-container');
        if (r.type === '借款') {
            sGroup.style.display = 'none';
        } else {
            sGroup.style.display = 'flex';
            document.getElementById('f-status').value = r.status;
        }

        document.getElementById('form-title-bar').innerHTML = `<span style="color:#faad14;"><i class="ri-edit-fill"></i> 修改记录 #${r.id}</span>`;
        document.getElementById('btn-submit').innerHTML = '<i class="ri-save-line"></i> 更新记录';
        document.getElementById('btn-submit').className = 'btn btn-primary';
        document.getElementById('btn-cancel').style.display = 'block';
    }
}

// 取消编辑，恢复添加模式
function cancelEdit() {
    document.getElementById('add-record-form').reset();
    document.getElementById('f-id').value = '';
    document.getElementById('f-date').value = selectedDateStr; 
    document.getElementById('status-container').style.display = 'flex'; // 默认支出，显示状态

    document.getElementById('form-title-bar').innerHTML = `<span><i class="ri-pencil-fill"></i> 记一笔</span>`;
    document.getElementById('btn-submit').innerHTML = '<i class="ri-check-line"></i> 确认保存';
    document.getElementById('btn-cancel').style.display = 'none';

    document.getElementById('f-wallet-ids').value = '';
    document.getElementById('bk-wallet-text').innerHTML = '关联票夹发票 (选填)';
    document.getElementById('bk-wallet-btn').style.background = '#e6f7ff';
    document.getElementById('bk-wallet-btn').style.borderColor = '#1890ff';
}

// 提交表单
async function saveRecord(e) {
    e.preventDefault();
    let data = new URLSearchParams();
    let idVal = document.getElementById('f-id').value;
    
    data.append('action', idVal ? 'update' : 'add');
    if (idVal) data.append('id', idVal);
    
    data.append('date', document.getElementById('f-date').value);
    data.append('type', document.getElementById('f-type').value);
    data.append('amount', document.getElementById('f-amount').value);
    data.append('item_name', document.getElementById('f-item').value);
    
    // 核心修复：提交公司和项目，不再提交旧的 note
    data.append('company', document.getElementById('f-company').value);
    data.append('project_name', document.getElementById('f-project').value);
    data.append('status', document.getElementById('f-status').value);
    
    // 修复：必须在发送 fetch 请求之前，就把关联的发票 ID 塞进数据包里
    data.append('wallet_ids', document.getElementById('f-wallet-ids').value);

    let res = await fetch('bookkeeping.php', { method: 'POST', body: data });
    let txt = await res.text();
    
    if (txt === 'ok') window.location.href = '?date=' + document.getElementById('f-date').value; 
    else alert("操作失败！");
}

async function deleteRecord(id) {
    if(!confirm("确定要删除这笔记录吗？")) return;
    let data = new URLSearchParams();
    data.append('action', 'delete');
    data.append('id', id);

    await fetch('bookkeeping.php', { method: 'POST', body: data });
    window.location.href = '?date=' + selectedDateStr;
}

async function toggleStatus(id) {
    let data = new URLSearchParams();
    data.append('action', 'toggle_status');
    data.append('id', id);
    await fetch('bookkeeping.php', { method: 'POST', body: data });
    window.location.href = '?date=' + selectedDateStr;
}
// ==================== 智能金额计算 ====================
// 监听金额输入框，鼠标移开（失焦）时自动计算加法
document.getElementById('f-amount').addEventListener('blur', function() {
    let val = this.value.trim().replace(/\s+/g, ''); // 获取输入值并去除所有空格
    if (!val) return;
    
    // 正则验证：只允许数字、小数点和加号
    if (/^[0-9\.\+]+$/.test(val)) {
        try {
            // 用加号分割并累加
            let sum = val.split('+').reduce((acc, curr) => {
                return acc + (parseFloat(curr) || 0); // 避免空字符串导致 NaN
            }, 0);
            
            // 将计算结果保留两位小数重新填入
            this.value = sum.toFixed(2);
        } catch (e) {
            console.error("金额解析错误");
        }
    } else {
        // 如果用户不小心输入了字母等非法字符，强制转换或清空
        let num = parseFloat(val);
        this.value = isNaN(num) ? '' : num.toFixed(2);
    }
});
document.addEventListener('DOMContentLoaded', () => { 
    // ✨ 初始化时，看看网址里有没有“记忆”的日期
    let urlParams = new URLSearchParams(window.location.search);
    let initDate = urlParams.get('date');
    if (initDate) {
        currentDate = new Date(initDate.replace(/-/g, '/'));
        selectedDateStr = initDate;
    }
    renderCalendar(); 
});

// ====== 记账本发票关联弹窗逻辑 (加强版) ======
window.currentBkWalletData = []; // 供智能凑票算法使用

async function openBkWalletModal() {
    let excludeId = document.getElementById('f-id').value || 0;
    let targetCompany = document.getElementById('f-company').value.trim();

    // 1. 获取数据 (包含拦截器过滤掉其他行已用的发票)
    let res = await fetch(`api_wallet_list.php?exclude_bk_id=${excludeId}`); 
    let list = await res.json();
    
    // 2. ✨ 核心增强：根据当前记账单选择的公司主体过滤发票
    let filteredList = list.filter(i => {
        if (targetCompany && i.buyer_name && i.buyer_name.trim() !== '') {
            // 互相包含即认为匹配（防止简称与全称的差异）
            if (i.buyer_name.indexOf(targetCompany) === -1 && targetCompany.indexOf(i.buyer_name) === -1) {
                return false; 
            }
        }
        // 注：如果发票没有购方抬头（如打车小票、火车票），则视为“通用闲置票”，允许放行
        return true;
    });

    window.currentBkWalletData = filteredList;
    
    // 3. 构建弹窗 HTML (采用 Flex 布局充分利用高度)
    let html = '<div style="flex:1; overflow-y:auto; padding:0;"><table class="data-table" style="width:100%; border-collapse:collapse;">';
    html += '<thead style="position:sticky; top:0; background:#fafafa; z-index:1; box-shadow:0 1px 2px rgba(0,0,0,0.05);"><tr><th width="40" style="text-align:center">选</th><th>金额</th><th>发票类型</th><th>日期</th><th>开票内容/备注</th></tr></thead><tbody>';
    
    let currentSelected = document.getElementById('f-wallet-ids').value.split(',');
    
    if(filteredList.length === 0) {
        html += `<tr><td colspan="5" style="text-align:center; padding:60px 20px; color:#999;"><i class="ri-inbox-line" style="font-size:48px; color:#e8e8e8;"></i><br><p style="margin-top:10px;">【${targetCompany}】名下暂无可用闲置发票</p></td></tr>`;
    }

    filteredList.forEach(item => {
        let isChecked = currentSelected.includes(item.id.toString()) ? 'checked' : '';
        let resTag = item.is_reserved == 1 ? '<span style="background:#fffbe6; color:#faad14; border:1px solid #ffe58f; padding:0 4px; font-size:10px; border-radius:2px; margin-left:4px;">禁凑</span>' : '';

        // 行内点击变色交互
        html += `<tr style="cursor:pointer; border-bottom:1px solid #f0f0f0; transition:0.2s;" onclick="let cb = this.querySelector('input'); cb.checked = !cb.checked; if(cb.checked) this.style.background='#e6f7ff'; else this.style.background='transparent';">
            <td style="text-align:center"><input type="checkbox" class="bk-inv-chk" value="${item.id}" data-amount="${item.amount}" ${isChecked} onclick="event.stopPropagation(); if(this.checked) this.closest('tr').style.background='#e6f7ff'; else this.closest('tr').style.background='transparent';"></td>
            <td style="color:#f5222d; font-weight:bold; font-family:Verdana;">¥${item.amount} ${resTag}</td>
            <td><span style="font-size:11px; background:#f5f5f5; border:1px solid #d9d9d9; padding:2px 6px; border-radius:4px; color:#666;">${item.invoice_special_type || '普票'}</span></td>
            <td style="font-size:13px; color:#555;">${item.invoice_date}</td>
            <td style="font-size:13px; color:#1890ff; max-width:200px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${item.invoice_detail || item.note || '-'}</td>
        </tr>`;
    });
    html += '</tbody></table></div>';
    
    // 4. 构建悬浮操作栏 (植入凑票引擎)
    let footerHtml = `
    <div style="padding:15px 24px; border-top:1px solid #f0f0f0; background:#fafafa; display:flex; justify-content:space-between; align-items:center; border-radius:0 0 8px 8px; flex-shrink:0;">
        <div style="display:flex; align-items:center;">
            <button onclick="bkAutoMatch()" class="btn btn-sm" style="background:#e6f7ff; color:#1890ff; border:1px solid #91d5ff; padding:6px 12px; font-weight:bold; cursor:pointer;"><i class="ri-robot-line"></i> 智能凑票</button>
            <span style="font-size:12px; color:#999; margin-left:10px;">* 仅显示【${targetCompany}】及无抬头的闲置发票</span>
        </div>
        <div>
            <button class="btn btn-ghost" onclick="document.getElementById('temp-bk-modal').remove()" style="margin-right:10px;">取消</button>
            <button class="btn btn-primary" onclick="confirmBkWallet()">确认关联</button>
        </div>
    </div>`;
    
    // 5. 借用系统的弹窗框架渲染 (设定为 80vh 高度)
    let div = document.createElement('div');
    div.id = 'temp-bk-modal';
    div.className = 'modal-overlay';
    div.style.display = 'flex';
    div.style.position = 'fixed';
    div.style.top = '0'; div.style.left = '0'; div.style.width = '100%'; div.style.height = '100%';
    div.style.background = 'rgba(0,0,0,0.5)'; div.style.zIndex = '9999';
    div.style.alignItems = 'center'; div.style.justifyContent = 'center';
    
    div.innerHTML = `
    <div class="modal-box" style="background:#fff; border-radius:8px; width:850px; max-width:95%; height:75vh; display:flex; flex-direction:column; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <div class="modal-header" style="padding:16px 24px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; background:#fff; border-radius:8px 8px 0 0; flex-shrink:0;">
            <h3 style="margin:0; font-size:16px;"><i class="ri-wallet-3-line" style="color:#1890ff; margin-right:5px;"></i> 关联发票 (记账本)</h3>
            <button onclick="document.getElementById('temp-bk-modal').remove()" style="border:none; background:none; font-size:24px; color:#999; cursor:pointer;">&times;</button>
        </div>
        ${html}
        ${footerHtml}
    </div>`;
    document.body.appendChild(div);

    // 渲染完后，将已选中的行背景变蓝
    setTimeout(() => {
        document.querySelectorAll('.bk-inv-chk:checked').forEach(cb => {
            cb.closest('tr').style.background = '#e6f7ff';
        });
    }, 50);
}

// ✨ 记账本专属凑票算法
function bkAutoMatch() {
    let targetAmtStr = document.getElementById('f-amount').value;
    let targetAmt = parseFloat(targetAmtStr);

    if (!targetAmt || isNaN(targetAmt) || targetAmt <= 0) {
        targetAmt = parseFloat(prompt('检测到金额为空，请手动输入需要凑出的目标总额：', '2000'));
        if (!targetAmt || isNaN(targetAmt) || targetAmt <= 0) return;
    }
    if (targetAmt > 50000) return alert('目标金额过大，请手动勾选！');

    let available = window.currentBkWalletData.filter(i => i.is_reserved != 1);
    if(available.length === 0) return alert('当前列表没有可用的“闲置发票”！\n(发票可能已被标为专属)');

    // === 核心：0-1 背包动态规划求最接近子集和 ===
    let target = Math.round(targetAmt * 100);
    let limit = target + 1500; // 允许最多高出 15 元

    let dp = new Array(limit + 1).fill(null);
    dp[0] = [];

    let bestSum = 0;
    let bestDiff = Infinity;

    for (let inv of available) {
        let val = Math.round(parseFloat(inv.amount) * 100);
        for (let v = limit; v >= val; v--) {
            if (dp[v - val] !== null && dp[v] === null) {
                dp[v] = [...dp[v - val], inv.id];
                let diff = Math.abs(v - target);
                if (diff < bestDiff) {
                    bestDiff = diff;
                    bestSum = v;
                }
            }
        }
        if (bestDiff === 0) break;
    }

    if (bestDiff <= 1500 && bestSum > 0) {
        // 清空所有勾选
        document.querySelectorAll('.bk-inv-chk').forEach(cb => {
            cb.checked = false;
            cb.closest('tr').style.background = 'transparent';
        });

        // 勾选最优解
        let matchedIds = dp[bestSum];
        matchedIds.forEach(id => {
            let cb = document.querySelector(`.bk-inv-chk[value="${id}"]`);
            if(cb) {
                cb.checked = true;
                cb.closest('tr').style.background = '#e6f7ff';
            }
        });
        alert(`🎉 凑票成功！\n\n目标金额: ¥${targetAmt}\n凑出金额: ¥${(bestSum/100).toFixed(2)}\n误差范围: ¥${(bestDiff/100).toFixed(2)}`);
    } else {
        alert(`😔 凑票失败...\n\n当前可用发票无法拼凑出接近 ¥${targetAmt} 的金额。\n(系统最大允许上下误差为15元)`);
    }
}

function confirmBkWallet() {
    let chks = document.querySelectorAll('.bk-inv-chk:checked');
    let ids = [];
    let totalAmount = 0;

    chks.forEach(c => {
        ids.push(c.value);
        totalAmount += parseFloat(c.getAttribute('data-amount') || 0);
    });

    document.getElementById('f-wallet-ids').value = ids.join(',');

    // ✨ 极致体验：如果您点开弹窗前没填金额，选完/凑完发票后，系统帮您把金额自动填进记账单！
    let fAmt = document.getElementById('f-amount');
    if (ids.length > 0 && (!fAmt.value || parseFloat(fAmt.value) === 0)) {
        fAmt.value = totalAmount.toFixed(2);
        fAmt.style.backgroundColor = '#e6f7ff';
        setTimeout(() => fAmt.style.backgroundColor = '', 800);
    }

    if(ids.length > 0) {
        document.getElementById('bk-wallet-text').innerHTML = `已关联 ${ids.length} 张发票 (¥${totalAmount.toFixed(2)})`;
        document.getElementById('bk-wallet-btn').style.background = '#f6ffed';
        document.getElementById('bk-wallet-btn').style.borderColor = '#b7eb8f';
    } else {
        document.getElementById('bk-wallet-text').innerHTML = '关联票夹发票 (选填)';
        document.getElementById('bk-wallet-btn').style.background = '#e6f7ff';
        document.getElementById('bk-wallet-btn').style.borderColor = '#1890ff';
    }
    document.getElementById('temp-bk-modal').remove();
}
</script>

</body>
</html>