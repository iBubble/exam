<?php
require_once __DIR__ . '/../inc/db.inc.php';

$docx_path = __DIR__ . '/../docs/2025-2026-2大数据技术专业《人工智能数据标注》期末试卷——刘鲲/2025-2026-2大数据技术专业《人工智能数据标注》期末试卷A卷——刘鲲.docx';

if (!file_exists($docx_path)) {
    die("File not found: $docx_path\n");
}

// Helper to extract text from docx
function get_docx_text_and_paragraphs($path) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        die("Failed to open docx file: $path\n");
    }
    
    $xml_content = $zip->getFromName('word/document.xml');
    $zip->close();
    
    if (!$xml_content) {
        die("Failed to read word/document.xml from docx\n");
    }
    
    // Parse XML
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($xml_content);
    libxml_clear_errors();
    
    $paragraphs = [];
    $p_nodes = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p');
    
    foreach ($p_nodes as $p_node) {
        $p_text = '';
        $t_nodes = $p_node->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't');
        foreach ($t_nodes as $t_node) {
            $p_text .= $t_node->nodeValue;
        }
        if (trim($p_text) !== '') {
            $paragraphs[] = trim($p_text);
        }
    }
    
    return $paragraphs;
}

$paragraphs = get_docx_text_and_paragraphs($docx_path);
$all_text = implode("\n", $paragraphs);

// Split using exact positions to avoid splitting header sections inside answer sheet
$pos_sheet = mb_strpos($all_text, 'A卷答题卡');
$pos_key = mb_strpos($all_text, 'A卷答案');

if ($pos_sheet !== false && $pos_key !== false) {
    $paper_text = mb_substr($all_text, 0, $pos_sheet);
    $answer_key_text = mb_substr($all_text, $pos_key);
} else {
    // Fallback split logic
    $parts = preg_split('/A卷答案|参考答案|答案/', $all_text);
    $paper_text = $parts[0];
    $answer_key_text = isset($parts[1]) ? $parts[1] : '';
}

// Parse answers from answer key
$ans_sections = preg_split('/一、单项选择题|二、多项选择题|三、判断题|四、填空题|五、简答题|六、论述题|六、实操论述题|六、分析题/', $answer_key_text);

$single_choice_ans = [];
$multi_choice_ans = [];
$tf_ans = [];
$fill_ans = [];
$short_ans = [];
$disc_ans = [];

// Parse single choices:
if (isset($ans_sections[1])) {
    $sec = $ans_sections[1];
    preg_match_all('/\b([A-D])\b/', $sec, $matches);
    foreach ($matches[1] as $idx => $letter) {
        $single_choice_ans[$idx + 1] = $letter;
    }
}

// Parse multi choices:
if (isset($ans_sections[2])) {
    $sec = $ans_sections[2];
    preg_match_all('/\b([A-D]{2,4})\b/', $sec, $matches);
    foreach ($matches[1] as $idx => $letter) {
        $multi_choice_ans[$idx + 1] = $letter;
    }
}

// Parse T/F:
if (isset($ans_sections[3])) {
    $sec = $ans_sections[3];
    preg_match_all('/([√×])/', $sec, $matches);
    foreach ($matches[1] as $idx => $sym) {
        $tf_ans[$idx + 1] = ($sym == '√') ? '正确' : '错误';
    }
}

// Parse fills:
if (isset($ans_sections[4])) {
    $sec = $ans_sections[4];
    $lines = explode("\n", $sec);
    $idx = 1;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '得 分') !== false || strpos($line, '评卷人') !== false) {
            continue;
        }
        // Remove leading numbers
        $cleaned = preg_replace('/^\d+[\.\s、]+/', '', $line);
        // Split by tabs or multiple spaces
        $parts_line = preg_split('/\s{2,}|\t/', $cleaned);
        foreach ($parts_line as $p) {
            $p = trim($p);
            if ($p !== '') {
                $fill_ans[$idx] = $p;
                $idx++;
            }
        }
    }
}

// Parse short answers:
if (isset($ans_sections[5])) {
    $sec = $ans_sections[5];
    $lines = explode("\n", $sec);
    $current_idx = null;
    $current_text = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '得 分') !== false || strpos($line, '评卷人') !== false) {
            continue;
        }
        if (preg_match('/^(\d+)[\.\s、]+(.*)/', $line, $match)) {
            if ($current_idx !== null) {
                $short_ans[$current_idx] = implode(" ", $current_text);
            }
            $current_idx = intval($match[1]);
            $current_text = [trim($match[2])];
        } else {
            if ($current_idx !== null) {
                $current_text[] = $line;
            }
        }
    }
    if ($current_idx !== null) {
        $short_ans[$current_idx] = implode(" ", $current_text);
    }
}

// Parse disc answers:
if (isset($ans_sections[6])) {
    $sec = $ans_sections[6];
    $lines = explode("\n", $sec);
    $current_idx = null;
    $current_text = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '得 分') !== false || strpos($line, '评卷人') !== false) {
            continue;
        }
        if (preg_match('/^(\d+)[\.\s、]+(.*)/', $line, $match)) {
            if ($current_idx !== null) {
                $disc_ans[$current_idx] = implode(" ", $current_text);
            }
            $current_idx = intval($match[1]);
            $current_text = [trim($match[2])];
        } else {
            if ($current_idx !== null) {
                $current_text[] = $line;
            }
        }
    }
    if ($current_idx !== null) {
        $disc_ans[$current_idx] = implode(" ", $current_text);
    }
}

// Parse the questions from the exam paper section
$paper_sections = preg_split('/一、单项选择题|二、多项选择题|三、判断题|四、填空题|五、简答题|六、论述题|六、实操论述题|六、分析题/', $paper_text);

$docx_questions = [];

function clean_docx_line($line) {
    // Strip page numbering strings
    $line = preg_replace('/第\s*\d+\s*页\s*（\s*共\s*\d+\s*页\s*）/u', '', $line);
    $line = preg_replace('/第\s*\d+\s*页/u', '', $line);
    $line = preg_replace('/共\s*\d+\s*页/u', '', $line);
    return trim($line);
}

function parse_choices($text, $q_type, $answers_map) {
    $lines = explode("\n", $text);
    $questions = [];
    $current_q = null;
    
    foreach ($lines as $line) {
        $line = clean_docx_line($line);
        if ($line === '' || strpos($line, '第 ') === 0 || strpos($line, '共') !== false) {
            continue;
        }
        
        if (preg_match('/^(\d+)[\.\s、．]+(.*)/u', $line, $match_q)) {
            if ($current_q) {
                $questions[] = $current_q;
            }
            $q_num = intval($match_q[1]);
            $current_q = [
                'num' => $q_num,
                'type' => $q_type,
                'text' => trim($match_q[2]),
                'option_a' => '',
                'option_b' => '',
                'option_c' => '',
                'option_d' => '',
                'answer' => isset($answers_map[$q_num]) ? $answers_map[$q_num] : ''
            ];
        } else {
            if ($current_q) {
                // Find options A, B, C, D
                preg_match_all('/([A-D])[\.\s、．]+(.*?)(?=\s+[A-D][\.\s、．]|$)/u', $line, $opts, PREG_SET_ORDER);
                if (!empty($opts)) {
                    foreach ($opts as $opt) {
                        $opt_letter = strtoupper($opt[1]);
                        $opt_val = trim($opt[2]);
                        if ($opt_letter === 'A') $current_q['option_a'] = $opt_val;
                        elseif ($opt_letter === 'B') $current_q['option_b'] = $opt_val;
                        elseif ($opt_letter === 'C') $current_q['option_c'] = $opt_val;
                        elseif ($opt_letter === 'D') $current_q['option_d'] = $opt_val;
                    }
                } else {
                    $current_q['text'] .= " " . $line;
                }
            }
        }
    }
    
    if ($current_q) {
        $questions[] = $current_q;
    }
    return $questions;
}

function parse_tf($text, $answers_map) {
    $lines = explode("\n", $text);
    $questions = [];
    foreach ($lines as $line) {
        $line = clean_docx_line($line);
        if ($line === '' || strpos($line, '第 ') === 0 || strpos($line, '共') !== false) {
            continue;
        }
        if (preg_match('/^(\d+)[\.\s、．]+(.*)/u', $line, $match)) {
            $q_num = intval($match[1]);
            $questions[] = [
                'num' => $q_num,
                'type' => '判断题',
                'text' => trim($match[2]),
                'option_a' => '',
                'option_b' => '',
                'option_c' => '',
                'option_d' => '',
                'answer' => isset($answers_map[$q_num]) ? $answers_map[$q_num] : ''
            ];
        }
    }
    return $questions;
}

function parse_fills($text, $answers_map) {
    $lines = explode("\n", $text);
    $questions = [];
    foreach ($lines as $line) {
        $line = clean_docx_line($line);
        if ($line === '' || strpos($line, '第 ') === 0 || strpos($line, '共') !== false) {
            continue;
        }
        if (preg_match('/^(\d+)[\.\s、．]+(.*)/u', $line, $match)) {
            $q_num = intval($match[1]);
            $questions[] = [
                'num' => $q_num,
                'type' => '填空题',
                'text' => trim($match[2]),
                'option_a' => '',
                'option_b' => '',
                'option_c' => '',
                'option_d' => '',
                'answer' => isset($answers_map[$q_num]) ? $answers_map[$q_num] : ''
            ];
        }
    }
    return $questions;
}

function parse_subjectives($text, $q_type, $answers_map) {
    $lines = explode("\n", $text);
    $questions = [];
    $current_q = null;
    foreach ($lines as $line) {
        $line = clean_docx_line($line);
        if ($line === '' || strpos($line, '第 ') === 0 || strpos($line, '共') !== false || strpos($line, '评卷人') !== false || strpos($line, '得 分') !== false) {
            continue;
        }
        if (preg_match('/^(\d+)[\.\s、．]+(.*)/u', $line, $match)) {
            if ($current_q) {
                $questions[] = $current_q;
            }
            $q_num = intval($match[1]);
            $current_q = [
                'num' => $q_num,
                'type' => $q_type,
                'text' => trim($match[2]),
                'option_a' => '',
                'option_b' => '',
                'option_c' => '',
                'option_d' => '',
                'answer' => isset($answers_map[$q_num]) ? $answers_map[$q_num] : ''
            ];
        } else {
            if ($current_q) {
                $current_q['text'] .= " " . $line;
            }
        }
    }
    if ($current_q) {
        $questions[] = $current_q;
    }
    return $questions;
}

// 1. Single choices
if (isset($paper_sections[1])) {
    $docx_questions = array_merge($docx_questions, parse_choices($paper_sections[1], '单选题', $single_choice_ans));
}
// 2. Multi choices
if (isset($paper_sections[2])) {
    $docx_questions = array_merge($docx_questions, parse_choices($paper_sections[2], '多选题', $multi_choice_ans));
}
// 3. T/F
if (isset($paper_sections[3])) {
    $docx_questions = array_merge($docx_questions, parse_tf($paper_sections[3], $tf_ans));
}
// 4. Fills
if (isset($paper_sections[4])) {
    $docx_questions = array_merge($docx_questions, parse_fills($paper_sections[4], $fill_ans));
}
// 5. Short answers
if (isset($paper_sections[5])) {
    $docx_questions = array_merge($docx_questions, parse_subjectives($paper_sections[5], '简答题', $short_ans));
}
// 6. Disc answers
if (isset($paper_sections[6])) {
    $docx_questions = array_merge($docx_questions, parse_subjectives($paper_sections[6], '实操论述题', $disc_ans));
}

// Fetch DB questions for paper_id = 13
$stmt = $pdo->prepare("SELECT * FROM questions WHERE paper_id = 13 ORDER BY id ASC");
$stmt->execute();
$db_questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

function clean_text($text) {
    if (!$text) return '';
    $text = preg_replace('/[\s\p{P}“”‘’，。！？、()（）\?\.\:\：]/u', '', $text);
    return mb_strtolower($text);
}

echo "Database count: " . count($db_questions) . "\n";
echo "Docx count: " . count($docx_questions) . "\n\n";

$matched_db_ids = [];
$differences = [];

foreach ($docx_questions as $dq) {
    $dq_text_clean = clean_text($dq['text']);
    $best_match = null;
    
    foreach ($db_questions as $dbq) {
        if (in_array($dbq['id'], $matched_db_ids)) {
            continue;
        }
        
        if ($dbq['question_type'] === $dq['type']) {
            $db_text_clean = clean_text($dbq['question_text']);
            if ($db_text_clean === $dq_text_clean || mb_strpos($db_text_clean, $dq_text_clean) !== false || mb_strpos($dq_text_clean, $db_text_clean) !== false) {
                $best_match = $dbq;
                break;
            }
        }
    }
    
    if ($best_match) {
        $matched_db_ids[] = $best_match['id'];
        $diffs = [];
        
        // Compare answers
        $db_ans = clean_text($best_match['correct_answer']);
        $dq_ans = clean_text($dq['answer']);
        if ($db_ans !== $dq_ans) {
            $diffs[] = "Answer differs: DB='{$best_match['correct_answer']}', DOCX='{$dq['answer']}'";
        }
        
        // Compare options for choices
        if ($dq['type'] === '单选题' || $dq['type'] === '多选题') {
            foreach (['option_a', 'option_b', 'option_c', 'option_d'] as $opt) {
                $db_opt = clean_text($best_match[$opt]);
                $dq_opt = clean_text($dq[$opt]);
                if ($db_opt !== $dq_opt) {
                    $diffs[] = strtoupper($opt) . " differs: DB='{$best_match[$opt]}', DOCX='{$dq[$opt]}'";
                }
            }
        }
        
        if (!empty($diffs)) {
            $differences[] = [
                'type' => $dq['type'],
                'docx_num' => $dq['num'],
                'db_id' => $best_match['id'],
                'text' => $dq['text'],
                'diffs' => $diffs
            ];
        }
    } else {
        $differences[] = [
            'type' => $dq['type'],
            'docx_num' => $dq['num'],
            'db_id' => null,
            'text' => $dq['text'],
            'diffs' => ["Could not match this docx question in the database"]
        ];
    }
}

// Check unmatched DB questions
foreach ($db_questions as $dbq) {
    if (!in_array($dbq['id'], $matched_db_ids)) {
        $differences[] = [
            'type' => $dbq['question_type'],
            'docx_num' => null,
            'db_id' => $dbq['id'],
            'text' => $dbq['question_text'],
            'diffs' => ["Could not match this DB question in the docx document"]
        ];
    }
}

// Print report
echo "Found " . count($differences) . " differences:\n";
foreach ($differences as $d) {
    echo "------------------------------------------------------------\n";
    echo "Type: {$d['type']} | Docx Num: " . ($d['docx_num'] ?? 'N/A') . " | DB ID: " . ($d['db_id'] ?? 'N/A') . "\n";
    echo "Text: " . mb_substr($d['text'], 0, 70) . "...\n";
    foreach ($d['diffs'] as $diff) {
        echo "  * $diff\n";
    }
}
