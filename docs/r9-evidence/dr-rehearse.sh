#!/bin/bash
# DR rehearsal for 0.50.0 (R9 · Q-19). Prints no secret (APP_KEY, DB password, demo password stay in files).
set -u
D=$(cd "$(dirname "$0")" && pwd)
ts() { date -u +%H:%M:%S; }
copy() { rsync -a --exclude=.git --exclude=vendor/ --exclude=config/env.php --exclude=collector/data/ --exclude=public/assets/ \
  --exclude='storage/*/*' --exclude=storage/install.lock <repo>/ "$1/"; mkdir -p "$1"/storage/{logs,cache,locks,documents,backups,pdf-temp,tmp}; }
counts() { mysql -uroot -N -e "SELECT CONCAT('users=',(SELECT COUNT(*) FROM $1.users),' employees=',(SELECT COUNT(*) FROM $1.employees),' audit_events=',(SELECT COUNT(*) FROM $1.audit_events),' documents=',(SELECT COUNT(*) FROM $1.documents))"; }
echo "== [$(ts)] OLD MACHINE A: install (APP_ENV=staging, demo data for volume)"
copy "$D/A"; php "$D/install.php" "$D/A" blue_drA 1
php -r '$f=$argv[1]; $c=include $f; $c["BACKUP_OFFSITE_PATH"]=$argv[2]; file_put_contents($f, "<?php\nreturn ".var_export($c,true).";\n");' "$D/A/config/env.php" "$D/offsite"
php -r '$c=include $argv[1]; file_put_contents($argv[2], $c["APP_KEY"]);' "$D/A/config/env.php" "$D/keyvault/APP_KEY.txt"   # the copy kept away from the machine
cd "$D/A" && php bin/blue assets:publish >/dev/null && php bin/blue jobs:run
echo "-- doctor (A, before backup)"; php bin/blue doctor
echo "== [$(ts)] A: backup + drill"
php bin/blue backup:run
php bin/blue backup:drill --as=phichaphop
echo "-- A data:"; counts blue_drA
mysql -uroot -N -e "CHECKSUM TABLE blue_drA.users, blue_drA.employees, blue_drA.audit_events" | awk '{print "   checksum", $1, $2}' > "$D/checksum_A.txt"; cat "$D/checksum_A.txt"
LOSS=$(date -u +%s)
echo "== [$(ts)] DISASTER: machine A is gone (folder + database deleted). Left: the off-site folder + the APP_KEY copy"
cd "$D"; rm -rf "$D/A"; mysql -uroot -e "DROP DATABASE blue_drA; DROP DATABASE IF EXISTS blue_drA_drill"
ls -la "$D/offsite" | tail -n +2 | awk '{print "   offsite:", $5, $9}'
T0=$(date -u +%s)
echo "== [$(ts)] NEW MACHINE B: install the same release WITHOUT demo data"
copy "$D/B"; php "$D/install.php" "$D/B" blue_drB 0
echo "-- put the saved APP_KEY back into config/env.php"
php -r '$f=$argv[1]; $c=include $f; $c["APP_KEY"]=trim(file_get_contents($argv[2])); file_put_contents($f, "<?php\nreturn ".var_export($c,true).";\n");' "$D/B/config/env.php" "$D/keyvault/APP_KEY.txt"
cd "$D/B" && php bin/blue assets:publish >/dev/null
F=$(ls -t "$D"/offsite/*.bluebak | head -1)   # only used to show the phrase; the command itself picks the newest copy in the folder
echo "== [$(ts)] B: restore:fresh from the off-site copy (phrase typed at the prompt)"
php -r 'require $argv[1]."/core/bootstrap.php"; echo Blue\Core\Backup\RestoreService::freshPhrase($argv[2]), "\n";' "$D/B" "$F" | php bin/blue restore:fresh "$D/offsite" --as=phichaphop
echo "-- B data:"; counts blue_drB
mysql -uroot -N -e "CHECKSUM TABLE blue_drB.users, blue_drB.employees" | awk '{print "   checksum", $1, $2}' | sed 's/blue_drB/blue_drA/' > "$D/checksum_B.txt"; cat "$D/checksum_B.txt"
grep -v audit_events "$D/checksum_A.txt" | diff - "$D/checksum_B.txt" && echo "   users + employees: identical to machine A"
php bin/blue migrate:status | grep -c applied | sed 's/^/   migrations applied: /'
php bin/blue jobs:run >/dev/null
echo "-- set BACKUP_OFFSITE_PATH again on B (config/env.php is per machine)"
php -r '$f=$argv[1]; $c=include $f; $c["BACKUP_OFFSITE_PATH"]=$argv[2]; file_put_contents($f, "<?php\nreturn ".var_export($c,true).";\n");' "$D/B/config/env.php" "$D/offsite"
php bin/blue backup:run >/dev/null && echo "   first backup on B: done"
echo "-- doctor (B)"; php bin/blue doctor
T1=$(date -u +%s)
echo "-- sign-in check: a restored account's password still works (password_verify against the restored hash)"
php -r '$c=include $argv[1]; $p=new PDO("mysql:host=127.0.0.1;dbname=blue_drB", $c["DB_USER"], $c["DB_PASS"]); $h=$p->query("SELECT password_hash FROM users WHERE username=\"hr.demo1\"")->fetchColumn(); echo "   hr.demo1 + demo password: ", password_verify(trim(file_get_contents($argv[2])), (string)$h) ? "OK" : "FAIL", "\n";' "$D/B/config/env.php" "$D/demo_pw.txt"
echo "== [$(ts)] RTO (new machine install → doctor): $((T1-T0)) s · RPO: backup taken $(( (T0-LOSS) )) s before the loss in this rehearsal"
