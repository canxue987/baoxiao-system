<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') die("无权访问");

$id = $_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM print_templates WHERE id=?");
$stmt->execute([$id]);
$tpl = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tpl) die("模板不存在");

// 保存坐标
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $json = $_POST['config_json'];
    $stmt = $pdo->prepare("UPDATE print_templates SET config_json=? WHERE id=?");
    $stmt->execute([$json, $id]);
    echo "ok"; exit;
}

include 'header.php';
?>
<style>
/* 设计器专用样式 */
.design-container { display: flex; height: calc(100vh - 100px); gap: 20px; }
.canvas-area { flex: 1; background: #e9e9e9; overflow: auto; position: relative; border: 1px solid #ccc; display:flex; justify-content:center; padding:20px; }
.canvas-wrapper { position: relative; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
.bg-img { display: block; max-width: none; user-select: none; }
.print-tag {
    position: absolute; 
    background: rgba(24, 144, 255, 0.8); 
    color: #fff; 
    padding: 2px 5px; 
    font-size: 12px; 
    cursor: move; 
    border-radius: 2px;
    white-space: nowrap; 
    user-select: none;
    
    /* 新增：让设计时的字体也变成黑体，与打印一致 */
    font-family: "SimHei", "Microsoft YaHei", sans-serif; 
    font-weight: bold;
}
.print-tag:hover { background: #1890ff; z-index: 100; }
.sidebar-tools { width: 280px; background: #fff; padding: 15px; border-radius: 8px; overflow-y: auto; display: flex; flex-direction: column; }
.tag-group { margin-bottom: 15px; }
.tag-group-title { font-weight: bold; margin-bottom: 8px; color: #333; font-size: 13px; border-bottom: 1px solid #eee; padding-bottom: 4px; }
.tool-tag {
    display: inline-block; background: #f5f5f5; border: 1px solid #d9d9d9;
    padding: 4px 8px; margin: 0 5px 5px 0; border-radius: 4px; font-size: 12px;
    cursor: pointer; transition: all 0.2s;
}
.tool-tag:hover { border-color: #1890ff; color: #1890ff; }
</style>

<div class="card" style="padding:10px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">
    <div>
        <strong><i class="ri-drag-move-line"></i> 模板设计器：<?php echo h($tpl['name']); ?></strong>
        <span style="font-size:12px; color:#666; margin-left:10px;">点击右侧标签添加到画布，拖拽调整位置，双击标签删除</span>
    </div>
    <div>
        <button onclick="saveConfig()" class="btn btn-primary"><i class="ri-save-line"></i> 保存配置</button>
        <a href="templates.php" class="btn btn-ghost">返回</a>
    </div>
</div>

<div class="design-container">
    <div class="canvas-area">
        <div class="canvas-wrapper" id="wrapper">
            <img src="<?php echo $tpl['bg_image']; ?>" class="bg-img" id="bgImg">
            </div>
    </div>

    <div class="sidebar-tools">
        <div class="tag-group">
            <div class="tag-group-title">通用信息</div>
            <div class="tool-tag" onclick="addTag('{公司主体}')">公司主体</div>
            <div class="tool-tag" onclick="addTag('{报销部门}')">报销部门</div>
            <div class="tool-tag" onclick="addTag('{报销人姓名}')">报销人姓名</div>
            <div class="tool-tag" onclick="addTag('{报销账号}')">报销账号</div>
            <div class="tool-tag" onclick="addTag('{所属项目}')">所属项目</div>
            <div class="tool-tag" onclick="addTag('{填报日期}')">填报日期</div>
        </div>

        <div class="tag-group">
            <div class="tag-group-title">金额统计</div>
            <div class="tool-tag" onclick="addTag('{报销总额_小写}')">总额(小写)</div>
            <div class="tool-tag" onclick="addTag('{报销总额_大写}')">总额(大写)</div>
            <div class="tool-tag" onclick="addTag('{附件总张数}')">附件总张数</div>
        </div>

        <div class="tag-group">
            <div class="tag-group-title">按费用类型 (金额/张数)</div>
            <?php 
            // 这里我们硬编码 main.js 里的那些类型，或者让用户手动添加
            $common_types = ["招待费", "办公费", "交通费", "停车费", "过路桥费", "团建费", "福利费", "飞机票", "火车票", "住宿费", "市内交通", "差旅补贴"];
            foreach($common_types as $t): 
            ?>
            <div class="tool-tag" onclick="addTag('{<?php echo $t; ?>_金额}')"><?php echo $t; ?>-金额</div>
            <div class="tool-tag" onclick="addTag('{<?php echo $t; ?>_张数}')"><?php echo $t; ?>-张数</div>
            <?php endforeach; ?>
        </div>

        <div class="tag-group">
            <div class="tag-group-title">差旅专属</div>
            <div class="tool-tag" onclick="addTag('{出差事由}')">出差事由</div>
            <div class="tool-tag" onclick="addTag('{出差人员}')">出差人员</div>
            <div class="tool-tag" onclick="addTag('{开始日期}')">开始日期</div>
            <div class="tool-tag" onclick="addTag('{结束日期}')">结束日期</div>
            <div class="tool-tag" onclick="addTag('{出差天数}')">出差天数</div>
        </div>
    </div>
</div>

<script>
// 1. 获取原始数据
let rawConfig = <?php echo $tpl['config_json'] ?: '[]'; ?>;
// 2. 容错处理：如果是对象(即数据库里的 '{}')，强制转为空数组
let tags = Array.isArray(rawConfig) ? rawConfig : [];

const wrapper = document.getElementById('wrapper');
const bgImg = document.getElementById('bgImg');

// 初始化渲染
window.onload = function() {
    renderTags();
}

function addTag(key) {
    // 默认加在左上角
    tags.push({ key: key, x: 10, y: 10, size: 14 });
    renderTags();
}

function renderTags() {
    // 清除旧的tag (保留img)
    const oldTags = document.querySelectorAll('.print-tag');
    oldTags.forEach(el => el.remove());

    tags.forEach((t, index) => {
        const el = document.createElement('div');
        el.className = 'print-tag';
        el.innerText = t.key;
        el.style.left = t.x + '%';
        el.style.top = t.y + '%';
        el.style.fontSize = (t.size || 14) + 'px';
        
        // 拖拽逻辑
        el.onmousedown = function(e) {
            e.stopPropagation();
            const startX = e.clientX;
            const startY = e.clientY;
            // 【核心修复】使用 bgImg 而不是 wrapper 来计算尺寸
            // 这样能排除容器 padding 或多余高度带来的误差
            const rect = bgImg.getBoundingClientRect(); 
            
            // 计算 el 相对于图片的偏移，而不是相对于 wrapper
            // 注意：这里需要减去图片在视口中的偏移
            const imgRect = bgImg.getBoundingClientRect();
            const elRect = el.getBoundingClientRect();
            
            const startLeftPixel = elRect.left - imgRect.left;
            const startTopPixel = elRect.top - imgRect.top;

            document.onmousemove = function(moveE) {
                const dx = moveE.clientX - startX;
                const dy = moveE.clientY - startY;
                
                // 计算百分比
                const currentMouseX = moveE.clientX;
                const currentMouseY = moveE.clientY;
                
                // 计算鼠标相对于图片左上角的位移
                // 这种算法比之前的 delta 更直接，不容易累积误差
                let pixelLeft = currentMouseX - startX + startLeftPixel;
                let pixelTop = currentMouseY - startY + startTopPixel;
                
                let newLeft = pixelLeft / rect.width * 100;
                let newTop = pixelTop / rect.height * 100;
                
                el.style.left = newLeft + '%';
                el.style.top = newTop + '%';
                
                // 更新数据
                tags[index].x = newLeft;
                tags[index].y = newTop;
            };

            document.onmouseup = function() {
                document.onmousemove = null;
                document.onmouseup = null;
            };
        };

        // 双击删除
        el.ondblclick = function() {
            if(confirm('删除 ' + t.key + '?')) {
                tags.splice(index, 1);
                renderTags();
            }
        };

        wrapper.appendChild(el);
    });
}

function saveConfig() {
    const formData = new FormData();
    formData.append('config_json', JSON.stringify(tags));
    
    fetch('template_design.php?id=<?php echo $id; ?>', {
        method: 'POST',
        body: formData
    }).then(res => res.text()).then(txt => {
        if(txt === 'ok') alert('保存成功！');
        else alert('保存失败');
    });
}
</script>