<?php
$h = file_get_contents('https://register.olasentra.com/index.php');
preg_match('/id="event-selection-wrap"[^>]*>/', $h, $m);
echo ($m[0] ?? 'not found') . PHP_EOL;
echo (str_contains($h, 'data-wizard-mode="1"') ? 'wizard=1' : 'wizard=0') . PHP_EOL;
$g = file_get_contents('https://register.olasentra.com/assets/js/registration-shift-gate.js');
echo (str_contains($g, 'wizardWrap.classList.remove') ? 'gate_unlock=yes' : 'gate_unlock=no') . PHP_EOL;
