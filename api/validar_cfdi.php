<?php

// ---- DEPURACIÓN ----
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
date_default_timezone_set('America/Mexico_City');

// --- CONFIGURACIÓN (Idéntica a los otros endpoints) ---
define('SECRET_API_TOKEN', 'Darkbyte234327*');

// --- RECURSOS ---
require_once __DIR__ . '/security.php';

// --- VALIDACIÓN (Idéntica a los otros endpoints) ---
$token = getBearerToken();
if ($token !== SECRET_API_TOKEN) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Acceso no autorizado.']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit();
}

// --- LÓGICA DE VALIDACIÓN DE CFDI ---
try {
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido en el cuerpo de la petición.');
    }

    // 1. Validar los datos de entrada
    $rfcEmisor = $data['rfc_emisor'] ?? null;
    $rfcReceptor = $data['rfc_receptor'] ?? null;
    $total = $data['total'] ?? null;
    $uuid = $data['uuid'] ?? null;

    if (empty($rfcEmisor) || empty($rfcReceptor) || is_null($total) || empty($uuid)) {
        throw new Exception('Los campos "rfc_emisor", "rfc_receptor", "total" y "uuid" son requeridos.');
    }
    
    // El Web Service del SAT espera el total sin comas y con punto decimal.
    // Aunque nuestro sistema Laravel lo envía bien, esta es una validación extra.
    $totalFormateado = number_format((float)$total, 2, '.', '');

    // 2. Construir la cadena de consulta para el Web Service
    // No necesitamos los últimos 8 caracteres del sello para este servicio.
    // La documentación actualizada solo requiere los 4 parámetros básicos.
    $expresionImpresa = "?re={$rfcEmisor}&rr={$rfcReceptor}&tt={$totalFormateado}&id={$uuid}";

    // 3. Crear el XML de la petición SOAP
    $soapRequestXml = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tem="http://tempuri.org/">
   <soapenv:Header/>
   <soapenv:Body>
      <tem:Consulta>
         <tem:expresionImpresa><![CDATA[{$expresionImpresa}]]></tem:expresionImpresa>
      </tem:Consulta>
   </soapenv:Body>
</soapenv:Envelope>
XML;

    // 4. Configurar y ejecutar la petición cURL al Web Service del SAT
    $url = 'https://consultaqr.facturaelectronica.sat.gob.mx/ConsultaCFDIService.svc';
    
    $headers = [
        'Content-Type: text/xml; charset=utf-8',
        'SOAPAction: http://tempuri.org/IConsultaCFDIService/Consulta',
        'Content-Length: ' . strlen($soapRequestXml)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $soapRequestXml);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    // Opcional: añadir timeouts para evitar que la API se quede colgada
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 segundos para conectar
    curl_setopt($ch, CURLOPT_TIMEOUT, 20); // 20 segundos para la respuesta total

    $responseXml = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Error de cURL al consultar el servicio del SAT: " . $curlError);
    }

    if ($httpCode != 200 || empty($responseXml)) {
        throw new Exception("El servicio del SAT no respondió correctamente. Código HTTP: {$httpCode}");
    }

    // 5. Parsear la respuesta XML del SAT
    // Usamos SimpleXML para navegar la respuesta SOAP
    $responseXml = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $responseXml); // Quitar namespaces
    $xmlObj = new \SimpleXMLElement($responseXml);
    $resultNode = $xmlObj->sBody->ConsultaResponse->ConsultaResult;

    $codigoEstatus = (string)$resultNode->aCodigoEstatus;
    $estado = (string)$resultNode->aEstado;
    $esCancelable = (string)$resultNode->aEsCancelable;
    $estatusCancelacion = (string)$resultNode->aEstatusCancelacion;
    
    // 6. Preparar y enviar la respuesta JSON unificada
    // Si el código de estatus no empieza con 'S', consideramos que hubo un error de validación
    if (strpos($codigoEstatus, 'S - ') === 0) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'CFDI encontrado y validado.',
            'data' => [
                'codigo_estatus' => $codigoEstatus,
                'estado' => $estado, // "Vigente", "Cancelado"
                'es_cancelable' => $esCancelable,
                'estatus_cancelacion' => $estatusCancelacion,
            ]
        ]);
        exit();
    } else {
        // Para errores como "N - 601", lo reportamos como un error del cliente
        throw new Exception("El SAT reportó un error de validación: {$codigoEstatus}");
    }

} catch (\Exception $e) {
    // Manejo de errores centralizado
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Error al validar el CFDI.',
        'details' => $e->getMessage()
    ]);
}