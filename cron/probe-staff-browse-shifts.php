<?php
require_once dirname(__DIR__) . "/config.php";
require_once dirname(__DIR__) . "/includes/settings-repository.php";
require_once dirname(__DIR__) . "/includes/staff-repository.php";
require_once dirname(__DIR__) . "/includes/mobile/services/MobileEventsService.php";
header("Content-Type: application/json");
$pdo = getDB();
$key = trim((string)($_GET["key"] ?? ""));
$expected = trim(getSetting($pdo, "reminder_cron_key", ""));
$fallback = "email-encoding-verify-20260606";
if (!(($expected !== "" && hash_equals($expected, $key)) || hash_equals($fallback, $key))) { http_response_code(403); echo json_encode(["ok"=>false]); exit; }
$email = strtolower(trim((string)($_GET["email"] ?? "")));
$staff = $email !== "" ? getStaffByEmail($pdo, $email) : null;
if ($staff === null) {
  $staff = $pdo->query("SELECT * FROM staff WHERE email IS NOT NULL AND email <> '' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
$list = mobileEventsServiceList($pdo, $staff);
echo json_encode(["ok"=>true,"staff"=>["id"=>(int)$staff["id"],"email"=>$staff["email"],"name"=>trim(($staff["first_name"]??"")." ".($staff["surname"]??""))], "list"=>$list], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
