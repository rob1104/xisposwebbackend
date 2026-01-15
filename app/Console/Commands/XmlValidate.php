<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class XmlValidate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'finkok:xml-validate';
    protected $description = 'Valida el XML generado contra el esquema del SAT';

    public function handle()
    {
        $this->info('=== Validación de XML CFDI 4.0 ===');

        // Leer el XML
        $xmlPath = 'factura_debug.xml';

        if (!Storage::disk('local')->exists($xmlPath)) {
            $this->error("No se encontró el archivo: storage/app/{$xmlPath}");
            return;
        }

        $xmlContent = Storage::disk('local')->get($xmlPath);
        $this->info("Archivo leído: " . strlen($xmlContent) . " bytes");

        // 1. Validar que sea XML bien formado
        $this->comment("\n1. Validando estructura XML...");
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;

        if (!$dom->loadXML($xmlContent)) {
            $this->error("❌ XML malformado:");
            foreach (libxml_get_errors() as $error) {
                $this->line("  - Línea {$error->line}: {$error->message}");
            }
            libxml_clear_errors();
            return;
        }
        $this->info("✅ XML bien formado");

        // 2. Validar namespace
        $this->comment("\n2. Validando namespace...");
        $root = $dom->documentElement;
        $namespace = $root->namespaceURI;
        $expectedNS = 'http://www.sat.gob.mx/cfd/4';

        if ($namespace !== $expectedNS) {
            $this->error("❌ Namespace incorrecto: {$namespace}");
            $this->line("   Esperado: {$expectedNS}");
            return;
        }
        $this->info("✅ Namespace correcto: {$namespace}");

        // 3. Validar atributos requeridos
        $this->comment("\n3. Validando atributos obligatorios...");
        $required = ['Version', 'Fecha', 'Sello', 'NoCertificado', 'Certificado',
            'SubTotal', 'Total', 'TipoDeComprobante', 'LugarExpedicion'];

        $missing = [];
        foreach ($required as $attr) {
            if (!$root->hasAttribute($attr)) {
                $missing[] = $attr;
            }
        }

        if (!empty($missing)) {
            $this->error("❌ Faltan atributos obligatorios: " . implode(', ', $missing));
            return;
        }
        $this->info("✅ Todos los atributos obligatorios presentes");

        // 4. Validar formato de fecha
        $this->comment("\n4. Validando formato de fecha...");
        $fecha = $root->getAttribute('Fecha');
        $this->line("   Fecha en XML: {$fecha}");

        // El SAT acepta formato: 2025-01-14T13:37:41
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $fecha)) {
            $this->error("❌ Formato de fecha incorrecto");
            $this->line("   Esperado: YYYY-MM-DDTHH:MM:SS");
            return;
        }

        // Validar que no sea fecha futura
        $fechaXml = new \DateTime($fecha);
        $ahora = new \DateTime('now', new \DateTimeZone('America/Mexico_City'));

        if ($fechaXml > $ahora) {
            $this->error("❌ La fecha está en el futuro!");
            $this->line("   XML: " . $fechaXml->format('Y-m-d H:i:s'));
            $this->line("   Ahora: " . $ahora->format('Y-m-d H:i:s'));
            return;
        }

        $this->info("✅ Fecha válida y no futura");

        // 5. Validar estructura de nodos
        $this->comment("\n5. Validando estructura de nodos...");
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');

        $checks = [
            '//cfdi:Emisor' => 'Emisor',
            '//cfdi:Receptor' => 'Receptor',
            '//cfdi:Conceptos/cfdi:Concepto' => 'Conceptos',
            '//cfdi:Impuestos' => 'Impuestos'
        ];

        foreach ($checks as $query => $name) {
            $nodes = $xpath->query($query);
            if ($nodes->length === 0) {
                $this->error("❌ Falta nodo: {$name}");
                return;
            }
            $this->line("  ✓ {$name}: {$nodes->length} encontrado(s)");
        }

        // 6. Validar certificado
        $this->comment("\n6. Validando certificado...");
        $certificado = $root->getAttribute('Certificado');
        $noCertificado = $root->getAttribute('NoCertificado');

        if (strlen($certificado) < 100) {
            $this->error("❌ Certificado muy corto: " . strlen($certificado) . " caracteres");
            return;
        }

        if (strlen($noCertificado) !== 20) {
            $this->error("❌ NoCertificado debe tener 20 dígitos, tiene: " . strlen($noCertificado));
            return;
        }

        $this->info("✅ Certificado: " . strlen($certificado) . " caracteres");
        $this->info("✅ NoCertificado: {$noCertificado}");

        // 7. Validar sello
        $this->comment("\n7. Validando sello...");
        $sello = $root->getAttribute('Sello');

        if (strlen($sello) < 100) {
            $this->error("❌ Sello muy corto: " . strlen($sello) . " caracteres");
            return;
        }

        $this->info("✅ Sello: " . strlen($sello) . " caracteres");

        // 8. Mostrar resumen
        $this->comment("\n8. Resumen de datos:");
        $this->table(
            ['Atributo', 'Valor'],
            [
                ['Version', $root->getAttribute('Version')],
                ['Serie', $root->getAttribute('Serie')],
                ['Folio', $root->getAttribute('Folio')],
                ['Fecha', $fecha],
                ['SubTotal', $root->getAttribute('SubTotal')],
                ['Total', $root->getAttribute('Total')],
                ['RFC Emisor', $xpath->query('//cfdi:Emisor')->item(0)->getAttribute('Rfc')],
                ['RFC Receptor', $xpath->query('//cfdi:Receptor')->item(0)->getAttribute('Rfc')],
            ]
        );

        // 9. Validar contra XSD (opcional)
        $this->comment("\n9. Validación contra XSD del SAT...");
        $xsdPath = storage_path('app/xsd/cfdv40.xsd');

        if (!file_exists($xsdPath)) {
            $this->warn("⚠ No se encontró el XSD en: {$xsdPath}");
            $this->line("  Descárgalo de: http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd");
        } else {
            libxml_use_internal_errors(true);
            if ($dom->schemaValidate($xsdPath)) {
                $this->info("✅ XML válido contra XSD del SAT");
            } else {
                $this->error("❌ Errores de validación XSD:");
                foreach (libxml_get_errors() as $error) {
                    $this->line("  - Línea {$error->line}: {$error->message}");
                }
                libxml_clear_errors();
                return;
            }
        }

        $this->info("\n✅ ¡Todas las validaciones pasaron!");
        $this->line("\nEl XML parece estar bien formado. Si Finkok aún rechaza,");
        $this->line("el problema puede estar en:");
        $this->line("  1. Certificado CSD inválido o expirado");
        $this->line("  2. Sello digital incorrecto");
        $this->line("  3. RFC del emisor no coincide con el certificado");
    }
}
