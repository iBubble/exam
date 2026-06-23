<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

// 获取学生ID（从URL参数或表单提交）
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : (isset($_POST['student_id']) ? intval($_POST['student_id']) : 0);

// 获取所有学生
// 优化：只选择需要的字段
$stmt = $pdo->query("SELECT id, student_no, name, class FROM students ORDER BY id DESC");
$all_students = $stmt->fetchAll();

// 获取指定学生的刷题记录
$records = [];
$student_info = null;
$coverage_stats = []; // 按科目统计题目覆盖率
if ($student_id > 0) {
    // 获取学生信息
    // 优化：只选择需要的字段
    $stmt = $pdo->prepare("SELECT id, student_no, name, class FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student_info = $stmt->fetch();
    
    if ($student_info) {
        // 获取该学生的所有考试记录
        $stmt = $pdo->prepare("SELECT er.*, p.title as paper_title, s.name as subject_name 
                               FROM exam_records er 
                               LEFT JOIN papers p ON er.paper_id = p.id 
                               LEFT JOIN subjects s ON p.subject_id = s.id 
                               WHERE er.student_id = ? 
                               ORDER BY er.created_at DESC");
        $stmt->execute([$student_id]);
        $records = $stmt->fetchAll();
        
        // ========= 计算题目覆盖率（按科目） =========
        // 根据该学生班级指定的试卷题目计算覆盖率
        $coverage_stats = getStudentCoverageStats($pdo, $student_id, $student_info['class'] ?? null);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>学生刷题记录 - 后台管理</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/admin.css?v=<?php echo time(); ?>">
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <h2>学生刷题记录</h2>
        
        <div class="table-container">
            <h2>选择学生</h2>
            <form method="GET" style="margin-top: 20px;">
                <div class="form-group">
                    <label>选择学生</label>
                    <select name="student_id" onchange="this.form.submit()">
                        <option value="">请选择学生</option>
                        <?php foreach ($all_students as $student): ?>
                            <option value="<?php echo $student['id']; ?>" 
                                <?php echo $student_id == $student['id'] ? 'selected' : ''; ?>>
                                <?php echo escape($student['student_no']); ?> 
                                <?php if (!empty($student['name'])): ?>
                                    (<?php echo escape($student['name']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
        
        <?php if ($student_info && !empty($records)): ?>
        <div class="table-container">
            <h2>
                学号：<?php echo escape($student_info['student_no']); ?>
                <?php if (!empty($student_info['name'])): ?>
                    （<?php echo escape($student_info['name']); ?>）
                <?php endif; ?>
                - 刷题记录（共<?php echo count($records); ?>条）
            </h2>
            
            <?php if (!empty($coverage_stats)): ?>
            <div style="margin: 15px 0 10px; padding: 12px 14px; border-radius: 10px; background: linear-gradient(135deg, #eef2ff 0%, #f5f5ff 100%); border: 1px solid rgba(129, 140, 248, 0.4);">
                <div style="font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 8px;">按科目统计题目覆盖率</div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>科目</th>
                                <th>题目总数</th>
                                <th>已刷到题数</th>
                                <th>覆盖率</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($coverage_stats as $row): ?>
                                <tr>
                                    <td><?php echo escape($row['subject_name']); ?></td>
                                    <td><?php echo $row['total_count']; ?></td>
                                    <td><?php echo $row['seen_count']; ?></td>
                                    <td><?php echo number_format($row['rate'], 1); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p style="font-size: 12px; color: #6b7280; margin-top: 6px;">
                    覆盖率 = 该学生在该科目中刷到过的不同题目数量 ÷ 该生班级指定的该科目试卷去重题目总数。
                </p>
            </div>
            <?php endif; ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>试卷</th>
                        <th>科目</th>
                        <th>开始时间</th>
                        <th>结束时间</th>
                        <th>得分</th>
                        <th>总分</th>
                        <th>用时</th>
                        <th>状态</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <tr>
                            <td><?php echo $record['id']; ?></td>
                            <td><?php echo escape($record['paper_title'] ?? ''); ?></td>
                            <td><?php echo escape($record['subject_name'] ?? ''); ?></td>
                            <td><?php echo $record['start_time']; ?></td>
                            <td><?php echo $record['end_time'] ?? '-'; ?></td>
                            <td><?php echo $record['score'] ?? '-'; ?></td>
                            <td><?php echo $record['total_score'] ?? '-'; ?></td>
                            <td>
                                <?php 
                                if ($record['duration'] !== null && $record['duration'] > 0) {
                                    $minutes = floor($record['duration'] / 60);
                                    $seconds = $record['duration'] % 60;
                                    echo $minutes . '分' . $seconds . '秒';
                                } elseif ($record['start_time'] && $record['end_time']) {
                                    $start = strtotime($record['start_time']);
                                    $end = strtotime($record['end_time']);
                                    $duration = $end - $start;
                                    $minutes = floor($duration / 60);
                                    $seconds = $duration % 60;
                                    echo $minutes . '分' . $seconds . '秒';
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                if ($record['status'] == 'completed') {
                                    echo '<span style="color: #27ae60;">已完成</span>';
                                } else {
                                    echo '<span style="color: #f39c12;">进行中</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <a href="../exam_result.php?exam_record_id=<?php echo $record['id']; ?>" 
                                   target="_blank" class="btn btn-primary">查看详情</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php elseif ($student_id > 0 && empty($records)): ?>
            <div class="table-container">
                <p style="text-align: center; color: #999;">该学生暂无刷题记录</p>
            </div>
        <?php endif; ?>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

