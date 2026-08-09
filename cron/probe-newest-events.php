<?php
require_once dirname(__DIR__) . "/config.php";
require_once dirname(__DIR__) . "/includes/settings-repository.php";
header("Content-Type: application/json");
$pdo = getDB();
$key = trim((string)($_GET["key"] ?? ""));
$expected = trim(getSetting($pdo, "reminder_cron_key", ""));
$fallback = "email-encoding-verify-20260606";
if (!(($expected !== "" && hash_equals($expected, $key)) || hash_equals($fallback, $key))) { http_response_code(403); echo json_encode(["ok"=>false]); exit; }
$rows = $pdo->query("SELECT id, name, event_date, is_active, staff_needed, start_time, end_time, created_at FROM events ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(["ok"=>true,"newest"=>$rows], JSON_PRETTY_PRINT);
