<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Boleta;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\DailySummary;
use App\Models\DispatchGuide;
use App\Models\Retention;
use App\Models\VoidedDocument;
use App\Services\GreenterService;
use App\Services\FileService;
use App\Services\PdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class DocumentService
{
    protected $fileService;
    protected $pdfService;

    public function __construct(FileService $fileService, PdfService $pdfService)
    {
        $this->fileService = $fileService;
        $this->pdfService = $pdfService;
    }

    public function createInvoice(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            // Validar y obtener entidades
            $company = Company::findOrFail($data['company_id']);
            $branch = Branch::where('company_id', $company->id)
                           ->where('id', $data['branch_id'])
                           ->firstOrFail();
            
            // Crear o buscar cliente
            $client = $this->getOrCreateClient($data['client']);
            
            // Obtener siguiente correlativo
            $serie = $data['serie'];
            $correlativo = $branch->getNextCorrelative('01', $serie);
            
            // Preparar datos globales para cálculos
            $globalData = [
                'descuentos' => $data['descuentos'] ?? [],
                'anticipos' => $data['anticipos'] ?? [],
                'redondeo' => $data['redondeo'] ?? 0,
            ];
            
            // Procesar detalles según tipo de operación antes de calcular totales
            $tipoOperacion = $data['tipo_operacion'] ?? '0101';
            $this->processDetailsForOperationType($data['detalles'], $tipoOperacion);
            
            // Calcular totales automáticamente
            $totals = $this->calculateTotals($data['detalles'], $globalData);
            
            // Crear factura
            $invoice = Invoice::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'client_id' => $client->id,
                'tipo_documento' => '01',
                'serie' => $serie,
                'correlativo' => $correlativo,
                'numero_completo' => $serie . '-' . $correlativo,
                'fecha_emision' => $data['fecha_emision'],
                'fecha_vencimiento' => $data['fecha_vencimiento'] ?? null,
                'ubl_version' => $data['ubl_version'] ?? '2.1',
                'tipo_operacion' => $data['tipo_operacion'] ?? '0101',
                'moneda' => $data['moneda'] ?? 'PEN',
                'forma_pago_tipo' => $data['forma_pago_tipo'] ?? 'Contado',
                'forma_pago_cuotas' => $data['forma_pago_cuotas'] ?? null,
                'valor_venta' => $totals['valor_venta'],
                'mto_oper_gravadas' => $tipoOperacion === '0200' ? 0 : $totals['mto_oper_gravadas'],
                'mto_oper_exoneradas' => $tipoOperacion === '0200' ? 0 : $totals['mto_oper_exoneradas'],
                'mto_oper_inafectas' => $tipoOperacion === '0200' ? 0 : $totals['mto_oper_inafectas'],
                'mto_oper_exportacion' => $totals['mto_oper_exportacion'],
                'mto_oper_gratuitas' => $totals['mto_oper_gratuitas'],
                'mto_igv_gratuitas' => $totals['mto_igv_gratuitas'],
                'mto_igv' => $totals['mto_igv'],
                'mto_base_ivap' => $totals['mto_base_ivap'],
                'mto_ivap' => $totals['mto_ivap'],
                'mto_isc' => $totals['mto_isc'],
                'mto_icbper' => $totals['mto_icbper'],
                'mto_otros_tributos' => $totals['mto_otros_tributos'],
                'total_impuestos' => $totals['total_impuestos'],
                'sub_total' => $totals['sub_total'],
                'mto_imp_venta' => $totals['mto_imp_venta'],
                'redondeo' => $totals['redondeo'],
                'total_anticipos' => $totals['total_anticipos'],
                'descuento_global' => $totals['descuento_global'],
                'detalles' => $data['detalles'],
                'leyendas' => $this->generateLegends($totals['mto_imp_venta'], $data['moneda'] ?? 'PEN', $data),
                'guias' => $data['guias'] ?? null,
                'documentos_relacionados' => $data['documentos_relacionados'] ?? null,
                'datos_adicionales' => $data['datos_adicionales'] ?? null,
                'descuentos' => $data['descuentos'] ?? null,
                'anticipos' => $data['anticipos'] ?? null,
                'detraccion' => $data['detraccion'] ?? null,
                'percepcion' => $data['percepcion'] ?? null,
                'retencion' => $data['retencion'] ?? null,
                'usuario_creacion' => $data['usuario_creacion'] ?? null,
            ]);

            return $invoice;
        });
    }

    public function createBoleta(array $data): Boleta
    {
        return DB::transaction(function () use ($data) {
            // Validar y obtener entidades
            $company = Company::findOrFail($data['company_id']);
            $branch = Branch::where('company_id', $company->id)
                           ->where('id', $data['branch_id'])
                           ->firstOrFail();
            
            // Crear o buscar cliente
            $client = $this->getOrCreateClient($data['client']);
            
            // Obtener siguiente correlativo
            $serie = $data['serie'];
            $correlativo = $branch->getNextCorrelative('03', $serie);
            
            // Preparar datos globales para cálculos
            $globalData = [
                'descuentos' => $data['descuentos'] ?? [],
                'anticipos' => $data['anticipos'] ?? [],
                'redondeo' => $data['redondeo'] ?? 0,
            ];
            
            // Calcular totales automáticamente (esto modifica $data['detalles'] por referencia)
            $totals = $this->calculateTotals($data['detalles'], $globalData);
            
            // Crear boleta
            $boleta = Boleta::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'client_id' => $client->id,
                'tipo_documento' => '03',
                'serie' => $serie,
                'correlativo' => $correlativo,
                'numero_completo' => $serie . '-' . $correlativo,
                'fecha_emision' => $data['fecha_emision'],
                'ubl_version' => $data['ubl_version'] ?? '2.1',
                'tipo_operacion' => $data['tipo_operacion'] ?? '0101',
                'moneda' => $data['moneda'] ?? 'PEN',
                'metodo_envio' => $data['metodo_envio'] ?? 'individual',
                'valor_venta' => $totals['valor_venta'],
                'mto_oper_gravadas' => $totals['mto_oper_gravadas'],
                'mto_oper_exoneradas' => $totals['mto_oper_exoneradas'],
                'mto_oper_inafectas' => $totals['mto_oper_inafectas'],
                'mto_oper_gratuitas' => $totals['mto_oper_gratuitas'],
                'mto_igv_gratuitas' => $totals['mto_igv_gratuitas'],
                'mto_igv' => $totals['mto_igv'],
                'mto_base_ivap' => $totals['mto_base_ivap'],
                'mto_ivap' => $totals['mto_ivap'],
                'mto_isc' => $totals['mto_isc'],
                'mto_icbper' => $totals['mto_icbper'],
                'total_impuestos' => $totals['total_impuestos'],
                'sub_total' => $totals['sub_total'],
                'mto_imp_venta' => $totals['mto_imp_venta'],
                'detalles' => $data['detalles'],
                'leyendas' => $this->generateLegends($totals['mto_imp_venta'], $data['moneda'] ?? 'PEN', $data),
                'datos_adicionales' => $data['datos_adicionales'] ?? null,
                'usuario_creacion' => $data['usuario_creacion'] ?? null,
            ]);

            return $boleta;
        });
    }

    public function sendToSunat($document, string $documentType): array
    {
        try {
            $company = $document->company;
            $greenterService = new GreenterService($company);
            
            // Preparar datos para Greenter
            $documentData = $this->prepareDocumentData($document, $documentType);
            
            // Crear documento Greenter
            $greenterDocument = null;
            switch ($documentType) {
                case 'invoice':
                    $greenterDocument = $greenterService->createInvoice($documentData);
                    break;
                case 'boleta':
                    $greenterDocument = $greenterService->createInvoice($documentData); // Boleta usa Invoice
                    break;
                case 'credit_note':
                case 'debit_note':
                    $greenterDocument = $greenterService->createNote($documentData);
                    break;
            }
            
            if (!$greenterDocument) {
                throw new Exception('No se pudo crear el documento para Greenter');
            }
            
            // Enviar a SUNAT
            $result = $greenterService->sendDocument($greenterDocument);
            
            // Guardar archivos
            if ($result['xml']) {
                $xmlPath = $this->fileService->saveXml($document, $result['xml']);
                $document->xml_path = $xmlPath;
            }
            
            if ($result['success'] && $result['cdr_zip']) {
                $cdrPath = $this->fileService->saveCdr($document, $result['cdr_zip']);
                $document->cdr_path = $cdrPath;
                
                $document->estado_sunat = 'ACEPTADO';
                $document->respuesta_sunat = json_encode([
                    'id' => $result['cdr_response']->getId(),
                    'code' => $result['cdr_response']->getCode(),
                    'description' => $result['cdr_response']->getDescription(),
                    'notes' => $result['cdr_response']->getNotes(),
                ]);
                
                // Obtener hash del XML
                $xmlSigned = $greenterService->getXmlSigned($greenterDocument);
                if ($xmlSigned) {
                    $document->codigo_hash = $this->extractHashFromXml($xmlSigned);
                }
            } else {
                $document->estado_sunat = 'RECHAZADO';
                
                // Manejar diferentes tipos de error
                $errorCode = 'UNKNOWN';
                $errorMessage = 'Error desconocido';
                
                if (is_object($result['error'])) {
                    if (method_exists($result['error'], 'getCode')) {
                        $errorCode = $result['error']->getCode();
                    } elseif (property_exists($result['error'], 'code')) {
                        $errorCode = $result['error']->code;
                    }
                    
                    if (method_exists($result['error'], 'getMessage')) {
                        $errorMessage = $result['error']->getMessage();
                    } elseif (property_exists($result['error'], 'message')) {
                        $errorMessage = $result['error']->message;
                    }
                }
                
                $document->respuesta_sunat = json_encode([
                    'code' => $errorCode,
                    'message' => $errorMessage,
                ]);
            }
            
            $document->save();
            
            return [
                'success' => $result['success'],
                'document' => $document,
                'error' => $result['success'] ? null : $result['error']
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'document' => $document,
                'error' => (object)[
                    'code' => 'EXCEPTION',
                    'message' => $e->getMessage()
                ]
            ];
        }
    }

    protected function getOrCreateClient(array $clientData): Client
    {
        return Client::firstOrCreate([
            'tipo_documento' => $clientData['tipo_documento'],
            'numero_documento' => $clientData['numero_documento'],
        ], [
            'razon_social' => $clientData['razon_social'],
            'nombre_comercial' => $clientData['nombre_comercial'] ?? null,
            'direccion' => $clientData['direccion'] ?? null,
            'ubigeo' => $clientData['ubigeo'] ?? null,
            'distrito' => $clientData['distrito'] ?? null,
            'provincia' => $clientData['provincia'] ?? null,
            'departamento' => $clientData['departamento'] ?? null,
            'telefono' => $clientData['telefono'] ?? null,
            'email' => $clientData['email'] ?? null,
        ]);
    }

    protected function calculateTotals(array &$detalles, array $globalData = []): array
    {
        $totals = [
            'valor_venta' => 0,
            'mto_oper_gravadas' => 0,
            'mto_oper_exoneradas' => 0,
            'mto_oper_inafectas' => 0,
            'mto_oper_exportacion' => 0,
            'mto_oper_gratuitas' => 0,
            'mto_igv_gratuitas' => 0,
            'mto_igv' => 0,
            'mto_base_ivap' => 0,  // Base imponible IVAP
            'mto_ivap' => 0,       // Monto IVAP
            'mto_isc' => 0,
            'mto_icbper' => 0,
            'mto_otros_tributos' => 0,
            'total_impuestos' => 0,
            'sub_total' => 0,
            'mto_imp_venta' => 0,
            'redondeo' => 0,
        ];

        foreach ($detalles as &$detalle) {
            $cantidad = $detalle['cantidad'];
            $mtoValorUnitario = $detalle['mto_valor_unitario'];
            $porcentajeIgv = $detalle['porcentaje_igv'] ?? 18;
            $tipAfeIgv = $detalle['tip_afe_igv'];

            // Calcular valores automáticamente - aplicar descuentos por línea primero
            $mtoValorVenta = round($cantidad * $mtoValorUnitario, 2);

            // Aplicar descuentos por línea si existen
            if (isset($detalle['descuentos']) && is_array($detalle['descuentos'])) {
                foreach ($detalle['descuentos'] as $descuento) {
                    $montoDescuento = $descuento['monto'] ?? 0;
                    $mtoValorVenta -= $montoDescuento;
                }
            }

            // Calcular ISC si existe
            $isc = 0;
            $mtoBaseIsc = 0;
            if (isset($detalle['tip_sis_isc']) && isset($detalle['porcentaje_isc'])) {
                $mtoBaseIsc = $mtoValorVenta;
                $isc = round($mtoBaseIsc * ($detalle['porcentaje_isc'] / 100), 2);
                $detalle['mto_base_isc'] = $mtoBaseIsc;
                $detalle['isc'] = $isc;
            }

            // Calcular ICBPER si existe
            $icbper = 0;
            if (isset($detalle['factor_icbper'])) {
                $icbper = round($cantidad * $detalle['factor_icbper'], 2);
                $detalle['icbper'] = $icbper;
            }

            // Base para IGV incluye ISC
            $mtoBaseIgv = $mtoValorVenta + $isc;

            // Calcular IGV según tipo de afectación
            $igv = 0;
            if ($tipAfeIgv === '10') { // Gravado - paga IGV
                $igv = round($mtoBaseIgv * ($porcentajeIgv / 100), 2);
            } elseif ($tipAfeIgv === '17') { // IVAP - paga IVAP (normalmente 2%)
                $porcentajeIvap = $detalle['porcentaje_ivap'] ?? $porcentajeIgv ?? 2; // Usar porcentaje_ivap específico
                $igv = round($mtoBaseIgv * ($porcentajeIvap / 100), 2);
            }
            // Para '20' (exonerado), '30' (inafecto), '40' (exportación): IGV = 0 pero base = valor_venta

            $totalImpuestos = $igv + $isc + $icbper;
            $mtoPrecioUnitario = $mtoValorUnitario;
            if ($tipAfeIgv === '10' || $tipAfeIgv === '17') { // Incluir IVAP
                $mtoPrecioUnitario = round(($mtoValorVenta + $totalImpuestos) / $cantidad, 2);
            }

            // Manejar operaciones gratuitas (EXCLUIR '17' que es IVAP, NO gratuito)
            if (in_array($tipAfeIgv, ['11', '12', '13', '14', '15', '16', '31', '32', '33', '34', '35', '36'])) {
                $mtoValorGratuito = $detalle['mto_valor_gratuito'] ?? $detalle['mto_valor_unitario'];
                $mtoValorVenta = $cantidad * $mtoValorGratuito;
                $mtoBaseIgv = $mtoValorVenta;

                if (in_array($tipAfeIgv, ['11', '12', '13', '14', '15', '16'])) {
                    // Calcular IGV EXACTO para cada línea
                    $igv = round($mtoValorVenta * ($porcentajeIgv / 100), 2);

                    // Guardar en el detalle
                    $detalle['igv'] = $igv;
                    $detalle['total_impuestos'] = $igv;

                    // Acumular para el total
                    $totals['mto_igv_gratuitas'] += $igv;
                } else {
                    $igv = 0;
                    $detalle['igv'] = 0;
                    $detalle['total_impuestos'] = 0;
                }

                // Completar datos del detalle
                $detalle['mto_valor_venta'] = $mtoValorVenta;
                $detalle['mto_base_igv'] = $mtoBaseIgv;
                $detalle['mto_valor_gratuito'] = $mtoValorGratuito;
                $detalle['mto_precio_unitario'] = 0;

                // Acumular operaciones gratuitas
                $totals['mto_oper_gratuitas'] += $mtoValorVenta;

                // Saltar el resto del cálculo para gratuitas
                continue;
            }

            // Completar datos del detalle
            $detalle['mto_valor_venta'] = $mtoValorVenta;
            $detalle['mto_base_igv'] = $mtoBaseIgv;
            $detalle['igv'] = $igv;
            $detalle['total_impuestos'] = $totalImpuestos;
            $detalle['mto_precio_unitario'] = $mtoPrecioUnitario;
            $detalle['isc'] = $isc;
            $detalle['icbper'] = $icbper;

            // Acumular totales
            $totals['mto_isc'] += $isc;
            $totals['mto_icbper'] += $icbper;

            // Clasificar según tipo de afectación IGV
            switch ($tipAfeIgv) {
                case '10': // Gravado - IGV
                    $totals['mto_oper_gravadas'] += $mtoValorVenta;
                    $totals['valor_venta'] += $mtoValorVenta;
                    $totals['mto_igv'] += $igv;
                    break;
                case '17': // Gravado - IVAP
                    $totals['mto_base_ivap'] += $mtoValorVenta;  // Base IVAP específica
                    $totals['mto_ivap'] += $igv;                 // Monto IVAP específico
                    $totals['valor_venta'] += $mtoValorVenta;    // Valor venta total
                    // NO acumular en mto_oper_gravadas ni mto_igv para IVAP
                    break;
                case '20': // Exonerado
                    $totals['mto_oper_exoneradas'] += $mtoValorVenta;
                    $totals['valor_venta'] += $mtoValorVenta;
                    break;
                case '30': // Inafecto
                    $totals['mto_oper_inafectas'] += $mtoValorVenta;
                    $totals['valor_venta'] += $mtoValorVenta;
                    break;
                case '40': // Exportación
                    $totals['mto_oper_exportacion'] += $mtoValorVenta;
                    $totals['valor_venta'] += $mtoValorVenta;
                    break;
            }
        }

        // Aplicar descuentos globales si existen
        $descuentoGlobal = 0;
        if (isset($globalData['descuentos']) && is_array($globalData['descuentos'])) {
            foreach ($globalData['descuentos'] as $descuento) {
                $descuentoGlobal += $descuento['monto'] ?? 0;
            }
            $totals['mto_oper_gravadas'] -= $descuentoGlobal;
            $totals['valor_venta'] -= $descuentoGlobal;
        }

        // Aplicar anticipos si existen
        $totalAnticipos = 0;
        if (isset($globalData['anticipos']) && is_array($globalData['anticipos'])) {
            foreach ($globalData['anticipos'] as $anticipo) {
                $totalAnticipos += $anticipo['total'] ?? 0;
            }
        }

        // Para facturas puramente gratuitas, total_impuestos debe ser 0
        // Para facturas mixtas o normales, incluir todos los impuestos
        if ($totals['valor_venta'] == 0 && $totals['mto_oper_gratuitas'] > 0) {
            // Factura puramente gratuita: total_impuestos = 0
            $totals['total_impuestos'] = 0;
            $totals['sub_total'] = 0;
            $totals['mto_imp_venta'] = 0;
        } else {
            // Factura normal o mixta: incluir impuestos normalmente (IGV + IVAP + ISC + ICBPER)
            $totals['total_impuestos'] = $totals['mto_igv'] + $totals['mto_ivap'] + $totals['mto_isc'] + $totals['mto_icbper'];
            $totals['sub_total'] = $totals['valor_venta'] + $totals['total_impuestos'];
            $totals['mto_imp_venta'] = $totals['sub_total'] - $totalAnticipos;
        }

        // Aplicar redondeo si existe
        if (isset($globalData['redondeo'])) {
            $totals['redondeo'] = $globalData['redondeo'];
            $totals['mto_imp_venta'] += $totals['redondeo'];
        }

        // Agregar datos adicionales para casos especiales
        $totals['total_anticipos'] = $totalAnticipos;
        $totals['descuento_global'] = $descuentoGlobal;

        return $totals;
    }

    protected function processDetailsForOperationType(array &$detalles, string $tipoOperacion): void
    {
        // Para exportaciones (0200), configurar automáticamente los detalles
        if ($tipoOperacion === '0200') {
            foreach ($detalles as &$detalle) {
                $cantidad = $detalle['cantidad'];
                $valorUnitario = $detalle['mto_valor_unitario'];

                // Configuración automática para exportaciones
                $detalle['tip_afe_igv'] = '40'; // Exportación
                $detalle['porcentaje_igv'] = 0;

                // Calcular valores base
                $valorVenta = $cantidad * $valorUnitario;
                $detalle['mto_valor_venta'] = $valorVenta;
                $detalle['mto_base_igv'] = $valorVenta; // Base IGV = valor venta en exportaciones
                $detalle['igv'] = 0;
                $detalle['total_impuestos'] = 0;
                $detalle['mto_precio_unitario'] = $valorUnitario;
            }
        }
    }

    protected function generateLegends(float $total, string $moneda, array $data = []): array
    {
        $leyendas = [];
        
        // Leyenda 1000: Monto en letras
        $numeroALetras = $this->convertNumberToWords($total, $moneda);
        $leyendas[] = [
            'code' => '1000',
            'value' => $numeroALetras
        ];
        
        // Leyenda 1002: Transferencias gratuitas
        if (isset($data['detalles']) && $this->hasGratuitasItems($data['detalles'])) {
            $leyendas[] = [
                'code' => '1002',
                'value' => 'TRANSFERENCIA GRATUITA DE UN BIEN Y/O SERVICIO PRESTADO GRATUITAMENTE'
            ];
        }
        
        // Leyenda 2000: Percepción
        if (isset($data['percepcion'])) {
            $leyendas[] = [
                'code' => '2000',
                'value' => 'COMPROBANTE DE PERCEPCIÓN'
            ];
        }
        
        // Leyenda 2006: Detracción
        if (isset($data['detraccion'])) {
            $leyendas[] = [
                'code' => '2006',
                'value' => 'Operación sujeta a detracción'
            ];
        }
        
        // Leyenda 2007: IVAP (Operaciones con arroz pilado)
        if (isset($data['detalles']) && $this->hasIvapItems($data['detalles'])) {
            $leyendas[] = [
                'code' => '2007',
                'value' => 'OPERACIÓN SUJETA AL IVAP'
            ];
        }
        
        return $leyendas;
    }
    
    protected function hasGratuitasItems(array $detalles): bool
    {
        foreach ($detalles as $detalle) {
            $tipAfeIgv = $detalle['tip_afe_igv'] ?? '';
            if (in_array($tipAfeIgv, ['11', '12', '13', '14', '15', '16', '31', '32', '33', '34', '35', '36'])) { // Excluir '17' (IVAP)
                return true;
            }
        }
        return false;
    }
    
    protected function hasIvapItems(array $detalles): bool
    {
        foreach ($detalles as $detalle) {
            $tipAfeIgv = $detalle['tip_afe_igv'] ?? '';
            if ($tipAfeIgv === '17') { // IVAP
                return true;
            }
        }
        return false;
    }

    protected function convertNumberToWords(float $numero, string $moneda): string
    {
        $monedaName = $moneda === 'PEN' ? 'SOLES' : 'DÓLARES AMERICANOS';
        $entero = intval($numero);
        $decimales = intval(($numero - $entero) * 100);
        
        // Esta es una implementación básica, se puede mejorar con una librería
        $letras = $this->numeroALetras($entero);
        
        return strtoupper($letras . ' CON ' . sprintf('%02d', $decimales) . '/100 ' . $monedaName);
    }

    protected function numeroALetras($numero): string
    {
        // Implementación básica - se puede reemplazar con una librería más completa
        if ($numero == 0) return 'CERO';
        
        $unidades = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE'];
        $decenas = ['', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $especiales = ['DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE'];
        
        // Esta es una implementación muy básica
        // En producción se debe usar una librería completa
        return 'NÚMERO EN LETRAS'; // Placeholder
    }

    protected function prepareDocumentData($document, string $documentType): array
    {
        $data = $document->toArray();
        $data['client'] = $document->client->toArray();
        
        // Preparar datos globales
        $globalData = [
            'descuentos' => $data['descuentos'] ?? [],
            'anticipos' => $data['anticipos'] ?? [],
            'redondeo' => $data['redondeo'] ?? 0,
        ];
        
        // Procesar detalles para completar campos de tributos
        $detalles = $data['detalles'];
        $totals = $this->calculateTotals($detalles, $globalData); // Esto completa los campos faltantes en los detalles
        $data['detalles'] = $detalles;
        
        // Actualizar totales recalculados (crítico para operaciones gratuitas e IVAP)
        $data['mto_oper_gratuitas'] = $totals['mto_oper_gratuitas'];
        $data['mto_igv_gratuitas'] = $totals['mto_igv_gratuitas'];
        $data['mto_igv'] = $totals['mto_igv'];
        $data['mto_base_ivap'] = $totals['mto_base_ivap'];
        $data['mto_ivap'] = $totals['mto_ivap'];
        $data['total_impuestos'] = $totals['total_impuestos'];
        
        return $data;
    }

    protected function extractHashFromXml(string $xml): ?string
    {
        // Extraer el hash del XML firmado
        preg_match('/<ds:DigestValue[^>]*>([^<]+)<\/ds:DigestValue>/', $xml, $matches);
        return $matches[1] ?? null;
    }

    public function createDailySummary(array $data): DailySummary
    {
        return DB::transaction(function () use ($data) {
            // Validar y obtener entidades
            $company = Company::findOrFail($data['company_id']);
            $branch = Branch::where('company_id', $company->id)
                           ->where('id', $data['branch_id'])
                           ->firstOrFail();
            
            // Obtener siguiente correlativo para resúmenes
            $correlativo = $this->getNextSummaryCorrelative($company->id, $data['fecha_resumen']);
            
            // Crear el resumen diario
            $summary = DailySummary::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'correlativo' => $correlativo,
                'fecha_generacion' => $data['fecha_generacion'],
                'fecha_resumen' => $data['fecha_resumen'],
                'ubl_version' => $data['ubl_version'] ?? '2.1',
                'moneda' => $data['moneda'] ?? 'PEN',
                'estado_proceso' => 'GENERADO',
                'detalles' => $data['detalles'],
                'estado_sunat' => 'PENDIENTE',
                'usuario_creacion' => $data['usuario_creacion'] ?? null,
            ]);

            return $summary;
        });
    }

    public function sendDailySummaryToSunat(DailySummary $summary): array
    {
        try {
            $company = $summary->company;
            $greenterService = new GreenterService($company);
            
            // Preparar datos para Greenter
            $summaryData = $this->prepareSummaryData($summary);
            
            // Crear documento Greenter
            $greenterSummary = $greenterService->createSummary($summaryData);
            
            // Enviar a SUNAT
            $result = $greenterService->sendSummaryDocument($greenterSummary);
            
            if ($result['success']) {
                // Guardar archivos
                $xmlPath = $this->fileService->saveXml($summary, $result['xml']);
                
                // Actualizar el resumen
                $summary->update([
                    'xml_path' => $xmlPath,
                    'estado_proceso' => 'ENVIADO',
                    'estado_sunat' => 'PROCESANDO',
                    'ticket' => $result['ticket'],
                    'codigo_hash' => $this->extractHashFromXml($result['xml']),
                ]);
                
                return [
                    'success' => true,
                    'document' => $summary->fresh(),
                    'ticket' => $result['ticket']
                ];
            } else {
                // Actualizar estado de error
                $summary->update([
                    'estado_proceso' => 'ERROR',
                    'respuesta_sunat' => json_encode($result['error'])
                ]);
                
                return [
                    'success' => false,
                    'document' => $summary->fresh(),
                    'error' => $result['error']
                ];
            }
            
        } catch (Exception $e) {
            $summary->update([
                'estado_proceso' => 'ERROR',
                'respuesta_sunat' => json_encode(['message' => $e->getMessage()])
            ]);
            
            return [
                'success' => false,
                'document' => $summary->fresh(),
                'error' => (object)['message' => $e->getMessage()]
            ];
        }
    }

    public function checkSummaryStatus(DailySummary $summary): array
    {
        try {
            if (empty($summary->ticket)) {
                return [
                    'success' => false,
                    'error' => 'No hay ticket disponible para consultar'
                ];
            }
            
            $company = $summary->company;
            $greenterService = new GreenterService($company);
            
            $result = $greenterService->checkSummaryStatus($summary->ticket);
            
            if ($result['success'] && $result['cdr_response']) {
                // Guardar CDR
                $cdrPath = $this->fileService->saveCdr($summary, $result['cdr_zip']);
                
                // Actualizar estado
                $summary->update([
                    'cdr_path' => $cdrPath,
                    'estado_proceso' => 'COMPLETADO',
                    'estado_sunat' => 'ACEPTADO',
                    'respuesta_sunat' => json_encode([
                        'code' => $result['cdr_response']->getCode(),
                        'description' => $result['cdr_response']->getDescription()
                    ])
                ]);
                
                return [
                    'success' => true,
                    'document' => $summary->fresh(),
                    'cdr_response' => $result['cdr_response']
                ];
            } else {
                // Error en la consulta
                $summary->update([
                    'estado_proceso' => 'ERROR',
                    'estado_sunat' => 'RECHAZADO',
                    'respuesta_sunat' => json_encode($result['error'])
                ]);
                // Extraer el mensaje de error apropiadamente
                $errorMessage = 'Error desconocido';
                if (isset($result['error'])) {
                    if (is_object($result['error'])) {
                        if (method_exists($result['error'], 'getMessage')) {
                            $errorMessage = $result['error']->getMessage();
                        } elseif (property_exists($result['error'], 'message')) {
                            $errorMessage = $result['error']->message;
                        } else {
                            $errorMessage = json_encode($result['error']);
                        }
                    } elseif (is_string($result['error'])) {
                        $errorMessage = $result['error'];
                    } elseif (is_array($result['error'])) {
                        $errorMessage = json_encode($result['error']);
                    }
                }
                return [
                    'success' => false,
                    'document' => $summary->fresh(),
                    'error' => $errorMessage
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function createSummaryFromBoletas(array $data): DailySummary
    {
        return DB::transaction(function () use ($data) {
            // Obtener las boletas por rango de fechas y empresa
            $boletas = Boleta::where('company_id', $data['company_id'])
                            ->where('branch_id', $data['branch_id'])
                            ->whereDate('fecha_emision', $data['fecha_resumen'])
                            ->where('estado_sunat', 'PENDIENTE')
                            ->whereNull('daily_summary_id') // Solo boletas no incluidas en resumen
                            ->get();
            
            if ($boletas->isEmpty()) {
                throw new Exception('No hay boletas pendientes para la fecha seleccionada');
            }
            
            // Crear detalles del resumen basados en las boletas
            $detalles = [];
            foreach ($boletas as $boleta) {
                $detalles[] = [
                    'tipo_documento' => $boleta->tipo_documento,
                    'serie_numero' => $boleta->serie . '-' . $boleta->correlativo,
                    'estado' => '1', // Estado 1 = Adición
                    'cliente_tipo' => $boleta->client->tipo_documento ?? '1',
                    'cliente_numero' => $boleta->client->numero_documento ?? '00000000',
                    'total' => $boleta->mto_imp_venta,
                    'mto_oper_gravadas' => $boleta->mto_oper_gravadas,
                    'mto_oper_exoneradas' => $boleta->mto_oper_exoneradas,
                    'mto_oper_inafectas' => $boleta->mto_oper_inafectas,
                    'mto_oper_gratuitas' => $boleta->mto_oper_gratuitas,
                    'mto_igv' => $boleta->mto_igv,
                    'mto_isc' => $boleta->mto_isc ?? 0,
                    'mto_icbper' => $boleta->mto_icbper ?? 0,
                ];
            }
            
            // Datos para el resumen
            $summaryData = array_merge($data, [
                'detalles' => $detalles,
                'fecha_generacion' => now()->toDateString(),
            ]);
            
            // Crear el resumen
            $summary = $this->createDailySummary($summaryData);
            
            // Vincular boletas al resumen
            foreach ($boletas as $boleta) {
                $boleta->update(['daily_summary_id' => $summary->id]);
            }
            
            return $summary;
        });
    }

    protected function prepareSummaryData(DailySummary $summary): array
    {
        // Forzar fecha de generación como hoy para evitar problemas de zona horaria
        $fechaGeneracion = now()->format('Y-m-d');
        $fechaResumen = $summary->fecha_resumen->toDateString();
        
        return [
            'fecha_generacion' => $fechaGeneracion,
            'fecha_resumen' => $fechaResumen,
            'correlativo' => $summary->correlativo,
            'detalles' => $summary->detalles,
        ];
    }

    protected function getNextSummaryCorrelative(int $companyId, string $fechaResumen): string
    {
        // Obtener el último correlativo para la fecha de resumen
        $lastSummary = DailySummary::where('company_id', $companyId)
                                  ->whereDate('fecha_resumen', $fechaResumen)
                                  ->orderBy('correlativo', 'desc')
                                  ->first();
        
        if (!$lastSummary) {
            return '001';
        }
        
        $nextCorrelativo = intval($lastSummary->correlativo) + 1;
        return str_pad($nextCorrelativo, 3, '0', STR_PAD_LEFT);
    }

    public function createCreditNote(array $data): CreditNote
    {
        return DB::transaction(function () use ($data) {
            // Validar y obtener entidades
            $company = Company::findOrFail($data['company_id']);
            $branch = Branch::where('company_id', $company->id)
                           ->where('id', $data['branch_id'])
                           ->firstOrFail();
            
            // Crear o buscar cliente
            $client = $this->getOrCreateClient($data['client']);
            
            // Obtener siguiente correlativo
            $serie = $data['serie'];
            $correlativo = $branch->getNextCorrelative('07', $serie);
            
            // Procesar detalles y calcular totales
            $detalles = $data['detalles'];
            $globalData = [
                'descuentos' => $data['descuentos'] ?? [],
                'anticipos' => $data['anticipos'] ?? [],
                'redondeo' => $data['redondeo'] ?? 0,
            ];
            
            $this->calculateTotals($detalles, $globalData);
            
            // Calcular totales
            $valorVenta = array_sum(array_column($detalles, 'mto_valor_venta'));
            $mtoOperGravadas = 0;
            $mtoOperExoneradas = 0;
            $mtoOperInafectas = 0;
            $mtoOperGratuitas = 0;
            $mtoIgv = 0;
            $mtoIsc = 0;
            $mtoIcbper = 0;
            
            foreach ($detalles as $detalle) {
                switch ($detalle['tip_afe_igv']) {
                    case '10': // Gravado
                        $mtoOperGravadas += $detalle['mto_valor_venta'];
                        $mtoIgv += $detalle['igv'];
                        break;
                    case '20': // Exonerado
                        $mtoOperExoneradas += $detalle['mto_valor_venta'];
                        break;
                    case '30': // Inafecto
                        $mtoOperInafectas += $detalle['mto_valor_venta'];
                        break;
                    case '40': // Exportación
                        // Se maneja como inafecto para efectos internos
                        $mtoOperInafectas += $detalle['mto_valor_venta'];
                        break;
                    default:
                        if (isset($detalle['mto_valor_gratuito'])) {
                            $mtoOperGratuitas += $detalle['mto_valor_gratuito'];
                        }
                        break;
                }
                
                if (isset($detalle['isc'])) {
                    $mtoIsc += $detalle['isc'];
                }
                
                if (isset($detalle['icbper'])) {
                    $mtoIcbper += $detalle['icbper'];
                }
            }
            
            $totalImpuestos = $mtoIgv + $mtoIsc + $mtoIcbper;
            $subTotal = $valorVenta + $totalImpuestos;
            $mtoImpVenta = $subTotal;
            
            // Generar leyenda automática si no se proporciona
            $leyendas = $data['leyendas'] ?? [];
            if (empty($leyendas)) {
                $leyendas[] = [
                    'code' => '1000',
                    'value' => $this->convertirNumeroALetras($mtoImpVenta, $data['moneda'] ?? 'PEN')
                ];
            }
            
            // Crear la nota de crédito
            $creditNote = CreditNote::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'client_id' => $client->id,
                'tipo_documento' => '07',
                'serie' => $serie,
                'correlativo' => $correlativo,
                'tipo_doc_afectado' => $data['tipo_doc_afectado'],
                'num_doc_afectado' => $data['num_doc_afectado'],
                'cod_motivo' => $data['cod_motivo'],
                'des_motivo' => $data['des_motivo'],
                'fecha_emision' => $data['fecha_emision'],
                'ubl_version' => $data['ubl_version'] ?? '2.1',
                'moneda' => $data['moneda'] ?? 'PEN',
                'forma_pago_tipo' => $data['forma_pago_tipo'] ?? 'Contado',
                'forma_pago_cuotas' => $data['forma_pago_cuotas'] ?? null,
                'valor_venta' => $valorVenta,
                'mto_oper_gravadas' => $mtoOperGravadas,
                'mto_oper_exoneradas' => $mtoOperExoneradas,
                'mto_oper_inafectas' => $mtoOperInafectas,
                'mto_oper_gratuitas' => $mtoOperGratuitas,
                'mto_igv' => $mtoIgv,
                'mto_isc' => $mtoIsc,
                'mto_icbper' => $mtoIcbper,
                'total_impuestos' => $totalImpuestos,
                'mto_imp_venta' => $mtoImpVenta,
                'detalles' => $detalles,
                'leyendas' => $leyendas,
                'guias' => $data['guias'] ?? [],
                'datos_adicionales' => $data['datos_adicionales'] ?? [],
                'estado_sunat' => 'PENDIENTE',
                'usuario_creacion' => $data['usuario_creacion'] ?? null,
            ]);
            
            return $creditNote;
        });
    }

    public function createDebitNote(array $data): DebitNote
    {
        return DB::transaction(function () use ($data) {
            // Validar y obtener entidades
            $company = Company::findOrFail($data['company_id']);
            $branch = Branch::where('company_id', $company->id)
                           ->where('id', $data['branch_id'])
                           ->firstOrFail();
            
            // Crear o buscar cliente
            $client = $this->getOrCreateClient($data['client']);
            
            // Obtener siguiente correlativo
            $serie = $data['serie'];
            $correlativo = $branch->getNextCorrelative('08', $serie);
            
            // Procesar detalles y calcular totales
            $detalles = $data['detalles'];
            $globalData = [
                'descuentos' => $data['descuentos'] ?? [],
                'anticipos' => $data['anticipos'] ?? [],
                'redondeo' => $data['redondeo'] ?? 0,
            ];
            
            $this->calculateTotals($detalles, $globalData);
            
            // Calcular totales
            $valorVenta = array_sum(array_column($detalles, 'mto_valor_venta'));
            $mtoOperGravadas = 0;
            $mtoOperExoneradas = 0;
            $mtoOperInafectas = 0;
            $mtoOperGratuitas = 0;
            $mtoIgv = 0;
            $mtoIsc = 0;
            $mtoIcbper = 0;
            
            foreach ($detalles as $detalle) {
                switch ($detalle['tip_afe_igv']) {
                    case '10': // Gravado
                        $mtoOperGravadas += $detalle['mto_valor_venta'];
                        $mtoIgv += $detalle['igv'];
                        break;
                    case '20': // Exonerado
                        $mtoOperExoneradas += $detalle['mto_valor_venta'];
                        break;
                    case '30': // Inafecto
                        $mtoOperInafectas += $detalle['mto_valor_venta'];
                        break;
                    case '40': // Exportación
                        $mtoOperInafectas += $detalle['mto_valor_venta'];
                        break;
                    default:
                        if (isset($detalle['mto_valor_gratuito'])) {
                            $mtoOperGratuitas += $detalle['mto_valor_gratuito'];
                        }
                        break;
                }
                
                if (isset($detalle['isc'])) {
                    $mtoIsc += $detalle['isc'];
                }
                
                if (isset($detalle['icbper'])) {
                    $mtoIcbper += $detalle['icbper'];
                }
            }
            
            $totalImpuestos = $mtoIgv + $mtoIsc + $mtoIcbper;
            $subTotal = $valorVenta + $totalImpuestos;
            $mtoImpVenta = $subTotal;
            
            // Generar leyenda automática si no se proporciona
            $leyendas = $data['leyendas'] ?? [];
            if (empty($leyendas)) {
                $leyendas[] = [
                    'code' => '1000',
                    'value' => $this->convertirNumeroALetras($mtoImpVenta, $data['moneda'] ?? 'PEN')
                ];
            }
            
            // Crear la nota de débito
            $debitNote = DebitNote::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'client_id' => $client->id,
                'tipo_documento' => '08',
                'serie' => $serie,
                'correlativo' => $correlativo,
                'tipo_doc_afectado' => $data['tipo_doc_afectado'],
                'num_doc_afectado' => $data['num_doc_afectado'],
                'cod_motivo' => $data['cod_motivo'],
                'des_motivo' => $data['des_motivo'],
                'fecha_emision' => $data['fecha_emision'],
                'ubl_version' => $data['ubl_version'] ?? '2.1',
                'moneda' => $data['moneda'] ?? 'PEN',
                'valor_venta' => $valorVenta,
                'mto_oper_gravadas' => $mtoOperGravadas,
                'mto_oper_exoneradas' => $mtoOperExoneradas,
                'mto_oper_inafectas' => $mtoOperInafectas,
                'mto_oper_gratuitas' => $mtoOperGratuitas,
                'mto_igv' => $mtoIgv,
                'mto_isc' => $mtoIsc,
                'mto_icbper' => $mtoIcbper,
                'total_impuestos' => $totalImpuestos,
                'mto_imp_venta' => $mtoImpVenta,
                'detalles' => $detalles,
                'leyendas' => $leyendas,
                'datos_adicionales' => $data['datos_adicionales'] ?? [],
                'estado_sunat' => 'PENDIENTE',
                'usuario_creacion' => $data['usuario_creacion'] ?? null,
            ]);
            
            return $debitNote;
        });
    }

    public function createDispatchGuide(array $data): DispatchGuide
    {
        return DB::transaction(function () use ($data) {
            // Validar y obtener entidades
            $company = Company::findOrFail($data['company_id']);
            $branch = Branch::where('company_id', $company->id)
                           ->where('id', $data['branch_id'])
                           ->firstOrFail();
            
            // Crear o buscar destinatario
            if (isset($data['destinatario_id'])) {
                $destinatario = Client::findOrFail($data['destinatario_id']);
            } else {
                $destinatario = $this->getOrCreateClient($data['destinatario']);
            }
            
            // Obtener siguiente correlativo automático (ignorar correlativo enviado)
            $serie = $data['serie'];
            $correlativo = $branch->getNextCorrelative('09', $serie);
            
            // Crear la guía de remisión
            $dispatchGuide = DispatchGuide::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'client_id' => $destinatario->id,
                'tipo_documento' => '09',
                'serie' => $serie,
                'correlativo' => $correlativo,
                'fecha_emision' => $data['fecha_emision'],
                'version' => $data['version'] ?? '2022',
                
                // Datos del envío
                'cod_traslado' => $data['cod_traslado'],
                'des_traslado' => $data['des_traslado'] ?? null,
                'mod_traslado' => $data['mod_traslado'],
                'fecha_traslado' => $data['fecha_traslado'],
                'peso_total' => $data['peso_total'],
                'und_peso_total' => $data['und_peso_total'],
                'num_bultos' => $data['num_bultos'] ?? null,
                
                // Direcciones - Soporte para ambos formatos: plano y nested
                'partida' => isset($data['partida']) && is_array($data['partida']) ? [
                    'ubigeo' => $data['partida']['ubigeo'],
                    'direccion' => $data['partida']['direccion'],
                    'ruc' => $data['partida']['ruc'] ?? null,
                    'cod_local' => $data['partida']['cod_local'] ?? null,
                ] : [
                    'ubigeo' => $data['partida_ubigeo'],
                    'direccion' => $data['partida_direccion'],
                    'ruc' => $data['partida_ruc'] ?? null,
                    'cod_local' => $data['partida_cod_local'] ?? null,
                ],
                'llegada' => isset($data['llegada']) && is_array($data['llegada']) ? [
                    'ubigeo' => $data['llegada']['ubigeo'],
                    'direccion' => $data['llegada']['direccion'],
                    'ruc' => $data['llegada']['ruc'] ?? null,
                    'cod_local' => $data['llegada']['cod_local'] ?? null,
                ] : [
                    'ubigeo' => $data['llegada_ubigeo'],
                    'direccion' => $data['llegada_direccion'],
                    'ruc' => $data['llegada_ruc'] ?? null,
                    'cod_local' => $data['llegada_cod_local'] ?? null,
                ],
                
                // Datos de transporte (JSON según modalidad)
                'transportista' => $data['mod_traslado'] === '01' ? [
                    'tipo_doc' => $data['transportista_tipo_doc'] ?? null,
                    'num_doc' => $data['transportista_num_doc'] ?? null,
                    'razon_social' => $data['transportista_razon_social'] ?? null,
                    'nro_mtc' => $data['transportista_nro_mtc'] ?? null,
                ] : null,
                
                // Indicadores especiales (M1L, etc.)
                'indicadores' => $data['indicadores'] ?? null,
                
                'vehiculo' => isset($data['vehiculo_placa']) || isset($data['conductor_tipo_doc']) ? [
                    'placa' => $data['vehiculo_placa'] ?? null,
                    'placa_principal' => $data['vehiculo_placa'] ?? null,
                    'placa_secundaria' => $data['vehiculo_placa_secundaria'] ?? null,
                    'conductor' => $data['mod_traslado'] === '02' && !isset($data['indicadores']) ? [
                        'tipo' => $data['conductor_tipo'] ?? null,
                        'tipo_doc' => $data['conductor_tipo_doc'] ?? null,
                        'num_doc' => $data['conductor_num_doc'] ?? null,
                        'licencia' => $data['conductor_licencia'] ?? null,
                        'nombres' => $data['conductor_nombres'] ?? null,
                        'apellidos' => $data['conductor_apellidos'] ?? null,
                    ] : null,
                ] : null,
                
                // Detalles y observaciones
                'detalles' => $data['detalles'],
                'observaciones' => $data['observaciones'] ?? null,
                
                'estado_sunat' => 'PENDIENTE',
                'usuario_creacion' => $data['usuario_creacion'] ?? null,
            ]);
            
            return $dispatchGuide;
        });
    }

    public function sendDispatchGuideToSunat(DispatchGuide $guide): array
    {
        try {
            // IMPLEMENTACIÓN DIRECTA BASADA EN EJEMPLOS GREENTER
            // Evitamos completamente GreenterService para eliminar problemas de configuración
            
            Log::info("=== INICIO sendDispatchGuideToSunat DIRECTO ===", [
                'guide_id' => $guide->id,
                'client_id' => $guide->client_id,
            ]);
            
            // Cargar destinatario directamente
            $destinatario = \App\Models\Client::find($guide->client_id);
            if (!$destinatario) {
                throw new Exception("Destinatario no encontrado: ID {$guide->client_id}");
            }
            
            Log::info("Destinatario encontrado:", [
                'id' => $destinatario->id,
                'tipo_documento' => $destinatario->tipo_documento,
                'numero_documento' => $destinatario->numero_documento,
                'razon_social' => $destinatario->razon_social
            ]);
            
            // CREAR DOCUMENTO GREENTER DIRECTAMENTE (como en ejemplos)
            $despatch = new \Greenter\Model\Despatch\Despatch();
            $despatch->setVersion('2022')
                ->setTipoDoc('09')
                ->setSerie($guide->serie)
                ->setCorrelativo($guide->correlativo)
                ->setFechaEmision($guide->fecha_emision);
            
            // Empresa (usar datos reales de la guía)
            $company = new \Greenter\Model\Company\Company();
            $company->setRuc($guide->company->ruc)
                ->setRazonSocial($guide->company->razon_social);
            $despatch->setCompany($company);
            
            // Cliente/Destinatario
            $client = new \Greenter\Model\Client\Client();
            $client->setTipoDoc($destinatario->tipo_documento)
                ->setNumDoc($destinatario->numero_documento)
                ->setRznSocial($destinatario->razon_social);
            $despatch->setDestinatario($client);
            
            // Datos del envío
            $envio = new \Greenter\Model\Despatch\Shipment();
            $envio->setCodTraslado($guide->cod_traslado)
                ->setModTraslado($guide->mod_traslado)
                ->setFecTraslado($guide->fecha_traslado)
                ->setPesoTotal($guide->peso_total)
                ->setUndPesoTotal($guide->und_peso_total);
            
            // Direcciones con soporte para traslados misma empresa (ejemplo: guia-misma-empresa.php)
            $llegada = new \Greenter\Model\Despatch\Direction(
                $guide->llegada['ubigeo'] ?? '150101', 
                $guide->llegada['direccion'] ?? 'AV LIMA'
            );
            
            // Para traslados entre establecimientos de la misma empresa
            if (isset($guide->llegada['ruc']) && isset($guide->llegada['cod_local'])) {
                $llegada->setRuc($guide->llegada['ruc'])
                    ->setCodLocal($guide->llegada['cod_local']);
            }
            
            $partida = new \Greenter\Model\Despatch\Direction(
                $guide->partida['ubigeo'] ?? '150203', 
                $guide->partida['direccion'] ?? 'AV ITALIA'
            );
            
            // Para traslados entre establecimientos de la misma empresa
            if (isset($guide->partida['ruc']) && isset($guide->partida['cod_local'])) {
                $partida->setRuc($guide->partida['ruc'])
                    ->setCodLocal($guide->partida['cod_local']);
            }
            
            $envio->setLlegada($llegada)->setPartida($partida);
            
            // Configurar transporte según modalidad
            if ($guide->mod_traslado === '01') {
                // Transporte público - transportista (siguiendo ejemplo oficial Greenter)
                if (isset($guide->transportista) && is_array($guide->transportista)) {
                    $transportista = new \Greenter\Model\Despatch\Transportist();
                    $transportista->setTipoDoc($guide->transportista['tipo_doc'])
                        ->setNumDoc($guide->transportista['num_doc'])
                        ->setRznSocial($guide->transportista['razon_social'])
                        ->setNroMtc($guide->transportista['nro_mtc']);
                    
                    $envio->setTransportista($transportista);
                    
                    Log::info("Configurado transportista para modalidad 01", [
                        'tipo_doc' => $guide->transportista['tipo_doc'],
                        'num_doc' => $guide->transportista['num_doc'],
                        'razon_social' => $guide->transportista['razon_social'],
                        'nro_mtc' => $guide->transportista['nro_mtc']
                    ]);
                } else {
                    Log::error("Datos de transportista no encontrados para modalidad 01", [
                        'transportista' => $guide->transportista ?? 'null'
                    ]);
                    throw new Exception("Para modalidad de transporte '01' (público) se requieren los datos del transportista");
                }
                
                // ❌ ELIMINAR - Para transporte público (01) NO se configura vehículo según ejemplos Greenter
                // Los ejemplos oficiales de Greenter NO configuran vehículo para modalidad '01'
                Log::info("Modalidad 01 (Transporte Público) - No se configura vehículo según ejemplos oficiales");
                
            } elseif ($guide->mod_traslado === '02') {
                // Transporte privado - verificar si es M1L o con conductor/vehículo
                
                Log::info("=== DEBUG MODALIDAD 02 ===", [
                    'indicadores' => $guide->indicadores ?? 'null',
                    'indicadores_type' => gettype($guide->indicadores ?? null),
                    'indicadores_is_array' => is_array($guide->indicadores ?? null),
                    'vehiculo' => $guide->vehiculo ?? 'null'
                ]);
                
                // Verificar si tiene indicador M1L (vehículos menores)
                if (isset($guide->indicadores) && is_array($guide->indicadores) && in_array('SUNAT_Envio_IndicadorTrasladoVehiculoM1L', $guide->indicadores)) {
                    // Modalidad M1L - Sin conductor ni vehículo (ejemplo: guia-transporteM1L.php)
                    $envio->setIndicadores(['SUNAT_Envio_IndicadorTrasladoVehiculoM1L']);
                    
                    Log::info("Configurado transporte privado M1L - Sin conductor ni vehículo", [
                        'indicadores_configurados' => ['SUNAT_Envio_IndicadorTrasladoVehiculoM1L']
                    ]);
                    
                } else {
                    // Transporte privado normal - con conductor y vehículo (ejemplo: guia-transportePrivado.php)
                    Log::info("Entrando a configuración transporte privado NORMAL (sin M1L)");
                    
                    // Configurar conductor
                    if (isset($guide->vehiculo['conductor'])) {
                        $conductor = $guide->vehiculo['conductor'];
                        $chofer = new \Greenter\Model\Despatch\Driver();
                        $chofer->setTipo($conductor['tipo'] ?? 'Principal')
                            ->setTipoDoc($conductor['tipo_doc'] ?? '1')
                            ->setNroDoc($conductor['num_doc'] ?? '12345678')
                            ->setLicencia($conductor['licencia'] ?? 'L12345')
                            ->setNombres($conductor['nombres'] ?? 'CONDUCTOR')
                            ->setApellidos($conductor['apellidos'] ?? 'APELLIDO');
                        
                        $envio->setChoferes([$chofer]);
                        
                        Log::info("Configurado conductor", [
                            'tipo_doc' => $conductor['tipo_doc'] ?? '1',
                            'num_doc' => $conductor['num_doc'] ?? '12345678'
                        ]);
                    }
                    
                    // Configurar vehículo principal
                    if (isset($guide->vehiculo['placa_principal']) || isset($guide->vehiculo['placa'])) {
                        $placaPrincipal = $guide->vehiculo['placa_principal'] ?? $guide->vehiculo['placa'];
                        $vehiculo = new \Greenter\Model\Despatch\Vehicle();
                        $vehiculo->setPlaca($placaPrincipal);
                        
                        // Vehículo secundario (opcional)
                        if (isset($guide->vehiculo['placa_secundaria'])) {
                            $vehiculoSecundario = new \Greenter\Model\Despatch\Vehicle();
                            $vehiculoSecundario->setPlaca($guide->vehiculo['placa_secundaria']);
                            $vehiculo->setSecundarios([$vehiculoSecundario]);
                        }
                        
                        $envio->setVehiculo($vehiculo);
                        
                        Log::info("Configurado vehículo", [
                            'placa_principal' => $placaPrincipal,
                            'placa_secundaria' => $guide->vehiculo['placa_secundaria'] ?? null
                        ]);
                    }
                }
            }
            
            $despatch->setEnvio($envio);
            
            // Detalles con soporte completo (ejemplo: guia-extra-atributos.php)
            $details = [];
            foreach ($guide->detalles as $detalle) {
                $detail = new \Greenter\Model\Despatch\DespatchDetail();
                $detail->setCantidad($detalle['cantidad'])
                    ->setUnidad($detalle['unidad'])
                    ->setDescripcion($detalle['descripcion'])
                    ->setCodigo($detalle['codigo']);
                
                // Código SUNAT del producto (opcional)
                if (isset($detalle['cod_prod_sunat'])) {
                    $detail->setCodProdSunat($detalle['cod_prod_sunat']);
                }
                
                // Atributos adicionales (ejemplo: partida arancelaria)
                if (isset($detalle['atributos']) && is_array($detalle['atributos'])) {
                    $attributes = [];
                    foreach ($detalle['atributos'] as $attr) {
                        $attribute = new \Greenter\Model\Sale\DetailAttribute();
                        $attribute->setCode($attr['code'])
                            ->setName($attr['name'])
                            ->setValue($attr['value']);
                        $attributes[] = $attribute;
                    }
                    $detail->setAtributos($attributes);
                }
                
                $details[] = $detail;
            }
            $despatch->setDetails($details);
            
            // Documentos relacionados (ejemplo: guia-extra-atributos.php)
            if (isset($guide->documentos_relacionados) && is_array($guide->documentos_relacionados)) {
                $relDocs = [];
                foreach ($guide->documentos_relacionados as $doc) {
                    $relDoc = new \Greenter\Model\Despatch\AdditionalDoc();
                    $relDoc->setTipo($doc['tipo'])
                        ->setTipoDesc($doc['tipo_desc'])
                        ->setNro($doc['numero']);
                    $relDocs[] = $relDoc;
                }
                $despatch->setAddDocs($relDocs);
            }
            
            // USAR LA CONFIGURACIÓN DE LA CLASE UTIL DE GREENTER
            $api = new \Greenter\Api([
                'auth' => 'https://gre-test.nubefact.com/v1',
                'cpe' => 'https://gre-test.nubefact.com/v1',
            ]);
            
            // Configurar certificado
            $certificadoContent = file_get_contents(storage_path('app/public/certificado/certificado.pem'));
            if ($certificadoContent === false) {
                throw new Exception("No se pudo cargar el certificado");
            }
            
            // Obtener credenciales GRE de la configuración de la empresa
            $company = $guide->company;
            
            if (!$company->hasGreCredentials()) {
                throw new Exception("Las credenciales GRE no están configuradas para la empresa: {$company->razon_social}");
            }
            
            $clientId = $company->getGreClientId();
            $clientSecret = $company->getGreClientSecret();
            $rucProveedor = $company->getGreRucProveedor();
            $usuarioSol = $company->getGreUsuarioSol();
            $claveSol = $company->getGreClaveSol();
            
            Log::info("Configurando credenciales GRE desde base de datos", [
                'company_id' => $company->id,
                'modo_produccion' => $company->modo_produccion,
                'client_id' => $clientId ? '***' . substr($clientId, -4) : 'No configurado',
                'ruc_proveedor' => $rucProveedor,
                'usuario_sol' => $usuarioSol,
            ]);
            
            $api->setBuilderOptions([
                'strict_variables' => true,
                'optimizations' => 0,
                'debug' => true,
                'cache' => false,
            ])
            ->setApiCredentials($clientId, $clientSecret)
            ->setClaveSOL($rucProveedor, $usuarioSol, $claveSol)
            ->setCertificate($certificadoContent);
            
            Log::info("Enviando a SUNAT con credenciales configuradas...");
            $result = $api->send($despatch);
            
            // Procesar resultado como en ejemplos Greenter
            if ($result->isSuccess()) {
                // Obtener XML generado
                $xml = $api->getLastXml();
                $ticket = $result->getTicket();
                
                Log::info("Envío exitoso", ['ticket' => $ticket]);
                
                // Guardar archivos
                $xmlPath = $this->fileService->saveXml($guide, $xml);
                
                // Actualizar la guía
                $guide->update([
                    'xml_path' => $xmlPath,
                    'estado_sunat' => 'PROCESANDO',
                    'ticket' => $ticket,
                    'respuesta_sunat' => json_encode(['success' => true, 'ticket' => $ticket])
                ]);
                
                return [
                    'success' => true,
                    'document' => $guide->fresh(),
                    'ticket' => $ticket
                ];
            } else {
                // Error en envío
                $error = $result->getError();
                $errorMessage = $error ? $error->getMessage() : 'Error desconocido';
                $errorCode = $error ? $error->getCode() : 'UNKNOWN';
                
                Log::error("Error en envío SUNAT", [
                    'code' => $errorCode,
                    'message' => $errorMessage
                ]);
                
                // Actualizar estado de error
                $guide->update([
                    'respuesta_sunat' => json_encode([
                        'success' => false,
                        'code' => $errorCode,
                        'message' => $errorMessage
                    ])
                ]);
                
                return [
                    'success' => false,
                    'document' => $guide->fresh(),
                    'error' => $errorMessage
                ];
            }
            
        } catch (Exception $e) {
            Log::error("Excepción en sendDispatchGuideToSunat", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            $guide->update([
                'respuesta_sunat' => json_encode([
                    'success' => false,
                    'code' => 'EXCEPTION',
                    'message' => $e->getMessage()
                ])
            ]);
            
            return [
                'success' => false,
                'document' => $guide->fresh(),
                'error' => $e->getMessage()
            ];
        }
    }

    public function checkDispatchGuideStatus(DispatchGuide $guide): array
    {
        try {
            if (empty($guide->ticket)) {
                return [
                    'success' => false,
                    'error' => 'No hay ticket disponible para consultar'
                ];
            }
            
            Log::info("=== CONSULTANDO ESTADO GUÍA DIRECTA ===", [
                'guide_id' => $guide->id,
                'ticket' => $guide->ticket
            ]);
            
            // USAR CONFIGURACIÓN DIRECTA COMO EN ENVÍO
            $api = new \Greenter\Api([
                'auth' => 'https://gre-test.nubefact.com/v1',
                'cpe' => 'https://gre-test.nubefact.com/v1',
            ]);
            
            // Configurar certificado
            $certificadoContent = file_get_contents(storage_path('app/public/certificado/certificado.pem'));
            if ($certificadoContent === false) {
                throw new Exception("No se pudo cargar el certificado");
            }
            
            $api->setBuilderOptions([
                'strict_variables' => true,
                'optimizations' => 0,
                'debug' => true,
                'cache' => false,
            ])
            ->setApiCredentials('test-85e5b0ae-255c-4891-a595-0b98c65c9854', 'test-Hty/M6QshYvPgItX2P0+Kw==')
            ->setClaveSOL('20161515648', 'MODDATOS', 'MODDATOS')
            ->setCertificate($certificadoContent);
            
            Log::info("Consultando estado en SUNAT...");
            $result = $api->getStatus($guide->ticket);
            
            if ($result->isSuccess()) {
                $cdr = $result->getCdrResponse();
                Log::info("Estado obtenido exitosamente", [
                    'code' => $cdr->getCode(),
                    'description' => $cdr->getDescription()
                ]);
                
                // Guardar CDR
                $cdrZip = $result->getCdrZip();
                $cdrPath = null;
                if ($cdrZip) {
                    $cdrPath = $this->fileService->saveCdr($guide, $cdrZip);
                }
                
                // Actualizar estado
                $guide->update([
                    'cdr_path' => $cdrPath,
                    'estado_sunat' => 'ACEPTADO',
                    'respuesta_sunat' => json_encode([
                        'code' => $cdr->getCode(),
                        'description' => $cdr->getDescription()
                    ])
                ]);
                
                return [
                    'success' => true,
                    'document' => $guide->fresh(),
                    'cdr_response' => $cdr
                ];
            } else {
                // Error en la consulta
                $error = $result->getError();
                $errorMessage = $error ? $error->getMessage() : 'Error desconocido';
                $errorCode = $error ? $error->getCode() : 'UNKNOWN';
                
                Log::error("Error al consultar estado", [
                    'code' => $errorCode,
                    'message' => $errorMessage
                ]);
                
                $guide->update([
                    'estado_sunat' => 'RECHAZADO',
                    'respuesta_sunat' => json_encode([
                        'code' => $errorCode,
                        'message' => $errorMessage
                    ])
                ]);
                
                return [
                    'success' => false,
                    'document' => $guide->fresh(),
                    'error' => $errorMessage
                ];
            }
            
        } catch (Exception $e) {
            Log::error("Excepción en checkDispatchGuideStatus", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    protected function prepareDispatchGuideData(DispatchGuide $guide): array
    {
        // Cargar destinatario usando consulta directa para evitar problemas de relación
        $destinatario = null;
        if ($guide->client_id) {
            $destinatario = \App\Models\Client::find($guide->client_id);
        }
        
        // Validar que existe el destinatario
        if (!$destinatario) {
            throw new Exception("No se pudo encontrar el destinatario (client_id: {$guide->client_id}) para la guía de remisión ID: {$guide->id}");
        }

        Log::info("Destinatario encontrado:", [
            'id' => $destinatario->id,
            'tipo_documento' => $destinatario->tipo_documento,
            'numero_documento' => $destinatario->numero_documento,
            'razon_social' => $destinatario->razon_social
        ]);

        $data = [
            'tipo_documento' => $guide->tipo_documento,
            'serie' => $guide->serie,
            'correlativo' => $guide->correlativo,
            'fecha_emision' => $guide->fecha_emision->format('Y-m-d'),
            'version' => $guide->version,
            
            // Destinatario - usando consulta directa
            'destinatario' => [
                'tipo_documento' => $destinatario->tipo_documento,
                'numero_documento' => $destinatario->numero_documento,
                'razon_social' => $destinatario->razon_social,
                'direccion' => $destinatario->direccion ?? '',
                'ubigeo' => $destinatario->ubigeo ?? '',
                'distrito' => $destinatario->distrito ?? '',
                'provincia' => $destinatario->provincia ?? '',
                'departamento' => $destinatario->departamento ?? '',
                'telefono' => $destinatario->telefono ?? '',
                'email' => $destinatario->email ?? '',
            ],
            
            // Datos del envío
            'cod_traslado' => $guide->cod_traslado,
            'mod_traslado' => $guide->mod_traslado,
            'fec_traslado' => $guide->fecha_traslado->format('Y-m-d'),
            'peso_total' => $guide->peso_total,
            'und_peso_total' => $guide->und_peso_total,
            'num_bultos' => $guide->num_bultos,
            
            // Direcciones
            'partida_ubigeo' => $guide->partida['ubigeo'] ?? '',
            'partida_direccion' => $guide->partida['direccion'] ?? '',
            'llegada_ubigeo' => $guide->llegada['ubigeo'] ?? '',
            'llegada_direccion' => $guide->llegada['direccion'] ?? '',
            
            // Detalles
            'detalles' => $guide->detalles,
            'observaciones' => $guide->observaciones,
        ];
        
        // Agregar datos de transporte según modalidad
        if ($guide->mod_traslado === '01') {
            // Transporte público
            $data['transportista'] = $guide->transportista;
        } elseif ($guide->mod_traslado === '02') {
            // Transporte privado - verificar si es M1L
            $esM1L = isset($guide->indicadores) && is_array($guide->indicadores) && 
                     in_array('SUNAT_Envio_IndicadorTrasladoVehiculoM1L', $guide->indicadores);
                     
            if ($esM1L) {
                // M1L - Sin datos de vehículo ni conductor (ejemplo: guia-misma-empresa.php)
                $data['indicadores'] = $guide->indicadores;
                Log::info("prepareDispatchGuideData: Configurando M1L sin vehículo", [
                    'indicadores' => $guide->indicadores
                ]);
            } else {
                // Transporte privado normal - con conductor y vehículo
                $data['conductor'] = $guide->vehiculo['conductor'] ?? [];
                $data['vehiculo_placa'] = $guide->vehiculo['placa_principal'] ?? $guide->vehiculo['placa'] ?? '';
                $data['vehiculos_secundarios'] = [];
                Log::info("prepareDispatchGuideData: Configurando transporte privado normal", [
                    'vehiculo_placa' => $data['vehiculo_placa'],
                    'tiene_conductor' => !empty($data['conductor'])
                ]);
            }
        }
        
        return $data;
    }

    // Métodos para generación de PDFs
    public function generateDocumentPdf($document, string $documentType, string $format = 'A4'): void
    {
        try {
            logger()->info("Generando PDF para documento: {$document->id}, tipo: {$documentType}, formato: {$format}");
            
            $document = $document->load(['company', 'branch', 'destinatario']);
            
            $pdfContent = match($documentType) {
                'invoice' => $this->pdfService->generateInvoicePdf($document, $format),
                'boleta' => $this->pdfService->generateBoletaPdf($document, $format),
                'credit-note' => $this->pdfService->generateCreditNotePdf($document, $format),
                'debit-note' => $this->pdfService->generateDebitNotePdf($document, $format),
                'dispatch-guide' => $this->pdfService->generateDispatchGuidePdf($document, $format),
                default => throw new Exception("Tipo de documento no soportado: $documentType")
            };

            logger()->info("PDF generado, tamaño: " . strlen($pdfContent) . " bytes");

            // Guardar el PDF
            $pdfPath = $this->fileService->savePdf($document, $pdfContent);
            logger()->info("PDF guardado en: {$pdfPath}");
            
            // Actualizar la ruta del PDF en el documento
            $document->update(['pdf_path' => $pdfPath]);
            logger()->info("Ruta PDF actualizada en BD: {$pdfPath}");

        } catch (Exception $e) {
            // Log del error pero no interrumpir el flujo
            logger()->error("Error generando PDF para $documentType: " . $e->getMessage());
            logger()->error("Stack trace: " . $e->getTraceAsString());
        }
    }

    public function generateInvoicePdf(Invoice $invoice): void
    {
        $this->generateDocumentPdf($invoice, 'invoice');
    }

    public function generateBoletaPdf(Boleta $boleta): void  
    {
        $this->generateDocumentPdf($boleta, 'boleta');
    }

    public function generateCreditNotePdf(CreditNote $creditNote): void
    {
        $this->generateDocumentPdf($creditNote, 'credit-note');
    }

    public function generateDebitNotePdf(DebitNote $debitNote): void
    {
        $this->generateDocumentPdf($debitNote, 'debit-note');
    }

    public function generateDispatchGuidePdf(DispatchGuide $dispatchGuide, string $format = 'A4'): void
    {
        $this->generateDocumentPdf($dispatchGuide, 'dispatch-guide', $format);
    }

    public function createRetention(array $data): Retention
    {
        return DB::transaction(function () use ($data) {
            // Validar y obtener entidades
            $company = Company::findOrFail($data['company_id']);
            $branch = Branch::where('company_id', $company->id)
                           ->where('id', $data['branch_id'])
                           ->firstOrFail();
            
            // Crear o buscar el proveedor
            $proveedor = $this->getOrCreateClient($data['proveedor']);
            
            // Obtener siguiente correlativo (tipo '20' para retenciones)
            $serie = $data['serie'];
            $correlativo = $branch->getNextCorrelative('20', $serie);
            
            // Crear la retención
            $retention = Retention::create([
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'],
                'proveedor_id' => $proveedor->id,
                'serie' => $data['serie'],
                'correlativo' => $correlativo,
                'fecha_emision' => $data['fecha_emision'],
                'regimen' => $data['regimen'],
                'tasa' => $data['tasa'],
                'observacion' => $data['observacion'] ?? '',
                'imp_retenido' => $data['imp_retenido'],
                'imp_pagado' => $data['imp_pagado'],
                'moneda' => $data['moneda'],
                'detalles' => $data['detalles'],
                'estado_sunat' => 'PENDIENTE'
            ]);

            return $retention;
        });
    }

    public function sendRetentionToSunat(Retention $retention): array
    {
        try {
            $greenterService = new GreenterService($retention->company);
            
            // Preparar datos para Greenter
            $retentionData = [
                'company_id' => $retention->company_id,
                'serie' => $retention->serie,
                'correlativo' => $retention->correlativo,
                'fecha_emision' => $retention->fecha_emision->format('Y-m-d'),
                'regimen' => $retention->regimen,
                'tasa' => $retention->tasa,
                'observacion' => $retention->observacion,
                'imp_retenido' => $retention->imp_retenido,
                'imp_pagado' => $retention->imp_pagado,
                'proveedor' => [
                    'tipo_documento' => $retention->proveedor->tipo_documento,
                    'numero_documento' => $retention->proveedor->numero_documento,
                    'razon_social' => $retention->proveedor->razon_social,
                    'nombre_comercial' => $retention->proveedor->nombre_comercial,
                    'direccion' => $retention->proveedor->direccion,
                    'ubigeo' => $retention->proveedor->ubigeo,
                    'distrito' => $retention->proveedor->distrito,
                    'provincia' => $retention->proveedor->provincia,
                    'departamento' => $retention->proveedor->departamento,
                    'telefono' => $retention->proveedor->telefono,
                    'email' => $retention->proveedor->email,
                ],
                'detalles' => $retention->detalles
            ];

            // Crear documento Greenter
            $greenterRetention = $greenterService->createRetention($retentionData);
            
            // Enviar a SUNAT
            $result = $greenterService->sendRetention($greenterRetention);
            
            // Guardar archivos
            if ($result['xml']) {
                $retention->xml_path = $this->fileService->saveXml($retention, $result['xml'], 'retention');
            }
            
            if ($result['success'] && $result['cdr_zip']) {
                $retention->cdr_path = $this->fileService->saveCdr($retention, $result['cdr_zip'], 'retention');
                $retention->hash_cdr = $result['cdr_response']->getId() ?? '';
                $retention->estado_sunat = 'ACEPTADO';
            } else {
                $retention->estado_sunat = 'RECHAZADO';
            }
            
            $retention->save();
            
            return [
                'success' => $result['success'],
                'document' => $retention->fresh(['company', 'branch', 'proveedor']),
                'cdr_response' => $result['cdr_response'] ?? null,
                'error' => $result['error'] ?? null,
                'respuesta_sunat' => $result['error'] ? json_encode($result['error']) : null
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'document' => $retention,
                'error' => $e->getMessage()
            ];
        }
    }

    public function generateRetentionPdf(Retention $retention): void
    {
        $this->generateDocumentPdf($retention, 'retention');
    }

    public function createVoidedDocument(array $data): VoidedDocument
    {
        return DB::transaction(function () use ($data) {
            // Validar y obtener entidades
            $company = Company::findOrFail($data['company_id']);
            $branch = Branch::where('company_id', $company->id)
                           ->where('id', $data['branch_id'])
                           ->firstOrFail();
            
            // Obtener siguiente correlativo para la fecha
            $correlativo = $this->getNextVoidedDocumentCorrelative($company->id, $data['fecha_referencia']);
            
            // Crear comunicación de baja
            $voidedDocument = VoidedDocument::create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'tipo_documento' => 'RA',
                'correlativo' => $correlativo,
                'fecha_emision' => now()->toDateString(), // Fecha de comunicación (hoy)
                'fecha_referencia' => $data['fecha_referencia'], // Fecha de documentos a anular
                'ubl_version' => $data['ubl_version'] ?? '2.0',
                'detalles' => $data['detalles'],
                'motivo_baja' => $data['motivo_baja'],
                'total_documentos' => count($data['detalles']),
                'estado_sunat' => 'PENDIENTE',
                'usuario_creacion' => $data['usuario_creacion'] ?? null,
            ]);

            return $voidedDocument;
        });
    }

    public function sendVoidedDocumentToSunat(VoidedDocument $voidedDocument): array
    {
        try {
            $company = $voidedDocument->company;
            $greenterService = new GreenterService($company);
            
            // Preparar datos para Greenter
            $voidedData = $this->prepareVoidedDocumentData($voidedDocument);
            
            // Crear documento Greenter
            $greenterVoided = $greenterService->createVoidedDocument($voidedData);
            
            // Enviar a SUNAT
            $result = $greenterService->sendVoidedDocument($greenterVoided);
            
            if ($result['success']) {
                // Guardar archivos
                $xmlPath = $this->fileService->saveXml($voidedDocument, $result['xml']);
                
                // Actualizar la comunicación de baja
                $voidedDocument->update([
                    'xml_path' => $xmlPath,
                    'estado_sunat' => 'ENVIADO',
                    'ticket' => $result['ticket'],
                    'codigo_hash' => $this->extractHashFromXml($result['xml']),
                ]);
                
                return [
                    'success' => true,
                    'document' => $voidedDocument->fresh(),
                    'ticket' => $result['ticket']
                ];
            } else {
                // Error al enviar
                $voidedDocument->update([
                    'estado_sunat' => 'ERROR',
                    'respuesta_sunat' => json_encode([
                        'code' => $result['error']->code ?? 'UNKNOWN',
                        'message' => $result['error']->message ?? 'Error desconocido'
                    ])
                ]);
                
                return [
                    'success' => false,
                    'document' => $voidedDocument->fresh(),
                    'error' => $result['error']
                ];
            }
        } catch (Exception $e) {
            $voidedDocument->update([
                'estado_sunat' => 'ERROR',
                'respuesta_sunat' => json_encode(['message' => $e->getMessage()])
            ]);
            
            return [
                'success' => false,
                'document' => $voidedDocument->fresh(),
                'error' => $e->getMessage()
            ];
        }
    }

    public function checkVoidedDocumentStatus(VoidedDocument $voidedDocument): array
    {
        try {
            $company = $voidedDocument->company;
            $greenterService = new GreenterService($company);
            
            // Consultar estado con ticket
            $result = $greenterService->checkVoidedDocumentStatus($voidedDocument->ticket);
            
            if ($result['success']) {
                // Procesar respuesta CDR
                $cdrResponse = $result['cdr_response'];
                $estado = 'PROCESANDO';
                
                if ($cdrResponse && method_exists($cdrResponse, 'getDescription') && $cdrResponse->getDescription() !== null) {
                    if (strpos($cdrResponse->getDescription(), 'aceptad') !== false) {
                        $estado = 'ACEPTADO';
                        
                        // Guardar CDR
                        if ($result['cdr_zip']) {
                            $cdrPath = $this->fileService->saveCdr($voidedDocument, $result['cdr_zip']);
                            $voidedDocument->cdr_path = $cdrPath;
                        }
                    } elseif (strpos($cdrResponse->getDescription(), 'rechazad') !== false) {
                        $estado = 'RECHAZADO';
                    }
                }
                
                $voidedDocument->update([
                    'estado_sunat' => $estado,
                    'respuesta_sunat' => $cdrResponse ? json_encode([
                        'code' => $cdrResponse->getCode(),
                        'description' => $cdrResponse->getDescription()
                    ]) : null,
                    'cdr_path' => $voidedDocument->cdr_path
                ]);
                
                return [
                    'success' => true,
                    'document' => $voidedDocument->fresh()
                ];
            } else {
                return [
                    'success' => false,
                    'document' => $voidedDocument,
                    'error' => $result['error']
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'document' => $voidedDocument,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getDocumentsForVoiding(int $companyId, int $branchId, string $fechaReferencia, ?string $tipoDocumento = null): array
    {
        $documents = [];
        
        // Buscar facturas ACEPTADAS de la fecha
        if (!$tipoDocumento || $tipoDocumento === '01') {
            $facturas = Invoice::where('company_id', $companyId)
                              ->where('branch_id', $branchId)
                              ->whereDate('fecha_emision', $fechaReferencia)
                              ->where('estado_sunat', 'ACEPTADO')
                              ->get(['id', 'serie', 'correlativo', 'numero_completo', 'mto_imp_venta']);
            
            foreach ($facturas as $factura) {
                $documents[] = [
                    'id' => $factura->id,
                    'tipo_documento' => '01',
                    'serie' => $factura->serie,
                    'correlativo' => $factura->correlativo,
                    'numero_completo' => $factura->numero_completo,
                    'monto' => $factura->mto_imp_venta,
                    'tipo_nombre' => 'Factura'
                ];
            }
        }
        
        // Buscar boletas ACEPTADAS de la fecha
        if (!$tipoDocumento || $tipoDocumento === '03') {
            $boletas = Boleta::where('company_id', $companyId)
                            ->where('branch_id', $branchId)
                            ->whereDate('fecha_emision', $fechaReferencia)
                            ->where('estado_sunat', 'ACEPTADO')
                            ->get(['id', 'serie', 'correlativo', 'numero_completo', 'mto_imp_venta']);
            
            foreach ($boletas as $boleta) {
                $documents[] = [
                    'id' => $boleta->id,
                    'tipo_documento' => '03',
                    'serie' => $boleta->serie,
                    'correlativo' => $boleta->correlativo,
                    'numero_completo' => $boleta->numero_completo,
                    'monto' => $boleta->mto_imp_venta,
                    'tipo_nombre' => 'Boleta'
                ];
            }
        }
        
        // Buscar notas de crédito ACEPTADAS de la fecha
        if (!$tipoDocumento || $tipoDocumento === '07') {
            $creditNotes = CreditNote::where('company_id', $companyId)
                                   ->where('branch_id', $branchId)
                                   ->whereDate('fecha_emision', $fechaReferencia)
                                   ->where('estado_sunat', 'ACEPTADO')
                                   ->get(['id', 'serie', 'correlativo', 'numero_completo', 'mto_imp_venta']);
            
            foreach ($creditNotes as $creditNote) {
                $documents[] = [
                    'id' => $creditNote->id,
                    'tipo_documento' => '07',
                    'serie' => $creditNote->serie,
                    'correlativo' => $creditNote->correlativo,
                    'numero_completo' => $creditNote->numero_completo,
                    'monto' => $creditNote->mto_imp_venta,
                    'tipo_nombre' => 'Nota de Crédito'
                ];
            }
        }
        
        // Se pueden agregar más tipos de documentos según requerimientos SUNAT
        
        return $documents;
    }

    protected function prepareVoidedDocumentData(VoidedDocument $voidedDocument): array
    {
        return [
            'correlativo' => $voidedDocument->correlativo,
            'fecha_emision' => $voidedDocument->fecha_emision->toDateString(),
            'fecha_referencia' => $voidedDocument->fecha_referencia->toDateString(),
            'detalles' => $voidedDocument->detalles,
            'motivo_baja' => $voidedDocument->motivo_baja,
        ];
    }

    protected function getNextVoidedDocumentCorrelative(int $companyId, string $fechaReferencia): string
    {
        // Obtener el último correlativo para la fecha de referencia
        $lastVoided = VoidedDocument::where('company_id', $companyId)
                                  ->whereDate('fecha_referencia', $fechaReferencia)
                                  ->orderBy('correlativo', 'desc')
                                  ->first();
        
        if (!$lastVoided) {
            return '001';
        }
        
        $nextCorrelativo = intval($lastVoided->correlativo) + 1;
        return str_pad($nextCorrelativo, 3, '0', STR_PAD_LEFT);
    }

    public function convertirNumeroALetras($numero, $moneda = 'PEN'): string
    {
        $unidades = [
            '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
            'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte'
        ];
        
        $decenas = [
            '', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'
        ];
        
        $centenas = [
            '', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos',
            'seiscientos', 'setecientos', 'ochocientos', 'novecientos'
        ];

        // Convertir número a entero y obtener decimales
        $partes = explode('.', number_format($numero, 2, '.', ''));
        $entero = (int)$partes[0];
        $decimales = $partes[1];

        if ($entero == 0) {
            $resultado = "cero con $decimales/100";
        } else {
            $resultado = $this->convertirEntero($entero, $unidades, $decenas, $centenas);
            $resultado = trim($resultado) . " con $decimales/100";
        }

        // Añadir moneda
        $monedaNombre = match($moneda) {
            'PEN' => 'SOLES',
            'USD' => 'DÓLARES AMERICANOS',
            default => 'SOLES'
        };

        return strtoupper($resultado . ' ' . $monedaNombre);
    }

    protected function convertirEntero($numero, $unidades, $decenas, $centenas): string
    {
        if ($numero < 21) {
            return $unidades[$numero];
        }

        if ($numero < 100) {
            $dec = intval($numero / 10);
            $uni = $numero % 10;
            
            if ($uni > 0) {
                return $decenas[$dec] . ' y ' . $unidades[$uni];
            } else {
                return $decenas[$dec];
            }
        }

        if ($numero < 1000) {
            $cen = intval($numero / 100);
            $resto = $numero % 100;
            
            $resultado = ($numero == 100) ? 'cien' : $centenas[$cen];
            
            if ($resto > 0) {
                $resultado .= ' ' . $this->convertirEntero($resto, $unidades, $decenas, $centenas);
            }
            
            return $resultado;
        }

        if ($numero < 1000000) {
            $miles = intval($numero / 1000);
            $resto = $numero % 1000;
            
            if ($miles == 1) {
                $resultado = 'mil';
            } else {
                $resultado = $this->convertirEntero($miles, $unidades, $decenas, $centenas) . ' mil';
            }
            
            if ($resto > 0) {
                $resultado .= ' ' . $this->convertirEntero($resto, $unidades, $decenas, $centenas);
            }
            
            return $resultado;
        }

        // For millions and beyond, simplified version
        return 'número muy grande';
    }
}