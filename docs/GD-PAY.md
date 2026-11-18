# GD-PAY v1 — ชุดข้อมูลทองคำของเครื่องคำนวณเงินเดือน

> **สถานะ: `AI-prepared — unverified`** · ค่าทั้งหมดเป็นค่าทดสอบ (ไม่ใช่อัตราตามกฎหมาย) · คำนวณด้วยวิธีอ้างอิงแยก (`tests/fixtures/gd-pay/build_gd_pay.py`, Python Decimal ปัดครึ่งขึ้น) ไม่ได้เรียกโค้ดของระบบ
> ผู้ตรวจ (Payroll Checker) ต้องตรวจกรณีขอบ (edge) ทั้งหมด + สุ่มกรณีปกติอย่างน้อย 20 % แล้วลงชื่อในตารางท้ายเอกสาร จึงเปลี่ยนเป็น `Verified` ได้ (P0-06 §7, DEC-41)

**ค่าทดสอบที่ใช้:** กติกาบริษัท ตัวหาร 30 · 8 ชม./วัน · ไม่เต็มเดือนแบบตามวันปฏิทิน · รายวันจ่ายวันทำงาน + วันหยุด — ประกันสังคม 3.5 % / 4 % ฐาน 1,000–20,000 ปัดเป็นบาท — ภาษี (ทดสอบ) หักค่าใช้จ่าย 40 % ไม่เกิน 80,000 · ส่วนตัว 50,000 · ประกันสังคมไม่เกิน 9,000 · ขั้น 0 % ถึง 20,000 / 10 % ถึง 100,000 / 20 % ส่วนเกิน · INS ไม่เกิน 20,000 · บุตรคนละ 25,000

## GD-PAY-01 · Monthly, full month, fixed allowance, social security at the cap (กรณีปกติ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 30000.00 | profile |
| POS | 2000.00 | profile |
| SSO_EE | 700.00 | sso |
| SSO_ER | 800.00 | sso |

**รวม:** เงินได้ 32000.00 · เงินหัก 700.00 · **สุทธิ 31300.00** · นายจ้างจ่าย 800.00

**ทำไม:**
- SALARY 30000.00: full month salary 30000
- POS 2000.00: fixed item on the pay profile
- SSO_EE 700.00: wage 32000.00 kept between 1000 and 20000 = 20000 × 3.5 % (half_up_baht)
- SSO_ER 800.00: wage 32000.00 kept between 1000 and 20000 = 20000 × 4 % (half_up_baht)

## GD-PAY-02 · Social security floor: wage below the floor (กรณีขอบ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 800.00 | profile |
| SSO_EE | 35.00 | sso |
| SSO_ER | 40.00 | sso |

**รวม:** เงินได้ 800.00 · เงินหัก 35.00 · **สุทธิ 765.00** · นายจ้างจ่าย 40.00

**ทำไม:**
- SALARY 800.00: full month salary 800
- SSO_EE 35.00: wage 800.00 kept between 1000 and 20000 = 1000 × 3.5 % (half_up_baht)
- SSO_ER 40.00: wage 800.00 kept between 1000 and 20000 = 1000 × 4 % (half_up_baht)

## GD-PAY-03 · Joined mid-month, calendar-day proration (15 of 31 days) (กรณีขอบ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 15000.00 | profile |
| SSO_EE | 525.00 | sso |
| SSO_ER | 600.00 | sso |

**รวม:** เงินได้ 15000.00 · เงินหัก 525.00 · **สุทธิ 14475.00** · นายจ้างจ่าย 600.00

**ทำไม:**
- SALARY 15000.00: 31000 × 15 ÷ 31 calendar days
- SSO_EE 525.00: wage 15000.00 kept between 1000 and 20000 = 15000.00 × 3.5 % (half_up_baht)
- SSO_ER 600.00: wage 15000.00 kept between 1000 and 20000 = 15000.00 × 4 % (half_up_baht)

## GD-PAY-04 · Joined mid-month, divisor proration (10 days ÷ 30) (กรณีขอบ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 10000.00 | profile |
| SSO_EE | 350.00 | sso |
| SSO_ER | 400.00 | sso |

**รวม:** เงินได้ 10000.00 · เงินหัก 350.00 · **สุทธิ 9650.00** · นายจ้างจ่าย 400.00

**ทำไม:**
- SALARY 10000.00: 30000 × 10 ÷ divisor 30
- SSO_EE 350.00: wage 10000.00 kept between 1000 and 20000 = 10000.00 × 3.5 % (half_up_baht)
- SSO_ER 400.00: wage 10000.00 kept between 1000 and 20000 = 10000.00 × 4 % (half_up_baht)

## GD-PAY-05 · Daily paid: worked + holiday days, social security rounded to the baht (กรณีปกติ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 10920.00 | time |
| SSO_EE | 382.00 | sso |
| SSO_ER | 437.00 | sso |

**รวม:** เงินได้ 10920.00 · เงินหัก 382.00 · **สุทธิ 10538.00** · นายจ้างจ่าย 437.00

**ทำไม:**
- SALARY 10920.00: daily rate 520 × 21 days (worked_days + holiday_days)
- SSO_EE 382.00: wage 10920.00 kept between 1000 and 20000 = 10920.00 × 3.5 % (half_up_baht)
- SSO_ER 437.00: wage 10920.00 kept between 1000 and 20000 = 10920.00 × 4 % (half_up_baht)

## GD-PAY-06 · OT 1.5× 10 hours and 3× 2 hours (กรณีปกติ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 24000.00 | profile |
| OT | 1500.00 | ot |
| OT | 600.00 | ot |
| SSO_EE | 700.00 | sso |
| SSO_ER | 800.00 | sso |

**รวม:** เงินได้ 26100.00 · เงินหัก 700.00 · **สุทธิ 25400.00** · นายจ้างจ่าย 800.00

**ทำไม:**
- SALARY 24000.00: full month salary 24000
- OT 1500.00: 24000 ÷ (divisor 30 × 8 h) × 1.5 × 600 min ÷ 60
- OT 600.00: 24000 ÷ (divisor 30 × 8 h) × 3 × 120 min ÷ 60
- SSO_EE 700.00: wage 26100.00 kept between 1000 and 20000 = 20000 × 3.5 % (half_up_baht)
- SSO_ER 800.00: wage 26100.00 kept between 1000 and 20000 = 20000 × 4 % (half_up_baht)

## GD-PAY-07 · Absence deduction: 2 days (กรณีปกติ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 15000.00 | profile |
| ABSENT | 1000.00 | time |
| SSO_EE | 490.00 | sso |
| SSO_ER | 560.00 | sso |

**รวม:** เงินได้ 15000.00 · เงินหัก 1490.00 · **สุทธิ 13510.00** · นายจ้างจ่าย 560.00

**ทำไม:**
- SALARY 15000.00: full month salary 15000
- ABSENT 1000.00: 15000 × 2 absent days ÷ 30
- SSO_EE 490.00: wage 14000.00 kept between 1000 and 20000 = 14000.00 × 3.5 % (half_up_baht)
- SSO_ER 560.00: wage 14000.00 kept between 1000 and 20000 = 14000.00 × 4 % (half_up_baht)

## GD-PAY-08 · Withholding tax, cumulative method, opening amounts and declared allowances (as G6C-01) (กรณีขอบ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 18500.00 | profile |
| SSO_EE | 648.00 | sso |
| SSO_ER | 740.00 | sso |
| TAX | 260.20 | tax |

**รวม:** เงินได้ 18500.00 · เงินหัก 908.20 · **สุทธิ 17591.80** · นายจ้างจ่าย 740.00

**ทำไม:**
- SALARY 18500.00: full month salary 18500
- SSO_EE 648.00: wage 18500.00 kept between 1000 and 20000 = 18500.00 × 3.5 % (half_up_baht)
- SSO_ER 740.00: wage 18500.00 kept between 1000 and 20000 = 18500.00 × 4 % (half_up_baht)
- TAX 260.20: year income 222000.00 − expenses 80000.00 − allowances 102194.00 = net 39806.00 → year tax 1980.60 − withheld 1200 = 780.60 ÷ 3 months

## GD-PAY-09 · Withholding tax, ×12 method, no declaration (กรณีปกติ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 50000.00 | profile |
| SSO_EE | 700.00 | sso |
| SSO_ER | 800.00 | sso |
| TAX | 6693.33 | tax |

**รวม:** เงินได้ 50000.00 · เงินหัก 7393.33 · **สุทธิ 42606.67** · นายจ้างจ่าย 800.00

**ทำไม:**
- SALARY 50000.00: full month salary 50000
- SSO_EE 700.00: wage 50000.00 kept between 1000 and 20000 = 20000 × 3.5 % (half_up_baht)
- SSO_ER 800.00: wage 50000.00 kept between 1000 and 20000 = 20000 × 4 % (half_up_baht)
- TAX 6693.33: year income 600000.00 − expenses 80000.00 − allowances 58400.00 = net 461600.00 → year tax 80320.00 − withheld 0 = 80320.00 ÷ 12 months

## GD-PAY-10 · Welfare claim and a manual bonus in a regular run (กรณีปกติ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 20000.00 | profile |
| BONUS | 5000.00 | manual |
| WELFARE | 3000.00 | welfare |
| SSO_EE | 700.00 | sso |
| SSO_ER | 800.00 | sso |

**รวม:** เงินได้ 28000.00 · เงินหัก 700.00 · **สุทธิ 27300.00** · นายจ้างจ่าย 800.00

**ทำไม:**
- SALARY 20000.00: full month salary 20000
- BONUS 5000.00: amount entered by Payroll
- WELFARE 3000.00: approved welfare claim WF-2026-00001
- SSO_EE 700.00: wage 20000.00 kept between 1000 and 20000 = 20000 × 3.5 % (half_up_baht)
- SSO_ER 800.00: wage 20000.00 kept between 1000 and 20000 = 20000 × 4 % (half_up_baht)

## GD-PAY-11 · Off-cycle bonus with tax entered by Payroll — nothing else is worked out (กรณีขอบ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| BONUS | 50000.00 | manual |
| WHT_M | 2500.00 | manual |

**รวม:** เงินได้ 50000.00 · เงินหัก 2500.00 · **สุทธิ 47500.00** · นายจ้างจ่าย 0.00

**ทำไม:**
- BONUS 50000.00: amount entered by Payroll
- WHT_M 2500.00: amount entered by Payroll

## GD-PAY-12 · Correction: pay raised back in time 15,000 → 16,000 (salary and social security differences) (กรณีขอบ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 1000.00 | correction |
| SSO_EE | 35.00 | correction |
| SSO_ER | 40.00 | correction |

**รวม:** เงินได้ 1000.00 · เงินหัก 35.00 · **สุทธิ 965.00** · นายจ้างจ่าย 40.00

**ทำไม:**
- SALARY 16000.00: full month salary 16000
- SSO_EE 560.00: wage 16000.00 kept between 1000 and 20000 = 16000.00 × 3.5 % (half_up_baht)
- SSO_ER 640.00: wage 16000.00 kept between 1000 and 20000 = 16000.00 × 4 % (half_up_baht)
- — ส่วนต่าง / difference —
- SALARY 1000.00: now 16000.00 − paid 15000.00
- SSO_EE 35.00: now 560.00 − paid 525.00
- SSO_ER 40.00: now 640.00 − paid 600.00

## GD-PAY-13 · Correction that lowers pay 16,000 → 15,000 (negative difference, warning only) (กรณีขอบ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | -1000.00 | correction |
| SSO_EE | -35.00 | correction |
| SSO_ER | -40.00 | correction |

**รวม:** เงินได้ -1000.00 · เงินหัก -35.00 · **สุทธิ -965.00** · นายจ้างจ่าย -40.00 · คำเตือน: payroll.warn.correction_negative

**ทำไม:**
- SALARY 15000.00: full month salary 15000
- SSO_EE 525.00: wage 15000.00 kept between 1000 and 20000 = 15000.00 × 3.5 % (half_up_baht)
- SSO_ER 600.00: wage 15000.00 kept between 1000 and 20000 = 15000.00 × 4 % (half_up_baht)
- — ส่วนต่าง / difference —
- SALARY -1000.00: now 15000.00 − paid 16000.00
- SSO_EE -35.00: now 525.00 − paid 560.00
- SSO_ER -40.00: now 600.00 − paid 640.00

## GD-PAY-14 · Daily paid without company pay rules: nothing guessed, blocking (กรณีขอบ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| — | — | — |

**รวม:** เงินได้ 0.00 · เงินหัก 0.00 · **สุทธิ 0.00** · นายจ้างจ่าย 0.00 · คำเตือน: payroll.warn.policy_missing

**ทำไม:**

## GD-PAY-15 · Correction with tax: 50,000 → 60,000 in October, tax worked out again from the tax position the original month used (กรณีขอบ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 10000.00 | correction |
| TAX | 2000.00 | correction |

**รวม:** เงินได้ 10000.00 · เงินหัก 2000.00 · **สุทธิ 8000.00** · นายจ้างจ่าย 0.00

**ทำไม:**
- SALARY 60000.00: full month salary 60000
- SSO_EE 700.00: wage 60000.00 kept between 1000 and 20000 = 20000 × 3.5 % (half_up_baht)
- SSO_ER 800.00: wage 60000.00 kept between 1000 and 20000 = 20000 × 4 % (half_up_baht)
- TAX 19743.33: year income 630000.00 − expenses 80000.00 − allowances 58850.00 = net 491150.00 → year tax 86230.00 − withheld 27000 = 59230.00 ÷ 3 months
- — ส่วนต่าง / difference —
- SALARY 10000.00: now 60000.00 − paid 50000.00
- TAX 2000.00: now 19743.33 − paid 17743.33

## GD-PAY-16 · Correction of a month whose tax was not worked out (tax entered by hand then): no tax worked out now either (กรณีขอบ)

| รายการ | จำนวน | ที่มา |
|---|---|---|
| SALARY | 10000.00 | correction |

**รวม:** เงินได้ 10000.00 · เงินหัก 0.00 · **สุทธิ 10000.00** · นายจ้างจ่าย 0.00

**ทำไม:**
- SALARY 60000.00: full month salary 60000
- WHT_M 3000.00: amount entered by Payroll
- SSO_EE 700.00: wage 60000.00 kept between 1000 and 20000 = 20000 × 3.5 % (half_up_baht)
- SSO_ER 800.00: wage 60000.00 kept between 1000 and 20000 = 20000 × 4 % (half_up_baht)
- — ส่วนต่าง / difference —
- SALARY 10000.00: now 60000.00 − paid 50000.00

## การตรวจโดยคน

| กรณี | ผู้ตรวจ | วันที่ | ผล (ตรง/ไม่ตรง + เหตุผล) |
|---|---|---|---|
| GD-PAY-01 | | | |
| GD-PAY-02 | | | |
| GD-PAY-03 | | | |
| GD-PAY-04 | | | |
| GD-PAY-05 | | | |
| GD-PAY-06 | | | |
| GD-PAY-07 | | | |
| GD-PAY-08 | | | |
| GD-PAY-09 | | | |
| GD-PAY-10 | | | |
| GD-PAY-11 | | | |
| GD-PAY-12 | | | |
| GD-PAY-13 | | | |
| GD-PAY-14 | | | |
| GD-PAY-15 | | | |
| GD-PAY-16 | | | |
