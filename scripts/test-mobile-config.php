<?php

$_SERVER['REQUEST_URI'] = '/api/mobile/v1/config?platform=android';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require dirname(__DIR__) . '/api/mobile/index.php';
