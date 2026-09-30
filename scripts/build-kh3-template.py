#!/usr/bin/env python3
"""Turn the official blank (خ-3) .docx into resources/forms/kh3-template.docx with PhpWord placeholders.

Usage: python3 scripts/build-kh3-template.py "<official blank .docx>" resources/forms/kh3-template.docx
Keeps page 1 only, wraps it in ${page}…${/page} (cloned per instructor), keeps one week row (${week_no},
cloned per week) and replaces every fillable field with a ${placeholder}. Fixed Arabic text is untouched.
"""
import re
import sys
import zipfile

src, out = sys.argv[1], sys.argv[2]
zin = zipfile.ZipFile(src)
xml = zin.read('word/document.xml').decode('utf-8')

P = r'<w:p\b[^>]*>(?:(?!<w:p\b).)*?</w:p>'   # innermost paragraph (no nested paragraph start)
TC = r'<w:tc>.*?</w:tc>'
TR = r'<w:tr\b[^>]*>.*?</w:tr>'
TBL = r'<w:tbl>.*?</w:tbl>'


def text(x):
    return ''.join(re.findall(r'<w:t[^>]*>([^<]*)</w:t>', x))


def set_para(p, new):
    """Keep pPr and the first run's rPr; replace all runs with one run holding new."""
    ptag = re.match(r'<w:p\b[^>]*>', p).group(0)
    ppr = re.search(r'<w:pPr>.*?</w:pPr>', p, re.S)
    rpr = re.search(r'<w:r\b[^>]*>(<w:rPr>.*?</w:rPr>)', p, re.S)
    return ptag + (ppr.group(0) if ppr else '') + '<w:r>' + (rpr.group(1) if rpr else '') + \
        '<w:t xml:space="preserve">' + new + '</w:t></w:r></w:p>'


def set_cell(tc, new):
    """Keep tcPr; one paragraph (the cell's first) with the new text."""
    tcpr = re.search(r'<w:tcPr>.*?</w:tcPr>', tc, re.S)
    first = re.search(P, tc, re.S).group(0)
    return '<w:tc>' + (tcpr.group(0) if tcpr else '') + set_para(first, new) + '</w:tc>'


def replace_para_containing(body, needle, new, occurrence=1):
    seen = 0
    for m in re.finditer(P, body, re.S):
        if needle in text(m.group(0)):
            seen += 1
            if seen == occurrence:
                return body[:m.start()] + set_para(m.group(0), new) + body[m.end():]
    raise SystemExit(f'paragraph containing {needle!r} not found')


head, rest = xml.split('<w:body>', 1)
body, tail = rest.rsplit('<w:sectPr', 1)
tail = '<w:sectPr' + tail

# 1. page 1 only: cut after the first "ملاحظة مهمة" paragraph
m = next(m for m in re.finditer(P, body, re.S) if 'ملاحظة مهمة' in text(m.group(0)))
body = body[:m.end()]

# 2. header paragraphs
body = replace_para_containing(body, '(من خارج الهيئة', '${category} ${term_label}')
body = replace_para_containing(body, '2025-2026', '${academic_year}')
body = replace_para_containing(body, 'القسم العلمي', 'القسم العلمي: ${dept_name}')
body = replace_para_containing(body, 'طبقا لقرار التكليف', 'طبقا لقرار التكليف الصادر من الهيئة برقم ( ${decision_number} )  بتاريخ ( ${decision_date} )')
body = replace_para_containing(body, 'اسم المنتدب', 'اسم المنتدب/ ${full_name}      المسمى الوظيفي ${job_title}')
body = replace_para_containing(body, 'جهة العمل الأصلية', 'جهة العمل الأصلية  ${employer}')
body = replace_para_containing(body, 'اسم المقرر 1', 'اسم المقرر 1 ${course1}    2 ${course2}    3 ${course3}')
body = replace_para_containing(body, 'رقم الحساب', 'رقم الحساب ${account_number}    اسم البنك ${bank_name}    فرع ${bank_branch}')
body = replace_para_containing(body, 'الراتب الأساسي', 'الراتب الأساسي ${basic_salary}    الراتب الإجمالي ${total_salary}')
body = replace_para_containing(body, 'التلفون/ العمل', 'التلفون/ العمل ${phone_work}    المنزل ${phone_home}    النقال ${phone_mobile}    عدد الساعات ${weekly_hours}')

# 3. tables
tables = list(re.finditer(TBL, body, re.S))
assert len(tables) == 3, len(tables)
# civil-ID boxes: cells 1..12
t1 = tables[1].group(0)
cells = list(re.finditer(TC, t1, re.S))
assert len(cells) == 13, len(cells)
new_t1 = t1
for idx in range(12, 0, -1):
    c = cells[idx]
    new_t1 = new_t1[:c.start()] + set_cell(c.group(0), '${cid%d}' % idx) + new_t1[c.end():]
# schedule table
t2 = tables[2].group(0)
rows = list(re.finditer(TR, t2, re.S))
assert len(rows) == 7, len(rows)
r0 = rows[0].group(0)
c0 = re.search(TC, r0, re.S)
r0 = r0[:c0.start()] + set_cell(c0.group(0), '${month_title}') + r0[c0.end():]
week = rows[2].group(0)
wcells = list(re.finditer(TC, week, re.S))
assert len(wcells) == 9, len(wcells)
names = ['week_no', 'week_dates', 'week_courses', 'week_students', 'week_theory', 'week_practical', 'week_field', 'week_total', 'week_note']
new_week = week
for c, name in reversed(list(zip(wcells, names))):
    new_week = new_week[:c.start()] + set_cell(c.group(0), '${%s}' % name) + new_week[c.end():]
tot = rows[6].group(0)
tcells = list(re.finditer(TC, tot, re.S))
assert len(tcells) == 7, len(tcells)
sums = [None, 'sum_students', 'sum_theory', 'sum_practical', 'sum_field', 'sum_total', '']
new_tot = tot
for c, name in reversed(list(zip(tcells, sums))):
    if name is None:
        continue
    new_tot = new_tot[:c.start()] + set_cell(c.group(0), '${%s}' % name if name else '') + new_tot[c.end():]
new_t2 = t2[:rows[0].start()] + r0 + rows[1].group(0) + new_week + new_tot + t2[rows[6].end():]
body = body[:tables[1].start()] + new_t1 + body[tables[1].end():tables[2].start()] + new_t2 + body[tables[2].end():]

# 4. page block: ${page} before the first table; a page-break paragraph at the END of the block, then ${/page}.
#    (A break at the start added a blank line to every page after the first.) Kh3TemplateProcessor::stripLastPageBreak()
#    removes the break after the last cloned page.
first_tbl = body.index('<w:tbl>')
body = (body[:first_tbl]
        + '<w:p><w:r><w:t>${page}</w:t></w:r></w:p>'
        + body[first_tbl:]
        + '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
        + '<w:p><w:r><w:t>${/page}</w:t></w:r></w:p>')

new_xml = head + '<w:body>' + body + tail
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zout:
    for item in zin.infolist():
        data = new_xml.encode('utf-8') if item.filename == 'word/document.xml' else zin.read(item.filename)
        zout.writestr(item, data)
vars_found = sorted(set(re.findall(r'\$\{([^}]+)\}', new_xml)))
print(len(vars_found), 'placeholders:', ' '.join(vars_found))
