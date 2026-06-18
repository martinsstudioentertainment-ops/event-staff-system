<?php
$transcript = 'C:/Users/Workshop/.cursor/projects/e-event-staff-system/agent-transcripts/85fda9ab-8f45-422d-aa9a-413c2e95e482/85fda9ab-8f45-422d-aa9a-413c2e95e482.jsonl';
$outDir = dirname(__DIR__) . '/temp';
$patches = [];
foreach (file($transcript) as $i => $line) {
    if (strpos($line, 'dashboard.php') === false) {
        continue;
    }
    $obj = json_decode($line, true);
    if (!$obj || ($obj['role'] ?? '') !== 'assistant') {
        continue;
    }
    foreach ($obj['message']['content'] ?? [] as $item) {
        if (($item['name'] ?? '') !== 'StrReplace') {
            continue;
        }
        $p = str_replace('\\', '/', $item['input']['path'] ?? '');
        if (!preg_match('#admin/dashboard\.php$#', $p)) {
            continue;
        }
        $patches[] = [
            'line' => $i + 1,
            'old'  => $item['input']['old_string'] ?? '',
            'new'  => $item['input']['new_string'] ?? '',
        ];
    }
}
file_put_contents($outDir . '/dashboard-patches.json', json_encode($patches, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'Found ' . count($patches) . " patches\n";
foreach ($patches as $p) {
    echo 'Line ' . $p['line'] . ': old=' . strlen($p['old']) . ' new=' . strlen($p['new']) . "\n";
}
