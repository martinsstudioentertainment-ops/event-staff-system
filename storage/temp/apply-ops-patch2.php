<?php
$p = json_decode(file_get_contents(__DIR__ . '/dashboard-patches.json'), true);
$file = dirname(__DIR__, 2) . '/admin/dashboard.php';
$content = file_get_contents($file);
$new = $p[23]['new'];

$anchor = "                </section>\r\n                <?php endif; ?>\r\n            </div>\r\n        </div>\r\n\r\n        <aside class=\"dash__side\">";
$replacement = str_replace("\n", "\r\n", substr($new, strpos($new, "                </section>")));

if (!str_contains($content, $anchor)) {
    fwrite(STDERR, "Anchor not found\n");
    exit(1);
}

$content = str_replace($anchor, $replacement, $content);
file_put_contents($file, $content);
echo 'OK size=' . strlen($content) . "\n";
