# รายงานผลทดสอบ — เฟส 3A Shared Business Services (รอบที่ 1)

```
Release/เฟส: เฟส 3 ส่วน A (จาก 3 ส่วน: 3A / 3B / 3C)   Environment: Local (เครื่องพัฒนาของ AI) + Apache จำลองโฮสต์   วันที่: 2026-10-12
Commit: d61026c (+ แก้ไขหลังทดสอบหน้าจอ)   ผู้ทดสอบ: AI (Claude)   ผู้ตรวจ: — (รอ Sponsor)
เครื่องมือ: PHP 8.3 · MariaDB 10.11 · PHPUnit 9.6 · PHPStan level 6 · PHPCS (PSR-12) · Chromium headless + axe-core · Apache 2.4
```

## ขอบเขตของรอบนี้
เฟส 3 ตามแผน (P0-08) ใหญ่ประมาณ 6 สัปดาห์ จึงแบ่งส่งเป็น 3 ส่วน (DEC-54):
| ส่วน | เนื้อหา | สถานะ |
|---|---|---|
| **3A** (รอบนี้) | Config Registry · หน้าตั้งค่ารูปลักษณ์/แบรนด์ · Workflow/Approval Engine + กล่องอนุมัติ + คำขอของฉัน + มอบหมายแทน · ขอสิทธิ์ใช้งาน · Calculation Ledger · Locks · Amendments · Outbox · เลขเอกสาร · แจ้งเตือนในระบบ · Pagination มาตรฐาน · หน้าอัปเดตฐานข้อมูล | ส่งแล้ว |
| 3B | เอกสาร + versioning + template freeze (6.15/6.17) · Import/Export Excel (6.10) · PDF core (S01) · ปฏิทินวันทำงาน/วันหยุด (6.14) · ค้นหาที่ตรวจสิทธิ์ · อัปโหลดโลโก้/favicon | ถัดไป |
| 3C | Posting Interface → Finance Core · ไฟล์โอนเงิน (DEC-31) · Backup + M48 + restore drill ครั้งแรก · Integration Registry | ถัดไป |

## สรุป
| ผล | จำนวน |
|---|---|
| PASS | 33 |
| FAIL | 0 |
| NOT RUN | 6 |
| BLOCKED | 0 |
| ผ่านบางส่วน (บันทึกเป็นข้อจำกัด) | 5 |

อัตโนมัติ: **PHPUnit 154 tests, 916+ assertions — OK** · PHPStan level 6 ไม่มี error · PHPCS ผ่าน · `bin/ros check:all` PASS ทั้ง 4 ข้อ

**ผลต่อ Gate เฟส 3:** ยังไม่ครบ — gate ต้องการ TC-REC และ TC-DR ซึ่งอยู่ใน 3B/3C · ส่วนที่อยู่ใน 3A (TC-CFG, TC-CALC, TC-LOCK, TC-AMD, TC-WF, TC-PAGE) ผ่านทั้งหมด

## ผลรายข้อ
| Test ID | กลุ่ม | คำอธิบาย | ผล | หลักฐาน |
|---|---|---|---|---|
| T3-01 | TC-CFG | ค่าที่มีผลในอนาคต: ก่อนวันเริ่มใช้ฉบับเดิม หลังวันเริ่มใช้ฉบับใหม่ | PASS | `ConfigRegistryTest::testFutureDatedVersionAppliesFromItsStartAndOverridesInheritByScope` |
| T3-02 | TC-CFG | ค่ารายบริษัทแทนค่ากลุ่ม · บริษัทอื่นยังรับค่ากลุ่ม · สาขารับค่าบริษัท · แสดงลำดับการค้นหา | PASS | ข้อเดียวกับ T3-01 |
| T3-03 | TC-CFG | ช่วงวันมีผลซ้อนกัน / วันเริ่มซ้ำ → ปฏิเสธ | PASS | `testOverlapIsRefusedAndAGapStopsTheCalculation` |
| T3-04 | TC-CFG | ค่าบังคับไม่มีในช่วงวันนั้น → หยุดการคำนวณ (ConfigMissing) + บันทึก log/audit ไม่เลือกค่าเงียบ ๆ | PASS | ข้อเดียวกับ T3-03 |
| T3-05 | TC-CFG / SoD | ผู้เสนอค่าความเสี่ยงสูงอนุมัติเองไม่ได้ ทั้งผ่าน workflow และทางลัด applyNow | PASS | `testProposerCannotApproveTheirOwnHighRiskValue` |
| T3-06 | TC-CFG | ฉบับที่ตัดสินแล้วแก้ไม่ได้ · ยกเลิกได้เฉพาะฉบับที่ยังไม่มีผล · สถานะ active/superseded/scheduled/cancelled ถูกต้อง | PASS | `testDecidedVersionsAreNeverEditedOnlyFutureOnesCancelled` |
| T3-07 | TC-CFG | ตรวจค่าตามนิยาม (ทศนิยม, ช่วง, ตัวเลือก, สี, ระดับขอบเขต, วันที่) · ค่าตามกฎหมายต้องมีแหล่งอ้างอิง | PASS | `testValuesAreValidatedAgainstTheDefinition` |
| T3-08 | TC-CFG | impact analysis: ย้อนหลัง, จำนวนบริษัท/สาขาที่รับค่าต่อ, ช่องว่างหลังวันสิ้นสุด | PASS | `testImpactShowsRetroactiveDatesAndWhatInherits` |
| T3-09 | TC-CFG | สิทธิ์: HR เสนอค่าไม่ได้ · ผู้ตรวจสอบ (AUD) ดูได้แต่เสนอไม่ได้ | PASS | `testOnlyPeopleWithTheRightAndScopeMayPropose`, `SharedServicesWebTest::testAdminPagesNeedTheRightPermission` |
| T3-10 | TC-CFG | ผ่านหน้าเว็บ: เสนอ → ส่ง → ผู้อื่นอนุมัติ (ต้องยืนยันรหัสผ่านซ้ำ) · วันที่กรอกเป็นเวลาไทย เก็บเป็น UTC | PASS | `testHighRiskConfigApprovalNeedsReauthAndAnotherPerson` + Playwright |
| T3-11 | TC-CALC | เปิด run เก่าเห็น config version / input / output ที่ใช้จริง แม้ค่าปัจจุบันเปลี่ยนแล้ว · replay ได้ผลเดิมโดยไม่เขียนอะไร | PASS | `CalculationLedgerTest::testOldRunsShowTheVersionTheyReallyUsed` |
| T3-12 | TC-CALC | idempotency key เดิม → run เดียว · คำนวณใหม่ = run ใหม่ที่อ้าง run เดิม · run ที่ posted ไม่ถูกแก้ | PASS | `testRecalculationAddsARunAndTheSameCalculationIsRecordedOnce` |
| T3-13 | TC-CALC | snapshot เข้ารหัส (ไม่เห็นข้อมูลดิบในฐานข้อมูล) และแก้ไม่ได้ (trigger) | PASS | `testOldRuns…`, `testSnapshotsCannotBeChangedAfterwards` |
| T3-14 | TC-LOCK | ล็อกแบบ hard: เขียนไม่ได้ทุกช่องทาง (ui/api/import/job/integration) · ล็อกระดับบริษัทครอบคลุมสาขา · บันทึกทุกครั้งที่ถูกปฏิเสธ | PASS | `LocksAndAmendmentsTest::testHardLockBlocksEveryChannelAndReopenNeedsSomeoneElse` |
| T3-15 | TC-LOCK | ขอเปิดรอบ → ผู้ขออนุมัติเองไม่ได้ → ผู้อื่นอนุมัติ → ล็อกใหม่ได้ revision 2 · ประวัติครบ | PASS | ข้อเดียวกับ T3-14 |
| T3-16 | TC-LOCK | soft lock = เตือน · ประวัติการล็อกลบไม่ได้ | PASS | `testSoftLockWarnsAndHistoryIsAppendOnly` |
| T3-17 | TC-AMD | ขอแก้ → ไม่เปลี่ยนต้นฉบับ → อนุมัติ → ดำเนินการล้มเหลว (ไม่มีข้อมูลครึ่งทาง) → ลองใหม่สำเร็จ → ปิด · คำขอซ้อนถูกปฏิเสธ | PASS | `testAmendmentExecutesAfterApprovalAndRetriesSafely` |
| T3-18 | TC-AMD | รายการถูกแก้หลังยื่นคำขอ → ดำเนินการไม่ทับ (stale) | PASS | `testAStaleRecordIsNotOverwritten` |
| T3-19 | TC-WF | อนุมัติแล้วระบบให้สิทธิ์จริง · คำขอของฉันแสดงผล · ผู้ขอได้แจ้งเตือน | PASS | `WorkflowEngineTest::testApprovalGrantsTheRoleAndMyRequestsShowsTheResult` |
| T3-20 | TC-WF | เปลี่ยนสายอนุมัติระหว่างมีคำขอค้าง → คำขอเดิมใช้ฉบับเดิมจนจบ คำขอใหม่ใช้ฉบับใหม่ | PASS | `testRunningRequestsKeepTheirVersionWhenTheRouteChanges` |
| T3-21 | TC-WF | ผู้ขอไม่ได้รับงานอนุมัติของตัวเอง · ไม่มีผู้อนุมัติ → "ไม่พบผู้อนุมัติ" (ไม่อนุมัติอัตโนมัติ) | PASS | `testRequesterNeverApprovesAndNoApproverNeedsAttention` |
| T3-22 | TC-WF | มอบหมายแทน: งานไปที่ผู้รับมอบ (บันทึกว่าทำแทนใคร) · หมดอายุแล้วคืนงาน · ผู้รับมอบที่เป็นผู้ขอไม่ได้งาน | PASS | `testDelegationRoutesTasksAndEndsOnTime` |
| T3-23 | TC-WF | เกิน SLA → ส่งต่อ (ครั้งเดียว) | PASS | `testOverdueTasksEscalateOnce` |
| T3-24 | TC-WF | ส่งกลับ (ต้องมีความเห็น) → ส่งใหม่ → ไม่อนุมัติ · ยกเลิกได้เฉพาะผู้ขอ | PASS | `testReturnResubmitRejectAndCancel` |
| T3-25 | TC-WF | อนุมัติแล้วดำเนินการล้มเหลว → สถานะ "อนุมัติแล้ว/ดำเนินการไม่สำเร็จ" → ผู้ดูแลลองใหม่ได้ (ผู้อื่นไม่ได้) | PASS | `testFailedExecutionKeepsTheDecisionAndCanBeRetried` |
| T3-26 | TC-WF / IDOR | รายละเอียดคำขอเปิดได้เฉพาะผู้เกี่ยวข้อง · กดส่งซ้ำไม่สร้างคำขอซ้ำ · แจ้งเตือนของคนอื่นเปิดไม่ได้ | PASS | `testDetailIsOnlyForPeopleInvolved`, `testDoubleSubmitStartsOneRequest`, `SharedServicesWebTest::testAccessRequestFromTheWebToApprovalAndGrant` |
| T3-27 | TC-PAGE | page size 10–300 ทุกค่า · ค่าผิด → 20 · จำค่าต่อรายการ · หน้าเกินท้าย → หน้าสุดท้ายที่มีข้อมูล · ย่อเลขหน้า | PASS | `NumberingOutboxPagingTest::testPageSizeRulesAndRememberedChoice`, `testUserListUsesTheSharedPagination` |
| T3-28 | 6.30.10 | เลขเอกสารเรียงต่อเนื่องต่อบริษัท/รอบ · เลขที่ยกเลิกไม่ถูกนำกลับมาใช้ · ล็อกแถวกันเลขซ้ำเมื่อมี 2 คำขอพร้อมกัน | PASS | `testNumbersAreSequentialPerScopeNeverReusedAndVoidable`, `testTheSequenceRowIsLockedWhileANumberIsTaken` |
| T3-29 | 6.30.7 | Outbox: idempotency key เดิม = event เดิม · ผู้รับแต่ละรายทำงานครั้งเดียว · ล้มเหลวแล้วรอแบบ back-off · ครบ 8 ครั้ง → dead → ส่งใหม่ได้ | PASS | `testOutboxDeliversOncePerConsumerAndBacksOffOnFailure` |
| T3-30 | 6.8 | รูปลักษณ์: สีอ่านยากบันทึกไม่ได้ + เสนอสีใกล้เคียง · บันทึกเป็นค่ากำหนดฉบับใหม่ · ชื่อระบบเปลี่ยนทุกหน้า · theme.css สาธารณะ | PASS | `testAppearanceSavesAsConfigVersionsAndRefusesUnreadableColours` + Playwright (ปุ่มบันทึกถูกปิดเมื่อสีไม่ผ่าน) |
| T3-31 | DEC-16 | วางรุ่นใหม่ทับของเดิม: ผู้ดูแลถูกพาไปหน้าอัปเดตฐานข้อมูล ผู้ใช้อื่นเห็นหน้าปิดปรับปรุง · ต้องยืนยันรหัสผ่าน + ยืนยันสำรองข้อมูล · ไฟล์ CSS/JS สร้างใหม่อัตโนมัติ | PASS | `testAnOutOfDateDatabaseSendsAdminsToTheUpdatePage` + จำลองอัปเกรดจากเฟส 2 จริงด้วยเบราว์เซอร์ |
| T3-32 | TC-UI | 15 หน้าใหม่ (1366 + 360, สว่าง/มืด) ไม่มี scroll แนวนอน · axe ไม่พบปัญหา (ยกเว้นหน้าพรีวิวขณะกำลังแสดงสีที่ไม่ผ่าน ซึ่งเป็นสิ่งที่หน้านั้นตั้งใจแสดงและบันทึกไม่ได้) | PASS | `phase3a-evidence/` |
| T3-33 | Install | ติดตั้งใหม่จาก zip บน Apache โฟลเดอร์ชื่อไทย: ทุกหน้าใหม่ = 200, URL ที่มีจุด (`/admin/config/key/brand.app_name`) ใช้ได้, trigger ประวัติ = triggers | PASS | curl ผ่าน Apache |
| T3-34 | TC-REC | เอกสาร/ไฟล์/template freeze | **NOT RUN** | อยู่ใน 3B |
| T3-35 | TC-DR | restore drill ครั้งแรก | **NOT RUN** | อยู่ใน 3C |
| T3-36 | CI | GitHub Actions | **NOT RUN** | ยังไม่มี repo `r-os` (BT-01) |
| T3-37 | Compat | PHP 8.1 / InfinityFree | **NOT RUN** | เหมือนเฟสก่อน |
| T3-38 | UAT | ขั้นตอนอนุมัติกับคนจริง 2 คน | **NOT RUN** | ต้องมีผู้ใช้คนที่สอง (BT-13) |
| T3-39 | 6.30.13 | แจ้งเตือนทางอีเมล | **NOT RUN** | แจ้งเตือนในระบบเท่านั้น (Brevo ตาม DEC-48 ยังไม่เปิด) |

## ข้อบกพร่องที่พบและแก้ในรอบนี้
| ID | อาการ | สาเหตุ | การแก้ |
|---|---|---|---|
| D3-01 | **วางไฟล์รุ่นใหม่ทับแล้วหน้าตาไม่เปลี่ยน** (กระทบรอบก่อนด้วย — มุมโค้ง/แถบบนมือถือที่แก้ไปอาจยังไม่แสดงบน XAMPP) | สร้างไฟล์ CSS/JS ครั้งเดียวเมื่อยังไม่มีไฟล์ | เทียบ fingerprint ของโฟลเดอร์ `ui/` ทุกครั้ง ถ้าเปลี่ยนจะสร้างใหม่ |
| D3-02 | วางรุ่นใหม่ทับแล้วตารางใหม่ยังไม่มี → หน้าใหม่จะ error | ยังไม่มีขั้นตอนอัปเดตฐานข้อมูลบนเว็บ | หน้าอัปเดตฐานข้อมูล (DEC-16) + พาผู้ดูแลไปหน้านั้นอัตโนมัติ |
| D3-03 | การมอบหมายหมดอายุ: ปฏิเสธแล้วงานไม่คืนผู้มอบ | การคืนงานอยู่ใน transaction ที่ถูก rollback ตอนปฏิเสธ | บันทึกการคืนงานก่อน แล้วจึงปฏิเสธ; บันทึก audit การปฏิเสธนอก transaction |
| D3-04 | เครื่องพัฒนา (php -S) เปิด URL ที่มีจุดแล้ว 404 และไม่มี CSS | built-in server ตีความเป็นชื่อไฟล์ | กำหนด SCRIPT_NAME ใน router-dev (Apache/XAMPP ไม่มีปัญหานี้ — ทดสอบแล้ว) |
| D3-05 | ตัวย่อชื่อในรูปผู้ใช้เป็น "ผ(" | ใช้ตัวอักษรหลังช่องว่างตัวแรก | ใช้ตัวอักษรแรกของคำที่ขึ้นต้นด้วยตัวอักษร |

## ข้อจำกัดที่รู้แล้ว (ผ่านบางส่วน)
1. **Locks / Amendments / Calculation Ledger:** service + tests ครบ แต่ยังไม่มีโมดูลธุรกิจใช้จริงและยังไม่มีหน้าจอของตัวเอง — ใช้ครั้งแรกกับลา/เวลา (เฟส 4) และเงินเดือน (เฟส 6)
2. **SLA ของสายอนุมัตินับเป็นชั่วโมงปฏิทิน** — นับเฉพาะเวลาทำการเมื่อมีปฏิทินวันทำงาน (3B)
3. **Workflow:** แต่ละขั้นต้องการผู้อนุมัติ 1 คนจากกลุ่ม (any-one) — ยังไม่มีแบบ "ต้องครบทุกคน" (parallel all-of) ซึ่งสเปกให้ "เมื่อจำเป็น"
4. **Config Registry:** ยังไม่มี import/export ค่า (มากับ Import/Export ใน 3B) และ sandbox พรีวิวผลคำนวณ (มาเมื่อมีสูตรคำนวณจริง)
5. **แบรนด์:** อัปโหลดโลโก้/favicon ต้องใช้ Documents service (3B) · ตั้งฟอนต์/ความหนาแน่นระดับองค์กรยังไม่ทำ
6. **MFA ยังปิดเป็นค่าเริ่มต้น** (DEC-52) — ข้อเสนอให้เป็นเงื่อนไขของ Activation Gate ยังรอยืนยัน
