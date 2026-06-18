<?php
/**
 * Social share image (WhatsApp, Facebook) — always 1200×630; never serves a raw 8K logo file.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/brand-logo.php';
require_once __DIR__ . '/includes/settings-repository.php';

$pdo = getDB();
outputOgShareImage($pdo);
