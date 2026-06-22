<?php
require_once '../inc/db.inc.php';
require_once '../inc/functions.inc.php';
startAdminSession();
checkAdminLogin();

// Autoload composer dependencies
require_once '../vendor/autoload.php';

$error = '';
$success = '';

// Handle actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'upload') {
    if (!isset($_FILES['word_file']) || $_FILES['word_file']['error'] !== UPLOAD_ERR_OK) {
        $error = '请选择一个Word文件（.docx）上传！';
    } else {
        $file_name = $_FILES['word_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        if ($file_ext !== 'docx') {
            $error = '目前只支持上传 .docx 格式的Word文件！';
        } else {
            $temp_dir = sys_get_temp_dir();
            $temp_file = $temp_dir . '/xuexitong_' . uniqid() . '.docx';
            
            if (move_uploaded_file($_FILES['word_file']['tmp_name'], $temp_file)) {
                // Execute python parser
                $python_script = __DIR__ . '/parse_word.py';
                $cmd = "python3 " . escapeshellarg($python_script) . " " . escapeshellarg($temp_file);
                
                $output = shell_exec($cmd);
                unlink($temp_file); // Clean up uploaded file
                
                if (empty($output)) {
                    $error = 'Word文件解析失败，未收到解析器的任何输出！';
                } else {
                    $result = json_decode($output, true);
                    if ($result === null) {
                        $error = 'JSON解析错误：无法解码解析器的输出！';
                    } elseif (isset($result['error'])) {
                        $error = '解析错误：' . $result['error'];
                    } else {
                        $_SESSION['xuexitong_parsed_questions'] = $result;
                        
                        // Get subject name from first parsed question
                        $subject_name = 'Word导入';
                        if (!empty($result)) {
                            $subject_name = $result[0]['directory'];
                        }
                        $_SESSION['xuexitong_subject'] = $subject_name;
                        $success = '成功解析了 ' . count($result) . ' 道题目！';
                    }
                }
            } else {
                $error = '文件上传保存失败，请检查临时目录权限！';
            }
        }
    }
} elseif ($action === 'clear') {
    unset($_SESSION['xuexitong_parsed_questions']);
    unset($_SESSION['xuexitong_subject']);
    header('Location: xuexitong.php');
    exit;
} elseif ($action === 'export') {
    $questions = $_SESSION['xuexitong_parsed_questions'] ?? [];
    $subject_name = $_SESSION['xuexitong_subject'] ?? 'Word导入';
    
    if (empty($questions)) {
        header('Location: xuexitong.php');
        exit;
    }
    
    $template_path = '../docs/题目导入模板.xlsx';
    if (!file_exists($template_path)) {
        // Fallback: create new spreadsheet if template is missing
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        // Set headers
        $headers = ['目录', '题目类型', '大题题干', '选项A', '选项B', '选项C', '选项D', '正确答案', '答案解析', '知识点'];
        foreach ($headers as $col => $header) {
            $sheet->setCellValueByColumnAndRow($col + 1, 1, $header);
        }
    } else {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($template_path);
        $sheet = $spreadsheet->getActiveSheet();
    }
    
    $row_num = 2;
    foreach ($questions as $q) {
        $sheet->setCellValue('A' . $row_num, $q['directory']);
        $sheet->setCellValue('B' . $row_num, $q['type']);
        $sheet->setCellValue('C' . $row_num, $q['stem']);
        $sheet->setCellValue('D' . $row_num, $q['option_a']);
        $sheet->setCellValue('E' . $row_num, $q['option_b']);
        $sheet->setCellValue('F' . $row_num, $q['option_c']);
        $sheet->setCellValue('G' . $row_num, $q['option_d']);
        $sheet->setCellValue('H' . $row_num, $q['answer']);
        $sheet->setCellValue('I' . $row_num, $q['analysis']);
        $sheet->setCellValue('J' . $row_num, $q['knowledge']);
        $row_num++;
    }
    
    // Clear buffer to prevent corrupt downloads
    if (ob_get_length()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . urlencode($subject_name . '_导入模板.xlsx') . '"');
    header('Cache-Control: max-age=0');
    
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

$parsed_questions = $_SESSION['xuexitong_parsed_questions'] ?? [];
$subject_name = $_SESSION['xuexitong_subject'] ?? '';

// Calculate counts per type
$counts = [];
foreach ($parsed_questions as $q) {
    $counts[$q['type']] = ($counts[$q['type']] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>学习通Word解析器 - 后台管理</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="alternate icon" href="/favicon.svg">
    <link rel="stylesheet" href="css/admin.css?v=<?php echo time(); ?>">
    <style>
        .upload-card {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            text-align: center;
            border: 2px dashed #3498db;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .upload-card:hover {
            border-color: #2980b9;
            box-shadow: 0 6px 25px rgba(52, 152, 219, 0.15);
        }
        
        .upload-icon {
            font-size: 50px;
            color: #3498db;
            margin-bottom: 15px;
            display: inline-block;
        }
        
        .file-input-wrapper {
            position: relative;
            display: inline-block;
            margin-top: 15px;
        }
        
        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-top: 4px solid #3498db;
            text-align: center;
        }
        
        .stat-card h4 {
            margin: 0 0 8px 0;
            font-size: 14px;
            color: #666;
        }
        
        .stat-card span {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-danger {
            background-color: #fadbd8;
            color: #78281f;
            border-left: 5px solid #e74c3c;
        }
        
        .alert-success {
            background-color: #d4efdf;
            color: #196f3d;
            border-left: 5px solid #2ecc71;
        }
        
        .preview-table th, .preview-table td {
            padding: 10px 12px;
            font-size: 13px;
        }
        
        .option-tag {
            display: inline-block;
            background: #ebf5fb;
            color: #2980b9;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            margin-right: 5px;
        }
        
        .option-item {
            margin-bottom: 4px;
            color: #555;
        }
        
        .action-bar {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .loading-overlay {
            display: none;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            z-index: 10;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            border-radius: 12px;
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <h2 style="margin: 0;">学习通Word试卷解析器</h2>
            <a href="questions.php" class="btn btn-secondary" style="font-size: 13px; padding: 6px 12px;">返回题库管理</a>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo escape($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo escape($success); ?></div>
        <?php endif; ?>

        <div class="upload-card">
            <div class="loading-overlay" id="loadingOverlay">
                <div class="spinner"></div>
                <div style="font-weight: 500; color: #2c3e50;">正在解析Word文件，请稍候...</div>
            </div>
            
            <span class="upload-icon">📄</span>
            <h3 style="margin: 0 0 10px 0;">解析学习通Word试卷</h3>
            <p style="color: #666; margin: 0 auto; max-width: 600px; font-size: 14px; line-height: 1.5;">
                支持上传由学习通/超星平台导出的含有<strong>试卷题目、答题卡、以及参考答案</strong>的 <code>.docx</code> 格式文件。
                系统将自动拆分题干与答案并将其合并对齐，导出符合系统导入规范的Excel模板文件。
            </p>
            
            <form method="POST" enctype="multipart/form-data" action="xuexitong.php" id="uploadForm" onsubmit="showLoading()">
                <input type="hidden" name="action" value="upload">
                <div class="file-input-wrapper">
                    <button type="button" class="btn btn-primary" id="selectBtn">选择Word文件 (.docx)</button>
                    <input type="file" name="word_file" accept=".docx" required onchange="handleFileSelected(this)">
                </div>
                <div id="file-info" style="margin-top: 10px; font-weight: bold; color: #2c3e50;"></div>
                <div style="margin-top: 15px;">
                    <button type="submit" class="btn btn-success" id="submitBtn" style="display: none; padding: 10px 24px; font-size: 15px;">开始解析文件</button>
                </div>
            </form>
        </div>

        <?php if (!empty($parsed_questions)): ?>
            <div class="table-container">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #3498db; padding-bottom: 15px; margin-bottom: 20px;">
                    <h3 style="margin: 0; font-size: 18px;">
                        解析预览：<?php echo escape($subject_name); ?>
                    </h3>
                    <div style="display: flex; gap: 10px;">
                        <a href="xuexitong.php?action=export" class="btn btn-primary" style="background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%); border: none;">
                            📥 导出为Excel导入模板
                        </a>
                        <a href="xuexitong.php?action=clear" class="btn btn-danger" onclick="return confirm('确定要清空当前的解析记录吗？')">
                            🗑️ 清空
                        </a>
                    </div>
                </div>

                <div class="stats-container">
                    <div class="stat-card" style="border-color: #34495e;">
                        <h4>总题数</h4>
                        <span><?php echo count($parsed_questions); ?></span>
                    </div>
                    <?php foreach ($counts as $type => $count): ?>
                        <div class="stat-card">
                            <h4><?php echo escape($type); ?></h4>
                            <span><?php echo $count; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="overflow-x: auto; margin-top: 20px;">
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th style="width: 50px; text-align: center;">序号</th>
                                <th style="width: 100px;">题型</th>
                                <th>题干 / 选项</th>
                                <th style="width: 120px;">正确答案</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parsed_questions as $idx => $q): ?>
                                <tr>
                                    <td style="text-align: center; font-weight: bold; color: #7f8c8d;"><?php echo $idx + 1; ?></td>
                                    <td>
                                        <span class="badge" style="background: #3498db; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px;">
                                            <?php echo escape($q['type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500; font-size: 14px; margin-bottom: 8px; line-height: 1.4; color: #2c3e50;">
                                            <?php echo nl2br(escape($q['stem'])); ?>
                                        </div>
                                        <?php if ($q['type'] === '单选题' || $q['type'] === '多选题'): ?>
                                            <div style="padding-left: 10px; border-left: 2px solid #bdc3c7;">
                                                <?php if (!empty($q['option_a'])): ?>
                                                    <div class="option-item"><span class="option-tag">A</span><?php echo escape($q['option_a']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($q['option_b'])): ?>
                                                    <div class="option-item"><span class="option-tag">B</span><?php echo escape($q['option_b']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($q['option_c'])): ?>
                                                    <div class="option-item"><span class="option-tag">C</span><?php echo escape($q['option_c']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($q['option_d'])): ?>
                                                    <div class="option-item"><span class="option-tag">D</span><?php echo escape($q['option_d']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight: bold; color: #27ae60; font-size: 14px; vertical-align: top; padding-top: 12px; line-height: 1.4;">
                                        <?php echo nl2br(escape($q['answer'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <?php include '../inc/footer.php'; ?>
    
    <script>
        function handleFileSelected(input) {
            const fileInfo = document.getElementById('file-info');
            const submitBtn = document.getElementById('submitBtn');
            const selectBtn = document.getElementById('selectBtn');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                fileInfo.textContent = `已选择: ${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
                submitBtn.style.display = 'inline-block';
                selectBtn.textContent = '更改文件';
            } else {
                fileInfo.textContent = '';
                submitBtn.style.display = 'none';
                selectBtn.textContent = '选择Word文件 (.docx)';
            }
        }
        
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
    </script>
</body>
</html>
