<?php

namespace App\Services;

use App\Models\Cfdi;
use App\Models\TaxRegime;
use CfdiUtils\CfdiCreator40;
use CfdiUtils\Certificado\Certificado;
use CfdiUtils\PemPrivateKey\PemPrivateKey;
use Exception;
use Illuminate\Support\Facades\Storage;
use Log;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\TripleDES;
use SoapClient;

class FinkokService
{
    protected $username;
    protected $password;
    protected $url;

    public function __construct()
    {
        $this->username = config('services.finkok.username');
        $this->password = config('services.finkok.password');
        $this->url = config('services.finkok.url_stamp');
    }

    public function crearYTimbrar(Cfdi $cfdi, array $datosReceptor)
    {
        try {
            if (!$cfdi->sucursal || !$cfdi->sucursal->emisor) {
                throw new Exception("La sucursal ID {$cfdi->sucursale_id} no tiene un Emisor configurado.");
            }
            $emisor = $cfdi->sucursal->emisor;
            $cerPath = storage_path('app/private/' . $emisor->cer_path);
            if (!file_exists($cerPath)) {
                throw new Exception("El archivo .cer NO existe en la ruta física: " . $cerPath);
            }
            $certificado = new Certificado($cerPath);
            $keyBinary = Storage::disk('private')->get($emisor->key_path);
            $keyPem = $this->convertirKeyAPem($keyBinary, $emisor->password_csd);

            // 1. Configurar Comprobante (Anexo 20)
            $creator = new CfdiCreator40([
                'Version' => '4.0',
                'Serie' => $cfdi->serie,
                'Folio' => $cfdi->folio,
                'Fecha' => now()->modify('-1 hour')->format('Y-m-d\TH:i:s'),
                'FormaPago' => $cfdi->forma_pago,
                'SubTotal' => number_format($cfdi->subtotal, 2, '.', ''),
                'Moneda' => 'MXN',
                'Total' => number_format($cfdi->total, 2, '.', ''),
                'TipoDeComprobante' => 'I',
                'Exportacion' => '01',
                'MetodoPago' => $cfdi->metodo_pago,
                'LugarExpedicion' => $emisor->codigo_postal,
            ], $certificado);

            $comprobante = $creator->comprobante();

            // CORRECCIÓN PARA FINKOK: Agregar namespace tfd y schemaLocation completo
            $comprobante->addAttributes([
                'xmlns:tfd' => 'http://www.sat.gob.mx/TimbreFiscalDigital',
                'xsi:schemaLocation' => 'http://www.sat.gob.mx/cfd/4 http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd http://www.sat.gob.mx/TimbreFiscalDigital http://www.sat.gob.mx/sitio_internet/cfd/TimbreFiscalDigital/TimbreFiscalDigitalv11.xsd'
            ]);

            // 2. Datos del Emisor (Usando tus variables originales)
            $comprobante->addEmisor([
                'Rfc' => strtoupper(trim($emisor->rfc)),
                'Nombre' => mb_strtoupper($emisor->razon_social),
                'RegimenFiscal' => $emisor->regimen_fiscal,
            ]);

            $regimen = TaxRegime::find($datosReceptor['regimen']);

            // 3. Datos del Receptor (Datos fijos para la prueba)
            $comprobante->addReceptor([
                'Rfc'                     => strtoupper($datosReceptor['rfc']),
                'Nombre'                  => mb_strtoupper($datosReceptor['nombre']),
                'DomicilioFiscalReceptor' => $datosReceptor['cp'],
                'RegimenFiscalReceptor'   => $regimen->code,
                'UsoCFDI'                 => $datosReceptor['uso_cfdi'],
            ]);
            $impuestosAgrupados = [];

            // 4. Conceptos e Impuestos
            foreach ($cfdi->detalles as $det) {
                $concepto = $comprobante->addConcepto([
                    'ClaveProdServ' => $det->clave_prod_serv,
                    'Cantidad' => number_format($det->cantidad, 6, '.', ''),
                    'ClaveUnidad' => $det->clave_unidad,
                    'Descripcion' => mb_strtoupper($det->descripcion),
                    'ValorUnitario' => number_format($det->valor_unitario, 6, '.', ''),
                    'Importe' => number_format($det->importe, 2, '.', ''),
                    'ObjetoImp' => $det->objeto_imp,
                ]);

                $concepto->addTraslado([
                    'Base' => number_format($det->impuesto_base, 2, '.', ''),
                    'Impuesto' => '002',
                    'TipoFactor' => 'Tasa',
                    'TasaOCuota' => number_format($det->impuesto_tasa_cuota, 6, '.', ''),
                    'Importe' => number_format($det->impuesto_importe, 2, '.', ''),
                ]);

                $tasa = $det->impuesto_tasa_cuota;
                if (!isset($impuestosAgrupados[$tasa])) {
                    $impuestosAgrupados[$tasa] = ['base' => 0, 'importe' => 0];
                }
                $impuestosAgrupados[$tasa]['base'] += $det->impuesto_base;
                $impuestosAgrupados[$tasa]['importe'] += $det->impuesto_importe;
            }

            $impuestosGlobales = $comprobante->addImpuestos([
                'TotalImpuestosTrasladados' => number_format($cfdi->impuestos, 2, '.', ''),
            ]);
            foreach ($impuestosAgrupados as $tasa => $valores) {
                $impuestosGlobales->addTraslado([
                    'Base'       => number_format($valores['base'], 2, '.', ''),
                    'Impuesto'   => '002',
                    'TipoFactor' => 'Tasa',
                    'TasaOCuota' => $tasa,
                    'Importe'    => number_format($valores['importe'], 2, '.', ''),
                ]);
            }

            // 5. Sellado con la llave privada y el CSD
            $creator->addSello($keyPem, $emisor->password_csd);

            $xmlGenerado = $creator->asXml();
            // NUEVO: Guardar XML Borrador antes de enviar
            $fileName = 'cfdis/borrador_' . $cfdi->id . '_' . time() . '.xml';
            Storage::disk('private')->put($fileName, $xmlGenerado);

            // 6. Enviar a Finkok
            $respuesta = $this->enviarPeticionFinkok($creator->asXml());
            if ($respuesta['success']) {
                // Si tiene éxito, sobreescribimos el archivo con el XML que ya trae el timbre
                Storage::disk('private')->put($fileName, $respuesta['xml']);


                return [
                    'success'  => true,
                    'uuid'     => $respuesta['uuid'],
                    'xml_path' => $fileName
                ];
            }

        } catch (Exception $e) {

            return [
                'success'  => false,
                'message'  => $e->getMessage(),
                'xml_path' => $fileName ?? null
            ];
        }
    }

    public function cancelarCfdi($cfdi, $motivo, $folioSustitucion = null)
    {
        if (!$cfdi->sucursal || !$cfdi->sucursal->emisor) {
            throw new Exception("La sucursal ID {$cfdi->sucursale_id} no tiene un Emisor configurado.");
        }
        $emisor = $cfdi->sucursal->emisor;

        $cerPath = storage_path('app/private/' . $emisor->cer_path);
        $keyPath = storage_path('app/private/' . $emisor->key_path);
        $csdPassword = $emisor->password_csd;

        try {
            $archivosPem = $this->convertirConShellExec($cerPath, $keyPath, $csdPassword);
            $cerPem = $archivosPem['cer_pem'];
            $keyPem = $archivosPem['key_pem'];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error OpenSSL Shell: ' . $e->getMessage()];
        }

        // Conectar a Finkok (Demo o Producción)
        $username = config('services.finkok.username');
        $password = config('services.finkok.password');
        $url = config('services.finkok.url_cancel');

        $client = new \SoapClient($url, ['trace' => 1]);
        $taxpayer_id = $emisor->rfc;

        // 3. LIMPIEZA EXTREMA DEL UUID (Corrección del error actual)
        // Eliminamos cualquier cosa que no sea letra, número o guion.
        $uuidLimpio = preg_replace('/[^a-zA-Z0-9\-]/', '', $cfdi->uuid);
        $uuidLimpio = strtoupper($uuidLimpio);
        $motivoString = str_pad((string)$motivo, 2, '0', STR_PAD_LEFT);

        $uuidItem = [
            'UUID' => $uuidLimpio,
            'Motivo' => $motivoString
        ];

        if ($motivoString === '01' && !empty($folioSustitucion)) {
            $uuidItem['FolioSustitucion'] = trim($folioSustitucion);
        }

        $contenidoUuids = ['UUID' => $uuidItem];

        // Formato específico que pide Finkok
        $params = [
            "UUIDS" => $contenidoUuids,
            "username" => $username,
            "password" => $password,
            "taxpayer_id" => $taxpayer_id,
            "cer" => $cerPem,
            "key" => $keyPem,
            "store_pending" => true
        ];

        try {
            $response = $client->cancel($params);
            // Analizar respuesta SOAP
            if (isset($response->cancelResult->Folios->Folio)) {
                $folioRes = $response->cancelResult->Folios->Folio;
                // A veces Folio es un array si cancelas masivos, aquí asumimos uno solo.
                // Si regresa array, tomamos el primero.
                if (is_array($folioRes)) {
                    $folioRes = $folioRes[0];
                }

                $estatus = (string)$folioRes->EstatusUUID;

                // 201: Cancelado, 202: Previamente Cancelado, 203: En Proceso
                if (in_array($estatus, ['201', '202', '203'])) {
                    return [
                        'success' => true,
                        'codigo_estatus' => $estatus,
                        'acuse' => $response->cancelResult->Acuse ?? null,
                        'message' => 'Proceso exitoso'
                    ];
                } else {
                    // Error específico del UUID (ej. 701, o formato invalido específico)
                    $desc = $folioRes->EstatusCancelacion ?? 'Error desconocido';
                    return ['success' => false, 'message' => "Error SAT ($estatus): $desc"];
                }
            }

            if (isset($response->cancelResult->CodEstatus)) {
                return ['success' => false, 'message' => "Error PAC: " . $response->cancelResult->CodEstatus];
            }

            return ['success' => false, 'message' => 'Respuesta vacía del PAC'];

        } catch (\SoapFault $e) {
            return ['success' => false, 'message' => 'Error SOAP: ' . $e->getMessage()];
        }
    }

    public function consultarEstatusSat($uuid, $total, $rfcReceptor)
    {
        $username = config('services.finkok.username');
        $password = config('services.finkok.password');
        $taxpayer_id = config('services.finkok.rfc_emisor');

        // URL del servicio de utilidades de Finkok (suele tener get_sat_status)
        // Nota: A veces está en cancel.wsdl o utilities.wsdl dependiendo tu integración.
        // Usaremos la URL de cancelación que ya tienes, que suele incluir este método.
        $url = config('services.finkok.url_cancel');

        $client = new \SoapClient($url);

        // Formato total a 17 posiciones (ej. 100.00 -> 000000000000100.00)
        // Aunque muchas veces el WS lo acepta normal, el estándar SAT pide formato fijo.
        // Para Finkok wrapper, solemos enviar los datos directos.

        $params = [
            "username" => $username,
            "password" => $password,
            "taxpayer_id" => $taxpayer_id,
            "rt" => $taxpayer_id, // RFC Emisor (Re)
            "rr" => $rfcReceptor, // RFC Receptor (Rr)
            "tt" => number_format($total, 2, '.', ''), // Total (Tt)
            "id" => $uuid // UUID
        ];

        try {
            // Finkok tiene un metodo 'get_sat_status'
            $response = $client->get_sat_status($params);

            // La respuesta suele traer: "Estado", "EstatusCancelacion"
            // Estructura típica: $response->get_sat_statusResult->sat->Estado (Vigente/Cancelado)
            // Y ->EstatusCancelacion (En proceso / Cancelado con aceptación / Plazo vencido / Rechazado)

            $result = $response->get_sat_statusResult;

            // Ajusta según la estructura exacta de tu WSDL, pero generalmente es:
            $estado = $result->sat->Estado ?? 'Desconocido';
            $estatusCancelacion = $result->sat->EstatusCancelacion ?? '';

            return [
                'success' => true,
                'estado' => $estado, // Vigente o Cancelado
                'estatus_cancelacion' => $estatusCancelacion
            ];

        } catch (\SoapFault $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function enviarPeticionFinkok(string $xmlContent)
    {
        $xmlLimpio = $this->limpiarYCodificarXml($xmlContent);
        $params = [
            "xml" => $xmlLimpio,
            "username" => $this->username,
            "password" => $this->password
        ];

        $client = new SoapClient($this->url, ['trace' => 1, 'encoding' => 'UTF-8']);

        try {
            $response = $client->stamp($params);
            $result = $response->stampResult;
            $uuid = $result->UUID ?? $result->uuid ?? null;

            if (!empty($uuid)) {
                return [
                    'success' => true,
                    'uuid'    => $uuid,
                    'xml'     => $result->xml
                ];
            }

            // Si no hay UUID, extraemos el error detallado del PAC
            if (isset($result->Incidencias->Incidencia)) {
                $incidencias = $result->Incidencias->Incidencia;

                // Finkok puede enviar una incidencia o un arreglo de ellas
                $listaErrores = is_array($incidencias) ? $incidencias : [$incidencias];

                $mensajes = [];
                foreach ($listaErrores as $error) {
                    // Limpiamos el mensaje de caracteres extraños para que sea legible
                    $mensajeLimpio = str_replace(['<![CDATA[', ']]>'], '', $error->MensajeIncidencia);
                    $mensajes[] = "[{$error->CodigoError}]: {$mensajeLimpio}";
                }

                // Unimos todos los errores encontrados en un solo string
                throw new Exception(implode(" | ", $mensajes));
            }

            throw new Exception("El PAC no devolvió un UUID ni una incidencia clara: " );

        } catch (Exception $e) {
            throw $e;
        }
    }

    private function limpiarYCodificarXml($xmlContent)
    {
        // 1. Eliminar saltos de línea literales (\n) y tabulaciones
        $xmlClean = str_replace(["\n", "\r", "\t"], '', $xmlContent);

        // 2. Eliminar espacios masivos entre atributos y etiquetas
        // Esto limpia el bloque de espacios que tienes en cfdi:Concepto
        $xmlClean = preg_replace('/\s+>/', '>', $xmlClean); // Espacios antes de cerrar tag
        $xmlClean = preg_replace('/>\s+</', '><', $xmlClean); // Espacios entre etiquetas

        // 3. Trim final para asegurar que no haya nada al inicio o fin
        // 4. Codificar a Base64 (Esto es lo que debe ir en el parámetro 'xml')
        return trim($xmlClean);
    }

    private function convertirKeyAPem($der, $password)
    {
        $pke = "-----BEGIN ENCRYPTED PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END ENCRYPTED PRIVATE KEY-----\n";

        $keyResource = openssl_pkey_get_private($pke, $password);

        if (!$keyResource) {
            throw new Exception("No se pudo leer la llave privada. Verifica que la contraseña del CSD sea correcta.");
        }

        openssl_pkey_export($keyResource, $pem);
        return $pem;
    }


    /**
     * Convierte los archivos CSD usando comandos directos del sistema (shell_exec).
     * Retorna un array con el contenido PEM del Cer y del Key (DES3).
     */
    private function convertirConShellExec($cerPath, $keyPath, $password)
    {
        // 1. Definir rutas temporales únicas para evitar colisiones
        $id = uniqid();
        $tempDir = storage_path('app/temp/');

        // Aseguramos que exista el directorio temporal
        if (!file_exists($tempDir)) mkdir($tempDir, 0755, true);

        $cerPemPath = $tempDir . "cer_{$id}.pem";
        $keyTempPath = $tempDir . "key_temp_{$id}.pem";
        $keyFinalPath = $tempDir . "key_final_{$id}.enc";

        // Preparamos rutas escapadas para evitar errores con espacios
        $cmdCerPath = escapeshellarg($cerPath);
        $cmdKeyPath = escapeshellarg($keyPath);
        $cmdCerOut = escapeshellarg($cerPemPath);
        $cmdKeyTemp = escapeshellarg($keyTempPath);
        $cmdKeyFinal = escapeshellarg($keyFinalPath);

        // Escapamos la contraseña para evitar inyección de comandos
        $passSafe = escapeshellarg($password);

        // ---------------------------------------------------------
        // COMANDO 1: Convertir CER (DER -> PEM)
        // ---------------------------------------------------------
        // openssl x509 -inform DER -in archivo.cer -out archivo.pem
        $cmd1 = "openssl x509 -inform DER -in $cmdCerPath -out $cmdCerOut";
        shell_exec($cmd1);

        // ---------------------------------------------------------
        // COMANDO 2: Convertir KEY (DER -> PEM Sin Encriptar)
        // ---------------------------------------------------------
        // openssl pkcs8 -inform DER -in archivo.key -passin pass:CONTRASEÑA -out temp.pem
        // NOTA: Si tu key original NO tiene contraseña, quita "-passin pass:$passSafe"
        $cmd2 = "openssl pkcs8 -inform DER -in $cmdKeyPath -passin pass:$passSafe -out $cmdKeyTemp";
        shell_exec($cmd2);

        // Validación intermedia: Si falló el paso 2, intentamos sin contraseña (por si el key venía desbloqueado)
        if (!file_exists($keyTempPath) || filesize($keyTempPath) === 0) {
            $cmd2Retry = "openssl pkcs8 -inform DER -in $cmdKeyPath -out $cmdKeyTemp";
            shell_exec($cmd2Retry);
        }

        // ---------------------------------------------------------
        // COMANDO 3: Encriptar a DES3 (Requisito Finkok)
        // ---------------------------------------------------------
        // openssl rsa -in temp.pem -des3 -out final.enc -passout pass:CONTRASEÑA
        $passPhrase = config('services.finkok.passphrase_cancel');
        $passPhrase = escapeshellarg($passPhrase);
        $cmd3 = "openssl rsa -in $cmdKeyTemp -des3 -out $cmdKeyFinal -passout pass:$passPhrase";
        shell_exec($cmd3);

        // ---------------------------------------------------------
        // LECTURA Y LIMPIEZA
        // ---------------------------------------------------------
        if (!file_exists($cerPemPath) || !file_exists($keyFinalPath)) {
            // Limpiar lo que se haya creado
            @unlink($cerPemPath); @unlink($keyTempPath); @unlink($keyFinalPath);
            throw new \Exception("Error al ejecutar OpenSSL en el sistema. Verifique que 'openssl' esté en el PATH.");
        }

        $cerContent = file_get_contents($cerPemPath);
        $keyContent = file_get_contents($keyFinalPath);

        // Borramos archivos temporales
        @unlink($cerPemPath);
        @unlink($keyTempPath);
        @unlink($keyFinalPath);

        return [
            'cer_pem' => $cerContent,
            'key_pem' => $keyContent
        ];
    }

}
