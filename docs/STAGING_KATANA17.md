# คู่มือติดตั้ง Staging บน Notebook MSI Katana 17 B13V (Blue OS 0.50.0)

> R9 · DEC-119 · คำตอบผู้ใช้: Q-17 Staging = Notebook Katana 17 B13V · Q-18 ยังไม่มี Production (ใช้ Staging แทน) · Q-19 ซ้อมกู้คืนหลังติดตั้ง Staging
> การตัดสินใจรอบนี้: ติดตั้งด้วย **XAMPP** และเปิดใช้ **บนเครื่องนี้เครื่องเดียว** (เครื่องอื่นในเครือข่ายเข้าไม่ได้)

**เครื่องนี้จะเก็บข้อมูลจริง** เพราะยังไม่มี Production จึงต้องทำส่วนที่ 1 (ป้องกันเครื่อง) และส่วนที่ 8 (สำเนาไฟล์สำรองนอกเครื่อง) ให้ครบก่อนใส่ข้อมูลจริง

คำสั่งทั้งหมดในคู่มือนี้พิมพ์ใน **Command Prompt** และถือว่าแตก Blue ไว้ที่ `C:\xampp\htdocs\blue` ถ้าเลือกที่อื่นให้เปลี่ยน path ตาม
ข้อความบางส่วนของคำสั่ง `bin\blue` เป็นภาษาไทย ถ้าแสดงเป็นตัวอ่านไม่ออก ให้พิมพ์ `chcp 65001` ก่อนในหน้าต่างนั้น

---

## 0. สิ่งที่ต้องเตรียม
| รายการ | หมายเหตุ |
|---|---|
| Katana 17 B13V กับ Windows 11 | สเปกต่างกันตามรุ่นย่อย (B13VEK / B13VFK / B13VGK — CPU, RAM, SSD) ดูของเครื่องตัวเองที่ Settings → System → About · Blue ใช้ทรัพยากรน้อย รุ่นไหนก็พอ |
| พื้นที่ว่างอย่างน้อย 10 GB | XAMPP + Blue + ไฟล์สำรองหลายชุด |
| ที่เก็บสำเนาไฟล์สำรองนอกเครื่อง | USB drive / external SSD ที่เสียบไว้ หรือโฟลเดอร์ที่ซิงก์ขึ้น cloud — ไฟล์สำรองเข้ารหัสด้วย APP_KEY แล้ว คนที่ไม่มีกุญแจเปิดไม่ได้ |
| Password manager | เก็บ APP_KEY, รหัส root ของ MariaDB, รหัสผู้ใช้ฐานข้อมูล `blue`, recovery key ของการเข้ารหัสดิสก์ — **ห้ามเก็บไว้บนเครื่องนี้ที่เดียว** |

## 1. ป้องกันเครื่องก่อน (ทำครั้งเดียว)
1. บัญชี Windows ต้องมีรหัสผ่านหรือ PIN และตั้งล็อกหน้าจอเมื่อไม่ใช้งาน
2. **เข้ารหัสดิสก์:** Settings → Privacy & security → **Device encryption** (Windows 11 Home) หรือ **BitLocker** (Windows 11 Pro) → เปิด แล้วเก็บ recovery key ไว้ใน password manager
   - ถ้าเมนูนี้ไม่มีในเครื่อง ให้จดไว้ว่า "ดิสก์ไม่ได้เข้ารหัส" เป็นความเสี่ยงที่รับไว้ เพราะถ้าเครื่องหาย คนที่ได้เครื่องไปจะอ่านข้อมูลได้
3. เปิด Windows Update และ Microsoft Defender ไว้ตามปกติ
4. ไม่ใช้บัญชี Windows นี้ร่วมกับคนอื่น

## 2. ติดตั้ง XAMPP
1. ดาวน์โหลด **XAMPP for Windows 8.2.12** จาก apachefriends.org (มี Apache 2.4, MariaDB 10.4 และ PHP 8.2)
2. ติดตั้งที่ `C:\xampp` (อย่าติดตั้งใน `Program Files`) เลือกแค่ Apache, MySQL, PHP, phpMyAdmin
3. **ข้อจำกัดที่รับไว้:** MariaDB 10.4 ใน XAMPP หมดระยะซัพพอร์ตจากผู้พัฒนาแล้ว
   - CI จึงมีชุดทดสอบ `staging-xampp-php8.2-mariadb10.4` คู่กับเครื่องนี้ ทดสอบทุก push ว่า Blue ทำงานบน PHP 8.2 + MariaDB 10.4 ได้
   - ความเสี่ยงด้านความปลอดภัยลดลงได้ด้วยข้อ 3 (ฐานข้อมูลรับการเชื่อมต่อจากเครื่องนี้เท่านั้น)
   - `doctor` จะขึ้น WARN ที่ `database version` ตลอด — ถือว่ารู้แล้ว

## 3. ปิดไม่ให้เครื่องอื่นเข้าถึง (ใช้เครื่องนี้เครื่องเดียว)
1. `C:\xampp\apache\conf\httpd.conf` → แก้บรรทัด `Listen 80` เป็น
   ```
   Listen 127.0.0.1:80
   ```
2. `C:\xampp\apache\conf\extra\httpd-ssl.conf` → แก้บรรทัด `Listen 443` เป็น `Listen 127.0.0.1:443`
3. `C:\xampp\mysql\bin\my.ini` → ใต้หัว `[mysqld]` เพิ่มบรรทัด
   ```
   bind-address=127.0.0.1
   ```
4. ถ้า Windows Firewall ถามว่าจะอนุญาต Apache หรือ mysqld ให้กด **Cancel** (ไม่ต้องอนุญาตเครือข่ายใด)
5. **ให้ Apache และ MySQL เริ่มเองตอนเปิดเครื่อง:**
   - เปิด XAMPP Control Panel ด้วย **Run as administrator**
   - กดกากบาทสีแดงหน้า Apache และ MySQL เพื่อติดตั้งเป็น service
   - กด Start ทั้งสองตัว
6. ตรวจ: เปิด `http://localhost/` บนเครื่องนี้ต้องเห็นหน้า XAMPP · ถ้าลองจากโทรศัพท์ใน Wi-Fi เดียวกันด้วย `http://<IP เครื่อง>/` ต้อง**เข้าไม่ได้**
> ใช้ `http://localhost` ได้โดยไม่ต้องมี HTTPS เพราะข้อมูลไม่ออกจากเครื่อง · ถ้าวันหลังต้องให้เครื่องอื่นเข้า ต้องตัดสินใจใหม่ (HTTPS, firewall, บัญชีผู้ใช้)

## 4. ตั้งค่า PHP
เปิด `C:\xampp\php\php.ini` แก้ค่าด้านล่างให้เป็นแบบนี้ แล้ว Restart Apache:
```
memory_limit = 512M
upload_max_filesize = 20M
post_max_size = 25M
date.timezone = Asia/Bangkok
```
ถ้า `doctor` (ข้อ 9) ขึ้น WARN ที่ `php gd` ให้ลบ `;` หน้า `extension=gd` แล้ว Restart Apache

## 5. ตั้งรหัส root ของ MariaDB และสร้างฐานข้อมูล
ค่าเริ่มต้นของ XAMPP คือ root **ไม่มีรหัสผ่าน** ถ้าปล่อยไว้ `doctor` จะขึ้น FAIL
1. XAMPP Control Panel → ปุ่ม **Shell** → พิมพ์ `mysql -u root`
2. วางคำสั่งด้านล่าง เปลี่ยน `รหัส-root` และ `รหัส-blue` เป็นรหัสยาวที่สุ่มจาก password manager
   - ถ้าบรรทัด `ALTER USER` บรรทัดใดขึ้นว่าไม่มีผู้ใช้นั้น ให้ข้ามบรรทัดนั้น
   ```sql
   ALTER USER 'root'@'localhost' IDENTIFIED BY 'รหัส-root';
   ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY 'รหัส-root';
   ALTER USER 'root'@'::1' IDENTIFIED BY 'รหัส-root';
   CREATE DATABASE blue_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE blue_staging_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'blue'@'localhost' IDENTIFIED BY 'รหัส-blue';
   CREATE USER 'blue'@'127.0.0.1' IDENTIFIED BY 'รหัส-blue';
   GRANT ALL PRIVILEGES ON blue_staging.* TO 'blue'@'localhost';
   GRANT ALL PRIVILEGES ON blue_staging_drill.* TO 'blue'@'localhost';
   GRANT ALL PRIVILEGES ON blue_staging.* TO 'blue'@'127.0.0.1';
   GRANT ALL PRIVILEGES ON blue_staging_drill.* TO 'blue'@'127.0.0.1';
   ```
   - สร้างผู้ใช้ `blue` ไว้ 2 แบบ (`localhost` และ `127.0.0.1`) ใช้รหัสเดียวกัน เพื่อให้เชื่อมต่อได้ไม่ว่าจะกรอก DB_HOST แบบไหน
   - `blue_staging_drill` คือฐานข้อมูลแยกสำหรับซ้อมกู้คืน ห้ามใช้ชื่อเดียวกับฐานข้อมูลจริง
3. phpMyAdmin จะถามรหัสก่อนเข้า: ในไฟล์ `C:\xampp\phpMyAdmin\config.inc.php` แก้ `auth_type` เป็น `'cookie'`

## 6. ดาวน์โหลด Blue และตรวจไฟล์
1. ที่ GitHub `Phichaphop/r-os` → **Releases** → ดาวน์โหลด `blue-0.50.0.zip` และ `blue-0.50.0.zip.sha256`
2. ตรวจว่าไฟล์ไม่เสียหายหรือถูกแก้:
   ```
   certutil -hashfile blue-0.50.0.zip SHA256
   ```
   ค่าที่ได้ต้องตรงกับในไฟล์ `.sha256`
3. แตก zip ไว้ใน `C:\xampp\htdocs\` จะได้โฟลเดอร์ `C:\xampp\htdocs\blue`
   - zip ไม่มี `config/env.php` และ `storage/` ที่เป็นข้อมูล จึงแตกทับรุ่นเก่าได้ตอนอัปเกรด

## 7. ติดตั้งผ่านเว็บ
1. เปิด `http://localhost/blue/` → ระบบพาไปหน้า `/install` เอง
2. รหัสติดตั้งอยู่ในไฟล์ `C:\xampp\htdocs\blue\storage\locks\setup-code.txt`
3. กรอกแบบฟอร์ม:
   - สภาพแวดล้อม = **Staging / UAT**
   - DB_HOST = `127.0.0.1`, ฐานข้อมูล = `blue_staging`, ผู้ใช้ = `blue` และรหัสที่ตั้งไว้
   - **ไม่ติ๊ก "ข้อมูลตัวอย่าง"** เพราะบัญชีตัวอย่างใช้รหัสผ่านเดียวกันทุกบัญชี ห้ามอยู่ร่วมกับข้อมูลจริง
   - บัญชีผู้ดูแลคนแรก: ใช้ชื่อและอีเมลจริงของคุณ
4. **ทันทีหลังติดตั้ง:** เปิด `C:\xampp\htdocs\blue\config\env.php` คัดลอกค่า `APP_KEY` ไปเก็บใน password manager
   - **APP_KEY คือกุญแจเปิดไฟล์สำรองทุกไฟล์** ถ้าเครื่องเสียและกุญแจหายไปด้วย ไฟล์สำรองจะใช้ไม่ได้เลย

## 8. สำเนานอกเครื่อง, งานตั้งเวลา และไฟล์สำรองชุดแรก
1. **สำเนาไฟล์สำรองนอกเครื่อง:** ใน `config\env.php` เพิ่มบรรทัดก่อน `);` ท้ายไฟล์ (ตัวอย่างเป็น USB drive ที่ได้อักษร E:)
   ```php
   'BACKUP_OFFSITE_PATH' => 'E:\\BlueBackup',
   ```
   สร้างโฟลเดอร์ `E:\BlueBackup` ไว้ก่อน ระบบจะคัดลอกไฟล์สำรองที่ตรวจแล้วทุกชุดไปที่นี่และตรวจ hash ซ้ำ
2. **งานตั้งเวลา (ทุก 1 นาที):** เปิด Command Prompt แบบ **Run as administrator** แล้วพิมพ์
   ```
   schtasks /Create /TN "Blue OS jobs" /SC MINUTE /MO 1 /RU SYSTEM /TR "C:\xampp\php\php.exe C:\xampp\htdocs\blue\bin\blue jobs:run" /F
   ```
   - จากนั้นเปิด Task Scheduler → งาน "Blue OS jobs" → Properties → แท็บ **Conditions** → เอาติ๊ก "Start the task only if the computer is on AC power" ออก (โน้ตบุ๊กใช้แบตก็ยังรัน)
   - งานนี้ทำสำรองข้อมูลตามรอบ ส่งการแจ้งเตือน และงานตามเวลาอื่น ๆ — **รันเฉพาะตอนเปิดเครื่อง** ถ้าปิดเครื่องหลายวัน การสำรองจะหยุดตามไปด้วย
3. **ไฟล์สำรองชุดแรก + ซ้อมกู้คืนในฐานข้อมูลแยก:** (`--as=` คือชื่อผู้ใช้ผู้ดูแลที่สร้างตอนติดตั้ง)
   ```
   cd C:\xampp\htdocs\blue
   C:\xampp\php\php.exe bin\blue backup:run
   C:\xampp\php\php.exe bin\blue backup:drill --as=ชื่อผู้ใช้ผู้ดูแล
   ```
   - ต้องขึ้น `verified` และ `Off-site: copied` แล้วตามด้วย `PASSED`
   - ทำในเว็บได้เหมือนกันที่เมนู **สำรองและกู้คืนข้อมูล**

## 9. ตรวจความพร้อมด้วย doctor
```
cd C:\xampp\htdocs\blue
C:\xampp\php\php.exe bin\blue doctor
```
- `doctor` อ่านอย่างเดียว ไม่เปลี่ยนอะไร ทุกบรรทัดเป็น PASS / WARN / FAIL
- **ห้ามใส่ข้อมูลจริงจนกว่าจะไม่มี FAIL**
- ที่คาดว่าจะเห็นบนเครื่องนี้: WARN ที่ `database version` (MariaDB 10.4 — ข้อ 2) ส่วนอื่นควรเป็น PASS
- `doctor` ไม่แสดง APP_KEY หรือรหัสผ่าน มีแค่ fingerprint ของกุญแจสำรอง ให้จด fingerprint ไว้คู่กับ APP_KEY ใน password manager เพื่อเทียบตอนกู้คืน

| บรรทัด | ถ้าไม่ผ่าน |
|---|---|
| `database account` FAIL | ยังใช้ root ไม่มีรหัส → ทำข้อ 5 แล้วแก้ `DB_USER` / `DB_PASS` ใน `config\env.php` |
| `scheduler` WARN | งานตั้งเวลายังไม่รัน → ข้อ 8.2 |
| `off-site copy` FAIL | ไม่มีโฟลเดอร์ปลายทาง หรือถอด USB drive ไว้ |
| `backup` / `restore drill` WARN | ยังไม่มีไฟล์สำรองชุดแรก หรือยังไม่ได้ซ้อม → ข้อ 8.3 |
| `demo data` WARN | ติดตั้งแบบมีข้อมูลตัวอย่าง → ติดตั้งใหม่แบบไม่มีข้อมูลตัวอย่างก่อนใส่ข้อมูลจริง |

## 10. ซ้อมกู้คืนหลังติดตั้ง (Q-19)
ทำตาม [DR_RUNBOOK.md](DR_RUNBOOK.md) ส่วน B "ซ้อมกรณีเครื่องเสียทั้งเครื่อง" บนเครื่องนี้ แล้วกรอกแบบบันทึกท้ายคู่มือ

## 11. อัปเกรดเป็นรุ่นใหม่
1. ดาวน์โหลดรุ่นใหม่จาก Releases แล้วตรวจ SHA-256 (ข้อ 6)
2. สำรองก่อน: `C:\xampp\php\php.exe bin\blue backup:run`
3. แตก zip ทับ `C:\xampp\htdocs\blue` (`config\env.php` และ `storage\` ไม่ถูกทับ)
4. อัปเดตฐานข้อมูล: `C:\xampp\php\php.exe bin\blue migrate --backup-confirmed` (หรือทำในเว็บที่ ระบบ → อัปเดต)
5. ตรวจซ้ำด้วย `C:\xampp\php\php.exe bin\blue doctor`

## ความเสี่ยงที่รับไว้ (ให้ผู้รับผิดชอบระบบตรวจและลงนาม — AI ลงนามแทนไม่ได้)
| ความเสี่ยง | ลดผลกระทบด้วย |
|---|---|
| ข้อมูลจริงอยู่บนโน้ตบุ๊กเครื่องเดียว (หาย / เสีย / ถูกขโมย) | เข้ารหัสดิสก์ (ข้อ 1) · สำเนาไฟล์สำรองนอกเครื่อง (ข้อ 8) · APP_KEY ใน password manager · ซ้อมกู้คืน (ข้อ 10) |
| MariaDB 10.4 ใน XAMPP หมดระยะซัพพอร์ต | รับการเชื่อมต่อจาก 127.0.0.1 เท่านั้น · CI ทดสอบ PHP 8.2 + MariaDB 10.4 ทุก push · ควรย้ายไปเซิร์ฟเวอร์ที่ใช้ MariaDB 10.11 เมื่อมี Production |
| งานตั้งเวลาและการสำรองหยุดเมื่อปิดเครื่อง | `doctor` เตือนเมื่อไฟล์สำรองเก่ากว่า 26 ชั่วโมง · ระบบแจ้งเตือนผู้ดูแลเมื่อสำรองเกินกำหนด |
| ไม่มี HTTPS | ใช้ได้เพราะเปิดใช้บนเครื่องนี้เท่านั้น (ข้อ 3) |
