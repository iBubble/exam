<?php
$docx_path = __DIR__ . '/../docs/2025-2026-2大数据技术专业《人工智能数据标注》期末试卷——刘鲲/2025-2026-2大数据技术专业《人工智能数据标注》期末试卷A卷——刘鲲.docx';

$zip = new ZipArchive();
$zip->open($docx_path);
$xml_content = $zip->getFromName('word/document.xml');
$zip->close();

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

$all_text = implode("\n", $paragraphs);
$pos_sheet = mb_strpos($all_text, 'A卷答题卡');
$pos_key = mb_strpos($all_text, 'A卷答案');

if ($pos_sheet !== false && $pos_key !== false) {
    $paper_text = mb_substr($all_text, 0, $pos_sheet);
    $answer_key_text = mb_substr($all_text, $pos_key);
} else {
    $parts = preg_split('/A卷答案|参考答案|答案/u', $all_text);
    $paper_text = $parts[0];
    $answer_key_text = isset($parts[1]) ? $parts[1] : '';
}

// 1. Parse Answers
$ans_sections = preg_split('/一、单项选择题|二、多项选择题|三、判断题|四、填空题|五、简答题|六、论述题|六、实操论述题|六、分析题/u', $answer_key_text);

$single_choice_ans = [];
$multi_choice_ans = [];
$tf_ans = [];
$fill_ans = [];
$short_ans = [];
$disc_ans = [];

// Parse single choices:
if (isset($ans_sections[1])) {
    preg_match_all('/\b([A-D])\b/', $ans_sections[1], $matches);
    foreach ($matches[1] as $idx => $letter) {
        $single_choice_ans[$idx + 1] = $letter;
    }
}
// Parse multi choices:
if (isset($ans_sections[2])) {
    preg_match_all('/\b([A-D]{2,4})\b/', $ans_sections[2], $matches);
    foreach ($matches[1] as $idx => $letter) {
        $multi_choice_ans[$idx + 1] = $letter;
    }
}
// Parse T/F:
if (isset($ans_sections[3])) {
    preg_match_all('/([√×])/u', $ans_sections[3], $matches);
    foreach ($matches[1] as $idx => $sym) {
        $tf_ans[$idx + 1] = ($sym == '√') ? '正确' : '错误';
    }
}
// Parse fills:
if (isset($ans_sections[4])) {
    $lines = explode("\n", $ans_sections[4]);
    $idx = 1;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '得 分') !== false || strpos($line, '评卷人') !== false) continue;
        $cleaned = preg_replace('/^\d+[\.\s、．]+/u', '', $line);
        $parts_line = preg_split('/\s{2,}|\t|\x{00a0}{2,}/u', $cleaned);
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
    $lines = explode("\n", $ans_sections[5]);
    $current_idx = null;
    $current_text = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '得 分') !== false || strpos($line, '评卷人') !== false) continue;
        if (preg_match('/^(\d+)[\.\s、．]+/u', $line, $match)) {
            if ($current_idx !== null) {
                $short_ans[$current_idx] = implode("\n", $current_text);
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
        $short_ans[$current_idx] = implode("\n", $current_text);
    }
}
// Parse disc answers:
if (isset($ans_sections[6])) {
    $lines = explode("\n", $ans_sections[6]);
    $current_idx = null;
    $current_text = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '得 分') !== false || strpos($line, '评卷人') !== false) continue;
        if (preg_match('/^(\d+)[\.\s、．]+/u', $line, $match)) {
            if ($current_idx !== null) {
                $disc_ans[$current_idx] = implode("\n", $current_text);
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
        $disc_ans[$current_idx] = implode("\n", $current_text);
    }
}

// 2. Parse Paper Sections
$paper_sections = preg_split('/一、单项选择题|二、多项选择题|三、判断题|四、填空题|五、简答题|六、论述题|六、实操论述题|六、分析题/u', $paper_text);

function clean_docx_line($line) {
    $line = preg_replace('/第\s*\d+\s*页\s*[（(]\s*共\s*\d+\s*页\s*[）)]/u', '', $line);
    $line = preg_replace('/第\s*\d+\s*页/u', '', $line);
    $line = preg_replace('/共\s*\d+\s*页/u', '', $line);
    return trim($line);
}

function parse_section_questions($text, $q_type, $answers_map) {
    $lines = explode("\n", $text);
    $questions = [];
    $current_q = null;
    $q_num_internal = 1;
    
    foreach ($lines as $line) {
        $line = clean_docx_line($line);
        if ($line === '' || strpos($line, '得 分') !== false || strpos($line, '评卷人') !== false) {
            continue;
        }
        
        // Skip header info block
        if (strpos($line, '(') === 0 || strpos($line, '（') === 0 || strpos($line, '每题') !== false || strpos($line, '每小题') !== false || strpos($line, '本大题') !== false) {
            continue;
        }
        
        // Check if there's options in the line
        $has_options = preg_match('/[A-D][\.\s、．\x{00a0}]+/u', $line);
        
        // Check if line starts with a number like "1.", "10." etc. (either typed or parsed)
        $starts_with_num = preg_match('/^(\d+)[\.\s、．]+(.*)/u', $line, $match_q);
        
        if ($starts_with_num) {
            if ($current_q) {
                $questions[] = $current_q;
                $q_num_internal++;
            }
            $q_num = intval($match_q[1]);
            $q_text = trim($match_q[2]);
            
            // Separate option part if any
            $opt_pos = preg_locate_options($q_text);
            if ($opt_pos !== false) {
                $opts_str = substr($q_text, $opt_pos);
                $q_text = trim(substr($q_text, 0, $opt_pos));
            } else {
                $opts_str = '';
            }
            
            $current_q = [
                'num' => $q_num,
                'type' => $q_type,
                'text' => $q_text,
                'option_a' => '',
                'option_b' => '',
                'option_c' => '',
                'option_d' => '',
                'answer' => isset($answers_map[$q_num]) ? $answers_map[$q_num] : ''
            ];
            
            if ($opts_str !== '') {
                parse_options_into($opts_str, $current_q);
            }
        } else {
            // Does not start with number
            if ($has_options) {
                if ($current_q) {
                    parse_options_into($line, $current_q);
                }
            } else {
                // Just text, either create new question (if none exists or if it's tf/fill/short where we don't have typed numbers sometimes)
                // Or append to current question
                $is_subjective = in_array($q_type, ['判断题', '填空题', '简答题', '实操论述题']);
                if ($is_subjective && !$current_q) {
                    $current_q = [
                        'num' => $q_num_internal,
                        'type' => $q_type,
                        'text' => $line,
                        'option_a' => '',
                        'option_b' => '',
                        'option_c' => '',
                        'option_d' => '',
                        'answer' => isset($answers_map[$q_num_internal]) ? $answers_map[$q_num_internal] : ''
                    ];
                } elseif ($current_q) {
                    $current_q['text'] .= " " . $line;
                } else {
                    // Create new objective question without number
                    $current_q = [
                        'num' => $q_num_internal,
                        'type' => $q_type,
                        'text' => $line,
                        'option_a' => '',
                        'option_b' => '',
                        'option_c' => '',
                        'option_d' => '',
                        'answer' => isset($answers_map[$q_num_internal]) ? $answers_map[$q_num_internal] : ''
                    ];
                }
            }
        }
    }
    
    if ($current_q) {
        $questions[] = $current_q;
    }
    return $questions;
}

function preg_locate_options($text) {
    if (preg_match('/[A-D][\.\s、．\x{00a0}]+/u', $text, $m, PREG_OFFSET_CAPTURE)) {
        return $m[0][1];
    }
    return false;
}

function parse_options_into($text, &$q) {
    preg_match_all('/([A-D])[\.\s、．\x{00a0}]+(.*?)(?=\s*[A-D][\.\s、．\x{00a0}]|$)/u', $text, $opts, PREG_SET_ORDER);
    if (!empty($opts)) {
        foreach ($opts as $opt) {
            $opt_letter = strtoupper($opt[1]);
            $opt_val = trim($opt[2]);
            if ($opt_letter === 'A') $q['option_a'] = $opt_val;
            elseif ($opt_letter === 'B') $q['option_b'] = $opt_val;
            elseif ($opt_letter === 'C') $q['option_c'] = $opt_val;
            elseif ($opt_letter === 'D') $q['option_d'] = $opt_val;
        }
    }
}

$all_docx_questions = [];
$all_docx_questions = array_merge($all_docx_questions, parse_section_questions($paper_sections[1], '单选题', $single_choice_ans));
$all_docx_questions = array_merge($all_docx_questions, parse_section_questions($paper_sections[2], '多选题', $multi_choice_ans));
$all_docx_questions = array_merge($all_docx_questions, parse_section_questions($paper_sections[3], '判断题', $tf_ans));
$all_docx_questions = array_merge($all_docx_questions, parse_section_questions($paper_sections[4], '填空题', $fill_ans));
$all_docx_questions = array_merge($all_docx_questions, parse_section_questions($paper_sections[5], '简答题', $short_ans));
$all_docx_questions = array_merge($all_docx_questions, parse_section_questions($paper_sections[6], '实操论述题', $disc_ans));

echo "Total parsed: " . count($all_docx_questions) . "\n";
foreach ($all_docx_questions as $q) {
    echo "[Type: {$q['type']}] [Num: {$q['num']}] Text: " . mb_substr($q['text'], 0, 40) . "... | Ans: {$q['answer']}\n";
}
