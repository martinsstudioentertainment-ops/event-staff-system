<?php

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/work-types-repository.php';

$pdo = getDB();
ensureWorkTypesSchema($pdo);
echo 'Work types: ' . count(getAllWorkTypes($pdo)) . "\n";
