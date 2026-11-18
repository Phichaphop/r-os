# รายงานตรวจ Gate เฟส 5 — HR Core & Core+ (รุ่น 0.18.0)

> Release: `0.18.0 (phase 5 gate: privacy)` · วันที่ 2026-10-26 · Environment: เครื่องพัฒนา (PHP 8.3 + MariaDB) · ข้อมูลตัวอย่างทั้งหมด (DEC-34) — **ไม่มีข้อมูลบุคคลจริง**
> เกณฑ์: `07` ข้อ 10.4 (DoD 1–9) ทุกโมดูล + P0-06 §9 เฟส 5 ("โมดูล HR ในขอบเขตผ่าน DoD ทีละโมดูล; GPS ตามนโยบาย privacy · TC-PRV · ผู้ยืนยัน HR Admin")
> สถานะรายข้อเป็น PASS / FAIL / NOT RUN / BLOCKED / REVIEWED ONLY ตามจริง (ข้อ 10.3)

## สถานะ Gate

**พร้อมให้ตรวจรับ — ยังไม่ถือว่าผ่าน Gate** · งานที่ AI ทำได้ครบแล้ว (DoD ข้อ 2–8 ทุกโมดูล, TC-PRV ของ M57/M13, การทดสอบข้ามบริษัท) แต่ยังขาด 2 อย่างที่ AI ทำแทนไม่ได้:

1. **UAT โดย HR Admin** (DoD ข้อ 9) — ใช้ `docs/UAT_PHASE5.md`
2. **Privacy/Legal ยืนยัน** ประกาศ GPS + ระยะเก็บ (SP-05), ฐานการประมวลผล/ระยะเก็บ (SP-01/02), จำนวนวันตอบคำขอเจ้าของข้อมูล (`privacy.dsr_response_days`) และการตัดสินใจที่ยังเป็น PROPOSED (DEC-67…DEC-82)

ไม่มี Blocker/Critical ค้าง · ไม่มี FAIL · ข้อที่ยังไม่ได้รันระบุไว้เป็น NOT RUN ทั้งหมด (ไม่ได้นับว่าผ่าน)

## สรุปตัวเลข

| รายการ | ผล |
|---|---|
| PHPUnit ชุดเต็ม (รุ่น 0.18.0) | **352 ชุด 4,670 assertions ผ่านทั้งหมด** (21 นาที 2 วินาที) |
| รายงานทดสอบรายส่วน 5A–5F (10 ฉบับ) | PASS 188 · FAIL 0 · NOT RUN 64 · REVIEWED ONLY 1 (5E-5) |
| TC-PRV ของ M57/M13 (รอบนี้) | PASS 6 · NOT RUN 2 |
| ทดสอบข้ามบริษัท (รอบนี้) | PASS — 9 หน้า (7 ชนิดข้อมูล) × 3 ผู้ใช้ |
| E2E เบราว์เซอร์จริง (รอบนี้) | 14 หน้าจอ, overflow 0, axe 0 violation |
| PHPStan / PHPCS / check:all | PASS / PASS / PASS (TC-AI-00, secret scan, token lint, architecture) |

## สิ่งที่เพิ่มในรอบนี้ (รุ่น 0.18.0 — DEC-82)

ตรวจ Gate แล้วพบช่องว่างที่ทำให้ TC-PRV ไม่ครบ คือ **ยังไม่มีทางให้เจ้าของข้อมูลขอดู/ขอลบข้อมูลของตน** (P0-04 §5–7, 7K.1 ข้อ 15) จึงสร้างเพิ่มในส่วนกลาง:

- **ข้อมูลส่วนบุคคลของฉัน** (`/me/data`) — ดูว่าระบบเก็บอะไรเกี่ยวกับตัวเองกี่รายการ และดาวน์โหลดชุดข้อมูลของตน (JSON) — ต้องยืนยันรหัสผ่านใหม่ก่อน, บันทึก audit `privacy.self_export`
- **คำขอของเจ้าของข้อมูล (DSR)** — ขอสำเนา / แก้ไข / ลบ / คัดค้าน; เลขที่ `DSR-ปี-ลำดับ`; กำหนดตอบอ่านจาก `privacy.dsr_response_days` — **ยังไม่ตั้งค่า** (ระบบแสดง "ยังไม่ได้กำหนด (รอฝ่ายกฎหมาย)" — AI ไม่กำหนดจำนวนวันเอง)
- **โต๊ะงานเจ้าหน้าที่คุ้มครองข้อมูล** (บทบาท PRV, สิทธิ์ `core.privacy.manage` ระดับ high) — ยืนยันตัวตน → **จำลองการลบก่อน (dry run)** → **เจ้าหน้าที่อีกคนอนุมัติ** (คนเตรียม ≠ คนอนุมัติ, เจ้าหน้าที่ลบข้อมูลตัวเองไม่ได้) → ลบจริง → ตอบและปิด; ข้อมูลที่ติด legal hold หรือกฎหมายกำหนดให้เก็บ ระบบเก็บไว้พร้อมเหตุผล
- **สัญญากลาง `PersonalDataProvider`** — โมดูลประกาศใน `module.json` ว่าเก็บข้อมูลบุคคลอะไร ส่งออก/ลบอย่างไร · ทำแล้ว 3 โมดูล: M57 GPS (ลบพิกัด/ความแม่นยำ/IP/อุปกรณ์ เก็บเวลา + ผลในพื้นที่ไว้เป็นบันทึกเวลาทำงาน), M13 บัตร (ลบรูปบนบัตร เก็บเลขบัตร/สถานะ/รายการเงิน), M09 ประวัติ (ส่งออกได้ ไม่ลบ — แก้ผ่านคำขอเปลี่ยนข้อมูล)
- ข้อมูลตัวอย่าง: `dpo.demo1`, `dpo.demo2` (บทบาท PRV ทั้งองค์กร, รหัสผ่านตัวอย่างเดียวกับผู้ใช้อื่น)

## TC-PRV — M57 GPS + M13 บัตรพนักงาน

ทดสอบอัตโนมัติ `tests/Integration/PrivacyGateTest.php` (ผ่านหน้าจอและ service จริง) + E2E `p0180` บนเบราว์เซอร์

| ID | กรณี | ผล | หลักฐาน |
|---|---|---|---|
| TC-PRV-G1 | ค่าที่ปิดบัง (masked): รายงานหัวหน้าเห็นแค่ "ในพื้นที่/นอกพื้นที่"; ตรวจเสร็จแล้วผู้ตรวจไม่เห็นพิกัดอีก · หน้าตรวจบัตร (M13) เห็นแค่รูป ชื่อ รหัส สถานะ ไม่เห็นเลขประจำตัว/ข้อมูลติดต่อ | PASS | PrivacyGateTest (1) |
| TC-PRV-G2 | ส่งออกข้อมูลของตนเอง: ต้องยืนยันรหัสผ่าน (302 → /reauth), มีพิกัดและบัตรของตัวเอง ไม่มีของคนอื่น, บันทึก audit, `Cache-Control: no-store` | PASS | PrivacyGateTest (2) · E2E 02–03 |
| TC-PRV-G3 | คำขอลบ: ต้องยืนยันตัวตนก่อน → dry run ไม่เปลี่ยนข้อมูล → คนเดียวกันอนุมัติไม่ได้ (`privacy.error.same_person`) → คนที่สองอนุมัติ → พิกัด/IP/อุปกรณ์ = NULL, เวลา + ผลในพื้นที่ยังอยู่, รูปบัตรถูก dispose, audit `privacy.erased` → ปิดคำขอ, ผู้ขอเห็นผล, คนอื่นเปิดคำขอไม่ได้ (404) | PASS | PrivacyGateTest (3) · E2E 05–08, 11–14 |
| TC-PRV-G4 | คำขอสำเนา: ชุดข้อมูลของเจ้าหน้าที่ระบุ `purpose = dsr`, ต้องยืนยันรหัสผ่าน, audit `privacy.package_exported` | PASS | PrivacyGateTest (3) |
| TC-PRV-G5 | ไม่มีข้อมูลบุคคลใน log / audit / การแจ้งเตือน / URL: ค้นพิกัดทดสอบ ชื่อ และรหัสผ่าน ใน log ทุกไฟล์ ไม่พบ · ลิงก์แจ้งเตือนมีแค่ id · ลิงก์ใน QR บัตรไม่มีรหัสพนักงาน/ชื่อ | PASS | PrivacyGateTest (4) |
| TC-PRV-G6 | หน้าจอเจ้าหน้าที่/พนักงานแสดงครบ ไม่มีคีย์แปลค้าง · ไม่ได้ตั้งจำนวนวันตอบ → แสดงข้อความแจ้งแทนการเดา | PASS | PrivacyGateTest · E2E 06, 10 |
| TC-PRV-G7 | GPS บนมือถือจริง (อนุญาตตำแหน่ง, HTTPS) และประกาศ GPS ที่ DPO เขียน (SP-05) | NOT RUN | ต้องใช้มือถือจริง + ข้อความจาก DPO (AI ไม่ร่าง) |
| TC-PRV-G8 | ทดสอบบน InfinityFree | NOT RUN | ยังไม่ได้ deploy · **ห้ามข้อมูลจริงบน InfinityFree** |

## ทดสอบข้ามบริษัท (7K.3 ข้อ 1)

`tests/Integration/CrossCompanyPhase5Test.php` — สร้างข้อมูลของบริษัท DEMO1 ผ่าน service ของแต่ละโมดูล แล้วเปิดด้วยผู้ใช้ 3 คน

| หน้า (ข้อมูล DEMO1) | hr.demo1 (HR DEMO1) | hr.demo2 (HR DEMO2) | employee.demo1 (พนักงาน DEMO1) |
|---|---|---|---|
| ประวัติพนักงาน (M09) · หน้าบัตรของพนักงานฝั่ง HR (M13) · แบบฟอร์ม หน้าใช้ + หน้าจัดการ (M02) · หลักสูตร (M10) · แบบประเมิน (M38) | 200 | 403/404 และไม่เห็นชื่อพนักงาน | 403/404 |
| คำขอเซ็น (M20) · KPI หน้าจัดการ + หน้านิยาม (M46) | 200 | 403/404 และไม่เห็นชื่อพนักงาน | KPI หน้าจัดการ 403/404 (ไม่ได้ทดสอบหน้าเซ็นและหน้านิยาม — พนักงานบริษัทเดียวกันอาจมีสิทธิ์ตามบทบาท) |

ผล: **PASS** — ผู้ใช้ต่างบริษัทเปิดไม่ได้ทุกหน้า · คำขอ DSR: คนอื่นที่ไม่ใช่ผู้ขอเปิดไม่ได้ (404) ทดสอบใน PrivacyGateTest · ร่วมกับ `IdorMatrixTest` เดิมที่ครอบคลุมโมดูลเฟส 2–4

## DoD 10.4 รายโมดูล

ข้อ 1 Requirement/RTM · 2 workflow + กรณีผิดพลาด · 3 สิทธิ์/scope ฝั่ง server + validation + audit · 4 UI กลาง + responsive + a11y · 5 migration/seed/docs ไม่มี secret/ข้อมูลจริง · 6 tests รันจริง · 7 ไม่มี Blocker/Critical · 8 deploy/rollback/ข้อจำกัด · 9 UAT

| โมดูล | ส่วน (รุ่น) | ทดสอบอัตโนมัติ | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| M09 ประวัติพนักงาน | 5A (0.8.0) | EmployeeRecordsWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M35 จัดตารางกะ | 5B (0.9.0) | ShiftsWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M33 ขอ OT | 5B (0.9.0) | OvertimeWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M57 ลงเวลา GPS | 5C (0.10.0) | GpsWebTest + PrivacyGateTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M13 บัตรพนักงาน + Wallet | 5C (0.10.0) | EidWebTest + PrivacyGateTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M02 แบบฟอร์มกลาง | 5D (0.11.0) | CommsWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M19 ประกาศ | 5D (0.11.0) | CommsWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M40 เอกสารควบคุม/รับทราบนโยบาย | 5D (0.11.0) | CommsWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M47 สมุดรายชื่อ | 5D (0.11.0) | CommsWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M08 สรรหา | 5E-1 (0.12.0) | RecruitWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M49 คลังเรซูเม่ | 5E-1 (0.12.0) | TalentWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M56 JD | 5E-2 (0.13.0) | JdWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M12 หนังสือรับรอง | 5E-2 (0.13.0) | CertsWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M45 วันเกิด/ครบรอบงาน | 5E-2 (0.13.0) | CelebrateWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M10 ฝึกอบรม | 5E-3 (0.14.0) | TrainingWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M11 งบอัตรากำลัง | 5E-3 (0.14.0) | ManpowerWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M38 ประเมินผลงาน | 5E-4 (0.15.0) | AppraisalWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M46 แดชบอร์ดผู้บริหาร | 5E-5 (0.16.0) | DashboardWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M20 เซ็นเอกสารออนไลน์ | 5F (0.17.0) | EsignWebTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN |
| M32 ลา · M34 เวลาทำงาน | เฟส 4 (0.7.x) | LeaveWebTest, AttendanceWebTest, AttendanceGoldenTest | บางส่วน | PASS | PASS | PASS | PASS | PASS | PASS | PASS | NOT RUN (UAT เฟส 4 ยังรอ) |

หมายเหตุรายข้อ:

- **ข้อ 1 = บางส่วน ทุกโมดูล:** ขอบเขตแต่ละส่วนบันทึกใน DEC-67…DEC-82 และรายงานรายส่วนอ้างข้อ spec แต่ (ก) การตัดสินใจยังเป็น `PROPOSED` (ผู้ใช้สั่ง "ต่อไป" — รายละเอียด AI ตัดสิน) และ (ข) `TRACEABILITY_MAP.md` ยังไม่ลงถึงระดับ REQ-ID → test case ของทุกโมดูลเฟส 5 · ต้องให้ Sponsor/HR ยืนยันใน UAT
- **ข้อ 2–3:** ทุกส่วนมี test ของกรณีผิด/ยกเลิก/สิทธิ์ (ดูรายงานรายส่วน) · ข้ามบริษัทตรวจซ้ำในรอบนี้
- **ข้อ 4:** E2E ทุกส่วนวัด overflow = 0 และ axe 0 violation ที่ 390 px / 1366 px + ธีมมืด (หลักฐานใน `docs/phase5-evidence/`)
- **ข้อ 5:** ไม่มี `config/env.php` ใน repo/zip · ข้อมูลตัวอย่างใช้ชื่อ "(DEMO)" และอีเมล `@demo.invalid`
- **ข้อ 7:** ข้อบกพร่องที่พบระหว่างทำแก้แล้วในรอบนั้น (ดูหัวข้อ "ข้อบกพร่อง" ของแต่ละรายงาน) · ไม่มี defect เปิดค้าง
- **ข้อ 8:** README มีขั้นตอน deploy/rollback + health check ทุกรุ่น · migration ทุกตัวเป็นแบบเพิ่มเท่านั้น

## ผลรันรอบนี้ (0.18.0)

| ID | รายการ | ผล | หลักฐาน |
|---|---|---|---|
| G5-01 | PHPUnit ชุดเต็ม | PASS | 352 ชุด 4,670 assertions (21:02) — รันหลังแก้ไฟล์ครบ ไม่มีการแก้ระหว่างรัน |
| G5-02 | PHPStan | PASS | `[OK] No errors` |
| G5-03 | PHPCS | PASS | ไม่มี violation (หลัง phpcbf จัดรูปแบบ 6 จุด) |
| G5-04 | check:all (TC-AI-00, secret scan, token lint, architecture) | PASS | `php bin/ros check:all` |
| G5-05 | TC-PRV M57/M13 | PASS | PrivacyGateTest (1 test, 80 assertions) |
| G5-06 | ข้ามบริษัท 9 หน้า / 7 ชนิดข้อมูล | PASS | CrossCompanyPhase5Test |
| G5-07 | E2E ข้อมูลของฉัน → DSR ลบ 2 คน → ปิดคำขอ | PASS | `docs/phase5-evidence/gate/` (14 ภาพ + log) |
| G5-08 | migrate บนฐานที่มีข้อมูลรุ่น 0.17.0 | PASS | fresh4b: migration ใหม่ 1 ตัว + สร้าง dpo.demo1/2 |
| G5-09 | UAT โดย HR Admin | NOT RUN | รอผู้ใช้ — `docs/UAT_PHASE5.md` |
| G5-10 | Privacy/Legal ยืนยัน SP-01/02/05 + จำนวนวันตอบ DSR | NOT RUN | AI ยืนยันแทนไม่ได้ |

## งานที่ยังเปิด (ไม่ใช่ Blocker ของการพัฒนา แต่ต้องปิดก่อนใช้จริง)

| # | เรื่อง | เจ้าของ | ผลต่อ Gate |
|---|---|---|---|
| O-1 | UAT เฟส 5 (และเฟส 4 ที่ยังค้าง) | HR Admin (+ HR Time) | **ต้องมีจึงผ่าน Gate** |
| O-2 | DEC-67…DEC-82 ยังเป็น PROPOSED | Sponsor | ยืนยันพร้อม UAT |
| O-3 | SP-01/02 ฐานการประมวลผล/ระยะเก็บ · SP-05 ประกาศ GPS · SP-06 นโยบายวันเกิด · จำนวนวันตอบ DSR | DPO/Legal | ก่อนเปิด M57/M45 และก่อนใช้ข้อมูลจริง |
| O-4 | โมดูลที่ยังไม่มี `personal_data` provider (เช่น M08/M49 ผู้สมัคร, M32 ลา, M34 เวลา, M38 ประเมิน, M12 หนังสือรับรอง) — ตอนนี้ DSR ครอบคลุม M57/M13/M09 | ทีมพัฒนา | เพิ่มทีละโมดูลก่อนเฟส 11 (TC-PRV ทั้งหมด) |
| O-5 | ผู้สมัครที่ไม่มีบัญชี (M08) ยื่น DSR เองไม่ได้ — ต้องติดต่อ DPO นอกระบบ | DPO | กำหนดช่องทาง |
| O-6 | อีเมลจริง (SMTP), สแกนไวรัส, ทดสอบบนมือถือ/InfinityFree | ผู้ดูแลระบบ | NOT RUN ในรายงานรายส่วน |
| O-7 | RTM ระดับ REQ-ID → test | ทีมพัฒนา + Sponsor | DoD ข้อ 1 |

## ก่อนเริ่มเฟส 6 (Payroll & Benefits)

ตาม P0-08 เฟส 6 และ Stage Gate (`07` ข้อ 10.4):

- **อัตรา/เพดานตามกฎหมายใน Country Pack TH ต้องยืนยันโดยคน** (NFR-F3) — AI ไม่ใส่อัตราประกันสังคม ภาษี หรือเพดานใด ๆ เอง; engine สร้างได้แต่ค่าจะว่างจนกว่ายืนยัน
- Staging VPS (DEC-40) — ไม่มีก็พัฒนาได้แต่ UAT ไม่ได้ (ASM-R5)
- ผู้ตรวจ GD-PAY ที่เป็นคน และ Payroll Officer ≠ Payroll Checker
- ถ้าจะเริ่มเฟส 6 ก่อน UAT เฟส 5 เสร็จ ให้บันทึกเป็น "ผ่านแบบมีเงื่อนไข" ใน Decision Log (แบบเดียวกับเฟส 2)
