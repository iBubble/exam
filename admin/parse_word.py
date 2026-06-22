#!/usr/bin/env python3
import sys
import zipfile
import re
import xml.etree.ElementTree as ET
import json
import os

def clean_page_noise(text):
    # Remove page numbers
    text = re.sub(r'第\s*\d+\s*页\s*（?\s*共\s*\d+\s*页\s*）?', '', text)
    text = re.sub(r'第\s*\d+\s*页', '', text)
    text = re.sub(r'共\s*\d+\s*页', '', text)
    # Remove student info lines and boundaries
    text = re.sub(r'学号：.*?姓名：.*?班级：.*?(答\s*题\s*不\s*得\s*超\s*过\s*此\s*密\s*封\s*线)?', '', text)
    text = re.sub(r'答\s*题\s*不\s*得\s*超\s*过\s*此\s*密\s*封\s*线', '', text)
    # Collapse multiple spaces
    text = re.sub(r'\s{3,}', ' ', text)
    return text.strip()

def detect_section(text):
    if '单项选择题' in text or ('一、' in text and '选择' in text):
        return '单选题'
    elif '多项选择题' in text or ('二、' in text and '选择' in text):
        return '多选题'
    elif '判断题' in text or '三、' in text:
        return '判断题'
    elif '填空题' in text or '四、' in text:
        return '填空题'
    elif '简答题' in text or '五、' in text:
        return '简答题'
    elif '论述题' in text or '六、' in text:
        return '实操论述题'
    return None

def is_ignored_line(text):
    text_clean = text.replace(' ', '').replace('\xa0', '').replace('\u3000', '')
    ignored_patterns = [
        r'^学号：',
        r'^姓名：',
        r'^班级：',
        r'答题不得超过此密封线',
        r'云南财经职业学院',
        r'校内统一考试',
        r'学年第二学期期末考试',
        r'适用）$',
        r'^题号$',
        r'^合计$',
        r'^得分$',
        r'^评卷人$',
        r'^答题卡$',
        r'^答案$',
        r'^本试卷考生在答题前应检查是否有缺页'
    ]
    for pattern in ignored_patterns:
        if re.search(pattern, text_clean):
            return True
    return False

def parse_docx(path):
    if not os.path.exists(path):
        return None
        
    try:
        doc = zipfile.ZipFile(path)
        xml_content = doc.read('word/document.xml')
    except Exception:
        return None
        
    root = ET.fromstring(xml_content)
    ns = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
    body = root.find('w:body', ns)
    
    # Extract elements in order
    elements = []
    for child in body:
        tag = child.tag.split('}')[-1]
        if tag == 'p':
            pPr = child.find('w:pPr', ns)
            has_num = False
            if pPr is not None:
                numPr = pPr.find('w:numPr', ns)
                if numPr is not None:
                    has_num = True
            text = ''.join([t.text for t in child.findall('.//w:t', ns) if t.text]).strip()
            text = clean_page_noise(text)
            if text:
                elements.append({'type': 'p', 'text': text, 'has_num': has_num})
        elif tag == 'tbl':
            rows_data = []
            for tr in child.findall('.//w:tr', ns):
                row_cells = []
                for tc in tr.findall('.//w:tc', ns):
                    cell_text = ''.join([t.text for t in tc.findall('.//w:t', ns) if t.text]).strip()
                    row_cells.append(cell_text)
                rows_data.append(row_cells)
            elements.append({'type': 'tbl', 'rows': rows_data})
            
    # Find answer key start (first occurrence of '答案')
    answers_start_idx = -1
    for idx, el in enumerate(elements):
        if el['type'] == 'p':
            if ('答案' in el['text']) and ('《' in el['text'] or '学期' in el['text'] or '卷' in el['text']):
                answers_start_idx = idx
                break
                
    if answers_start_idx == -1:
        for idx, el in enumerate(elements):
            if el['type'] == 'p' and el['text'].endswith('答案'):
                answers_start_idx = idx
                break
                
    # Find answer sheet start (last occurrence of '答题卡' before answers_start_idx)
    answer_sheet_start_idx = -1
    limit = answers_start_idx if answers_start_idx != -1 else len(elements)
    for idx in range(limit):
        el = elements[idx]
        if el['type'] == 'p':
            if ('答题卡' in el['text'] or '答  题  卡' in el['text']) and ('《' in el['text'] or '学期' in el['text'] or '卷' in el['text']):
                answer_sheet_start_idx = idx
                
    if answer_sheet_start_idx == -1:
        for idx in range(limit):
            el = elements[idx]
            if el['type'] == 'p' and (el['text'].endswith('答题卡') or el['text'].endswith('答  题  卡')):
                answer_sheet_start_idx = idx
                
    if answer_sheet_start_idx == -1:
        answer_sheet_start_idx = answers_start_idx
        
    if answers_start_idx == -1:
        return None
        
    question_elements = elements[:answer_sheet_start_idx]
    answer_elements = elements[answers_start_idx:]
    
    # Try to extract subject name from first few paragraphs or filename
    subject_name = ''
    filename = path.split('/')[-1]
    m_sub = re.search(r'《([^》]+)》', filename)
    if m_sub:
        subject_name = m_sub.group(1)
    else:
        for el in question_elements[:10]:
            if el['type'] == 'p' and '《' in el['text'] and '》' in el['text']:
                m_sub = re.search(r'《([^》]+)》', el['text'])
                if m_sub:
                    subject_name = m_sub.group(1)
                    break
    if not subject_name:
        subject_name = '未知科目'
        
    # Parse Questions
    questions = []
    current_section = None
    
    for el in question_elements:
        if el['type'] == 'p':
            if is_ignored_line(el['text']):
                continue
                
            sec = detect_section(el['text'])
            if sec:
                current_section = sec
                continue
                
            if not current_section:
                continue
                
            # Match question number (manual prefix or auto-numbering detection)
            m_q = re.match(r'^(\d+)[．\.\s、]\s*(.*)$', el['text'])
            is_new_q = False
            q_idx = None
            q_text = el['text']
            
            if m_q:
                is_new_q = True
                q_idx = int(m_q.group(1))
                q_text = m_q.group(2).strip()
            elif el.get('has_num'):
                if re.match(r'^[B-D][．\.\s]', el['text'].strip()):
                    is_new_q = False
                else:
                    is_new_q = True
                    current_sec_qs = [q for q in questions if q['type'] == current_section]
                    q_idx = len(current_sec_qs) + 1
            
            if is_new_q:
                options_text = ''
                if 'A.' in q_text or 'A．' in q_text:
                    split_char = 'A.' if 'A.' in q_text else 'A．'
                    parts = q_text.split(split_char, 1)
                    q_text = parts[0].strip()
                    options_text = split_char + parts[1]
                    
                questions.append({
                    'index': q_idx,
                    'type': current_section,
                    'stem': q_text,
                    'options_text': options_text,
                    'options': ['', '', '', '']
                })
            else:
                if current_section == '实操论述题':
                    essay_qs = [q for q in questions if q['type'] == '实操论述题']
                    if not essay_qs:
                        questions.append({
                            'index': 1,
                            'type': current_section,
                            'stem': el['text'],
                            'options_text': '',
                            'options': ['', '', '', '']
                        })
                    else:
                        essay_qs[-1]['stem'] += '\n' + el['text']
                else:
                    if questions:
                        last_q = questions[-1]
                        if last_q['type'] == current_section:
                            if 'A.' in el['text'] or 'A．' in el['text'] or last_q['options_text']:
                                last_q['options_text'] = (last_q['options_text'] + ' ' + el['text']).strip()
                            else:
                                last_q['stem'] = (last_q['stem'] + '\n' + el['text']).strip()
        elif el['type'] == 'tbl':
            for row in el['rows']:
                for cell in row:
                    sec = detect_section(cell)
                    if sec:
                        current_section = sec
                        
    # Helper to parse options
    for q in questions:
        if q['type'] in ['单选题', '多选题'] and q['options_text']:
            opt_a = re.search(r'A[．\.\s]\s*(.*?)(?=B[．\.\s]|C[．\.\s]|D[．\.\s]|$)', q['options_text'], re.DOTALL)
            opt_b = re.search(r'B[．\.\s]\s*(.*?)(?=A[．\.\s]|C[．\.\s]|D[．\.\s]|$)', q['options_text'], re.DOTALL)
            opt_c = re.search(r'C[．\.\s]\s*(.*?)(?=A[．\.\s]|B[．\.\s]|D[．\.\s]|$)', q['options_text'], re.DOTALL)
            opt_d = re.search(r'D[．\.\s]\s*(.*?)(?=A[．\.\s]|B[．\.\s]|C[．\.\s]|$)', q['options_text'], re.DOTALL)
            
            q['options'][0] = opt_a.group(1).strip() if opt_a else ''
            q['options'][1] = opt_b.group(1).strip() if opt_b else ''
            q['options'][2] = opt_c.group(1).strip() if opt_c else ''
            q['options'][3] = opt_d.group(1).strip() if opt_d else ''
            
    # Parse Answers
    answers = {}
    current_ans_section = None
    
    for el in answer_elements:
        if el['type'] == 'p':
            if is_ignored_line(el['text']):
                continue
                
            sec = detect_section(el['text'])
            if sec:
                current_ans_section = sec
                continue
                
            if not current_ans_section:
                continue
                
            if current_ans_section in ['填空题', '简答题']:
                m_ans = re.match(r'^(\d+)[．\.\s、]\s*(.*)$', el['text'])
                if m_ans:
                    ans_idx = int(m_ans.group(1))
                    ans_val = m_ans.group(2).strip()
                    if current_ans_section not in answers:
                        answers[current_ans_section] = {}
                    answers[current_ans_section][ans_idx] = ans_val
                else:
                    if current_ans_section not in answers or el.get('has_num'):
                        if current_ans_section not in answers:
                            answers[current_ans_section] = {}
                            ans_idx = 1
                        else:
                            ans_idx = max(answers[current_ans_section].keys()) + 1
                        answers[current_ans_section][ans_idx] = el['text']
                    elif answers.get(current_ans_section):
                        last_idx = max(answers[current_ans_section].keys())
                        answers[current_ans_section][last_idx] += '\n' + el['text']
            elif current_ans_section == '实操论述题':
                if current_ans_section not in answers:
                    answers[current_ans_section] = {1: ''}
                answers[current_ans_section][1] = (answers[current_ans_section][1] + '\n' + el['text']).strip()
                
        elif el['type'] == 'tbl':
            for row in el['rows']:
                for cell in row:
                    sec = detect_section(cell)
                    if sec:
                        current_ans_section = sec
            
            if not current_ans_section:
                continue
                
            rows = el['rows']
            for r in range(0, len(rows) - 1, 2):
                idx_row = rows[r]
                val_row = rows[r+1]
                for col in range(min(len(idx_row), len(val_row))):
                    idx_str = idx_row[col].strip()
                    val_str = val_row[col].strip()
                    if idx_str.isdigit():
                        q_idx = int(idx_str)
                        if current_ans_section not in answers:
                            answers[current_ans_section] = {}
                            
                        if current_ans_section == '判断题':
                            if val_str in ['√', '正确', '对']:
                                val_str = '正确'
                            elif val_str in ['×', '错误', '错']:
                                val_str = '错误'
                                
                        answers[current_ans_section][q_idx] = val_str
                        
    # Combine Questions and Answers
    final_questions = []
    for q in questions:
        sec = q['type']
        idx = q['index']
        ans_val = answers.get(sec, {}).get(idx, '')
        
        final_questions.append({
            'directory': subject_name,
            'type': q['type'],
            'stem': q['stem'],
            'option_a': q['options'][0],
            'option_b': q['options'][1],
            'option_c': q['options'][2],
            'option_d': q['options'][3],
            'answer': ans_val,
            'analysis': '',
            'knowledge': ''
        })
        
    return final_questions

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(json.dumps({'error': 'Missing file path argument'}))
        sys.exit(1)
        
    file_path = sys.argv[1]
    parsed = parse_docx(file_path)
    if parsed is None:
        print(json.dumps({'error': 'Failed to parse file'}))
        sys.exit(1)
        
    print(json.dumps(parsed, ensure_ascii=False))
