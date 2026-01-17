<?php

namespace App\Services;

use App\Models\Cfdi;
use CfdiUtils\CfdiCreator40;
use CfdiUtils\Certificado\Certificado;
use CfdiUtils\PemPrivateKey\PemPrivateKey;
use Exception;
use Illuminate\Support\Facades\Storage;
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

            // Cargar archivos CSD desde storage private
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

            // 3. Datos del Receptor (Datos fijos para la prueba)
            $comprobante->addReceptor([
                'Rfc'                     => strtoupper($datosReceptor['rfc']),
                'Nombre'                  => mb_strtoupper($datosReceptor['nombre']),
                'DomicilioFiscalReceptor' => $datosReceptor['cp'],
                'RegimenFiscalReceptor'   => $datosReceptor['regimen'],
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


}
