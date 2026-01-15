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

    public function crearYTimbrar(Cfdi $cfdi)
    {
        try {
            // RECUPERAR EMISOR COMO ANTES
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
                'Rfc' => 'EAMR870411S57',
                'Nombre' => 'ROBERTO EZEQUIEL ESCAMILLA MARTINEZ',
                'DomicilioFiscalReceptor' => '87370',
                'RegimenFiscalReceptor' => '626',
                'UsoCFDI' => 'G03',
            ]);

            // 4. Conceptos e Impuestos
            foreach ($cfdi->detalles as $det) {
                $concepto = $comprobante->addConcepto([
                    'ClaveProdServ' => $det->clave_prod_serv,
                    'Cantidad' => number_format($det->cantidad, 6, '.', ''),
                    'ClaveUnidad' => $det->clave_unidad,
                    'Descripcion' => mb_strtoupper($det->descripcion),
                    'ValorUnitario' => number_format($det->valor_unitario, 6, '.', ''),
                    'Importe' => number_format($det->importe, 2, '.', ''),
                    'ObjetoImp' => '02',
                ]);

                $concepto->addTraslado([
                    'Base' => number_format($det->importe, 2, '.', ''),
                    'Impuesto' => '002',
                    'TipoFactor' => 'Tasa',
                    'TasaOCuota' => '0.160000',
                    'Importe' => number_format($det->impuesto_importe, 2, '.', ''),
                ]);
            }

            // Impuestos Globales
            $comprobante->addImpuestos([
                'TotalImpuestosTrasladados' => number_format($cfdi->impuestos, 2, '.', ''),
            ])->addTraslado([
                'Base' => number_format($cfdi->subtotal, 2, '.', ''),
                'Impuesto' => '002',
                'TipoFactor' => 'Tasa',
                'TasaOCuota' => '0.160000',
                'Importe' => number_format($cfdi->impuestos, 2, '.', ''),
            ]);

            // 5. Sellado con la llave privada y el CSD
            $creator->addSello($keyPem, $emisor->password_csd);

            // 6. Enviar a Finkok
            return $this->enviarPeticionFinkok($creator->asXml());

        } catch (Exception $e) {
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

// 2. Si existe un UUID, el timbrado fue exitoso (Ignoramos incidencias informativas)
            if (!empty($result->uuid)) {
                return [
                    'success' => true,
                    'uuid'    => $result->uuid,
                    'xml'     => $result->xml
                ];
            }

// 3. Si no hay UUID, buscamos el error en Incidencias de forma segura
            if (isset($result->Incidencias->Incidencia)) {
                $incidencias = $result->Incidencias->Incidencia;

                // Finkok puede devolver un solo objeto o un array de objetos
                if (is_array($incidencias)) {
                    $errorPrincipal = $incidencias[0];
                } else {
                    $errorPrincipal = $incidencias;
                }

                $mensaje = $errorPrincipal->Mensaje ?? 'Error desconocido';
                $codigo = $errorPrincipal->CodigoError ?? 'N/A';

                throw new Exception("Error del SAT [{$codigo}]: {$mensaje}");
            }

            return [
                'success' => true,
                'uuid' => $response->stampResult->UUID,
                'xml' => $response->stampResult->xml
            ];
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
