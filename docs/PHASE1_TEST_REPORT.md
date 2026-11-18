# รายงานผลทดสอบ — เฟส 1 Platform Foundation (รอบที่ 1)

```
Release/เฟส: เฟส 1 รอบที่ 1 (ก่อน push ขึ้น GitHub)   Environment: Local (เครื่องพัฒนาของ AI) + Apache จำลองโฮสต์   วันที่: 2026-10-12
Commit/Version: commit แรกของ repository r-os (ยังไม่มี tag)   ผู้ทดสอบ: AI (Claude)   ผู้ตรวจ: — (รอ Sponsor)
เครื่องมือ: PHP 8.3.6 · MariaDB 10.11.14 · PHPUnit 9.6.17 · PHPStan 1.12.7 (level 6) · PHP_CodeSniffer (PSR-12) · Chromium headless + axe-core 4.10.2 · Apache 2.4 + .htaccess
```

## สรุป
| ผล | จำนวน |
|---|---|
| PASS | 28 |
| FAIL | 0 |
| NOT RUN | 6 (เหตุผลในตาราง) |
| BLOCKED | 0 |
| ผ่านบางส่วน (บันทึกเป็นข้อจำกัด) | 1 — Showcase ยังไม่ครบทุก component |

**ผลต่อ Gate เฟส 1:** ยังไม่ผ่าน — **ผ่านแบบมีเงื่อนไขได้** ถ้า Sponsor ยอมรับว่า component ที่เหลือ (ข้อ L-01) จะเพิ่มในเฟสที่ใช้ครั้งแรก และหลังจาก CI บน GitHub เป็นสีเขียว (T-24) · AI อนุมัติ gate แทนไม่ได้

## ผลรายข้อ
| Test ID | กลุ่ม | คำอธิบาย | ผล | หลักฐาน |
|---|---|---|---|---|
| T-01 | TC-RES | migration รันครั้งแรกสร้างตาราง, รันซ้ำไม่เปลี่ยนอะไร (0 applied) | PASS | `MigrationRunnerTest::testFreshInstallThenRerunIsANoOp`, CLI `bin/ros migrate` ×2 |
| T-02 | TC-RES | ล้มกลางทาง (DDL แล้วแต่ยังไม่บันทึก) แล้วรันใหม่ได้ | PASS | `testUpIsSafeToReRunAfterAPartialFailure` |
| T-03 | TC-RES | ไฟล์ migration ที่รันแล้วถูกแก้ → ขึ้น DRIFT และหยุด | PASS | `testEditedAppliedMigrationIsDetectedAndRefused` + เกิดจริงระหว่างพัฒนา (ตัวจัดรูปแบบโค้ดแก้ไฟล์หลังรัน → หน้าสถานะขึ้น DRIFT) |
| T-04 | TC-RES | pre-check ไม่ผ่าน → ไม่รัน + บันทึก FAIL ใน migration_log | PASS | `testFailedPreCheckStopsAndLeavesEvidence` |
| T-05 | TC-RES | migration แบบ destructive ต้องมี `--approve-destructive` | PASS | `testDestructiveMigrationNeedsExplicitApproval` |
| T-06 | TC-RES | สองคนรันพร้อมกันไม่ได้ (DB lock) | PASS | `testConcurrentRunIsBlockedByLock` |
| T-07 | TC-RES | Staging/Production ต้องยืนยัน backup ก่อน migrate | PASS | CLI: `Refusing: confirm a fresh backup…` (exit 2) |
| T-08 | TC-SEC | ติดตั้งสำเร็จ → `/install` ตอบ 404 ถาวร, ติดตั้งซ้ำไม่ได้ | PASS | `KernelTest::testInstallerIsLockedAfterInstallation`, `InstallerTest`, Apache: `/install` = 404 |
| T-09 | TC-SEC | ติดตั้งต้องใช้รหัสครั้งเดียวจากไฟล์บนเซิร์ฟเวอร์, รหัสผิด = 422 | PASS | `testCsrfIsRequiredForStateChangingRequests`, curl |
| T-10 | TC-SEC | หน้า error 500 บน prototype/staging: มีรหัสอ้างอิง ไม่มี stack trace / path / secret | PASS | `testTechnicalErrorPageHasReferenceButNoStackTraceOrSecrets`, `error-500-prototype.jpg` |
| T-11 | TC-SEC | CSRF: POST ไม่มี token = 419 | PASS | `KernelTest`, curl |
| T-12 | TC-SEC | Security headers + CSP แบบ nonce (สคริปต์แปลกปลอมถูกบล็อกจริงในเบราว์เซอร์) | PASS | `testSecurityHeadersAndCorrelationId`; Chromium ปฏิเสธ inline script ที่ไม่มี nonce |
| T-13 | TC-SEC | ไม่มี secret ใน repo (สแกนภายใน + ตรวจว่า scanner จับของปลอมได้) | PASS | `bin/ros check:secrets`, `StaticChecksTest` |
| T-14 | TC-SEC | `.htaccess` ที่รากปิด core/config/storage/tests/.git/composer.json เมื่ออัปโหลดทั้งโฟลเดอร์ | PASS | Apache: 403 ทุกโฟลเดอร์ภายใน, `/`, `/assets` = 200 (ต้องตรวจซ้ำบน InfinityFree จริง — T-27) |
| T-15 | TC-SEC | log ปิดบังรหัสผ่าน/โทเคน/เลขบัตร/บัญชี | PASS | `LoggerTest` |
| T-16 | TC-AI-00 | ไม่มีการเรียกผู้ให้บริการ AI; มีแค่ DisabledProvider; ค่า FREE_API_ONLY/MAX_BILLABLE_COST แก้ไม่ได้ | PASS | `bin/ros check:ai`, `AiGatewayTest`, `AppConfigTest` |
| T-17 | Arch | กฎชั้นสถาปัตยกรรม 5 ข้อ (view ไม่มี SQL, Domain ไม่เรียก Infra, ข้ามโมดูล, Clock, PDO) | PASS | `bin/ros check:arch` + ทดสอบว่าจับการฝ่าฝืนได้ |
| T-18 | TC-UI | ไม่มีสีตายตัวนอก `ui/tokens`, ไม่มี inline style | PASS | `bin/ros check:tokens` |
| T-19 | TC-UI | contrast ของ token ผ่าน WCAG AA ทั้ง light และ dark (23 คู่ × 2 ธีม) | PASS | `TokenContrastTest` |
| T-20 | TC-UI | 5 ความกว้าง (360/768/1024/1366/1920) × 2 ธีม × 5 หน้า = 50 กรณี ไม่มี scroll แนวนอนทั้งหน้า | PASS | `ui-matrix-results.json` (overflow = 0 ทุกกรณี) |
| T-21 | TC-UI | axe-core (WCAG 2.0/2.1/2.2 A+AA) ที่ 360 และ 1366 ทั้งสองธีม — ไม่มี violation | PASS | `ui-matrix-results.json` (axe = [] ทุกหน้า หลังแก้ 1 จุด: skeleton ขาด role) |
| T-22 | TC-UI | ใช้คีย์บอร์ดอย่างเดียว: skip link, แท็บ (ลูกศร/Home/End), dialog Esc คืน focus, drawer บนแท็บเล็ต | PASS | `keyboard-smoke.mjs` — 13/13 PASS |
| T-23 | i18n | คีย์ TH = EN ครบ, ทุกคีย์ที่ view ใช้มีคำแปล, ภาษาไทยเก็บใน DB ไม่เพี้ยน, ปี พ.ศ. ถูก | PASS | `TranslatorTest`, `testThaiTextRoundTripsThroughUtf8mb4` |
| T-24 | CI | GitHub Actions เป็นสีเขียว | **NOT RUN** | ยังไม่มี repository `r-os` (BT-01) |
| T-25 | CI | gitleaks + `composer audit` | **NOT RUN** | รันใน CI เท่านั้น (เครือข่ายของ AI เข้า packagist ไม่ได้) |
| T-26 | Compat | PHP 8.1 | **NOT RUN** | เครื่อง AI มี 8.3 — CI matrix รัน 8.1 ให้ |
| T-27 | Prototype | อัปโหลดขึ้น InfinityFree แล้วติดตั้ง + ตรวจ `.htaccess` จริง | **NOT RUN** | ต้องใช้บัญชี InfinityFree ของผู้ใช้ |
| T-28 | TC-UI | screen reader | **NOT RUN** | ตามแผนตรวจเฉพาะ flow หลักตั้งแต่เฟส 4 (P0-05 §7) |
| T-29 | UAT | ผู้ใช้ทดลอง Showcase และยืนยันหน้าตา | **NOT RUN** | รอ Sponsor (AI อนุมัติแทนไม่ได้) |
| T-30 | Static | PHPStan level 6 — 0 error | PASS | `phpstan analyse` |
| T-31 | Static | PSR-12 — 0 error | PASS | `phpcs` |
| T-32 | Jobs | scheduler: งานถึงเวลาทำงาน, ตั้งรอบถัดไป, งานล้มไม่ลากงานอื่น, ทำได้ทีละ runner | PASS | `SchedulerTest`; piggyback ทำงานจริงหลังเปิดหน้าเว็บ (system_info.scheduler_last_run) |
| T-34 | Install | ติดตั้งในโฟลเดอร์ย่อยแบบ XAMPP (`http://localhost/<โฟลเดอร์>/`) รวมชื่อโฟลเดอร์ภาษาไทย: redirect, ลิงก์, CSS, ติดตั้ง, ล็อก | PASS | Apache: `/r-os/` และ `/ระบบ/` ติดตั้งสำเร็จ, `RequestBaseTest` 10 กรณี |
| T-33 | Data | optimistic lock (row_version) ปฏิเสธการบันทึกทับ, transaction rollback | PASS | `RepositoryTest` |

PHPUnit รวม: **66 tests, 449 assertions — OK** (`phpunit-testdox.txt`)

## Defects ที่พบและแก้แล้วในรอบนี้
| ID | ระดับ | อาการ | การแก้ |
|---|---|---|---|
| D-01 | Major | router อ่าน pattern ที่มี `{26}` ผิด (เส้นทางที่ใช้ ULID จะหาไม่เจอ) | แก้ regex ของ router + มีเทสต์ |
| D-02 | Major | `.htaccess` รากบล็อกเส้นทาง `/install` (ชื่อซ้ำกับโฟลเดอร์ `install/`) → ติดตั้งบน InfinityFree ไม่ได้ | ลบโฟลเดอร์ว่าง `install/`, แก้กฎ, ทดสอบกับ Apache |
| D-03 | Minor | ลิงก์ได้ `/public/...` เมื่อเข้าผ่าน `.htaccess` ราก | คำนวณ base path ใหม่ |
| D-04 | Minor | การ์ดกรมท่าบนหน้าหลักเป็นสีขาว, ปุ่มเมนูโผล่บน desktop | แก้ลำดับ CSS |
| D-05 | Minor | scroll แนวนอนที่ 360px ในหน้า Showcase | ให้แถวปุ่ม/ข้อความตัดบรรทัด |
| D-06 | Minor | axe: skeleton ใช้ aria-label โดยไม่มี role | เพิ่ม `role="status"` |
| D-08 | Major | (ผู้ใช้พบบน XAMPP) เปิด `http://localhost/<โฟลเดอร์>/` แล้วถูกพาไป `http://localhost/install` — ชื่อโฟลเดอร์หาย | คำนวณ base path ใหม่ให้รองรับโฟลเดอร์ย่อย + ชื่อภาษาไทย + Windows, แก้ `.htaccess` ราก, สร้างไฟล์ CSS/JS อัตโนมัติครั้งแรก |
| D-09 | Blocker (Windows/macOS) | (ผู้ใช้พบบน XAMPP) ติดตั้งไม่ได้: `Bad migration file name: Migration` — บน Windows โฟลเดอร์ `core/Migrations` (คลาส) กับ `core/migrations` (ไฟล์ migration) เป็นโฟลเดอร์เดียวกัน | ย้ายคลาสไป `core/Database/` + เพิ่มกฎตรวจ rule 6 (ชื่อไฟล์/โฟลเดอร์ซ้ำเมื่อไม่สนตัวพิมพ์) ใน `check:arch` เพราะ CI บน Linux จับไม่ได้ |
| D-07 | Minor | หลังติดตั้งใหม่ อาจอ่าน config เก่าจาก OPcache | เรียก `opcache_invalidate` หลังเขียนไฟล์ |

**Defects เปิดค้าง:** Blocker 0 / Critical 0 / Major 0 / Minor 0

## ข้อจำกัดที่ทราบ
- **L-01 Showcase ยังไม่ครบ:** ยังไม่มี Employee/Org Picker, Searchable Select, Date picker แบบปฏิทิน พ.ศ., Import Wizard, Calendar/Shift Grid, QR, File Upload — ระบุไว้ในหัวข้อ "ยังไม่ได้ทำ" ของหน้า Showcase พร้อมเฟสที่จะเพิ่ม
- **L-02 ยังไม่มีการล็อกอิน:** หน้า `/system/status` และ Showcase เปิดได้โดยไม่ล็อกอินบน Prototype/Staging (ปิดใน Production) — จะจำกัดเฉพาะ System Admin ในเฟส 2
- **L-03 Installer เฟส 1** ยังไม่สร้างบัญชีผู้ดูแลคนแรก/โปรไฟล์บริษัท/ข้อมูลตัวอย่าง (ต้องมีระบบ Auth ก่อน — เฟส 2)
- **L-04 Scheduler:** retry/backoff, dead-letter และหน้าจองานล้มเหลวอยู่ในเฟส 3 (ตอนนี้บันทึกผลล้มเหลวไว้แล้ว)
- **L-05 ฟอนต์หัวข้อ Bai Jamjuree** ใช้ตามต้นแบบที่ผู้ใช้ยืนยัน (P0-05 §1.3 เดิมระบุเฉพาะ IBM Plex Sans Thai) — อัปเดตเอกสาร P0-05 แล้ว
