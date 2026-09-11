<?php

$serverName = getenv('DB_SERVER' );

$connectionInfo = [
    'Database' => getenv('DB_NAME'),
    'UID' => getenv('DB_USER'),
    'PWD' => getenv('DB_PASSWORD'),
    'CharacterSet' => 'UTF-8',
];

$conn = sqlsrv_connect($serverName, $connectionInfo);

if ($conn === false) {
    error_log(print_r(sqlsrv_errors(), true));
    http_response_code(500 );
    die('No se pudo establecer la conexión con la base de datos.');
}
