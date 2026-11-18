# รายงานผลทดสอบ — เฟส 2 Identity & Governance (รอบที่ 1)

- ขอบเขต: โครงสร้างองค์กร (กลุ่ม → บริษัท → สาขา → แผนก), ผู้ใช้และวงจรชีวิตบัญชี, เข้าสู่ระบบ / เปิดใช้งาน / รีเซ็ตด้วย OTP, TOTP MFA, session, RBAC + access_scopes + ScopedRepository, SoD, audit แบบเพิ่มได้อย่างเดียว, masked value + ยืนยันตัวตนซ้ำ (re-auth), ข้อมูลตัวอย่าง TD-DEMO (DEC-34), IDOR matrix
- เกณฑ์ผ่านเฟส (P0-08): TC-AUTH และ TC-AUTHZ ผ่านทุกช่องทางที่มีอยู่ในเฟสนี้
- สภาพแวดล้อมทดสอบ: PHP 8.3 + MariaDB 10.11 (เครื่องของ AI), Chromium headless (Playwright) + axe-core
- เวลาในการทดสอบอัตโนมัติถูกตรึงไว้ที่ 2026-11-09T02:00Z (FrozenClock) เพื่อทดสอบ timeout / lockout / TOTP ได้แน่นอน
- ผลรวมอัตโนมัติ: **PHPUnit 114 tests, 649 assertions — OK**, PHPStan level 6 — ไม่มี error, PHPCS — ผ่าน, `bin/ros check:all` — PASS ทุกข้อ

## สรุป
| ผล | จำนวน |
|---|---|
| PASS | 39 |
| FAIL | 0 |
| NOT RUN | 7 (เหตุผลในตาราง) |
| BLOCKED | 0 |
| ผ่านบางส่วน (บันทึกเป็นข้อจำกัด) | 2 — masked value, ตัวเลือกพนักงาน |

**ข้อสรุปเกณฑ์เฟส:** TC-AUTH และ TC-AUTHZ ผ่านทุกช่องทางที่มีในเฟสนี้: หน้ารายละเอียด (GET), แก้ไข (POST), รายการ, การเดา ID, ส่งออก และ API ซึ่งตอบ 401 เพราะยังไม่มี endpoint
ช่องทาง API จริง / มือถือ / ไฟล์แนบ ยังไม่เกิดในเฟสนี้ จึงต้องทดสอบ IDOR matrix ซ้ำเมื่อสร้างช่องทางเหล่านั้น (ตั้งแต่เฟส 3 เป็นต้นไป)
→ เสนอว่า **ผ่านแบบมีเงื่อนไข** — รอผู้ใช้ลงนาม และรอ UAT SoD ที่ต้องใช้คนจริง 2 คน (ข้อ T2-40)

## ผลรายข้อ
| Test ID | กลุ่ม | คำอธิบาย | ผล | หลักฐาน |
|---|---|---|---|---|
| T2-01 | TC-AUTH | เข้าสู่ระบบสำเร็จ; ผู้ใช้ไม่มีอยู่ / รหัสผิด ได้ข้อความเดียวกัน (กันการเดาชื่อผู้ใช้) + ตรวจ hash หลอกให้เวลาใกล้เคียงกัน | PASS | `AuthTest::testLoginSuccessAndGenericFailures` |
| T2-02 | TC-AUTH | ผิด 5 ครั้งใน 15 นาที → ล็อก 15 นาที; จำกัดต่อ IP 30 ครั้ง | PASS | `testFiveFailuresLockTheAccountForFifteenMinutes` |
| T2-03 | TC-AUTH | บัญชี invited / suspended / offboarded / deactivated เข้าระบบไม่ได้ | PASS | `testInactiveAccountsCannotSignIn` |
| T2-04 | TC-AUTH | session หมดเมื่อไม่ใช้งาน 30 นาที และครบ 12 ชั่วโมง (DEC-51) | PASS | `testIdleAndAbsoluteSessionTimeouts` |
| T2-05 | TC-AUTH | ลิงก์เปิดใช้งานใช้ได้ครั้งเดียว หมดอายุ 72 ชม. เก็บเฉพาะ hash | PASS | `testActivationLinkIsSingleUseAndExpires`, `testActivationLinkExpiresAfter72Hours` |
| T2-06 | TC-AUTH | รีเซ็ตด้วย OTP: จำกัดจำนวนครั้ง, หมดอายุ, ตั้งรหัสใหม่แล้ว session เดิมถูกยกเลิกทั้งหมด | PASS | `testResetOtpLimitsAttemptsAndRevokesSessions`, `testOtpExpires` |
| T2-07 | TC-AUTH | นโยบายรหัสผ่าน: ≥12 ตัว, ≤128, ไม่อยู่ในรายการรหัสยอดนิยม, ไม่มีชื่อผู้ใช้/อีเมล | PASS | `TotpTest::testPasswordPolicy` |
| T2-08 | TC-AUTH | TOTP ตรง RFC 6238 test vectors, หน้าต่าง ±1, ใช้รหัสซ้ำในช่วงเวลาเดิมไม่ได้ | PASS | `testRfc6238Vectors`, `testBase32RoundTripAndWindow`, `testReplayOfAUsedStepIsRefused` |
| T2-09 | TC-AUTH | secret ของ TOTP เข้ารหัส (sodium secretbox ด้วย APP_KEY); token เก็บเป็น keyed hash | PASS | `testFieldEncryptionAndKeyedHash` |
| T2-10 | TC-AUTH | ยังไม่ยืนยัน MFA → ทุกหน้าถูกกั้น (ยกเว้น MFA/logout) | PASS | `IdorMatrixTest::testMfaGateBlocksEverythingUntilVerified` |
| T2-11 | TC-AUTH | ไม่มีรหัสผ่าน / OTP / token / secret หลุดเข้า audit | PASS | `testNoSecretsReachTheAuditTrail` |
| T2-12 | TC-AUTH | เชิญ → เปิดใช้งาน → ตั้งรหัส → เข้าระบบ ครบวงจรผ่านหน้าเว็บ | PASS | `WebPagesTest::testInviteActivateAndSignInEndToEnd`, curl ผ่าน HTTP จริง |
| T2-13 | TC-AUTHZ | สิทธิ์ที่มีผล = permission ∩ scope; HR ของ DEMO1 เห็นเฉพาะ DEMO1 | PASS | `AuthzTest::testScopeIsLimitedToAssignedCompany`, `org-scoped-hr-demo1.jpg` |
| T2-14 | TC-AUTHZ | ไม่มีแถว scope = ไม่มีสิทธิ์ (deny-by-default) | PASS | `testDenyByDefaultWithoutScopeRows` |
| T2-15 | TC-AUTHZ | การมอบสิทธิ์ที่หมดอายุ / ยังไม่เริ่ม / รออนุมัติ ไม่ให้สิทธิ์ใด ๆ | PASS | `testExpiredFutureAndPendingAssignmentsGrantNothing` |
| T2-16 | TC-AUTHZ | บังคับ MFA สำหรับ SYS/PYA/FNC/POSS และผู้มี scope ข้ามบริษัท | PASS | `testMfaIsRequiredForAdminsApproversAndCrossCompany` |
| T2-17 | TC-AUTHZ / SoD | มอบบทบาทให้ตัวเองไม่ได้ | PASS | `testCannotGrantToSelf` |
| T2-18 | TC-AUTHZ | ต้องระบุ scope เสมอ และ scope ต้องอยู่ในขอบเขตของผู้มอบ | PASS | `testScopeIsRequiredAndValidated` |
| T2-19 | TC-AUTHZ / SoD | บทบาทเสี่ยงสูงต้องมี SYS คนที่สอง (ไม่ใช่ผู้ขอ ไม่ใช่ผู้รับ) อนุมัติ + ต้อง re-auth | PASS | `testHighRiskGrantNeedsASecondAdmin` |
| T2-20 | TC-AUTHZ / SoD | รายงานคู่บทบาทขัดกัน (PYO+PYA, FNM+FNC, WAL+FNC) ในบริษัทเดียวกัน | PASS | `testSodReportFindsConflictingRoles` |
| T2-21 | TC-AUTHZ | ผู้ที่ไม่ใช่ admin จัดการผู้ใช้ / มอบสิทธิ์ไม่ได้ (403) | PASS | `testNonAdminCannotManageUsersOrGrant`, `testAdministrationPagesRequireThePermission` |
| T2-22 | TC-AUTHZ | เพิกถอน / เปลี่ยนสิทธิ์ → session ของผู้นั้นถูกยกเลิกทันที | PASS | `testRevokingRightsSignsTheUserOut` |
| T2-23 | TC-AUTHZ | audit_events แก้ / ลบไม่ได้ (trigger ใน DB; ถ้าโฮสต์ไม่ยอมให้สร้าง trigger จะบันทึกเป็น code_only) | PASS | `testAuditTrailIsAppendOnly` (บนเครื่องทดสอบ = triggers) |
| T2-24 | IDOR | 8 กรณี × 2 ช่องทาง (ดู GET / แก้ POST): ข้ามบริษัท, ข้ามสาขา, บทบาทอ่านอย่างเดียว, พนักงาน, แคชเชียร์ — ได้ 404; SYS ได้ 200/303 | PASS | `IdorMatrixTest::testDetailAndUpdateChannels` |
| T2-25 | IDOR | หน้ารายการแสดงเฉพาะบริษัท / สาขาของตน | PASS | `testListChannelShowsOnlyOwnCompany`, `testSiteScopedUserSeesOnlyTheirSite` |
| T2-26 | IDOR | เดา ID ข้ามบริษัทไม่ได้ (ULID + 404 แบบเดียวกับไม่มีอยู่) และความพยายามถูกบันทึก `authz.out_of_scope` | PASS | `testCrossCompanyIdsAreNotEnumerableAndAttemptsAreAudited` |
| T2-27 | IDOR | guest → ไปหน้า login; เส้นทาง API (ยังไม่มี) → 401 JSON | PASS | `testGuestsAreSentToLogin` |
| T2-28 | Re-auth | ส่งออก audit, ออกลิงก์รีเซ็ต, รีเซ็ต MFA, อนุมัติสิทธิ์ ต้องยืนยันตัวตนซ้ำ | PASS | `testReauthIsRequiredForAuditExport` + curl |
| T2-29 | Org | สร้าง / แก้ บริษัท-สาขา-แผนก ผ่านฟอร์ม; แก้ซ้อนกัน (row_version เก่า) ถูกปฏิเสธ | PASS | `testCreateCompanySiteDepartmentThroughForms`, `testStaleEditIsRejectedAsConcurrencyConflict` |
| T2-30 | TD-DEMO | ติดตั้งพร้อมข้อมูลตัวอย่าง DEC-34: 2 บริษัท, 3 สาขา, แผนก, ผู้ใช้ตัวอย่าง 8 คน (รหัสสุ่ม แสดงครั้งเดียว) | PASS | `TestApp` (ทุก integration test ใช้), ติดตั้งผ่านหน้าเว็บจริง |
| T2-31 | TC-UI | 2 ความกว้าง (360/1366) × 2 ธีม × 11 หน้า + หน้า guest 3 หน้า = 54 กรณี ไม่มี scroll แนวนอนทั้งหน้า | PASS | `phase2-evidence/ui-matrix-results.json` (overflow = 0 ทุกกรณีหลังแก้ 2 จุด) |
| T2-32 | TC-UI | axe-core WCAG 2.0/2.1/2.2 A+AA — ไม่มี violation | PASS | `ui-matrix-results.json` (axe = [] ทุกหน้า หลังแก้ปุ่มเมนูผู้ใช้บนมือถือที่ไม่มีชื่อ) |
| T2-33 | TC-UI | คีย์บอร์ด: login → MFA → หน้าผู้ใช้; focus อัตโนมัติที่ช่องแรก, เมนูผู้ใช้เปิดด้วย Enter ปิดด้วย Esc แล้ว focus กลับ | PASS | `phase2-evidence/keyboard-smoke.mjs` |
| T2-41 | Install | ติดตั้งจากไฟล์ zip บน Apache ในโฟลเดอร์ย่อยชื่อไทย + ช่องว่าง (จำลอง XAMPP): ติดตั้ง + ข้อมูลตัวอย่าง → `/install` = 404, login → MFA โดยไม่หลุดโฟลเดอร์, audit guard = triggers, core/config/storage/tests/.git = 403 | PASS | curl ผ่าน Apache 2.4 (ฐานข้อมูลใหม่ `ros_ziptest`) |
| T2-42 | Compat | ทำงานได้เมื่อ PHP ไม่มี libsodium (เหมือน XAMPP บน Windows): ติดตั้ง → login → ลงทะเบียน MFA → ทุกหน้า admin = 200; secret ถูกเข้ารหัสด้วย AES-256-GCM | PASS | PHP ที่ปิดฟังก์ชัน sodium_* ทั้ง 104 ตัว + curl; `testEveryAvailableCryptoBackendRoundTripsAndDetectsTampering`, `testNoDirectSodiumCallsOutsideCrypto` |
| T2-43 | DEC-52 | ติดตั้งใหม่ = MFA ปิด: admin เข้าระบบได้ทันที, หน้า MFA ปิด, re-auth ใช้รหัสผ่าน | PASS | `SecurityPolicyTest::testFreshInstallHasMfaOffAndAdminsGoStraightIn` |
| T2-44 | DEC-52 | เฉพาะ SYS เห็น/เปลี่ยนสวิตช์ (HR = 403 ทั้ง GET/POST) | PASS | `testOnlyTheSystemAdminCanSeeOrChangeTheSwitch` |
| T2-45 | DEC-52 | เปิดสวิตช์ต้อง re-auth + เหตุผล, บันทึก audit (before/after), SYS ถูกพาไปลงทะเบียน MFA ทันที, พนักงานทั่วไปไม่ถูกบังคับ | PASS | `testTurningOnNeedsReauthAndReasonIsAuditedAndForcesEnrolment` |
| T2-46 | DEC-52/53 | ปิดสวิตช์แล้วอุปกรณ์ที่ลงทะเบียนยังอยู่; ธีมเริ่มต้นสว่างแม้ OS ตั้งโหมดมืด; หน้าความปลอดภัย / ผู้ใช้ / บัญชีของฉัน axe = 0 | PASS | `testTurningOffKeepsEnrolledDevices`, Playwright (colorScheme=dark → data-theme=light) |
| T2-34 | CI | GitHub Actions (PHP 8.1 + 8.3, MariaDB) | **NOT RUN** | ยังไม่มี repository `r-os` (BT-01) |
| T2-35 | Compat | PHP 8.1 runtime | **NOT RUN** | เครื่อง AI มี 8.3 — CI จะรันให้ |
| T2-36 | Prototype | ติดตั้งบน InfinityFree + ตรวจว่า trigger สร้างได้หรือตกไปเป็น code_only | **NOT RUN** | ต้องใช้บัญชีของผู้ใช้ |
| T2-37 | TC-AUTH | QR code สำหรับลงทะเบียน TOTP | **NOT RUN** | ยังไม่มีไลบรารี QR (มากับ M27) — ตอนนี้ใช้การพิมพ์ key เข้าแอป authenticator เอง |
| T2-38 | TC-AUTH | ส่งอีเมลจริง (เปิดใช้งาน / OTP) ผ่าน Brevo | **NOT RUN** | DEC-48: ตอนนี้ใช้กล่องจดหมายทดสอบ `/system/mailbox` (เก็บ 24 ชม.); production ใช้ driver `none` |
| T2-39 | TC-UI | screen reader | **NOT RUN** | ตามแผนเริ่มเฟส 4 (P0-05 §7) |
| T2-40 | UAT | SoD กับคนจริง 2 คน (ผู้ขอ + SYS คนที่สองอนุมัติ) | **NOT RUN** | ต้องมีผู้ใช้คนที่สอง; ทดสอบอัตโนมัติผ่านแล้ว (T2-19) |

## ข้อบกพร่องที่พบและแก้ในรอบนี้
| ID | อาการ | สาเหตุ | การแก้ |
|---|---|---|---|
| D2-01 | country_code ว่าง → 422 ตอนสร้างบริษัท | ไม่มีค่าเริ่มต้น | ใช้ TH เป็นค่าเริ่มต้น |
| D2-02 | หน้าแรกบน production มีลิงก์ไป Showcase | ลิงก์ตายตัว | ดึงลิงก์จากเมนูตามสิทธิ์ (ShellData) |
| D2-03 | หน้าบทบาท (matrix) ล้นแนวนอนทั้งหน้า | ข้อความ sr-only (position:absolute) หลุดออกนอกกล่องเลื่อนตาราง | `.table-wrap { position: relative }` |
| D2-04 | ปุ่มเมนูผู้ใช้บนมือถือไม่มีชื่อสำหรับ screen reader (axe critical) | ซ่อนชื่อด้วย display:none | เปลี่ยนเป็นซ่อนแบบ visually-hidden |
| D2-05 | แถบบนมือถือ: ตัวเลือกบริษัททับปุ่มภาษา | flex item ไม่หด | ตั้ง min-width:0, ซ่อนไอคอน/ป้ายภาษาที่ ≤599px (ชื่อยังอ่านได้ด้วย screen reader) |
| D2-06 | เมนูผู้ใช้ปิดด้วย Esc ไม่ได้ | ไม่มีตัวจัดการ keydown | เพิ่ม Esc + ปิดเมื่อ focus ออก |
| D2-08 | **ผู้ใช้พบบน XAMPP:** ติดตั้งสำเร็จแต่เข้าสู่ระบบแล้วขึ้น "เกิดข้อผิดพลาดที่ไม่คาดคิด" (`Call to undefined function sodium_crypto_generichash`) | Crypto เรียก libsodium ตรง ๆ ซึ่ง XAMPP บน Windows ไม่เปิดไว้ (เครื่องทดสอบของ AI มี sodium ในตัว จึงไม่เจอ) | ใช้ HKDF/HMAC ของ PHP core + AES-256-GCM (OpenSSL) โดยใช้ sodium เป็นทางสำรอง; หน้าติดตั้งตรวจว่ามีอย่างน้อยหนึ่งตัว; เพิ่มเทสต์กันไม่ให้เรียก sodium_* นอก Crypto |
| D2-07 | ป้ายขอบเขตในฟอร์มมอบสิทธิ์แยกไม่ออกว่าเป็นสาขาของบริษัทไหน | ชื่อซ้ำกันข้ามบริษัท (HQ) | เติมรหัสบริษัทนำหน้า |

## การเปลี่ยนแปลงตามคำขอผู้ใช้ (2026-10-12 รอบ 2)
- **DEC-52:** ยืนยันตัวตนขั้นที่ 2 เป็นสวิตช์ระดับองค์กร **ปิดเป็นค่าเริ่มต้น** (หน้า `/admin/security`, SYS เท่านั้น, re-auth + เหตุผล + audit) — เมื่อเปิด ใช้กฎ DEC-51 เดิม · เทสต์ TC-AUTH/TC-AUTHZ ชุดเดิมรันโดยเปิดสวิตช์ เพื่อยืนยันว่าเมื่อเปิดแล้วยังบังคับถูกต้อง
- **DEC-53:** ธีมเริ่มต้น = สว่างเสมอ (เดิมตามโหมดของระบบปฏิบัติการ ทำให้เครื่องที่ตั้ง Windows โหมดมืดเห็นธีมมืด); มุมโค้งเพิ่มเป็น sm 6 / md 10 / lg 16 px

## ข้อจำกัดที่รู้แล้ว
0. **MFA ปิดเป็นค่าเริ่มต้น (DEC-52):** บัญชีสิทธิ์สูงป้องกันด้วยรหัสผ่านอย่างเดียวจนกว่าผู้ดูแลจะเปิด — ต้องเปิดก่อนใช้ข้อมูลจริง (เสนอให้เป็นเงื่อนไขของ Activation Gate)
1. **Masked value:** มี component และ re-auth พร้อมแล้ว แต่ข้อมูลระดับ R (เลขบัตร, บัญชี, เงินเดือน) จะเริ่มมีในเฟส HR/Payroll — ต้องทดสอบซ้ำเมื่อมีข้อมูลจริง
2. **ตัวเลือกพนักงาน:** ผูกบัญชีผู้ใช้กับพนักงานได้เมื่อมี M-Employee (เฟส 4); ตอนนี้ scope ระดับ self/team ยังไม่มีข้อมูลจริงให้ทดสอบ
3. **การติดตั้งเดิม:** migration ของเฟส 2 เป็นชุดใหม่ ต้องติดตั้งใหม่บนฐานข้อมูลว่าง (prototype ยังไม่มีข้อมูลจริงตาม DEC-34)
4. **ช่องทางที่ยังไม่มี:** API / mobile / ไฟล์แนบ — ต้องเพิ่มแถวใน IDOR matrix เมื่อสร้าง
