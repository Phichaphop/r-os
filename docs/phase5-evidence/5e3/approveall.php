<?php
// test helper for the walkthrough: approves every open workflow step about a subject (the assignee decides), prints who
require $argv[1] . '/core/bootstrap.php';
$app = Ros\Core\App::boot($argv[1]);
$db = $app->db();
for ($i = 0; $i < 5; $i++) {
    $row = $db->one("SELECT i.public_id, k.assignee_id FROM workflow_instances i JOIN workflow_tasks k ON k.instance_id = i.id AND k.status = 'open' WHERE i.subject_id = ? AND i.status IN ('pending','needs_attention') LIMIT 1", [$argv[2]]);
    if ($row === null) { break; }
    $app->workflow()->decide((string) $row['public_id'], (int) $row['assignee_id'], 'approve');
    echo $db->scalar('SELECT username FROM users WHERE id = ?', [$row['assignee_id']]), "\n";
}
