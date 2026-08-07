<?php
// api/test_provider.php
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

use Facturando\Ecodex\Proveedor;

try {
    $ecodex = new Proveedor();
    
    // Usamos Reflection para ver las propiedades de la clase
    $reflection = new ReflectionClass($ecodex);
    $properties = $reflection->getProperties();
    $details = [];
    
    foreach ($properties as $prop) {
        $prop->setAccessible(true);
        $name = $prop->getName();
        $value = $prop->getValue($ecodex);
        
        // Evitamos imprimir objetos complejos o contraseñas
        if (is_scalar($value) || is_array($value) || is_null($value)) {
            $details[$name] = $value;
        } else {
            $details[$name] = get_class($value);
        }
    }
    
    echo json_encode([
        'class' => get_class($ecodex),
        'properties' => $details,
        'methods' => array_map(function($m) { return $m->name; }, $reflection->getMethods())
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
