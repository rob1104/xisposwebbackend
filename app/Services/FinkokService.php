<?php

namespace App\Services;

use App\Models\Cfdi;
use Exception;
use SoapClient;
use Illuminate\Support\Facades\Storage;

class FinkokService
{
    protected $username;
    protected $password;
    protected $url;

    public function __construct()
    {
        $this->username = config('services.finkok.username');
        $this->password = config('services.finkok.password');
        // URL de pruebas o producción
        $this->url = config('services.finkok.url_stamp');
    }

    /**
     * Proceso principal: Generar XML y Timbrar
     */
    public function timbrarFactura(Cfdi $cfdi)
    {
        try {
            // 1. Crear el XML base (CFDI 4.0)
            $xmlBase64 = $this->generarXmlBase64($cfdi);

            // 2. Preparar conexión SOAP con Finkok
            $params = [
                "xml" => $xmlBase64,
                "username" => config('services.finkok.username'),
                "password" => config('services.finkok.password')
            ];


            $options = [
                'trace' => 1,
                'exceptions' => true,
                'encoding' => 'UTF-8',
                'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
            ];

            $client = new \SoapClient(config('services.finkok.url_stamp'), $options);
            $response = $client->stamp($params);



            // 3. Validar respuesta
            if (isset($response->stampResult->Incidencias)) {
                $error = $response->stampResult->Incidencias->Incidencia->Mensaje;
                throw new Exception("Error del SAT: " . $error);
            }

            // 4. Guardar resultados (UUID y XML Timbrado)
            $xmlTimbrado = $response->stampResult->xml;
            $uuid = $response->stampResult->uuid;

            return [
                'success' => true,
                'uuid' => $uuid,
                'xml' => $xmlTimbrado
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Genera el XML 4.0 siguiendo el Anexo 20
     */
    private function generarXmlBase64(Cfdi $cfdi)
    {
        $emisor = $cfdi->sucursal->emisor;
        $dom = new \DOMDocument('1.0', 'UTF-8');

        $comprobante = $dom->createElement('cfdi:Comprobante');
        $this->setAttributes($comprobante, [
            'xmlns:cfdi' => 'http://www.sat.gob.mx/cfd/4',
            'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
            'xsi:schemaLocation' => 'http://www.sat.gob.mx/cfd/4 http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd',
            'Version' => '4.0',
            'Serie' => $cfdi->serie,
            'Folio' => $cfdi->folio,
            'Fecha' => date('Y-m-d\TH:i:s'),
            'SubTotal' => number_format($cfdi->subtotal, 2, '.', ''),
            'Moneda' => 'MXN',
            'Total' => number_format($cfdi->total, 2, '.', ''),
            'TipoDeComprobante' => 'I',
            'Exportacion' => '01',
            'MetodoPago' => $cfdi->metodo_pago,
            'FormaPago' => $cfdi->forma_pago,
            'LugarExpedicion' => $emisor->codigo_postal,
        ]);

        // A. Obtener datos del certificado (.cer)
        $cerContent = Storage::disk('private')->get($emisor->cer_path);
        $certificado = str_replace(["\n", "\r"], '', base64_encode($cerContent));
        $noCertificado = $this->extractNoCertificado($cerContent);

        $comprobante->setAttribute('NoCertificado', $noCertificado);
        $comprobante->setAttribute('Certificado', $certificado);

        // --- Nodos Emisor y Receptor ---
        $emisorNode = $dom->createElement('cfdi:Emisor');
        $this->setAttributes($emisorNode, [
            'Rfc' => $emisor->rfc,
            'Nombre' => $emisor->razon_social,
            'RegimenFiscal' => $emisor->regimen_fiscal
        ]);
        $comprobante->appendChild($emisorNode);

        $receptorNode = $dom->createElement('cfdi:Receptor');
        $this->setAttributes($receptorNode, [
            'Rfc' => 'EKU9003173C9',
            'Nombre' => 'ESCUELA KEMPER URGATE',
            'DomicilioFiscalReceptor' => '01234',
            'RegimenFiscalReceptor' => '601',
            'UsoCFDI' => $cfdi->uso_cfdi
        ]);
        $comprobante->appendChild($receptorNode);

        // --- Conceptos ---
        $conceptosNode = $dom->createElement('cfdi:Conceptos');
        foreach ($cfdi->detalles as $det) {
            $concepto = $dom->createElement('cfdi:Concepto');
            $this->setAttributes($concepto, [
                'ClaveProdServ' => $det->clave_prod_serv,
                'Cantidad' => number_format($det->cantidad, 6, '.', ''),
                'ClaveUnidad' => $det->clave_unidad,
                'Descripcion' => $det->descripcion,
                'ValorUnitario' => number_format($det->valor_unitario, 6, '.', ''),
                'Importe' => number_format($det->importe, 6, '.', ''),
                'ObjetoImp' => '02'
            ]);

            $impConcepto = $dom->createElement('cfdi:Impuestos');
            $trasladosC = $dom->createElement('cfdi:Traslados');
            $trasladoC = $dom->createElement('cfdi:Traslado');
            $this->setAttributes($trasladoC, [
                'Base' => number_format($det->impuesto_base, 6, '.', ''),
                'Impuesto' => '002',
                'TipoFactor' => 'Tasa',
                'TasaOCuota' => '0.160000',
                'Importe' => number_format($det->impuesto_importe, 2, '.', '')
            ]);
            $trasladosC->appendChild($trasladoC);
            $impConcepto->appendChild($trasladosC);
            $concepto->appendChild($impConcepto);
            $conceptosNode->appendChild($concepto);
        }
        $comprobante->appendChild($conceptosNode);

        // --- B. IMPUESTOS TOTALES (Lo que te faltaba) ---
        $impuestosGlobales = $dom->createElement('cfdi:Impuestos');
        $impuestosGlobales->setAttribute('TotalImpuestosTrasladados', number_format($cfdi->impuestos, 2, '.', ''));
        $trasladosG = $dom->createElement('cfdi:Traslados');
        $trasladoG = $dom->createElement('cfdi:Traslado');
        $this->setAttributes($trasladoG, [
            'Base' => number_format($cfdi->subtotal, 2, '.', ''),
            'Impuesto' => '002',
            'TipoFactor' => 'Tasa',
            'TasaOCuota' => '0.160000',
            'Importe' => number_format($cfdi->impuestos, 2, '.', '')
        ]);
        $trasladosG->appendChild($trasladoG);
        $impuestosGlobales->appendChild($trasladosG);
        $comprobante->appendChild($impuestosGlobales);

        $dom->appendChild($comprobante);

        // --- C. SELLADO DIGITAL ---
        $xmlSinSello = $dom->saveXML();
        $cadenaOriginal = $this->generarCadenaOriginal($xmlSinSello);
        $sello = $this->generarSello($cadenaOriginal, $emisor);

        $comprobante->setAttribute('Sello', $sello);

        $xmlFinal = $dom->saveXML();
        // Validamos que el XML no esté vacío
        if (empty($xmlFinal)) {
            throw new \Exception("El XML generado está vacío.");
        }

        // Codificamos y LIMPIAMOS cualquier salto de línea o espacio
        $base64 = base64_encode($xmlFinal);
        return str_replace(["\n", "\r", " "], '', $base64);
    }

    private function setAttributes($node, $attributes) {
        foreach ($attributes as $key => $value) {
            $node->setAttribute($key, $value);
        }
    }

    private function extractNoCertificado($cerContent) {
        if (!$cerContent) {
            throw new Exception("El contenido del certificado (.cer) está vacío.");
        }

        // CONVERSIÓN CRÍTICA: De Binario (DER) a Texto (PEM)
        $cerPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($cerContent), 64, "\n") . "-----END CERTIFICATE-----\n";

        $data = openssl_x509_parse($cerPem);

        if ($data === false) {
            throw new Exception("Error de OpenSSL: No se pudo parsear el certificado. Asegúrate de que sea un archivo .cer válido.");
        }

        if (!isset($data['serialNumberHex'])) {
            throw new Exception("No se pudo extraer el número de serie del certificado.");
        }

        $serial = $data['serialNumberHex'];
        $res = '';
        // El SAT requiere los caracteres en posiciones nones del hex para el NoCertificado de 20 dígitos
        for ($i = 1; $i < strlen($serial); $i += 2) {
            $res .= substr($serial, $i, 1);
        }
        return $res;
    }

    private function generarCadenaOriginal($xml) {
        $xsltPath = storage_path('app/xslt/cadenaoriginal_4_0.xslt');

        if (!file_exists($xsltPath)) {
            throw new Exception("No se encontró el archivo XSLT en: {$xsltPath}. Descárgalo del SAT y colócalo en esa ruta.");
        }

        $xsl = new \DOMDocument();
        $xsl->load($xsltPath);

        $proc = new \XSLTProcessor();
        $proc->importStyleSheet($xsl);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        $cadena = $proc->transformToXML($dom);

        if (!$cadena) {
            throw new Exception("Error al generar la Cadena Original. Verifica que el XML base sea válido.");
        }

        return $cadena;
    }

    private function generarSello($cadenaOriginal, $emisor) {
        $keyContent = Storage::disk('private')->get($emisor->key_path);

        if (!$keyContent) {
            throw new Exception("No se pudo leer el archivo .key en la ruta: {$emisor->key_path}");
        }


        $keyPem = "-----BEGIN ENCRYPTED PRIVATE KEY-----\n" .
            chunk_split(base64_encode($keyContent), 64, "\n") .
            "-----END ENCRYPTED PRIVATE KEY-----\n";

        $privateKey = openssl_get_privatekey($keyPem, $emisor->password_csd);

        if ($privateKey === false) {
            throw new Exception("La contraseña del CSD es incorrecta o el archivo .key es inválido.");
        }

        openssl_sign($cadenaOriginal, $selloBinary, $privateKey, OPENSSL_ALGO_SHA256);
        openssl_free_key($privateKey);

        return base64_encode($selloBinary);
    }


    /**
     * Prueba la conexión con Finkok consultando los créditos de la cuenta
     */
    public function testConnection()
    {
        try {
            // El WSDL para utilerías/créditos suele ser distinto al de timbrado
            // URL de Pruebas: https://demo-itax.finkok.com/servicios/soap/utilities.wsdl
            $urlUtilities = config('services.finkok.url_utilities');


            $params = [
                "username" => config('services.finkok.username'),
                "password" => config('services.finkok.password'),
                "taxpayer_id" => "CACX7605101P8"
            ];

            $client = new \SoapClient($urlUtilities, ['trace' => 1]);
            $response = $client->report_credit($params);
            //dd($response->report_creditResult->result);
            return [
                'success' => true,
                'credits' => $response->report_creditResult->result->ReportTotalCredit->credit,
                'message' => 'Conexión exitosa con Finkok'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error de conexión: ' . $e->getMessage()
            ];
        }
    }
}
