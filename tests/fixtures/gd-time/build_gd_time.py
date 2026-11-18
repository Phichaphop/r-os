#!/usr/bin/env python3
"""GD-TIME v1 — golden dataset for attendance (P0-06 §7, DEC-41).

Writes GD-TIME_v1.xlsx where every expected value is an OPEN spreadsheet formula (not the PHP engine), lets
LibreOffice recalculate it, then exports the calculated values to CSV fixtures that
tests/Integration/AttendanceGoldenTest.php compares the engine against.

    python3 tests/fixtures/gd-time/build_gd_time.py      (needs openpyxl + LibreOffice `soffice`)

Status: AI-prepared — unverified (DEC-41 ข้อ 4) until HR Time checks every edge case + 20% of normal cases.
The work rule below is TEST ONLY — not company rules (HR must enter the real ones, DEC-64).
"""
import csv
import os
import shutil
import subprocess
import tempfile

from openpyxl import Workbook, load_workbook
from openpyxl.styles import Font, PatternFill
from openpyxl.workbook.defined_name import DefinedName

HERE = os.path.dirname(os.path.abspath(__file__))
XLSX = os.path.join(HERE, 'GD-TIME_v1.xlsx')

EMP = {'000101': 'D1-0001', '000102': 'D1-0002', '000103': 'D1-0003', '000104': 'D1-0004',
       '000105': 'D1-0005', '000106': 'D1-0006', '000107': 'D1-0007'}
DATES = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08']
WEEKEND = {'2026-11-07', '2026-11-08'}

# (case, device user code, local date-time at the device, source, why)
P = []


def add(case, code, when, source, why):
    P.append((case, code, when, source, why))


def day(case, code, d, t_in, t_out, source='api', why=''):
    add(case, code, f'{d} {t_in}', source, why)
    add(case, code, f'{d} {t_out}', source, why)


for d in DATES[:5]:
    day('N-01', '000101', d, '07:55:00', '17:05:00', why='ปกติ — เข้าก่อนเวลา ออกหลังเวลา')
day('L-01', '000102', '2026-11-02', '08:20:00', '17:00:00', why='สายเกินผ่อนผัน — นับตั้งแต่ 08:00 = 20 นาที')
day('L-02', '000102', '2026-11-03', '08:05:00', '17:00:00', why='สายเท่ากับผ่อนผันพอดี (5 นาที) — ไม่สาย')
day('L-03', '000102', '2026-11-04', '08:06:00', '17:00:00', why='เกินผ่อนผัน 1 นาที — สาย 6 นาที')
day('L-04', '000102', '2026-11-05', '08:30:00', '16:00:00', why='สายและออกก่อน')
day('L-05', '000102', '2026-11-06', '07:50:00', '16:30:00', why='ออกก่อน 30 นาที (ไม่มีผ่อนผันออกก่อน)')
add('M-01', '000103', '2026-11-02 08:00:00', 'api', 'ลืมสแกนออก — สแกนครั้งเดียวก่อนกลางวันทำงาน = เข้า')
add('M-02', '000103', '2026-11-03 17:00:00', 'api', 'ลืมสแกนเข้า — สแกนครั้งเดียวหลังกลางวันทำงาน = ออก')
for t in ['07:58:00', '07:58:30', '07:59:10', '17:01:00', '17:01:20']:
    add('D-01', '000103', f'2026-11-04 {t}', 'api', 'สแกนซ้ำหลายครั้ง — เข้า = ครั้งแรก ออก = ครั้งสุดท้าย')
# 000103 2026-11-05: no punch at all → A-01 absent
day('N-02', '000103', '2026-11-06', '08:00:00', '17:00:00', why='ปกติ — ตรงเวลาพอดี')
day('N-03', '000104', '2026-11-02', '07:58:00', '17:02:00', why='ปกติ')
add('X-01', '000104', '2026-11-03 07:58:00', 'api', 'ออกหลังเที่ยงคืนก่อนเวลาเริ่มวันใหม่ (04:00) — นับเป็นของวันก่อน')
add('X-01', '000104', '2026-11-04 02:30:00', 'api', 'ออกหลังเที่ยงคืนก่อนเวลาเริ่มวันใหม่ (04:00) — นับเป็นของวันก่อน')
for d in DATES[2:5]:
    day('N-03', '000104', d, '07:58:00', '17:02:00', why='ปกติ')
day('H-01', '000104', '2026-11-07', '09:00:00', '12:00:00', why='มาทำงานวันหยุดสุดสัปดาห์')
day('F-01', '000105', '2026-11-02', '08:01:00', '17:05:00', source='file', why='มาจากไฟล์นำเข้าเท่านั้น')
day('F-02', '000105', '2026-11-03', '07:59:00', '17:03:00', why='API และไฟล์นำเข้าเวลาเดียวกันทับกัน — เข้า/ออกไม่เปลี่ยน')
day('F-02', '000105', '2026-11-03', '07:59:00', '17:03:00', source='file', why='API และไฟล์นำเข้าเวลาเดียวกันทับกัน — เข้า/ออกไม่เปลี่ยน')
for d in DATES[2:5]:
    day('E-01', '000105', d, '08:00:00', '17:00:00', why='ดึงซ้ำตั้งแต่ต้น (source_event_id ซ้ำ) — เก็บครั้งเดียว')
add('S-01', '000106', '2026-11-02 12:29:00', 'api', 'สแกนครั้งเดียวก่อนกลางวันทำงาน (12:30) 1 นาที = เข้า')
add('S-02', '000106', '2026-11-03 12:30:00', 'api', 'สแกนครั้งเดียวตรงกลางวันทำงานพอดี = ออก')
for d in DATES[2:5]:
    day('N-04', '000106', d, '07:45:00', '17:15:00', why='ปกติ')
# 000107: the device stopped sending (silent device) — no punch all week → absent every working day (V-01)

CASES = [
    ('N-01..N-04', 'ปกติ', 'ok'), ('L-01', 'สายเกินผ่อนผัน', 'late 20'), ('L-02', 'สายเท่ากับผ่อนผัน', 'ok'),
    ('L-03', 'สายเกินผ่อนผัน 1 นาที', 'late 6'), ('L-04', 'สายและออกก่อน', 'late_early 30/60'), ('L-05', 'ออกก่อน', 'early 30'),
    ('M-01', 'ลืมสแกนออก', 'missing_out'), ('M-02', 'ลืมสแกนเข้า', 'missing_in'), ('D-01', 'สแกนซ้ำ', 'ok · 5 ครั้ง'),
    ('A-01', 'ขาด (000103 วันที่ 5)', 'absent'), ('X-01', 'ออกหลังเที่ยงคืน', 'ok · ของวันก่อน'), ('H-01', 'ทำงานวันหยุด', 'off_worked'),
    ('F-01', 'จากไฟล์นำเข้า', 'ok'), ('F-02', 'ไฟล์ทับ API', 'ok · 4 ครั้ง'), ('E-01', 'source_event_id ซ้ำ', 'เก็บครั้งเดียว'),
    ('S-01', 'สแกนครั้งเดียวก่อนกลางวัน', 'missing_out'), ('S-02', 'สแกนครั้งเดียวตรงกลางวัน', 'missing_in'),
    ('V-01', 'เครื่องเงียบ (000107 ทั้งสัปดาห์)', 'absent × 5 — ระบบแยกไม่ได้ว่าเครื่องเงียบหรือขาดจริง; หน้าปิดงวดเตือนให้ HR ยืนยัน'),
    ('—', 'กะดึกข้ามคืนเต็มกะ', 'ไม่อยู่ในชุดนี้ — ต้องมีตารางกะ (M35 เฟส 5)'),
    ('—', 'GPS นอกพื้นที่', 'ไม่อยู่ในชุดนี้ — ลงเวลา GPS (M57 เฟส 5)'),
    ('—', 'ลาเต็มวัน/ครึ่งวัน, คำขอแก้เวลา', 'ไม่อยู่ในชุดนี้ — ทดสอบแยกใน AttendanceUnitTest::testDayRules และ AttendanceWebTest'),
]

bold = Font(bold=True)
head = PatternFill('solid', fgColor='DDE6F0')


def header(ws, cols):
    ws.append(cols)
    for c in ws[1]:
        c.font = bold
        c.fill = head


wb = Workbook()
rd = wb.active
rd.title = 'README'
for line in [
    ['GD-TIME v1 — ชุดข้อมูลทดสอบเวลาทำงาน 1 งวด (2–8 พ.ย. 2569) · บริษัทตัวอย่าง DEMO1'],
    ['สถานะ', 'AI-prepared — unverified (DEC-41) · ต้องให้ HR Time ตรวจทุกกรณีขอบ + สุ่ม 20% ของกรณีปกติ แล้วเปลี่ยนเป็น Verified'],
    ['วิธีคำนวณ', 'ค่าที่คาดหวังทุกช่องเป็นสูตรในไฟล์นี้ (MINIFS/MAXIFS/COUNTIFS/SUMIFS) — ไม่ได้มาจากโค้ดของระบบ'],
    ['กติกาเวลา', 'ชีต rule — ค่าทดสอบเท่านั้น ไม่ใช่ข้อบังคับของบริษัท'],
    ['ใช้ทดสอบ', 'tests/Integration/AttendanceGoldenTest.php ส่งเวลาสแกนผ่าน Punch API และไฟล์นำเข้าจริง ปิดงวด แล้วเทียบกับชีต expected_days และ expected_period'],
    ['หน่วย', 'เวลาเป็นเวลาท้องถิ่นของเครื่อง (Asia/Bangkok) · นาทีปัดลง (ตัดเศษวินาที)'],
    [],
    ['กรณี', 'เรื่อง', 'ผลที่คาด'],
] + [list(c) for c in CASES]:
    rd.append(line)
rd['A1'].font = Font(bold=True, size=13)
rd.column_dimensions['A'].width = 14
rd.column_dimensions['B'].width = 40
rd.column_dimensions['C'].width = 90

ru = wb.create_sheet('rule')
header(ru, ['item', 'minutes', 'meaning'])
for name, val, why in [('start', 480, 'เริ่มงาน 08:00'), ('end', 1020, 'เลิกงาน 17:00'), ('break', 60, 'พัก 60 นาที (หักเมื่อช่วงเวลาทำงานยาวกว่าพัก)'),
                       ('late_grace', 5, 'ผ่อนผันสาย 5 นาที'), ('early_grace', 0, 'ผ่อนผันออกก่อน 0 นาที'), ('day_start', 240, 'เริ่มวันใหม่ 04:00 (สแกนก่อนนี้เป็นของวันก่อน)')]:
    ru.append([name, val, why])
    wb.defined_names[name] = DefinedName(name, attr_text=f'rule!$B${ru.max_row}')
ru.append(['mid', '=INT((start+end)/2)', 'กลางวันทำงาน — สแกนครั้งเดียวก่อนเวลานี้ = เข้า ตั้งแต่เวลานี้ = ออก'])
wb.defined_names['mid'] = DefinedName('mid', attr_text=f'rule!$B${ru.max_row}')
ru.column_dimensions['C'].width = 60

ca = wb.create_sheet('calendar')
header(ca, ['date', 'day_type'])
for d in DATES:
    ca.append([d, 'weekend' if d in WEEKEND else 'working'])

pu = wb.create_sheet('punches')
header(pu, ['case', 'device_user_code', 'employee_code', 'local_time', 'source', 'work_date', 'sec_of_work_date', 'why'])
for i, (case, code, when, source, why) in enumerate(P, start=2):
    pu.append([case, code, EMP[code], when, source,
               f'=TEXT(DATEVALUE(LEFT(D{i},10))+TIMEVALUE(MID(D{i},12,8))-day_start/1440,"YYYY-MM-DD")',
               f'=ROUND((DATEVALUE(LEFT(D{i},10))+TIMEVALUE(MID(D{i},12,8))-DATEVALUE(F{i}))*86400,0)', why])
n = len(P) + 1
for w, col in [(8, 'A'), (12, 'B'), (12, 'C'), (20, 'D'), (8, 'E'), (12, 'F'), (12, 'G'), (60, 'H')]:
    pu.column_dimensions[col].width = w
E = f'punches!$C$2:$C${n}'
W = f'punches!$F$2:$F${n}'
S = f'punches!$G$2:$G${n}'

ed = wb.create_sheet('expected_days')
header(ed, ['employee_code', 'work_date', 'day_type', 'punch_count', 'first_sec', 'last_sec', 'in_min', 'out_min',
            'worked_minutes', 'late_minutes', 'early_minutes', 'status'])
r = 2
for code in sorted(EMP.values()):
    for d in DATES:
        ed.append([code, d,
                   f'=VLOOKUP(B{r},calendar!$A$2:$B$8,2,0)',
                   f'=COUNTIFS({E},A{r},{W},B{r})',
                   f'=IF(D{r}=0,"",_xlfn.MINIFS({S},{E},A{r},{W},B{r}))',
                   f'=IF(D{r}=0,"",_xlfn.MAXIFS({S},{E},A{r},{W},B{r}))',
                   f'=IF(D{r}>=2,INT(E{r}/60),IF(D{r}=1,IF(INT(E{r}/60)<mid,INT(E{r}/60),""),""))',
                   f'=IF(D{r}>=2,INT(F{r}/60),IF(D{r}=1,IF(INT(F{r}/60)>=mid,INT(F{r}/60),""),""))',
                   f'=IF(OR(G{r}="",H{r}=""),"",IF(F{r}>E{r},MAX(0,INT((F{r}-E{r})/60)-IF(INT((F{r}-E{r})/60)>break,break,0)),""))',
                   f'=IF(OR(C{r}<>"working",G{r}=""),"",IF(G{r}-start>late_grace,G{r}-start,0))',
                   f'=IF(OR(C{r}<>"working",H{r}=""),"",IF(end-H{r}>early_grace,end-H{r},0))',
                   f'=IF(C{r}<>"working",IF(D{r}>0,"off_worked","off"),IF(D{r}=0,"absent",IF(G{r}="","missing_in",IF(H{r}="","missing_out",'
                   f'IF(AND(J{r}>0,K{r}>0),"late_early",IF(J{r}>0,"late",IF(K{r}>0,"early","ok")))))))'])
        r += 1
m = r - 1
ep = wb.create_sheet('expected_period')
header(ep, ['employee_code', 'scheduled_days', 'worked_days', 'absent_days', 'missing_punch_days', 'late_count', 'late_minutes',
            'early_count', 'early_minutes', 'worked_minutes', 'off_days'])
X = lambda c: f'expected_days!${c}$2:${c}${m}'  # noqa: E731
for i, code in enumerate(sorted(EMP.values()), start=2):
    ep.append([code,
               f'=COUNTIFS({X("A")},A{i},{X("C")},"working")',
               f'=COUNTIFS({X("A")},A{i},{X("D")},">0")',
               f'=COUNTIFS({X("A")},A{i},{X("L")},"absent")',
               f'=COUNTIFS({X("A")},A{i},{X("L")},"missing_in")+COUNTIFS({X("A")},A{i},{X("L")},"missing_out")',
               f'=COUNTIFS({X("A")},A{i},{X("J")},">0")',
               f'=SUMIFS({X("J")},{X("A")},A{i})',
               f'=COUNTIFS({X("A")},A{i},{X("K")},">0")',
               f'=SUMIFS({X("K")},{X("A")},A{i})',
               f'=SUMIFS({X("I")},{X("A")},A{i})',
               f'=COUNTIFS({X("A")},A{i},{X("C")},"weekend")'])
for ws in (ed, ep):
    for col in 'ABCDEFGHIJKL':
        ws.column_dimensions[col].width = 14
    ws.freeze_panes = 'B2'

tmp = tempfile.mkdtemp()
raw = os.path.join(tmp, 'GD-TIME_v1.xlsx')
wb.save(raw)
out = os.path.join(tmp, 'out')
subprocess.run(['soffice', '--headless', '--calc', '--convert-to', 'xlsx:Calc MS Excel 2007 XML', '--outdir', out, raw],
               check=True, capture_output=True, env={**os.environ, 'HOME': tmp})
shutil.copyfile(os.path.join(out, 'GD-TIME_v1.xlsx'), XLSX)
vals = load_workbook(XLSX, data_only=True)


def dump(sheet, name, cols=None):
    ws = vals[sheet]
    rows = [[('' if c is None else (int(c) if isinstance(c, float) and c == int(c) else c)) for c in row] for row in ws.iter_rows(values_only=True)]
    if cols is not None:
        idx = [rows[0].index(c) for c in cols]
        rows = [[row[i] for i in idx] for row in rows]
    with open(os.path.join(HERE, name), 'w', newline='', encoding='utf-8') as fh:
        csv.writer(fh, lineterminator='\n').writerows(rows)
    return rows


pr = dump('punches', 'punches.csv', ['case', 'device_user_code', 'employee_code', 'local_time', 'source', 'work_date'])
dr = dump('expected_days', 'expected_days.csv', ['employee_code', 'work_date', 'day_type', 'punch_count', 'in_min', 'out_min',
                                                 'worked_minutes', 'late_minutes', 'early_minutes', 'status'])
er = dump('expected_period', 'expected_period.csv')
bad = [r for r in dr[1:] if r[-1] in ('', None) or str(r[-1]).startswith('#') or str(r[-1]).startswith('Err')]
assert not bad, f'formula errors: {bad[:3]}'
print(f'punches={len(pr) - 1} days={len(dr) - 1} employees={len(er) - 1}')
for r in er:
    print(r)
shutil.rmtree(tmp)
