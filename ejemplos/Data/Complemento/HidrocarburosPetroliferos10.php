<?php

namespace Facturando\EDL\Example\Data\Complemento;

use Facturando\ElectronicDocumentLibrary\Base\Types\ComplementoConcepto;
use Facturando\ElectronicDocumentLibrary\Document\Data;
use Facturando\ElectronicDocumentLibrary\Document\Data\Concepto;
use Facturando\ElectronicDocumentLibrary\Document\Data\Traslado;
use Facturando\ElectronicDocumentLibrary\Document\Data\TrasladoConcepto;
use Facturando\ElectronicDocumentLibrary\Document\ElectronicDocument;

final class HidrocarburosPetroliferos10
{
    /**
     * @param ElectronicDocument $electronicDocument
     */
    public static function CargarDatosCompleto($electronicDocument)
    {
        self::CargarDatosTimbrado($electronicDocument);
    }

    /**
     * @param ElectronicDocument $electronicDocument
     */
    public static function CargarDatosTimbrado($electronicDocument)
    {
        //En este método se cargan los datos del la parte del CFDI
        self::CargarDatosCfdi40($electronicDocument->Data);

        // Tomamos el concepto al que se le va a agregar el complemento
        /** @var Concepto $concepto */
        $concepto = $electronicDocument->Data->Conceptos->Items(0);

        $concepto->Complementos->add(ComplementoConcepto::HIDROCARBUROS_PETROLIFEROS);

        /** @var \Facturando\ElectronicDocumentLibrary\Complemento\Conceptos\HidrocarburosPetroliferos\Data $data */
        $data = $concepto->Complementos->last();

        $data->Version->Value = '1.0';
        $data->TipoPermiso->Value = 'PER11';
        $data->NumeroPermiso->Value = 'PL/364/DIS/OM/2015';
        $data->ClaveHyP->Value = '15101514';
        $data->SubProductoHyP->Value = 'SP16';
    }

    /**
     * @param Data\Data $data
     */
    private static function CargarDatosCfdi40($data)
    {

        $data->clear();

        //<editor-fold desc="Datos del comprobante">
        $data->Version->Value = '4.0';
        $data->Serie->Value = 'CFDI';
        $data->Folio->Value = '40';
        $data->FormaPago->Value = '04';
        $data->SubTotal->Value = 763.29;
        $data->Moneda->Value = 'MXN';

        $data->TipoCambioMx->Value = 1.00;
        $data->TipoCambioMx->Dot = false;
        $data->TipoCambioMx->Decimals = 0;

        $data->Total->Value = 881.94;
        $data->TipoComprobante->Value = 'I';
        $data->Exportacion->Value = '01';
        $data->MetodoPago->Value = 'PUE';
        $data->LugarExpedicion->Value = '89400';

        // Este código solo es para este ejemplo y se agregó
        // para evitar problemas cuando la computadora del programador
        // tiene una hora superior a la del servidor del PAC
        $data->Fecha->Value = new \DateTime('NOW -5 hours');
        //</editor-fold>

        //<editor-fold desc="Datos del emisor">
        $data->Emisor->Rfc->Value = 'EKU9003173C9';
        $data->Emisor->Nombre->Value = 'ESCUELA KEMPER URGATE SA DE CV';
        $data->Emisor->RegimenFiscal->Value = '601';
        //</editor-fold>

        //<editor-fold desc="Datos del Receptor">
        $data->Receptor->Rfc->Value = 'AAAD770905441';
        $data->Receptor->Nombre->Value = 'DARIO ALVAREZ ARANDA';
        $data->Receptor->DomicilioFiscalReceptor->Value = '07300';
        $data->Receptor->RegimenFiscalReceptor->Value = '612';
        $data->Receptor->UsoCfdi->Value = 'G03';
        //</editor-fold>

        //<editor-fold desc="Concepto">
        /** @var Concepto $concepto */
        $concepto = $data->Conceptos->add();
        $concepto->ClaveProductoServicio->Value = '15101514';
        $concepto->NumeroIdentificacion->Value = 'PL/6598/EXP/ES/2015-4003339';
        $concepto->Cantidad->Value = 36.7628;
        $concepto->ClaveUnidad->Value = 'LTR';
        $concepto->Unidad->Value = 'Litros';
        $concepto->Descripcion->Value = '32011->MAGNA';
        $concepto->ValorUnitario->Value = 20.7626;
        $concepto->Importe->Value = 763.29;
        $concepto->ObjetoImpuesto->Value = '02';

        //<editor-fold desc="Impuestos trasladados del concepto">
        /** @var TrasladoConcepto $trasladoConcepto */
        $trasladoConcepto = $concepto->Impuestos->Traslados->add();
        $trasladoConcepto->Base->Value = 741.5507;
        $trasladoConcepto->Impuesto->Value = '002';
        $trasladoConcepto->TipoFactor->Value = 'Tasa';
        $trasladoConcepto->TasaCuota->Value = 0.160000;
        $trasladoConcepto->Importe->Value = 118.65;
        //</editor-fold>
        //</editor-fold>

        //<editor-fold desc="Impuestos trasladados">
        /** @var Traslado $traslado */
        $traslado = $data->Impuestos->Traslados->add();
        $traslado->Base->Value = 741.55;
        $traslado->Tipo->Value = '002';
        $traslado->TipoFactor->Value = 'Tasa';
        $traslado->TasaCuota->Value = 0.160000;
        $traslado->Importe->Value = 118.65;
        //</editor-fold>

        $data->Impuestos->TotalTraslados->Value = 118.65;
    }
}