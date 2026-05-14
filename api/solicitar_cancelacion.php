<?php

// ---- DEPURACIÓN ----
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
date_default_timezone_set('America/Mexico_City');

// --- CONFIGURACIÓN ---
define('SECRET_API_TOKEN', 'Darkbyte234327*');
define('CERT_DIR', __DIR__ . '/../certificados/');
define('PASSWORD_FILE', __DIR__ . '/../config/passwords.ini');
// define('RFC_INTEGRADOR', 'SADR850924MG0');
define('ID_INTEGRADOR', 'a0319129-de14-47dd-8276-afaa9929c9be');
// define('ID_INTEGRADOR', '22e845b7-aa15-4929-9abd-6dc3004424f8'); // ID DE RAMON

// --- RECURSOS ---
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../vendor/autoload.php';

// --- USE STATEMENTS ---
use Facturando\Ecodex\Proveedor;
use Facturando\XmlSignature\Parameters;
use Facturando\XmlSignature\Signature;

// --- VALIDACIÓN DE TOKEN Y MÉTODO ---
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

try {
    // --- LECTURA DE JSON ENTRANTE ---
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido en el cuerpo de la petición.');
    }

    // --- VALIDACIÓN DE CAMPOS REQUERIDOS ---
    $emisorRfc = $data['emisor_rfc'] ?? null;
    $uuidACancelar = $data['uuid_a_cancelar'] ?? null;
    $motivo = $data['motivo'] ?? null;
    $folioSustitucion = $data['folio_sustitucion'] ?? null;

    if (empty($emisorRfc) || empty($uuidACancelar) || empty($motivo)) {
        throw new Exception('Los campos "emisor_rfc", "uuid_a_cancelar" y "motivo" son requeridos.');
    }

    if ($motivo === '01' && empty($folioSustitucion)) {
        throw new Exception('El campo "folio_sustitucion" es requerido cuando el motivo es "01".');
    }

    // --- VERIFICAR CERTIFICADOS Y CONTRASEÑA ---
    $pathCer = CERT_DIR . $emisorRfc . '.cer';
    $pathKey = CERT_DIR . $emisorRfc . '.key';

    if (!file_exists($pathCer) || !file_exists($pathKey)) {
        throw new Exception("No se encontraron los CSD para el RFC emisor: $emisorRfc");
    }

    if (!file_exists(PASSWORD_FILE)) {
        throw new Exception("No se encontró el archivo de contraseñas: " . PASSWORD_FILE);
    }

    $passwords = parse_ini_file(PASSWORD_FILE);
    if (!isset($passwords[$emisorRfc])) {
        throw new Exception("No se encontró la contraseña para el RFC emisor: $emisorRfc");
    }

    $password = $passwords[$emisorRfc];

    // --- CREAR FIRMA XML DE CANCELACIÓN ---
    $parameters = new Parameters();
    $parameters->Rfc = $emisorRfc;
    $parameters->Uuid = $uuidACancelar;
    $parameters->Motivo = $motivo;
    $parameters->FolioSustitucion = $folioSustitucion;
    $parameters->CertFile = $pathCer;
    $parameters->KeyFile = $pathKey;
    $parameters->Password = $password;

    $signature = new Signature();
    $xml = null;

    if ($signature->createXml($parameters, $xml) == false) {
        $errorDetails = json_decode($xml, true);
        $mensajeError = $errorDetails['message'] ?? 'Error desconocido al crear la firma XML.';
        throw new Exception('Error al crear la firma XML de cancelación: ' . $mensajeError);
    }

    // --- ENVIAR CANCELACIÓN AL PAC (ECODEX) ---
    $parameters = new \Facturando\Ecodex\Cancelar\Parameters();
    // $parameters->Rfc = RFC_INTEGRADOR;
    $parameters->Rfc = $data['emisor_rfc'] ?? null;
    $parameters->Usuario = ID_INTEGRADOR;
    $parameters->XmlSignature = $xml;

    $ecodex = new Proveedor();
    $result = $ecodex->CancelarCfdi($parameters);


    // --- INTERPRETAR RESPUESTA ---
    if (!is_string($result) || empty($result)) {
        throw new Exception('Respuesta inesperada o vacía del PAC.');
    }

    $respuestaPac = json_decode($result, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Puede que el PAC haya devuelto un error no-JSON, lo mostramos tal cual.
        throw new Exception('El PAC devolvió una respuesta que no es un JSON válido. Detalle: ' . $result);
    }
    
    if (isset($respuestaPac['estatus_uuid']) && $respuestaPac['estatus_uuid'] === '201') {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Solicitud de cancelación procesada exitosamente por el SAT.',
            'uuid_cancelado' => $respuestaPac['uuid'] ?? $uuidACancelar,
            'fecha_solicitud' => $respuestaPac['fecha'] ?? null,
            'respuesta_pac' => $respuestaPac // Incluimos la respuesta completa del PAC para trazabilidad
        ]);
        exit();
    }

    if (isset($respuestaPac['mensaje'])) {
        $detalleError = $respuestaPac['descripcion'] ?? $respuestaPac['mensaje'];
        $codigoError = isset($respuestaPac['codigo']) ? ' (Código: ' . $respuestaPac['codigo'] . ')' : '';
        
        throw new Exception('El PAC rechazó la solicitud: ' . $detalleError . $codigoError);
    }
    
    throw new Exception('El PAC devolvió una respuesta JSON con un formato desconocido. Detalle: ' . $result);


} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Error al procesar la solicitud de cancelación.',
        'details' => $e->getMessage()
    ]);
}
