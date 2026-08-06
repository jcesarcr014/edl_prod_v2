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

define('ID_INTEGRADOR', '22e845b7-aa15-4929-9abd-6dc3004424f8');

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../ejemplos/Utils/Utils.php';
require_once __DIR__ . '/../ejemplos/Data/Constants.php';

use Facturando\Ecodex\Proveedor;
use Facturando\Ecodex\Timbrado\Parameters;
use Facturando\EDL\Example\Utils\Utils;
use Facturando\ElectronicDocumentLibrary\Base\Types\ProcessProviderResult;
use Facturando\ElectronicDocumentLibrary\Document\ElectronicDocument;
use Facturando\ElectronicDocumentLibrary\Base\Types\Complemento;

// --- VALIDACIÓN ---
$token = getBearerToken();
if ($token !== SECRET_API_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Acceso no autorizado.']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit();
}

// --- LÓGICA TIMBRADO PAGO 2.0 ---
try {
    // --- Configurar Resolutor de Entidades para libxml ---
    libxml_set_external_entity_loader(function ($publicId, $systemId, $context) {
        $filename = basename($systemId);
        $schemas = [
            'pagos20.xsd' => __DIR__ . '/Pagos20.xsd',
            'pagos20_catalogo.xsd' => __DIR__ . '/pagos20_catalogo.xsd',
            'pagos20_lite.xsd' => __DIR__ . '/pagos20_lite.xsd',
            'cfdi_catalogo40.xsd' => __DIR__ . '/cfdi_catalogo40.xsd',
            'cfdi_tipos40.xsd' => __DIR__ . '/cfdi_tipos40.xsd',
        ];
        $lowerFilename = strtolower($filename);
        if (isset($schemas[$lowerFilename]) && file_exists($schemas[$lowerFilename])) {
            return $schemas[$lowerFilename];
        }
        return null;
    });

    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido en el cuerpo de la petición.');
    }

    $emisorRfc = $data['emisor']['rfc'] ?? null;
    if (empty($emisorRfc)) {
        throw new Exception('El RFC del emisor es requerido.');
    }

    // Certificados
    $pathCer = CERT_DIR . $emisorRfc . '.cer';
    $pathKey = CERT_DIR . $emisorRfc . '.key';
    if (!file_exists($pathCer) || !file_exists($pathKey)) {
        throw new Exception("No se encontraron los CSD para el RFC: $emisorRfc");
    }
    $passwords = parse_ini_file(PASSWORD_FILE);
    if (!isset($passwords[$emisorRfc])) {
        throw new Exception("No se encontró la contraseña para el RFC: $emisorRfc");
    }
    $password = $passwords[$emisorRfc];

    // Documento
    $electronicDocument = new ElectronicDocument();
    $certificate = Utils::loadCertificateFromFile($pathCer, $pathKey, $password);
    $electronicDocument->Manage->Save->Certificate = $certificate;
    
    // Inicializar datos del CFDI
    $electronicDocument->Data->clear();

    // --- Comprobante (Requerimientos específicos de Pago 2.0) ---
    $comprobante = $data['comprobante'] ?? [];
    $electronicDocument->Data->Version->Value = '4.0';
    $electronicDocument->Data->Serie->Value = $comprobante['serie'] ?? '';
    $electronicDocument->Data->Folio->Value = $comprobante['folio'] ?? '';
    $electronicDocument->Data->Fecha->Value = new \DateTime('NOW -5 hours');
    $electronicDocument->Data->LugarExpedicion->Value = $comprobante['lugarExpedicion'] ?? '';
    
    // Forzados para Pago
    $electronicDocument->Data->TipoComprobante->Value = 'P';
    $electronicDocument->Data->Exportacion->Value = '01';
    $electronicDocument->Data->Moneda->Value = 'XXX';
    
    $electronicDocument->Data->SubTotal->Value = 0;
    $electronicDocument->Data->SubTotal->Decimals = 0;
    $electronicDocument->Data->SubTotal->Dot = false;
    
    $electronicDocument->Data->Total->Value = 0;
    $electronicDocument->Data->Total->Decimals = 0;
    $electronicDocument->Data->Total->Dot = false;

    // --- Emisor ---
    $electronicDocument->Data->Emisor->Rfc->Value = $data['emisor']['rfc'];
    $electronicDocument->Data->Emisor->Nombre->Value = $data['emisor']['nombre'];
    $electronicDocument->Data->Emisor->RegimenFiscal->Value = $data['emisor']['regimenFiscal'];

    // --- Receptor ---
    $electronicDocument->Data->Receptor->Rfc->Value = $data['receptor']['rfc'];
    $electronicDocument->Data->Receptor->Nombre->Value = $data['receptor']['nombre'];
    $electronicDocument->Data->Receptor->RegimenFiscalReceptor->Value = $data['receptor']['regimenFiscalReceptor'];
    $electronicDocument->Data->Receptor->DomicilioFiscalReceptor->Value = $data['receptor']['domicilioFiscalReceptor'];
    // Para recibo de pago, el UsoCFDI siempre es CP01
    $electronicDocument->Data->Receptor->UsoCfdi->Value = $data['receptor']['usoCfdi'] ?? 'CP01';

    // --- Concepto Obligatorio para Pago ---
    $concepto = $electronicDocument->Data->Conceptos->add();
    $concepto->ClaveProductoServicio->Value = '84111506'; // Clave estándar SAT para servicios de facturación
    $concepto->Cantidad->Value = 1;
    $concepto->Cantidad->Decimals = 0;
    $concepto->Cantidad->Dot = false;
    $concepto->ClaveUnidad->Value = 'ACT';
    $concepto->Descripcion->Value = 'Pago';
    
    $concepto->ValorUnitario->Value = 0;
    $concepto->ValorUnitario->Decimals = 0;
    $concepto->ValorUnitario->Dot = false;
    
    $concepto->Importe->Value = 0;
    $concepto->Importe->Decimals = 0;
    $concepto->Importe->Dot = false;
    
    $concepto->ObjetoImpuesto->Value = '01'; // No objeto de impuesto a nivel de concepto general

    // --- Agregar Complemento de Pago ---
    $electronicDocument->Data->Complementos->add(Complemento::RECEPCIONPAGO);
    
    /** @var \Facturando\ElectronicDocumentLibrary\Complemento\RecepcionPago\Data $compPago */
    $compPago = $electronicDocument->Data->Complementos->last();
    $compPago->Version->Value = '2.0';

    // --- Totales del Complemento ---
    $totales = $data['totales'] ?? [];
    if (isset($totales['totalRetencionesIva'])) {
        $compPago->Totales->TotalRetencionesIva->Value = $totales['totalRetencionesIva'];
    }
    if (isset($totales['totalRetencionesIsr'])) {
        $compPago->Totales->TotalRetencionesIsr->Value = $totales['totalRetencionesIsr'];
    }
    if (isset($totales['totalRetencionesIeps'])) {
        $compPago->Totales->TotalRetencionesIeps->Value = $totales['totalRetencionesIeps'];
    }
    if (isset($totales['totalTrasladosBaseIva16'])) {
        $compPago->Totales->TotalTrasladosBaseIva16->Value = $totales['totalTrasladosBaseIva16'];
    }
    if (isset($totales['totalTrasladosImpuestoIva16'])) {
        $compPago->Totales->TotalTrasladosImpuestoIva16->Value = $totales['totalTrasladosImpuestoIva16'];
    }
    if (isset($totales['totalTrasladosBaseIva8'])) {
        $compPago->Totales->TotalTrasladosBaseIva8->Value = $totales['totalTrasladosBaseIva8'];
    }
    if (isset($totales['totalTrasladosImpuestoIva8'])) {
        $compPago->Totales->TotalTrasladosImpuestoIva8->Value = $totales['totalTrasladosImpuestoIva8'];
    }
    if (isset($totales['totalTrasladosBaseIva0'])) {
        $compPago->Totales->TotalTrasladosBaseIva0->Value = $totales['totalTrasladosBaseIva0'];
    }
    if (isset($totales['totalTrasladosImpuestoIva0'])) {
        $compPago->Totales->TotalTrasladosImpuestoIva0->Value = $totales['totalTrasladosImpuestoIva0'];
    }
    if (isset($totales['totalTrasladosBaseIvaExento'])) {
        $compPago->Totales->TotalTrasladosBaseIvaExento->Value = $totales['totalTrasladosBaseIvaExento'];
    }
    $compPago->Totales->MontoTotalPagos->Value = $totales['montoTotalPagos'] ?? 0;

    // --- Pagos ---
    if (!isset($data['pagos']) || !is_array($data['pagos'])) {
        throw new Exception('Se requiere al menos un pago en el arreglo "pagos".');
    }

    foreach ($data['pagos'] as $p) {
        /** @var \Facturando\ElectronicDocumentLibrary\Complemento\RecepcionPago\Pago $pago */
        $pago = $compPago->Pagos->add();
        
        $pago->FechaPago->Value = isset($p['fechaPago']) ? new \DateTime($p['fechaPago']) : new \DateTime('NOW -5 hours');
        $pago->FormaPago->Value = $p['formaPago'];
        $pago->Moneda->Value = $p['moneda'];
        $pago->Monto->Value = $p['monto'];
        
        // Tipo de Cambio
        if (isset($p['tipoCambio'])) {
            $pago->TipoCambio->Value = $p['tipoCambio'];
            if ($p['tipoCambio'] == 1) {
                $pago->TipoCambio->Dot = false;
                $pago->TipoCambio->Decimals = 0;
            }
        }
        
        // Bancos / Cuentas (Opcional)
        if (!empty($p['rfcEmisorCuentaOrdenante'])) {
            $pago->RfcEmisorCuentaOrdenante->Value = $p['rfcEmisorCuentaOrdenante'];
        }
        if (!empty($p['nombreBancoOrdenanteExtrajero'])) {
            $pago->NombreBancoOrdenanteExtrajero->Value = $p['nombreBancoOrdenanteExtrajero'];
        }
        if (!empty($p['cuentaOrdenante'])) {
            $pago->CuentaOrdenante->Value = $p['cuentaOrdenante'];
        }
        if (!empty($p['rfcEmisorCuentaBeneficiario'])) {
            $pago->RfcEmisorCuentaBeneficiario->Value = $p['rfcEmisorCuentaBeneficiario'];
        }
        if (!empty($p['cuentaBeneficiario'])) {
            $pago->CuentaBeneficiario->Value = $p['cuentaBeneficiario'];
        }
        if (!empty($p['tipoCadenaPago'])) {
            $pago->TipoCadenaPago->Value = $p['tipoCadenaPago'];
        }
        if (!empty($p['certificadoPago'])) {
            $pago->CertificadoPago->Value = $p['certificadoPago'];
        }
        if (!empty($p['cadenaOriginalPago'])) {
            $pago->CadenaOriginalPago->Value = $p['cadenaOriginalPago'];
        }
        if (!empty($p['selloPago'])) {
            $pago->SelloPago->Value = $p['selloPago'];
        }

        // Documentos Relacionados
        if (isset($p['documentosRelacionados']) && is_array($p['documentosRelacionados'])) {
            foreach ($p['documentosRelacionados'] as $doc) {
                /** @var \Facturando\ElectronicDocumentLibrary\Complemento\RecepcionPago\DocumentoRelacionado $documentoRelacionado */
                $documentoRelacionado = $pago->DocumentosRelacionados->add();
                
                $documentoRelacionado->IdDocumento->Value = $doc['idDocumento'];
                $documentoRelacionado->Moneda->Value = $doc['moneda'];
                $documentoRelacionado->NumeroParcialidad->Value = $doc['numeroParcialidad'];
                $documentoRelacionado->ImporteSaldoAnterior->Value = $doc['importeSaldoAnterior'];
                $documentoRelacionado->ImportePagado->Value = $doc['importePagado'];
                $documentoRelacionado->ImporteSaldoInsoluto->Value = $doc['importeSaldoInsoluto'];
                $documentoRelacionado->ObjetoImpuesto->Value = $doc['objetoImpuesto'];
                
                if (!empty($doc['serie'])) {
                    $documentoRelacionado->Serie->Value = $doc['serie'];
                }
                if (!empty($doc['folio'])) {
                    $documentoRelacionado->Folio->Value = $doc['folio'];
                }
                if (isset($doc['equivalencia'])) {
                    $documentoRelacionado->Equivalencia->Value = $doc['equivalencia'];
                    if ($doc['equivalencia'] == 1) {
                        $documentoRelacionado->Equivalencia->Dot = false;
                        $documentoRelacionado->Equivalencia->Decimals = 0;
                    }
                }

                // Impuestos detallados a nivel de Documento Relacionado
                if ($doc['objetoImpuesto'] === '02' && isset($doc['impuestos'])) {
                    $docImp = $doc['impuestos'];
                    
                    if (isset($docImp['traslados']) && is_array($docImp['traslados'])) {
                        foreach ($docImp['traslados'] as $tr) {
                            /** @var \Facturando\ElectronicDocumentLibrary\Complemento\RecepcionPago\TrasladoDocumento $trasladoDoc */
                            $trasladoDoc = $documentoRelacionado->Impuestos->Traslados->add();
                            $trasladoDoc->Base->Value = $tr['base'];
                            $trasladoDoc->Impuesto->Value = $tr['impuesto'];
                            $trasladoDoc->TipoFactor->Value = $tr['tipoFactor'];
                            $trasladoDoc->TasaCuota->Value = $tr['tasaCuota'];
                            $trasladoDoc->Importe->Value = $tr['importe'];
                        }
                    }
                    if (isset($docImp['retenciones']) && is_array($docImp['retenciones'])) {
                        foreach ($docImp['retenciones'] as $rt) {
                            /** @var \Facturando\ElectronicDocumentLibrary\Complemento\RecepcionPago\RetencionDocumento $retencionDoc */
                            $retencionDoc = $documentoRelacionado->Impuestos->Retenciones->add();
                            $retencionDoc->Base->Value = $rt['base'];
                            $retencionDoc->Impuesto->Value = $rt['impuesto'];
                            $retencionDoc->TipoFactor->Value = $rt['tipoFactor'];
                            $retencionDoc->TasaCuota->Value = $rt['tasaCuota'];
                            $retencionDoc->Importe->Value = $rt['importe'];
                        }
                    }
                }
            }
        }

        // Impuestos agrupados del Pago
        if (isset($p['impuestos']) && is_array($p['impuestos'])) {
            $pagoImp = $p['impuestos'];
            
            /** @var \Facturando\ElectronicDocumentLibrary\Complemento\RecepcionPago\Impuesto $impuestoPago */
            $impuestoPago = $pago->Impuestos->add();
            
            if (isset($pagoImp['traslados']) && is_array($pagoImp['traslados'])) {
                foreach ($pagoImp['traslados'] as $trP) {
                    /** @var \Facturando\ElectronicDocumentLibrary\Complemento\RecepcionPago\Traslado $trasladoPago */
                    $trasladoPago = $impuestoPago->Traslados->add();
                    $trasladoPago->Base->Value = $trP['base'];
                    $trasladoPago->Impuesto->Value = $trP['impuesto'];
                    $trasladoPago->TipoFactor->Value = $trP['tipoFactor'];
                    $trasladoPago->TasaCuota->Value = $trP['tasaCuota'];
                    $trasladoPago->Importe->Value = $trP['importe'];
                }
            }
            
            if (isset($pagoImp['retenciones']) && is_array($pagoImp['retenciones'])) {
                foreach ($pagoImp['retenciones'] as $rtP) {
                    /** @var \Facturando\ElectronicDocumentLibrary\Complemento\RecepcionPago\Retencion $retencionPago */
                    $retencionPago = $impuestoPago->Retenciones->add();
                    $retencionPago->Impuesto->Value = $rtP['impuesto'];
                    $retencionPago->Importe->Value = $rtP['importe'];
                }
            }
        }
    }

    // --- Timbrado con el PAC (Ecodex) ---
    $parameters = new Parameters();
    $parameters->Rfc = $data['emisor']['rfc'];
    $parameters->Usuario = ID_INTEGRADOR;
    
    if (!isset($data['max_id']) || !is_numeric($data['max_id'])) {
        $parameters->IdTransaccion = PHP_INT_MAX;
    } else {
        $parameters->IdTransaccion = (int)$data['max_id'] + 1;
    }
    $parameters->ElectronicDocument = $electronicDocument;

    $ecodex = new Proveedor();
    $result = $ecodex->TimbrarCfdi($parameters);

    ob_clean();

    if ($result == ProcessProviderResult::OK) {
        $electronicDocument->Manage->Save->Options->Validations = false;
        $electronicDocument->saveToString($xml);

        // Intentar obtener UUID desde la respuesta del PAC
        $uuidValue = is_string($parameters->Information->Timbre->Uuid)
            ? $parameters->Information->Timbre->Uuid
            : null;

        // Si no está en el objeto, buscarlo en el XML
        if (empty($uuidValue) && is_string($xml)) {
            try {
                $xmlObj = new SimpleXMLElement($xml);
                $xmlObj->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
                $xmlObj->registerXPathNamespace('tfd', 'http://www.sat.gob.mx/TimbreFiscalDigital');
                $nodes = $xmlObj->xpath('/cfdi:Comprobante/cfdi:Complemento/tfd:TimbreFiscalDigital/@UUID');

                if ($nodes && count($nodes) > 0) {
                    $uuidValue = (string) $nodes[0];
                }
            } catch (Exception $e) {
                $uuidValue = null;
            }
        }

        $xmlValue = is_string($xml) ? $xml : null;
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'uuid' => $uuidValue,
            'id_transaccion' => $parameters->IdTransaccion,
            'xml' => base64_encode($xmlValue)
        ]);
        exit();

    } else {
        throw new Exception('Error del PAC: ' . 
            $parameters->Information->Error->Tipo->Value . ' - ' . 
            $parameters->Information->Error->Numero->Value . ' - ' . 
            $parameters->Information->Error->Descripcion->Value
        );
    }

} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Error al procesar el recibo de pago.',
        'details' => $e->getMessage()
    ]);
}
