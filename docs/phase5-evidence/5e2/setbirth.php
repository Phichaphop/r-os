<?php
// test helper for the walkthrough (demo data only): sets demo birth dates so the board has something this month
require $argv[1] . '/core/bootstrap.php';
$app = Ros\Core\App::boot($argv[1]);
foreach (array_slice($argv, 2) as $pair) {
    [$code, $date] = explode('=', $pair);
    $app->db()->run('UPDATE employees SET birth_date_enc = ? WHERE employee_code = ? AND is_demo = 1', [$app->crypto()->encrypt($date), $code]);
}
echo "ok\n";
