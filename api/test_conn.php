<?php
// api/test_conn.php
header('Content-Type: application/json');

$targets = [
    'SAT Validador CFDI' => ['host' => 'consultaqr.facturaelectronica.sat.gob.mx', 'port' => 443],
    'Ecodex Timbrado Pruebas' => ['host' => 'pruebas.ecodex.com.mx', 'port' => 50001],
    'Ecodex Cancelacion Pruebas' => ['host' => 'pruebas.ecodex.com.mx', 'port' => 50002],
    'Ecodex Cancelacion Alt Pruebas' => ['host' => 'pruebas.ecodex.com.mx', 'port' => 50004],
    'Ecodex Timbrado Prod' => ['host' => 'servicios.ecodex.com.mx', 'port' => 40001],
    'Ecodex Cancelacion Prod' => ['host' => 'servicios.ecodex.com.mx', 'port' => 40002],
    'Ecodex Cancelacion Alt Prod' => ['host' => 'servicios.ecodex.com.mx', 'port' => 40004],
];

$results = [];

foreach ($targets as $name => $target) {
    $start = microtime(true);
    // Intentamos conectar con un timeout corto de 4 segundos
    $connection = @fsockopen($target['host'], $target['port'], $errno, $errstr, 4.0);
    $duration = round((microtime(true) - $start) * 1000, 2);
    
    if (is_resource($connection)) {
        $results[$name] = [
            'status' => 'CONECTADO (Puerto Abierto)',
            'time_ms' => $duration,
            'details' => 'Conexión exitosa.'
        ];
        fclose($connection);
    } else {
        $results[$name] = [
            'status' => 'ERROR / BLOQUEADO',
            'time_ms' => $duration,
            'details' => "No se pudo establecer conexión. Código: $errno ($errstr)"
        ];
    }
}

echo json_encode([
    'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'Desconocida',
    'timestamp' => date('Y-m-d H:i:s'),
    'diagnostics' => $results
], JSON_PRETTY_PRINT);
