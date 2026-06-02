<?php

/**

 * Event Staff System — Health check (database + storage)

 */



require_once __DIR__ . '/../config.php';



header('Content-Type: application/json; charset=utf-8');

header('Cache-Control: no-store');



$checks = [

    'app'      => 'ok',

    'database' => 'unknown',

    'storage'  => 'unknown',

];



try {

    $pdo = getDB();

    $pdo->query('SELECT 1');

    $checks['database'] = 'ok';



    if (!isProductionApp()) {

        $checks['events'] = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();

        $checks['staff']  = (int) $pdo->query('SELECT COUNT(*) FROM staff_registrations')->fetchColumn();

    }

} catch (Throwable $e) {

    $checks['database'] = 'error';

    if (!isProductionApp()) {

        $checks['message'] = 'Database connection failed';

    }

    http_response_code(503);

    echo json_encode($checks, JSON_PRETTY_PRINT);

    exit;

}



$logDir = dirname(__DIR__) . '/storage/logs';

if (is_dir($logDir) && is_writable($logDir)) {

    $checks['storage'] = 'ok';

} elseif (is_dir($logDir)) {

    $checks['storage'] = 'not_writable';

} else {

    $checks['storage'] = 'missing';

}



echo json_encode($checks, JSON_PRETTY_PRINT);

