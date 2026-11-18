#!/usr/bin/env python3
"""
GD-PAY v1 — golden dataset for the payroll engine (DEC-41, DEC-87).

An INDEPENDENT reference calculation: written from the rules in the engine documentation (PayCalc docblock,
DEC-83/85/87), with Python Decimal and ROUND_HALF_UP — it does not call or translate the PHP code. It writes:
  tests/fixtures/gd-pay/gd-pay.json   inputs + expected lines for each case (read by tests/Unit/GdPayTest.php)
  docs/GD-PAY.md               the same cases for a person to check, line by line with "why"

Status: AI-prepared — unverified. Every rate here is a TEST VALUE, not a legal rate. A Payroll Checker must check at
least every edge case and 20 % of the normal ones (P0-06 §7) before the status may become "Verified".

Run: python3 tests/fixtures/gd-pay/build_gd_pay.py   (from the repository root)
"""
import json
from decimal import Decimal as D, ROUND_HALF_UP, ROUND_FLOOR

SAT = D('0.01')
BAHT = D('1')


def r2(x):  # half up to the satang
    return D(x).quantize(SAT, rounding=ROUND_HALF_UP)


def rnd(x, mode):
    if mode == 'half_up_baht':
        return D(x).quantize(BAHT, rounding=ROUND_HALF_UP).quantize(SAT)
    if mode == 'down_baht':
        return D(x).quantize(BAHT, rounding=ROUND_FLOOR).quantize(SAT)
    return r2(x)


POLICY = {'monthly_divisor': '30', 'hours_per_day': '8', 'proration': 'calendar_days', 'daily_paid': ['worked_days', 'holiday_days']}
SSO = {'rate_employee': '3.5', 'rate_employer': '4', 'wage_floor': '1000', 'wage_cap': '20000', 'rounding': 'half_up_baht'}
WHT = {'method': 'cumulative', 'expense_rate': '40', 'expense_cap': '80000', 'personal_allowance': '50000', 'sso_allowance_cap': '9000',
       'brackets': [{'up_to': '20000', 'rate': '0'}, {'up_to': '100000', 'rate': '10'}, {'up_to': None, 'rate': '20'}],
       'allowances': [{'code': 'INS', 'name': 'INS', 'unit': 'amount', 'per_unit': None, 'cap': '20000'},
                      {'code': 'CHILD', 'name': 'CHILD', 'unit': 'count', 'per_unit': '25000', 'cap': None}],
       'rounding': 'half_up_satang'}

# component: id, code, kind, method, taxable, sso_base, statutory
C = {
    'SALARY': (1, 'earning', 'base', 1, 1, 'none'),
    'POS': (2, 'earning', 'fixed', 1, 1, 'none'),
    'OT': (3, 'earning', 'ot', 1, 1, 'none'),
    'BONUS': (4, 'earning', 'manual', 1, 0, 'none'),
    'WELFARE': (5, 'earning', 'manual', 0, 0, 'none'),
    'ABSENT': (6, 'deduction', 'absence', 1, 1, 'none'),
    'SSO_EE': (7, 'deduction', 'sso_employee', 0, 0, 'sso_employee'),
    'WHT_M': (8, 'deduction', 'manual', 0, 0, 'wht'),
    'TAX': (9, 'deduction', 'wht', 0, 0, 'wht'),
    'SSO_ER': (10, 'employer', 'sso_employer', 0, 0, 'sso_employer'),
}
REGULAR = ['SALARY', 'POS', 'OT', 'BONUS', 'WELFARE', 'ABSENT', 'SSO_EE', 'WHT_M', 'SSO_ER']


def comps(codes):
    return [{'id': C[c][0], 'code': c, 'kind': C[c][1], 'method': C[c][2], 'taxable': C[c][3], 'sso_base': C[c][4], 'statutory': C[c][5]} for c in codes]


def progressive(net, brackets):
    tax, lower = D(0), D(0)
    for b in brackets:
        upper = D(b['up_to']) if b['up_to'] is not None else None
        if net <= lower:
            break
        part = (min(net, upper) if upper is not None else net) - lower
        tax += part * D(b['rate']) / 100
        lower = upper if upper is not None else lower
    return tax


def reference(case):
    """The reference calculation of one person. Returns (lines, why, warnings)."""
    e, codes, pol, sso, wht, mode = case['employee'], case['components'], case.get('policy'), case.get('sso'), case.get('wht'), case.get('mode', 'regular')
    have = set(codes)
    lines, why, warn = [], [], []

    def add(code, amount, src, text):
        lines.append({'code': code, 'amount': str(r2(amount)), 'source_type': src})
        why.append(f'{code} {r2(amount)}: {text}')

    p = e.get('profile')
    if mode != 'off_cycle':
        basis, pay = p['basis'], D(p['base'])
        if basis == 'monthly':
            days, pdays = e['days_employed'], case['period_days']
            if days >= pdays:
                add('SALARY', pay, 'profile', f'full month salary {pay}')
            elif pol['proration'] == 'calendar_days':
                add('SALARY', pay * days / pdays, 'profile', f'{pay} × {days} ÷ {pdays} calendar days')
            else:
                add('SALARY', min(pay, pay * days / D(pol['monthly_divisor'])), 'profile', f'{pay} × {days} ÷ divisor {pol["monthly_divisor"]}')
        else:
            if pol is None:
                warn.append(('payroll.warn.policy_missing', True))
            else:
                n = sum(D(e['time'][k]) for k in pol['daily_paid'])
                add('SALARY', pay * n, 'time', f'daily rate {pay} × {n} days ({" + ".join(pol["daily_paid"])})')
        for code, amt in p.get('items', []):
            add(code, D(amt), 'profile', 'fixed item on the pay profile')
        groups = {}
        for o in e.get('ot', []):
            groups.setdefault(o['multiplier'], 0)
            groups[o['multiplier']] += o['minutes']
        for mult, minutes in groups.items():
            den = (D(pol['monthly_divisor']) if basis == 'monthly' else 1) * D(pol['hours_per_day']) * 60
            add('OT', pay * D(mult) * minutes / den, 'ot', f'{pay} ÷ ({"divisor " + pol["monthly_divisor"] + " × " if basis == "monthly" else ""}{pol["hours_per_day"]} h) × {mult} × {minutes} min ÷ 60')
        if basis == 'monthly' and e.get('time') and D(e['time']['absent_days']) > 0:
            add('ABSENT', pay * D(e['time']['absent_days']) / D(pol['monthly_divisor']), 'time', f'{pay} × {e["time"]["absent_days"]} absent days ÷ {pol["monthly_divisor"]}')
    for m in e.get('manual', []):
        add(m['code'], D(m['amount']), 'manual', 'amount entered by Payroll')
    for w in e.get('welfare', []):
        add(w['code'], D(w['amount']), 'welfare', f'approved welfare claim {w["ref"]}')
    if mode != 'off_cycle':
        base = D(0)
        for l in lines:
            k = C[l['code']]
            if k[4] == 1:
                base += D(l['amount']) if k[1] == 'earning' else (-D(l['amount']) if k[1] == 'deduction' else 0)
        if base > 0 and sso is not None:
            wage = max(D(sso['wage_floor']), min(D(sso['wage_cap']), base))
            for code, key in (('SSO_EE', 'rate_employee'), ('SSO_ER', 'rate_employer')):
                if code in have:
                    amt = rnd(wage * D(sso[key]) / 100, sso['rounding'])
                    lines.append({'code': code, 'amount': str(amt), 'source_type': 'sso'})
                    why.append(f'{code} {amt}: wage {base} kept between {sso["wage_floor"]} and {sso["wage_cap"]} = {wage} × {sso[key]} % ({sso["rounding"]})')
        if 'TAX' in have and wht is not None:
            t = e['tax']
            taxable = max(D(0), sum((D(l['amount']) if C[l['code']][1] == 'earning' else -D(l['amount'])) for l in lines if C[l['code']][3] == 1))
            sso_now = sum(D(l['amount']) for l in lines if l['code'] == 'SSO_EE')
            if wht['method'] == 'annualize_current':
                left, year_income, year_sso, withheld = 12, taxable * 12, sso_now * 12, D(0)
            else:
                left = 13 - t['month']
                year_income = D(t['ytd_taxable']) + taxable * left
                year_sso = D(t['ytd_sso']) + sso_now * left
                withheld = D(t['ytd_wht'])
            expense = min(year_income * D(wht['expense_rate']) / 100, D(wht['expense_cap']))
            allow = D(wht['personal_allowance'])
            decl = {d['code']: d['value'] for d in t.get('declared', [])}
            for a in wht['allowances']:
                if a['code'] in decl:
                    v = D(int(D(decl[a['code']]))) * D(a['per_unit']) if a['unit'] == 'count' else D(decl[a['code']])
                    allow += min(v, D(a['cap'])) if a['cap'] is not None else v
            if wht.get('sso_allowance_cap') is not None:
                allow += min(year_sso, D(wht['sso_allowance_cap']))
            net = max(D(0), year_income - expense - allow)
            year_tax = progressive(net, wht['brackets'])
            due = max(D(0), year_tax - withheld)
            amt = rnd(due / left, wht['rounding'])
            if amt > 0:
                lines.append({'code': 'TAX', 'amount': str(amt), 'source_type': 'tax'})
            why.append(f'TAX {amt}: year income {r2(year_income)} − expenses {r2(expense)} − allowances {r2(allow)} = net {r2(net)} → year tax {r2(year_tax)} − withheld {withheld} = {r2(due)} ÷ {left} months')
    return lines, why, warn


def diff(new_lines, orig):
    """Correction: per component, recalculated minus already paid."""
    now = {}
    for l in new_lines:
        now[l['code']] = now.get(l['code'], D(0)) + D(l['amount'])
    out, why = [], []
    for code in list(dict.fromkeys(list(now.keys()) + list(orig.keys()))):
        d = now.get(code, D(0)) - D(orig.get(code, '0'))
        if d != 0:
            out.append({'code': code, 'amount': str(r2(d)), 'source_type': 'correction'})
            why.append(f'{code} {r2(d)}: now {r2(now.get(code, D(0)))} − paid {r2(D(orig.get(code, "0")))}')
    return out, why


def totals(lines):
    g = sum(D(l['amount']) for l in lines if C[l['code']][1] == 'earning')
    d = sum(D(l['amount']) for l in lines if C[l['code']][1] == 'deduction')
    e = sum(D(l['amount']) for l in lines if C[l['code']][1] == 'employer')
    return {'gross': str(r2(g)), 'deductions': str(r2(d)), 'net': str(r2(g - d)), 'employer': str(r2(e))}


def emp(**k):
    base = {'days_employed': 31, 'time': None, 'ot': [], 'manual': [], 'welfare': [],
            'tax': {'ytd_taxable': '0', 'ytd_wht': '0', 'ytd_sso': '0', 'month': 10, 'declared': [], 'declared_ok': True}}
    base.update(k)
    return base


def prof(basis, amount, items=()):
    return {'basis': basis, 'base': amount, 'items': list(items)}


CASES = [
    ('GD-PAY-01', 'normal', 'Monthly, full month, fixed allowance, social security at the cap', dict(employee=emp(profile=prof('monthly', '30000', [('POS', '2000')])), components=REGULAR, policy=POLICY, sso=SSO)),
    ('GD-PAY-02', 'edge', 'Social security floor: wage below the floor', dict(employee=emp(profile=prof('monthly', '800')), components=REGULAR, policy=POLICY, sso=SSO)),
    ('GD-PAY-03', 'edge', 'Joined mid-month, calendar-day proration (15 of 31 days)', dict(employee=emp(days_employed=15, profile=prof('monthly', '31000')), components=REGULAR, policy=POLICY, sso=SSO)),
    ('GD-PAY-04', 'edge', 'Joined mid-month, divisor proration (10 days ÷ 30)', dict(employee=emp(days_employed=10, profile=prof('monthly', '30000')), components=REGULAR, policy=dict(POLICY, proration='divisor_days'), sso=SSO)),
    ('GD-PAY-05', 'normal', 'Daily paid: worked + holiday days, social security rounded to the baht', dict(employee=emp(profile=prof('daily', '520'), time={'worked_days': '20', 'absent_days': '0', 'holiday_days': '1', 'leave_days': '0'}), components=REGULAR, policy=POLICY, sso=SSO)),
    ('GD-PAY-06', 'normal', 'OT 1.5× 10 hours and 3× 2 hours', dict(employee=emp(profile=prof('monthly', '24000'), time={'worked_days': '22', 'absent_days': '0', 'holiday_days': '0', 'leave_days': '0'},
        ot=[{'multiplier': '1.5', 'minutes': 600}, {'multiplier': '3', 'minutes': 120}]), components=REGULAR, policy=POLICY, sso=SSO)),
    ('GD-PAY-07', 'normal', 'Absence deduction: 2 days', dict(employee=emp(profile=prof('monthly', '15000'), time={'worked_days': '20', 'absent_days': '2', 'holiday_days': '0', 'leave_days': '0'}), components=REGULAR, policy=POLICY, sso=SSO)),
    ('GD-PAY-08', 'edge', 'Withholding tax, cumulative method, opening amounts and declared allowances (as G6C-01)', dict(employee=emp(profile=prof('monthly', '18500'),
        tax={'ytd_taxable': '166500', 'ytd_wht': '1200', 'ytd_sso': '5250', 'month': 10, 'declared': [{'code': 'INS', 'value': '30000'}, {'code': 'CHILD', 'value': '1'}], 'declared_ok': True}),
        components=REGULAR + ['TAX'], policy=POLICY, sso=SSO, wht=WHT)),
    ('GD-PAY-09', 'normal', 'Withholding tax, ×12 method, no declaration', dict(employee=emp(profile=prof('monthly', '50000')), components=REGULAR + ['TAX'], policy=POLICY, sso=SSO, wht=dict(WHT, method='annualize_current'))),
    ('GD-PAY-10', 'normal', 'Welfare claim and a manual bonus in a regular run', dict(employee=emp(profile=prof('monthly', '20000'), manual=[{'code': 'BONUS', 'amount': '5000'}],
        welfare=[{'code': 'WELFARE', 'amount': '3000', 'ref': 'WF-2026-00001'}]), components=REGULAR, policy=POLICY, sso=SSO)),
    ('GD-PAY-11', 'edge', 'Off-cycle bonus with tax entered by Payroll — nothing else is worked out', dict(mode='off_cycle', employee=emp(profile=prof('monthly', '20000'),
        manual=[{'code': 'BONUS', 'amount': '50000'}, {'code': 'WHT_M', 'amount': '2500'}]), components=REGULAR + ['TAX'], policy=POLICY, sso=SSO, wht=WHT)),
    ('GD-PAY-12', 'edge', 'Correction: pay raised back in time 15,000 → 16,000 (salary and social security differences)', dict(mode='correction', employee=emp(profile=prof('monthly', '16000')),
        original={'SALARY': '15000', 'SSO_EE': '525', 'SSO_ER': '600'}, components=REGULAR, policy=POLICY, sso=SSO)),
    ('GD-PAY-13', 'edge', 'Correction that lowers pay 16,000 → 15,000 (negative difference, warning only)', dict(mode='correction', employee=emp(profile=prof('monthly', '15000')),
        original={'SALARY': '16000', 'SSO_EE': '560', 'SSO_ER': '640'}, components=REGULAR, policy=POLICY, sso=SSO)),
    ('GD-PAY-14', 'edge', 'Daily paid without company pay rules: nothing guessed, blocking', dict(employee=emp(profile=prof('daily', '520'), time={'worked_days': '20', 'absent_days': '0', 'holiday_days': '0', 'leave_days': '0'}),
        components=REGULAR, policy=None, sso=SSO)),
    ('GD-PAY-15', 'edge', 'Correction with tax: 50,000 → 60,000 in October, tax worked out again from the tax position the original month used', dict(mode='correction',
        employee=emp(profile=prof('monthly', '60000'), tax={'ytd_taxable': '450000', 'ytd_wht': '27000', 'ytd_sso': '6750', 'month': 10, 'declared': [], 'declared_ok': True}),
        original_profile=prof('monthly', '50000'), components=REGULAR + ['TAX'], policy=POLICY, sso=SSO, wht=WHT)),
    ('GD-PAY-16', 'edge', 'Correction of a month whose tax was not worked out (tax entered by hand then): no tax worked out now either', dict(mode='correction', work_out_tax=False,
        employee=emp(profile=prof('monthly', '60000'), manual=[{'code': 'WHT_M', 'amount': '3000'}]),
        original={'SALARY': '50000', 'SSO_EE': '700', 'SSO_ER': '800', 'WHT_M': '3000'}, components=REGULAR + ['TAX'], policy=POLICY, sso=SSO, wht=WHT)),
]


def main():
    out = []
    md = ['# GD-PAY v1 — ชุดข้อมูลทองคำของเครื่องคำนวณเงินเดือน', '',
          '> **สถานะ: `AI-prepared — unverified`** · ค่าทั้งหมดเป็นค่าทดสอบ (ไม่ใช่อัตราตามกฎหมาย) · คำนวณด้วยวิธีอ้างอิงแยก (`tests/fixtures/gd-pay/build_gd_pay.py`, Python Decimal ปัดครึ่งขึ้น) ไม่ได้เรียกโค้ดของระบบ',
          '> ผู้ตรวจ (Payroll Checker) ต้องตรวจกรณีขอบ (edge) ทั้งหมด + สุ่มกรณีปกติอย่างน้อย 20 % แล้วลงชื่อในตารางท้ายเอกสาร จึงเปลี่ยนเป็น `Verified` ได้ (P0-06 §7, DEC-41)', '',
          '**ค่าทดสอบที่ใช้:** กติกาบริษัท ตัวหาร 30 · 8 ชม./วัน · ไม่เต็มเดือนแบบตามวันปฏิทิน · รายวันจ่ายวันทำงาน + วันหยุด — ประกันสังคม 3.5 % / 4 % ฐาน 1,000–20,000 ปัดเป็นบาท — ภาษี (ทดสอบ) หักค่าใช้จ่าย 40 % ไม่เกิน 80,000 · ส่วนตัว 50,000 · ประกันสังคมไม่เกิน 9,000 · ขั้น 0 % ถึง 20,000 / 10 % ถึง 100,000 / 20 % ส่วนเกิน · INS ไม่เกิน 20,000 · บุตรคนละ 25,000', '']
    for cid, kind, title, c in CASES:
        c = dict(c)
        c['period_days'] = 31
        if c.get('original_profile') is not None:  # the original month worked out by the same reference
            o_lines, _, _ = reference(dict(c, mode='regular', employee=dict(c['employee'], profile=c['original_profile'])))
            c['original'] = {}
            for l in o_lines:
                c['original'][l['code']] = str(D(c['original'].get(l['code'], '0')) + D(l['amount']))
        lines, why, warn = reference(dict(c, wht=None) if c.get('work_out_tax') is False else c)
        if c.get('mode') == 'correction':
            lines, dwhy = diff(lines, c['original'])
            why = why + ['— ส่วนต่าง / difference —'] + dwhy
        tot = totals(lines)
        exp_warn = [w[0] for w in warn]
        if c.get('mode') == 'correction' and D(tot['net']) < 0:
            exp_warn.append('payroll.warn.correction_negative')
        out.append({'id': cid, 'kind': kind, 'title': title, 'mode': c.get('mode', 'regular'), 'period_days': 31, 'employee': c['employee'], 'components': comps(c['components']),
                    'config': {'payroll.policy': c.get('policy'), 'payroll.sso': c.get('sso'), 'payroll.wht': c.get('wht')}, 'original': c.get('original', {}), 'work_out_tax': c.get('work_out_tax', True),
                    'expected': {'lines': lines, 'totals': tot, 'warnings': exp_warn}})
        md += [f'## {cid} · {title} ({"กรณีขอบ" if kind == "edge" else "กรณีปกติ"})', '', '| รายการ | จำนวน | ที่มา |', '|---|---|---|']
        md += [f'| {l["code"]} | {l["amount"]} | {l["source_type"]} |' for l in lines] or ['| — | — | — |']
        md += ['', f'**รวม:** เงินได้ {tot["gross"]} · เงินหัก {tot["deductions"]} · **สุทธิ {tot["net"]}** · นายจ้างจ่าย {tot["employer"]}' + (f' · คำเตือน: {", ".join(exp_warn)}' if exp_warn else ''), '', '**ทำไม:**']
        md += [f'- {w}' for w in why] + ['']
    md += ['## การตรวจโดยคน', '', '| กรณี | ผู้ตรวจ | วันที่ | ผล (ตรง/ไม่ตรง + เหตุผล) |', '|---|---|---|---|'] + [f'| {c[0]} | | | |' for c in CASES]
    json.dump({'version': 'GD-PAY v1', 'status': 'AI-prepared — unverified', 'cases': out}, open('tests/fixtures/gd-pay/gd-pay.json', 'w'), ensure_ascii=False, indent=1)
    open('docs/GD-PAY.md', 'w').write('\n'.join(md) + '\n')
    print(len(out), 'cases')


if __name__ == '__main__':
    main()
