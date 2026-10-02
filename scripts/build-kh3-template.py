#!/usr/bin/env python3
"""Turn the official blank (خ-3) .docx into resources/forms/kh3-template.docx with PhpWord placeholders.

Usage: python3 scripts/build-kh3-template.py "<official blank .docx>" resources/forms/kh3-template.docx
Keeps page 1 only, wraps it in ${page}…${/page} (cloned per instructor), keeps one week row (${week_no},
cloned per week) and replaces every fillable field with a ${placeholder}. Fixed Arabic text is untouched.
Also fits the schedule table to one page (fixed column widths, 9 pt week/totals rows, tight cell margins) and merges
the footer captions into one run each (the official file splits "المنتدب" with a tab, printing "المنتد ب").
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


def set_cell(tc, new, strip_underline=False):
    """Keep tcPr; one paragraph (the cell's first) with the new text. strip_underline drops <w:u …/> from its rPr."""
    tcpr = re.search(r'<w:tcPr>.*?</w:tcPr>', tc, re.S)
    first = re.search(P, tc, re.S).group(0)
    para = set_para(first, new)
    if strip_underline:
        para = re.sub(r'<w:u\b[^>]*/>', '', para)
    return '<w:tc>' + (tcpr.group(0) if tcpr else '') + para + '</w:tc>'


def replace_para_containing(body, needle, new, occurrence=1):
    seen = 0
    for m in re.finditer(P, body, re.S):
        if needle in text(m.group(0)):
            seen += 1
            if seen == occurrence:
                return body[:m.start()] + set_para(m.group(0), new) + body[m.end():]
    raise SystemExit(f'paragraph containing {needle!r} not found')


# Schedule table column widths in XML (logical) order: week no, dates, course, students, theory, practical, field,
# total, notes. Hour/total columns must hold "1.83" on one line at 9 pt, the course column "name code" on one line.
# The official table is 9360 wide with a 368 overhang into the right margin; widened by the same overhang on the left
# (10096) because the minimum widths do not fit in 9360 without squeezing the notes column below two words a line.
GRID = [600, 750, 2600, 720, 850, 850, 850, 850, 2026]
TABLE_WIDTH = sum(GRID)


def set_tcw(row, spans):
    """Set each cell's tcW to the summed grid widths of the columns it spans (spans: list of span counts)."""
    cells = list(re.finditer(TC, row, re.S))
    assert len(cells) == len(spans), (len(cells), spans)
    widths, col = [], 0
    for span in spans:
        widths.append(sum(GRID[col:col + span]))
        col += span
    assert col == len(GRID), col
    for c, w in reversed(list(zip(cells, widths))):
        tc = re.sub(r'<w:tcW w:w="\d+" w:type="dxa"/>', '<w:tcW w:w="%d" w:type="dxa"/>' % w, c.group(0), count=1)
        row = row[:c.start()] + tc + row[c.end():]
    return row


def nine_point(row):
    """Every run (and paragraph mark) in the row at 9 pt; every rPr in these rows already carries sz/szCs."""
    assert row.count('<w:rPr>') == len(re.findall(r'<w:sz w:val="\d+"/>', row)), 'rPr without sz'
    row = re.sub(r'<w:sz w:val="\d+"/>', '<w:sz w:val="18"/>', row)
    return re.sub(r'<w:szCs w:val="\d+"/>', '<w:szCs w:val="18"/>', row)


def fit_table(tbl):
    tbl = re.sub(r'<w:tblW w:w="\d+" w:type="dxa"/>', '<w:tblW w:w="%d" w:type="dxa"/>' % TABLE_WIDTH, tbl, count=1)
    if '<w:tblLayout ' not in tbl:
        tbl = tbl.replace('<w:tblLook ', '<w:tblLayout w:type="fixed"/><w:tblLook ', 1)
    if '<w:tblCellMar>' not in tbl:
        tbl = tbl.replace('<w:tblLayout w:type="fixed"/>', '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="20" w:type="dxa"/>'
                          '<w:left w:w="108" w:type="dxa"/><w:bottom w:w="20" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar>', 1)
    assert '<w:tblCellMar>' in tbl and '<w:tblLayout w:type="fixed"/>' in tbl
    tbl = re.sub(r'<w:tblGrid>.*?</w:tblGrid>', '<w:tblGrid>' + ''.join('<w:gridCol w:w="%d"/>' % w for w in GRID) + '</w:tblGrid>', tbl, count=1, flags=re.S)
    rows = list(re.finditer(TR, tbl, re.S))
    assert len(rows) == 4, len(rows)
    spans = [[1, 1, 1, 1, 3, 1, 1], [1] * 9, [1] * 9, [3, 1, 1, 1, 1, 1, 1]]
    new_rows = []
    for i, (r, sp) in enumerate(zip(rows, spans)):
        row = set_tcw(r.group(0), sp)
        if i == 2:   # week row: shrink to content
            row = re.sub(r'<w:trHeight\b[^>]*/>', '', row).replace('<w:trPr></w:trPr>', '')
        if i in (2, 3):
            row = nine_point(row)
        new_rows.append(row)
    return tbl[:rows[0].start()] + ''.join(new_rows) + tbl[rows[-1].end():]


def drop_blank_before_note(tail):
    """Remove the empty paragraphs between the schedule table and the 'ملاحظة مهمة' note, and give the
    note paragraph <w:spacing w:before="120"/> in its pPr (created if absent, merged if present) so the
    note doesn't spill alone onto a second page on a five-week month."""
    paras = list(re.finditer(P, tail, re.S))
    note_i = next(i for i, m in enumerate(paras) if 'ملاحظة مهمة' in text(m.group(0)))
    for m in paras[:note_i]:
        assert not text(m.group(0)).strip(), 'expected only empty paragraphs before the note'
    note = paras[note_i]
    note_p = note.group(0)
    ppr = re.search(r'<w:pPr>(.*?)</w:pPr>', note_p, re.S)
    if ppr:
        assert '<w:spacing' not in ppr.group(1), 'note pPr already has spacing'
        inner = ppr.group(1)
        inner = inner.replace('<w:rPr>', '<w:spacing w:before="120"/><w:rPr>', 1) if '<w:rPr>' in inner \
            else inner + '<w:spacing w:before="120"/>'
        new_note_p = note_p[:ppr.start()] + '<w:pPr>' + inner + '</w:pPr>' + note_p[ppr.end():]
    else:
        ptag = re.match(r'<w:p\b[^>]*>', note_p).group(0)
        new_note_p = ptag + '<w:pPr><w:spacing w:before="120"/></w:pPr>' + note_p[len(ptag):]
    return new_note_p + tail[note.end():]


def merge_footer(footer):
    """Each text paragraph becomes one run: the first run's rPr, then its texts and tabs in order (adjacent texts
    joined). The official file puts a tab inside "المنتدب" (المنتد<tab>ب); that tab moves after the word."""
    out = footer
    for m in reversed(list(re.finditer(P, footer, re.S))):
        p = m.group(0)
        if not text(p).strip() or '<w:drawing' in p or '<w:pict' in p:
            continue
        tokens = []
        for r in re.finditer(r'<w:r\b[^>]*>(.*?)</w:r>', p, re.S):
            for t in re.finditer(r'<w:tab/>|<w:t(?:\s[^>]*)?>([^<]*)</w:t>', r.group(1)):
                tokens.append('\t' if t.group(0) == '<w:tab/>' else t.group(1))
        merged = ''.join(tokens).replace('المنتد\tب', 'المنتدب\t')
        ptag = re.match(r'<w:p\b[^>]*>', p).group(0)
        ppr = re.search(r'<w:pPr>.*?</w:pPr>', p, re.S)
        rpr = re.search(r'<w:r\b[^>]*>(<w:rPr>.*?</w:rPr>)', p, re.S)
        content = ''.join('<w:tab/>' if part == '\t' else '<w:t xml:space="preserve">%s</w:t>' % part
                          for part in re.split(r'(\t)', merged) if part != '')
        new_p = ptag + (ppr.group(0) if ppr else '') + '<w:r>' + (rpr.group(1) if rpr else '') + content + '</w:r></w:p>'
        out = out[:m.start()] + new_p + out[m.end():]
    return out


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
    # The official note cell is underlined; the generated notes are multi-line prose, so print them plain.
    new_week = new_week[:c.start()] + set_cell(c.group(0), '${%s}' % name, strip_underline=(name == 'week_note')) + new_week[c.end():]
tot = rows[6].group(0)
tcells = list(re.finditer(TC, tot, re.S))
assert len(tcells) == 7, len(tcells)
sums = [None, 'sum_students', 'sum_theory', 'sum_practical', 'sum_field', 'sum_total', '']
new_tot = tot
for c, name in reversed(list(zip(tcells, sums))):
    if name is None:
        continue
    new_tot = new_tot[:c.start()] + set_cell(c.group(0), '${%s}' % name if name else '') + new_tot[c.end():]
new_t2 = fit_table(t2[:rows[0].start()] + r0 + rows[1].group(0) + new_week + new_tot + t2[rows[6].end():])
sched_tail = drop_blank_before_note(body[tables[2].end():])
body = body[:tables[1].start()] + new_t1 + body[tables[1].end():tables[2].start()] + new_t2 + sched_tail

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
footer = merge_footer(zin.read('word/footer1.xml').decode('utf-8'))
assert 'توقيع عضو هيئة التدريس المنتدب' in footer
replaced = {'word/document.xml': new_xml, 'word/footer1.xml': footer}
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zout:
    for item in zin.infolist():
        data = replaced[item.filename].encode('utf-8') if item.filename in replaced else zin.read(item.filename)
        zout.writestr(item, data)
vars_found = sorted(set(re.findall(r'\$\{([^}]+)\}', new_xml)))
print(len(vars_found), 'placeholders:', ' '.join(vars_found))
print('schedule grid (XML order):', GRID, 'sum', TABLE_WIDTH)
