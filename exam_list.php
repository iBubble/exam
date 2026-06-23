<?php
require_once 'inc/db.inc.php';
require_once 'inc/functions.inc.php';
startStudentSession();
checkStudentLogin();
ensurePaperScheduleColumns($pdo);

// 获取学生班级
$student_class = $_SESSION['student_class'] ?? null;

// 获取试卷列表（根据学生班级过滤）
if (!empty($student_class)) {
    // 如果学生有班级，只显示分配给该班级的试卷，或者没有分配班级的试卷（所有班级可见）
    $stmt = $pdo->prepare("SELECT DISTINCT p.*, s.name as subject_name 
                          FROM papers p 
                          LEFT JOIN subjects s ON p.subject_id = s.id 
                          LEFT JOIN paper_classes pc ON p.id = pc.paper_id
                          WHERE (pc.class = ? OR pc.paper_id IS NULL)
                          ORDER BY p.id DESC");
    $stmt->execute([$student_class]);
} else {
    // 如果学生没有班级，只显示没有分配班级的试卷（所有班级可见）
    $stmt = $pdo->prepare("SELECT DISTINCT p.*, s.name as subject_name 
                          FROM papers p 
                     LEFT JOIN subjects s ON p.subject_id = s.id 
                          LEFT JOIN paper_classes pc ON p.id = pc.paper_id
                          WHERE pc.paper_id IS NULL
                     ORDER BY p.id DESC");
    $stmt->execute();
}
$all_papers = $stmt->fetchAll();
$papers = [];
foreach ($all_papers as $paperItem) {
    $state = getPaperActiveState($paperItem);
    if ($state['active']) {
        $paperItem['is_active'] = true;
        $paperItem['state_reason'] = $state['reason'];
        $papers[] = $paperItem;
    }
}
$msg = $_GET['msg'] ?? '';
$reason = $_GET['reason'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>试卷列表 - <?php echo escape(getSiteTitle()); ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <script>
        // 幽默警告消息数组（超搞笑玩梗版）
        const funnyWarnings = [
            { emoji: '😏', text: '嘿嘿，想复制？这波操作有点小寄' },
            { emoji: '🤭', text: '偷偷摸摸的，是不是想搞事情？' },
            { emoji: '😎', text: '别白费力气了，这题得用脑子，不是Ctrl+C' },
            { emoji: '🙈', text: '我看不见，你也别想复制！懂得都懂' },
            { emoji: '🦸', text: '系统保护已启动，复制请求已拦截！' },
            { emoji: '🔒', text: '内容已加密，复制无效，这波属于是白给' },
            { emoji: '🎭', text: '此路不通，请走正门！别整这些花活儿' },
            { emoji: '🚫', text: '禁止操作！专心学习才是王道！别摆烂' },
            { emoji: '💪', text: '靠实力刷题，不靠复制！卷起来！' },
            { emoji: '🎯', text: '想作弊？系统第一个不答应！这波寄了' },
            { emoji: '😤', text: '哼！想复制？门都没有！别想了' },
            { emoji: '🤖', text: 'AI监控中，禁止复制操作！已被标记' },
            { emoji: '🛡️', text: '防护盾已开启，复制被拦截！这波稳了' },
            { emoji: '⚡', text: '电击警告！禁止复制！再试就寄了' },
            { emoji: '🎪', text: '这里是学习马戏团，不是复制工厂！' },
            { emoji: '🐱', text: '小猫说：不可以复制哦~要用脑子' },
            { emoji: '🦉', text: '猫头鹰盯着你呢，别想复制！' },
            { emoji: '🌙', text: '月亮代表系统，禁止复制！别整活儿' },
            { emoji: '⭐', text: '星星在看着你，老实刷题吧！别摆烂' },
            { emoji: '🔥', text: '系统很生气，后果很严重！这波要寄' },
            { emoji: '🦀', text: '螃蟹都横着走了，你还想复制？' },
            { emoji: '🐌', text: '蜗牛都比你快，快用脑子刷题！' },
            { emoji: '🦖', text: '恐龙都灭绝了，你还在想复制？' }
        ];
        
        function showFunnyWarning() {
            const warning = funnyWarnings[Math.floor(Math.random() * funnyWarnings.length)];
            const toast = document.createElement('div');
            toast.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%); border: 3px solid #ffc107; border-radius: 20px; padding: 30px 40px; box-shadow: 0 10px 40px rgba(255, 193, 7, 0.5); z-index: 99999; text-align: center; font-size: 20px; font-weight: 600; color: #856404; animation: popIn 0.3s ease, fadeOut 0.3s ease 2s forwards; min-width: 300px;';
            toast.innerHTML = '<div style="font-size: 48px; margin-bottom: 15px;">' + warning.emoji + '</div><div>' + warning.text + '</div>';
            document.body.appendChild(toast);
            setTimeout(() => { if (toast.parentNode) toast.remove(); }, 2300);
        }
        
        const style = document.createElement('style');
        style.textContent = '@keyframes popIn { 0% { transform: translate(-50%, -50%) scale(0.5); opacity: 0; } 50% { transform: translate(-50%, -50%) scale(1.1); } 100% { transform: translate(-50%, -50%) scale(1); opacity: 1; } } @keyframes fadeOut { from { opacity: 1; transform: translate(-50%, -50%) scale(1); } to { opacity: 0; transform: translate(-50%, -50%) scale(0.8); } }';
        document.head.appendChild(style);
        
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('contextmenu', function(e) { e.preventDefault(); showFunnyWarning(); return false; });
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && (e.keyCode === 67 || e.keyCode === 65 || e.keyCode === 86 || e.keyCode === 88 || e.keyCode === 83)) {
                    e.preventDefault(); showFunnyWarning(); return false;
                }
                if (e.keyCode === 123 || (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74)) || (e.ctrlKey && e.keyCode === 85)) {
                    e.preventDefault(); showFunnyWarning(); return false;
                }
            });
            document.onselectstart = function() { showFunnyWarning(); return false; };
            document.ondragstart = function() { showFunnyWarning(); return false; };
        });
        
        <?php include 'inc/inactivity_reminder.inc.php'; ?>
    </script>
</head>
<body>
    <header class="main-header">
        <div class="header-content">
            <h1>
                <img src="/favicon.svg" alt="<?php echo escape(getSiteTitle()); ?>" class="logo-img" style="width: 40px; height: 40px; display: block;">
                <?php echo escape(getSiteTitle()); ?><?php echo getSiteEmoji(); ?>
            </h1>
            <div class="user-info">
                <span>
                    学号：<?php echo escape($_SESSION['student_no']); ?>
                    <?php if (!empty($_SESSION['student_name'])): ?>
                        | 姓名：<?php echo escape($_SESSION['student_name']); ?>
                    <?php endif; ?>
                    <?php if (!empty($_SESSION['student_class'])): ?>
                        | 班级：<?php echo escape($_SESSION['student_class']); ?>
                    <?php endif; ?>
                </span>
                <a href="records.php">我的记录</a>
                <a href="wrong_questions.php">错题本</a>
                <a href="logout.php">退出</a>
                <a href="help_student.php">使用说明</a>
            </div>
        </div>
    </header>
    
    <div class="container">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
            <h2 style="margin: 0;">选择试卷</h2>
            <?php if ($msg === 'paper_inactive'): ?>
                <div class="alert alert-warning" style="margin: 0; padding: 10px 15px; border-radius: 6px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba;">
                    当前试卷不可用<?php echo $reason ? '：' . escape($reason) : ''; ?>。
                </div>
            <?php endif; ?>
            <div style="display: inline-flex; align-items: center; padding: 10px 20px; background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%); border-radius: 12px; border: 2px solid rgba(102, 126, 234, 0.2);">
                <span style="font-size: 24px; margin-right: 10px;">📝</span>
                <span style="font-size: 18px; font-weight: 600; color: #667eea;">共 <?php echo count($papers); ?> 套试卷</span>
            </div>
        </div>
        
        <?php if (empty($papers)): ?>
            <div class="paper-card" style="text-align: center; padding: 60px 20px; max-width: 500px; margin: 0 auto;">
                <div style="font-size: 64px; margin-bottom: 20px;">📚</div>
                <p style="font-size: 18px; color: #2c3e50; font-weight: 600; margin-bottom: 10px;">暂无可用试卷</p>
                <p style="font-size: 14px; color: #7f8c8d; margin-bottom: 25px;">请联系管理员添加试卷</p>
            </div>
        <?php else: ?>
            <div class="paper-grid">
                <?php foreach ($papers as $paper): ?>
                    <div class="paper-card">
                        <div style="margin-bottom: 15px;">
                            <div style="margin-bottom: 10px;">
                                <span style="display: inline-flex; align-items: center; padding: 6px 12px; background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%); color: #667eea; border-radius: 8px; font-weight: 600; font-size: 13px;">
                                    <?php echo escape($paper['subject_name'] ?? '未分类'); ?>
                                </span>
                            </div>
                            <h3 style="margin: 0; font-size: 22px; line-height: 1.4;"><?php echo escape($paper['title']); ?></h3>
                        </div>
                        <?php if (!empty($paper['start_time']) || !empty($paper['end_time'])): ?>
                            <div style="margin-bottom: 12px; font-size: 13px; color: #555; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <span style="font-weight: 600; color: #34495e;">时间窗口</span>
                                <span>
                                    <?php if (!empty($paper['start_time'])): ?>
                                        开始：<?php echo date('m-d H:i', strtotime($paper['start_time'])); ?>
                                    <?php else: ?>
                                        开始：不限
                                    <?php endif; ?>
                                    <span style="margin: 0 6px;">~</span>
                                    <?php if (!empty($paper['end_time'])): ?>
                                        结束：<?php echo date('m-d H:i', strtotime($paper['end_time'])); ?>
                                    <?php else: ?>
                                        结束：不限
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 20px;">
                            <div style="padding: 12px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 10px; text-align: center;">
                                <div style="font-size: 12px; color: #7f8c8d; margin-bottom: 5px;">总分</div>
                                <div style="font-size: 20px; font-weight: 700; color: #667eea;">
                                    <?php echo $paper['total_score']; ?>
                                    <span style="font-size: 14px; color: #7f8c8d; font-weight: 400;">分</span>
                                </div>
                            </div>
                            <div style="padding: 12px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border-radius: 10px; text-align: center;">
                                <div style="font-size: 12px; color: #7f8c8d; margin-bottom: 5px;">时长</div>
                                <div style="font-size: 20px; font-weight: 700; color: #764ba2;">
                                    <?php echo $paper['duration']; ?>
                                    <span style="font-size: 14px; color: #7f8c8d; font-weight: 400;">分钟</span>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($paper['description'])): ?>
                            <div style="padding: 12px; background: #f8f9fa; border-radius: 10px; margin-bottom: 20px; font-size: 13px; color: #555; line-height: 1.6;">
                                <?php echo nl2br(escape($paper['description'])); ?>
                            </div>
                        <?php endif; ?>
                        <?php
                            $state_text = $paper['is_active'] ? '开始答题 →' : ($paper['state_reason'] ?: '不可用');
                            $is_active = $paper['is_active'];
                        ?>
                        <?php if ($is_active): ?>
                        <a href="exam.php?paper_id=<?php echo $paper['id']; ?>" class="btn btn-primary" style="width: 100%; text-align: center;">
                                <span><?php echo escape($state_text); ?></span>
                        </a>
                        <?php else: ?>
                            <button class="btn btn-secondary" style="width: 100%; text-align: center; cursor: not-allowed; opacity: 0.7;" disabled>
                                <span><?php echo escape($state_text); ?></span>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php include 'inc/footer.php'; ?>
</body>
</html>

