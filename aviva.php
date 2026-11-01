<?php
/**
 * Short WhatsApp link: https://olasentra.com/aviva
 * Always 200 with the Olasentra logo card. People are sent to the Google Form.
 * No session and no 302 — WhatsApp drops the logo on redirects and PHP cookies.
 */
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: public, max-age=300');

$formUrl = 'https://docs.google.com/forms/d/e/1FAIpQLSd3zQMebmrJPpi1_4jVMTfNdwr6raAwjDIgXqQ_uabFB86lOQ/viewform';
$shareUrl = 'https://olasentra.com/aviva';
$imageUrl = 'https://olasentra.com/aviva-share.jpg?v=20260921logo';
$title = 'Olasentra — AVIVA Stadium';
$description = 'Staff registration for AVIVA Stadium, Thursday 1 October 2026, 16:00–23:00. DSP and Static only.';

$esc = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $esc($title) ?>">
<meta property="og:description" content="<?= $esc($description) ?>">
<meta property="og:url" content="<?= $esc($shareUrl) ?>">
<meta property="og:site_name" content="Olasentra">
<meta property="og:image" content="<?= $esc($imageUrl) ?>">
<meta property="og:image:secure_url" content="<?= $esc($imageUrl) ?>">
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="280">
<meta property="og:image:height" content="280">
<meta property="og:image:alt" content="Olasentra logo">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?= $esc($title) ?>">
<meta name="twitter:description" content="<?= $esc($description) ?>">
<meta name="twitter:image" content="<?= $esc($imageUrl) ?>">
<meta name="description" content="<?= $esc($description) ?>">
<link rel="canonical" href="<?= $esc($shareUrl) ?>">
<title><?= $esc($title) ?></title>
<script>location.replace(<?= json_encode($formUrl, JSON_UNESCAPED_SLASHES) ?>);</script>
</head>
<body>
<p><a href="<?= $esc($formUrl) ?>">Open staff form</a></p>
</body>
</html>
