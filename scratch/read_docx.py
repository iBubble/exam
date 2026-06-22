import zipfile
import xml.etree.ElementTree as ET

def get_docx_text(path):
    namespaces = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
    text = []
    with zipfile.ZipFile(path) as z:
        xml_content = z.read('word/document.xml')
        root = ET.fromstring(xml_content)
        for p in root.iter('{http://schemas.openxmlformats.org/wordprocessingml/2006/main}p'):
            p_text = []
            for t in p.iter('{http://schemas.openxmlformats.org/wordprocessingml/2006/main}t'):
                if t.text:
                    p_text.append(t.text)
            if p_text:
                text.append("".join(p_text))
    return "\n".join(text)

docx_path = 'docs/2025-2026-2大数据技术专业《人工智能数据标注》期末试卷——刘鲲/2025-2026-2大数据技术专业《人工智能数据标注》期末试卷A卷——刘鲲.docx'
text = get_docx_text(docx_path)
print("Length of text:", len(text))
print("END OF TEXT:")
print(text[-4000:]) # print last 4000 chars to see if there's an answer key
