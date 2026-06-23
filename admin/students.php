<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

// 全局刷题次数（完成考试记录总数），用于与首页保持一致
$total_completed_exams = (int)$pdo->query("SELECT COUNT(*) FROM exam_records WHERE status = 'completed'")->fetchColumn();

// 学生刷题列表（按科目拆分，每行是“学生-科目”）
// 统计：该学生在该科目刷到过的不同题数 seen_count；该科目题库总题数 total_count；覆盖率 rate

// ---- 读取筛选与排序参数 ----
$per_page_options = [20, 50, 100, 0]; // 0表示全部
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
if (!in_array($per_page, $per_page_options)) {
    $per_page = 50;
}
$page       = max(1, intval($_GET['page'] ?? 1));
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$sort_by    = $_GET['sort_by'] ?? 'last_practice_at';
$sort_dir   = strtolower($_GET['sort_dir'] ?? 'desc');

// 允许排序的列
$allowed_sort = [
    'student_id'        => 'student_id',
    'student_no'        => 'student_no',
    'student_name'      => 'student_name',
    'subject_name'      => 'subject_name',
    'total_count'       => 'total_count',
    'seen_count'        => 'seen_count',
    'rate'              => 'rate',
    'exam_count'        => 'exam_count',
    'last_practice_at'  => 'last_practice_at',
];

if (!isset($allowed_sort[$sort_by])) {
    $sort_by = 'last_practice_at';
}
if (!in_array($sort_dir, ['asc', 'desc'], true)) {
    $sort_dir = 'desc';
}

// 排序 SQL 片段
$order_sql = " ORDER BY " . $allowed_sort[$sort_by] . " " . strtoupper($sort_dir) . ", student_id DESC, subject_id ASC";

// ---- 科目列表（用于筛选）----
$stmtSubjects = $pdo->query("SELECT id, name FROM subjects ORDER BY id ASC");
$subjects = $stmtSubjects->fetchAll();

// ---- 1. 获取所有班级与科目的抽题池 ----
$class_subject_pools = getClassSubjectQuestionPools($pdo);

// ---- 2. 获取所有科目 ----
$subjects_query = "SELECT id, name FROM subjects ORDER BY id ASC";
$subjects_stmt = $pdo->query($subjects_query);
$subjects_list = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

$filtered_subjects = $subjects_list;
if ($subject_id > 0) {
    $filtered_subjects = [];
    foreach ($subjects_list as $sub) {
        if ((int)$sub['id'] === $subject_id) {
            $filtered_subjects[] = $sub;
            break;
        }
    }
}

// ---- 3. 获取所有学生 ----
$students = $pdo->query("SELECT id, student_no, name, class FROM students ORDER BY student_no ASC")->fetchAll(PDO::FETCH_ASSOC);

// ---- 4. 生成有指定试卷的组合行 ----
$all_rows = [];
foreach ($students as $student) {
    $cls = $student['class'] ?? '';
    $student_id = (int)$student['id'];
    foreach ($filtered_subjects as $sub) {
        $sid = (int)$sub['id'];
        $pool = $class_subject_pools[$cls][$sid] ?? [];
        if (empty($pool)) continue;
        
        $all_rows[] = [
            'student_id' => $student_id,
            'student_no' => $student['student_no'],
            'student_name' => $student['name'] ?? '',
            'class' => $cls,
            'subject_id' => $sid,
            'subject_name' => $sub['name'],
            'total_count' => count($pool),
            'pool' => $pool,
        ];
    }
}

// ---- 5. 批量查询已完成考试的统计数据 ----
$stats_map = [];
$stmtStats = $pdo->query("
    SELECT 
        er.student_id,
        p.subject_id,
        COUNT(*) AS exam_count,
        MAX(er.start_time) AS last_practice_at,
        SUBSTRING_INDEX(GROUP_CONCAT(er.ip ORDER BY er.start_time DESC), ',', 1) AS last_ip
    FROM exam_records er
    JOIN papers p ON er.paper_id = p.id
    WHERE er.status = 'completed'
    GROUP BY er.student_id, p.subject_id
");
foreach ($stmtStats->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = $row['student_id'] . '_' . $row['subject_id'];
    $stats_map[$key] = $row;
}

// ---- 6. 批量查询所有学生的已刷题ID列表 ----
$student_seen = [];
$stmtSeen = $pdo->query("
    SELECT er.student_id, eq.question_id 
    FROM exam_records er
    JOIN exam_questions eq ON eq.exam_record_id = er.id
    WHERE er.status = 'completed'
");
while ($row = $stmtSeen->fetch(PDO::FETCH_ASSOC)) {
    $student_seen[(int)$row['student_id']][] = (int)$row['question_id'];
}

// ---- 7. 补全每一行的 seen_count, rate, exam_count, last_practice_at, last_ip ----
foreach ($all_rows as &$row) {
    $key = $row['student_id'] . '_' . $row['subject_id'];
    $stats = $stats_map[$key] ?? null;
    $row['exam_count'] = $stats ? (int)$stats['exam_count'] : 0;
    $row['last_practice_at'] = $stats ? $stats['last_practice_at'] : null;
    $row['last_ip'] = $stats ? $stats['last_ip'] : '-';
    
    $seen_qids = $student_seen[$row['student_id']] ?? [];
    if (empty($seen_qids)) {
        $row['seen_count'] = 0;
        $row['rate'] = 0.0;
    } else {
        $seen_in_pool = array_intersect($row['pool'], $seen_qids);
        $row['seen_count'] = count($seen_in_pool);
        $row['rate'] = round($row['seen_count'] / $row['total_count'] * 100, 1);
    }
}
unset($row);

// ---- 8. 排序 ----
usort($all_rows, function($a, $b) use ($sort_by, $sort_dir) {
    $valA = $a[$sort_by] ?? null;
    $valB = $b[$sort_by] ?? null;
    
    if ($valA === $valB) {
        return $a['student_id'] <=> $b['student_id'];
    }
    
    if ($valA === null) return 1;
    if ($valB === null) return -1;
    
    if (is_string($valA)) {
        $cmp = strcasecmp($valA, $valB);
    } else {
        $cmp = $valA <=> $valB;
    }
    
    return $sort_dir === 'asc' ? $cmp : -$cmp;
});

// ---- 9. 分页 ----
$total_rows = count($all_rows);
$total_pages = 1;
$offset = 0;
if ($per_page > 0) {
    $total_pages = max(1, (int)ceil($total_rows / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset = ($page - 1) * $per_page;
    $rows = array_slice($all_rows, $offset, $per_page);
} else {
    $rows = $all_rows;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>学生刷题列表 - 后台管理</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/admin.css?v=<?php echo time(); ?>">
    <style>
        /* 紧凑表格样式 */
        .table-container table {
            font-size: 13px;
        }
        
        .table-container table th,
        .table-container table td {
            padding: 6px 8px;
            line-height: 1.3;
            vertical-align: middle;
        }
        
        .table-container table th {
            padding: 8px 8px;
            font-size: 12px;
            white-space: nowrap;
        }
        
        .table-container table td {
            font-size: 13px;
        }
        
        .table-container table th a {
            color: #2c3e50;
            text-decoration: none;
            font-weight: 600;
            display: block;
        }
        
        .table-container table th a:hover {
            color: #667eea;
        }
        
        /* 数字列右对齐，更紧凑 */
        .table-container table td:nth-child(1),
        .table-container table td:nth-child(2),
        .table-container table td:nth-child(7),
        .table-container table td:nth-child(8),
        .table-container table td:nth-child(9),
        .table-container table td:nth-child(10) {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        
        /* 操作列保持左对齐 */
        .table-container table td:last-child {
            text-align: left;
        }
        
        .action-buttons {
            display: flex;
            gap: 6px;
            flex-wrap: nowrap;
        }
        
        .action-buttons .btn {
            padding: 4px 10px;
            font-size: 12px;
            border-radius: 6px;
            white-space: nowrap;
            line-height: 1.2;
        }
        
        .table-container {
            padding: 16px;
        }
        
        /* 减少表格行间距 */
        .table-container table tbody tr {
            height: auto;
        }
        
        /* 优化边框 */
        .table-container table th,
        .table-container table td {
            border-bottom: 1px solid #e0e0e0;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <h2>学生刷题列表
            <?php if ($per_page > 0): ?>
                （共<?php echo $total_rows; ?>条，第<?php echo $page; ?>/<?php echo $total_pages; ?>页）
            <?php else: ?>
                （共<?php echo $total_rows; ?>条，全部显示）
            <?php endif; ?>
        </h2>
        <div style="margin: 8px 0 18px; color: #4b5563; font-size: 13px;">
            总刷题次数（完成考试记录）：<strong><?php echo $total_completed_exams; ?></strong>
        </div>
        
        <div class="table-container">
            <form method="GET" style="margin-bottom: 16px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="subject_id">按科目筛选：</label>
                    <select id="subject_id" name="subject_id" onchange="this.form.page.value = 1; this.form.submit();">
                        <option value="0">全部科目</option>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?php echo $sub['id']; ?>" <?php echo $subject_id == $sub['id'] ? 'selected' : ''; ?>>
                                <?php echo escape($sub['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label for="per_page">每页显示：</label>
                    <select id="per_page" name="per_page" onchange="this.form.page.value = 1; this.form.submit();">
                        <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20条</option>
                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50条</option>
                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100条</option>
                        <option value="0" <?php echo $per_page == 0 ? 'selected' : ''; ?>>全部</option>
                    </select>
                </div>
                <input type="hidden" name="sort_by" value="<?php echo escape($sort_by); ?>">
                <input type="hidden" name="sort_dir" value="<?php echo escape($sort_dir); ?>">
                <input type="hidden" name="page" value="<?php echo $page; ?>">
            </form>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <?php 
                        // 生成带排序的表头链接
                        function sort_link($label, $column, $currentSortBy, $currentSortDir, $subject_id, $page) {
                            $nextDir = 'asc';
                            $arrow = '';
                            if ($currentSortBy === $column) {
                                if ($currentSortDir === 'asc') {
                                    $nextDir = 'desc';
                                    $arrow = ' ▲';
                                } else {
                                    $nextDir = 'asc';
                                    $arrow = ' ▼';
                                }
                            }
                            $query = http_build_query([
                                'subject_id' => $subject_id,
                                'sort_by'    => $column,
                                'sort_dir'   => $nextDir,
                                'page'       => $page,
                            ]);
                            return '<a href="students.php?' . $query . '">' . $label . $arrow . '</a>';
                        }
                        ?>
                        <th><?php echo sort_link('学生ID', 'student_id', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('学号', 'student_no', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('姓名', 'student_name', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('科目', 'subject_name', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('最近刷题时间', 'last_practice_at', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th>IP</th>
                        <th><?php echo sort_link('刷题次数', 'exam_count', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('已刷到题数', 'seen_count', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('题目总数', 'total_count', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th><?php echo sort_link('覆盖率', 'rate', $sort_by, $sort_dir, $subject_id, 1); ?></th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center;">暂无数据</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo $row['student_id']; ?></td>
                                <td><?php echo escape($row['student_no']); ?></td>
                                <td><?php echo escape($row['student_name'] ?? '-'); ?></td>
                                <td><?php echo escape($row['subject_name'] ?? '-'); ?></td>
                                <td style="white-space: nowrap;">
                                    <?php 
                                    if (!empty($row['last_practice_at'])) {
                                        echo date('m-d H:i', strtotime($row['last_practice_at']));
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td style="white-space: nowrap; font-family: monospace;">
                                    <?php echo !empty($row['last_ip']) ? escape($row['last_ip']) : '-'; ?>
                                </td>
                                <td><?php echo $row['exam_count']; ?></td>
                                <td><?php echo $row['seen_count']; ?></td>
                                <td><?php echo $row['total_count']; ?></td>
                                <td><?php echo number_format($row['rate'], 1); ?>%</td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="student_records.php?student_id=<?php echo $row['student_id']; ?>" 
                                           class="btn btn-primary">刷题记录</a>
                                        <a href="question_analysis.php?student_id=<?php echo $row['student_id']; ?>" 
                                           class="btn btn-success">答题分析</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($per_page > 0 && $total_pages > 1): ?>
        <div style="margin-top: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div style="font-size: 13px; color: #6b7280;">
                显示第 <?php echo $offset + 1; ?> - <?php echo min($offset + $per_page, $total_rows); ?> 条，共 <?php echo $total_rows; ?> 条
            </div>
            <div class="pagination">
                <?php
                $buildPageUrl = function($p) use ($subject_id, $sort_by, $sort_dir, $per_page) {
                    return 'students.php?' . http_build_query([
                        'subject_id' => $subject_id,
                        'sort_by'    => $sort_by,
                        'sort_dir'   => $sort_dir,
                        'per_page'   => $per_page,
                        'page'       => $p,
                    ]);
                };
                ?>
                <?php if ($page > 1): ?>
                    <a href="<?php echo $buildPageUrl(1); ?>">&laquo; 首页</a>
                    <a href="<?php echo $buildPageUrl($page - 1); ?>">上一页</a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 3);
                $end   = min($total_pages, $page + 3);
                for ($p = $start; $p <= $end; $p++): ?>
                    <?php if ($p == $page): ?>
                        <span class="current"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="<?php echo $buildPageUrl($p); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="<?php echo $buildPageUrl($page + 1); ?>">下一页</a>
                    <a href="<?php echo $buildPageUrl($total_pages); ?>">末页 &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php include '../inc/footer.php'; ?>
</body>
</html>

