<?php
/**
 * Página para descargar paquetes de una consulta verificada del SAT
 */

// Habilitar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Iniciar sesión
session_start();

// Verificar si estamos en entorno web
if (!isset($_SERVER['REQUEST_METHOD'])) {
    die("Este script debe ejecutarse a través de un servidor web (Apache/Nginx)");
}

// Incluir el autoloader de Composer
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die("Error: No se encontró vendor/autoload.php. Ejecuta 'composer install' primero.");
}
require_once __DIR__ . '/vendor/autoload.php';

// Importar las clases necesarias
use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\Fiel;
use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\FielRequestBuilder;
use PhpCfdi\SatWsDescargaMasiva\Service;
use PhpCfdi\SatWsDescargaMasiva\WebClient\GuzzleWebClient;
use GuzzleHttp\Client as GuzzleClient;
use PhpCfdi\SatWsDescargaMasiva\Shared\ServiceEndpoints;
use PhpCfdi\SatWsDescargaMasiva\PackageReader\Exceptions\OpenZipFileException;
use PhpCfdi\SatWsDescargaMasiva\PackageReader\MetadataPackageReader;
use ZipArchive;
use SimpleXMLElement;

// Configuración de la base de datos
$dbConfig = [
    'host' => 'sec.usoreal.com',
    'dbname' => 'sat',
    'username' => 'sat',
    'password' => 'dment25SAT!.',
    'charset' => 'latin1'
];

// Función para conectar a la base de datos
function connectDB($config) {
    try {
        $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("Error de conexión a la base de datos: " . $e->getMessage());
    }
}

// Variables para almacenar resultados y errores
$result = null;
$error = null;
$downloadResult = null;
$readResult = null;
$records = [];

try {
    // Obtener registros de la base de datos
    $pdo = connectDB($dbConfig);
    $stmt = $pdo->query("SELECT * FROM sat_consultas WHERE estado != 'ERROR' ORDER BY fecha_consulta DESC");
    $records = $stmt->fetchAll();

    // Si se solicitó leer paquetes de un ID específico
    if (isset($_GET['read_id']) && !empty($_GET['read_id'])) {
        $requestId = $_GET['read_id'];
        $downloadDir = __DIR__ . '/downloads/' . $requestId;
        
        if (file_exists($downloadDir)) {
            $readResult = "Leyendo paquetes para la solicitud {$requestId}...\n\n";
            $zipFiles = glob($downloadDir . '/*.zip');
            
            if (empty($zipFiles)) {
                $readResult .= "No se encontraron paquetes para leer.\n";
            } else {
                foreach ($zipFiles as $zipfile) {
                    $packageId = basename($zipfile, '.zip');
                    $readResult .= "\nLeyendo paquete {$packageId}:\n";
                    
                    try {
                        $metadataReader = MetadataPackageReader::createFromFile($zipfile);
                        foreach ($metadataReader->metadata() as $uuid => $metadata) {
                            $readResult .= "UUID: {$metadata->uuid}\n";
                            $readResult .= "Fecha Emisión: {$metadata->fechaEmision}\n";
                            $readResult .= "Rfc Emisor: {$metadata->rfcEmisor}\n";
                            $readResult .= "Nombre Emisor: {$metadata->nombreEmisor}\n";
                            $readResult .= "Rfc Receptor: {$metadata->rfcReceptor}\n";
                            $readResult .= "Nombre Receptor: {$metadata->nombreReceptor}\n";
                            $readResult .= "Total: " . number_format((float)$metadata->total, 2) . "\n";
                            $readResult .= "Efecto: " . ($metadata->efecto ?? 'No especificado') . "\n";
                            $readResult .= "Estado: " . ($metadata->estado ?? 'No especificado') . "\n";
                            $readResult .= "Fecha Cancelación: " . ($metadata->fechaCancelacion ?? 'No cancelado') . "\n";
                            $readResult .= "----------------------------------------\n";

                            // Leer el contenido XML del CFDI
                            $zip = new ZipArchive();
                            if ($zip->open($zipfile) === TRUE) {
                                $xmlFileName = $uuid . '.xml';
                                $readResult .= "\nBuscando archivo XML: {$xmlFileName}\n";
                                
                                if ($zip->locateName($xmlFileName) !== false) {
                                    $readResult .= "Archivo XML encontrado\n";
                                    $xmlContent = $zip->getFromName($xmlFileName);
                                    if ($xmlContent !== false) {
                                        try {
                                            $xml = new SimpleXMLElement($xmlContent);
                                            $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
                                            
                                            // Extraer atributos del Comprobante
                                            $comprobante = $xml->xpath('//cfdi:Comprobante')[0];
                                            $readResult .= "\nDatos del Comprobante:\n";
                                            $readResult .= "CondicionesDePago: " . ($comprobante['CondicionesDePago'] ?? 'N/A') . "\n";
                                            $readResult .= "SubTotal: " . ($comprobante['SubTotal'] ?? 'N/A') . "\n";
                                            $readResult .= "Moneda: " . ($comprobante['Moneda'] ?? 'N/A') . "\n";
                                            $readResult .= "Total: " . ($comprobante['Total'] ?? 'N/A') . "\n";
                                            $readResult .= "TipoDeComprobante: " . ($comprobante['TipoDeComprobante'] ?? 'N/A') . "\n";
                                            $readResult .= "Exportacion: " . ($comprobante['Exportacion'] ?? 'N/A') . "\n";
                                            $readResult .= "MetodoPago: " . ($comprobante['MetodoPago'] ?? 'N/A') . "\n";
                                            $readResult .= "LugarExpedicion: " . ($comprobante['LugarExpedicion'] ?? 'N/A') . "\n";
                                            
                                            // Extraer datos del Emisor
                                            $emisor = $xml->xpath('//cfdi:Emisor')[0];
                                            $readResult .= "\nDatos del Emisor:\n";
                                            $readResult .= "RFC: " . ($emisor['Rfc'] ?? 'N/A') . "\n";
                                            $readResult .= "Nombre: " . ($emisor['Nombre'] ?? 'N/A') . "\n";
                                            $readResult .= "RegimenFiscal: " . ($emisor['RegimenFiscal'] ?? 'N/A') . "\n";
                                            
                                            // Extraer datos del Receptor
                                            $receptor = $xml->xpath('//cfdi:Receptor')[0];
                                            $readResult .= "\nDatos del Receptor:\n";
                                            $readResult .= "RFC: " . ($receptor['Rfc'] ?? 'N/A') . "\n";
                                            $readResult .= "Nombre: " . ($receptor['Nombre'] ?? 'N/A') . "\n";
                                            $readResult .= "DomicilioFiscalReceptor: " . ($receptor['DomicilioFiscalReceptor'] ?? 'N/A') . "\n";
                                            $readResult .= "RegimenFiscalReceptor: " . ($receptor['RegimenFiscalReceptor'] ?? 'N/A') . "\n";
                                            $readResult .= "UsoCFDI: " . ($receptor['UsoCFDI'] ?? 'N/A') . "\n";
                                            
                                            // Extraer datos de los Conceptos
                                            $conceptos = $xml->xpath('//cfdi:Concepto');
                                            $readResult .= "\nDatos de los Conceptos:\n";
                                            foreach ($conceptos as $concepto) {
                                                $readResult .= "ClaveProdServ: " . ($concepto['ClaveProdServ'] ?? 'N/A') . "\n";
                                                $readResult .= "NoIdentificacion: " . ($concepto['NoIdentificacion'] ?? 'N/A') . "\n";
                                                $readResult .= "Cantidad: " . ($concepto['Cantidad'] ?? 'N/A') . "\n";
                                                $readResult .= "ClaveUnidad: " . ($concepto['ClaveUnidad'] ?? 'N/A') . "\n";
                                                $readResult .= "Unidad: " . ($concepto['Unidad'] ?? 'N/A') . "\n";
                                                $readResult .= "Descripcion: " . ($concepto['Descripcion'] ?? 'N/A') . "\n";
                                                $readResult .= "ValorUnitario: " . ($concepto['ValorUnitario'] ?? 'N/A') . "\n";
                                                $readResult .= "Importe: " . ($concepto['Importe'] ?? 'N/A') . "\n";
                                                $readResult .= "ObjetoImp: " . ($concepto['ObjetoImp'] ?? 'N/A') . "\n";
                                                $readResult .= "----------------------------------------\n";
                                            }
                                            
                                            // Extraer datos de Impuestos
                                            $impuestos = $xml->xpath('//cfdi:Impuestos')[0];
                                            $readResult .= "\nDatos de Impuestos:\n";
                                            $readResult .= "TotalImpuestosTrasladados: " . ($impuestos['TotalImpuestosTrasladados'] ?? 'N/A') . "\n";
                                            
                                        } catch (Exception $e) {
                                            $readResult .= "Error al procesar el XML: " . $e->getMessage() . "\n";
                                        }
                                    } else {
                                        $readResult .= "Error al leer el contenido del archivo XML\n";
                                    }
                                } else {
                                    // Intentar buscar cualquier archivo XML en el paquete
                                    $readResult .= "Archivo específico no encontrado, buscando cualquier archivo XML...\n";
                                    for ($i = 0; $i < $zip->numFiles; $i++) {
                                        $filename = $zip->getNameIndex($i);
                                        if (pathinfo($filename, PATHINFO_EXTENSION) === 'xml') {
                                            $readResult .= "Encontrado archivo XML: {$filename}\n";
                                            $xmlContent = $zip->getFromName($filename);
                                            if ($xmlContent !== false) {
                                                try {
                                                    $xml = new SimpleXMLElement($xmlContent);
                                                    $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
                                                    
                                                    // Extraer atributos del Comprobante
                                                    $comprobante = $xml->xpath('//cfdi:Comprobante')[0];
                                                    $readResult .= "\nDatos del Comprobante:\n";
                                                    $readResult .= "CondicionesDePago: " . ($comprobante['CondicionesDePago'] ?? 'N/A') . "\n";
                                                    $readResult .= "SubTotal: " . ($comprobante['SubTotal'] ?? 'N/A') . "\n";
                                                    $readResult .= "Moneda: " . ($comprobante['Moneda'] ?? 'N/A') . "\n";
                                                    $readResult .= "Total: " . ($comprobante['Total'] ?? 'N/A') . "\n";
                                                    $readResult .= "TipoDeComprobante: " . ($comprobante['TipoDeComprobante'] ?? 'N/A') . "\n";
                                                    $readResult .= "Exportacion: " . ($comprobante['Exportacion'] ?? 'N/A') . "\n";
                                                    $readResult .= "MetodoPago: " . ($comprobante['MetodoPago'] ?? 'N/A') . "\n";
                                                    $readResult .= "LugarExpedicion: " . ($comprobante['LugarExpedicion'] ?? 'N/A') . "\n";
                                                    
                                                    // Extraer datos del Emisor
                                                    $emisor = $xml->xpath('//cfdi:Emisor')[0];
                                                    $readResult .= "\nDatos del Emisor:\n";
                                                    $readResult .= "RFC: " . ($emisor['Rfc'] ?? 'N/A') . "\n";
                                                    $readResult .= "Nombre: " . ($emisor['Nombre'] ?? 'N/A') . "\n";
                                                    $readResult .= "RegimenFiscal: " . ($emisor['RegimenFiscal'] ?? 'N/A') . "\n";
                                                    
                                                    // Extraer datos del Receptor
                                                    $receptor = $xml->xpath('//cfdi:Receptor')[0];
                                                    $readResult .= "\nDatos del Receptor:\n";
                                                    $readResult .= "RFC: " . ($receptor['Rfc'] ?? 'N/A') . "\n";
                                                    $readResult .= "Nombre: " . ($receptor['Nombre'] ?? 'N/A') . "\n";
                                                    $readResult .= "DomicilioFiscalReceptor: " . ($receptor['DomicilioFiscalReceptor'] ?? 'N/A') . "\n";
                                                    $readResult .= "RegimenFiscalReceptor: " . ($receptor['RegimenFiscalReceptor'] ?? 'N/A') . "\n";
                                                    $readResult .= "UsoCFDI: " . ($receptor['UsoCFDI'] ?? 'N/A') . "\n";
                                                    
                                                    // Extraer datos de los Conceptos
                                                    $conceptos = $xml->xpath('//cfdi:Concepto');
                                                    $readResult .= "\nDatos de los Conceptos:\n";
                                                    foreach ($conceptos as $concepto) {
                                                        $readResult .= "ClaveProdServ: " . ($concepto['ClaveProdServ'] ?? 'N/A') . "\n";
                                                        $readResult .= "NoIdentificacion: " . ($concepto['NoIdentificacion'] ?? 'N/A') . "\n";
                                                        $readResult .= "Cantidad: " . ($concepto['Cantidad'] ?? 'N/A') . "\n";
                                                        $readResult .= "ClaveUnidad: " . ($concepto['ClaveUnidad'] ?? 'N/A') . "\n";
                                                        $readResult .= "Unidad: " . ($concepto['Unidad'] ?? 'N/A') . "\n";
                                                        $readResult .= "Descripcion: " . ($concepto['Descripcion'] ?? 'N/A') . "\n";
                                                        $readResult .= "ValorUnitario: " . ($concepto['ValorUnitario'] ?? 'N/A') . "\n";
                                                        $readResult .= "Importe: " . ($concepto['Importe'] ?? 'N/A') . "\n";
                                                        $readResult .= "ObjetoImp: " . ($concepto['ObjetoImp'] ?? 'N/A') . "\n";
                                                        $readResult .= "----------------------------------------\n";
                                                    }
                                                    
                                                    // Extraer datos de Impuestos
                                                    $impuestos = $xml->xpath('//cfdi:Impuestos')[0];
                                                    $readResult .= "\nDatos de Impuestos:\n";
                                                    $readResult .= "TotalImpuestosTrasladados: " . ($impuestos['TotalImpuestosTrasladados'] ?? 'N/A') . "\n";
                                                    
                                                } catch (Exception $e) {
                                                    $readResult .= "Error al procesar el XML: " . $e->getMessage() . "\n";
                                                }
                                            }
                                        }
                                    }
                                }
                                $zip->close();
                            } else {
                                $readResult .= "Error al abrir el archivo ZIP\n";
                            }
                        }
                    } catch (OpenZipFileException $exception) {
                        $readResult .= "Error al leer el paquete {$packageId}: {$exception->getMessage()}\n";
                    }
                }
            }
        } else {
            $readResult = "No se encontraron paquetes descargados para la solicitud {$requestId}.\n";
        }
    }

    // Si se solicitó descargar paquetes de un ID específico
    if (isset($_GET['download_id']) && !empty($_GET['download_id'])) {
        $requestId = $_GET['download_id'];
        
        // Obtener el registro específico
        $stmt = $pdo->prepare("SELECT * FROM sat_consultas WHERE id_solicitud = ?");
        $stmt->execute([$requestId]);
        $record = $stmt->fetch();

        if ($record) {
            // Crear archivos temporales para el certificado y la llave
            $cerFile = tempnam(sys_get_temp_dir(), 'cer');
            $keyFile = tempnam(sys_get_temp_dir(), 'key');

            // Guardar los contenidos en archivos temporales
            file_put_contents($cerFile, $record['certificado']);
            file_put_contents($keyFile, $record['llave']);

            try {
                // Crear objeto Fiel
                $fiel = Fiel::create(
                    file_get_contents($cerFile),
                    file_get_contents($keyFile),
                    $record['password']
                );

                // Configuración de Guzzle Client
                $guzzleClient = new GuzzleClient([
                    'timeout' => 120,
                    'connect_timeout' => 60,
                    'http_errors' => false,
                    'verify' => true,
                    'headers' => [
                        'User-Agent' => 'phpcfdi/sat-ws-descarga-masiva-guzzle',
                        'Accept' => 'application/xml',
                        'Cache-Control' => 'no-cache'
                    ]
                ]);

                // Crear cliente HTTP con Guzzle
                $webClient = new GuzzleWebClient($guzzleClient);

                // Crear constructor de solicitudes con la FIEL
                $requestBuilder = new FielRequestBuilder($fiel);

                // Crear el servicio según el tipo guardado
                $service = new Service(
                    $requestBuilder, 
                    $webClient, 
                    null, 
                    $record['tipo_servicio'] === 'Retenciones' ? ServiceEndpoints::retenciones() : null
                );

                // Primero verificar el estado de la solicitud
                $verify = $service->verify($requestId);

                if (!$verify->getStatus()->isAccepted()) {
                    throw new Exception("Fallo al verificar la consulta {$requestId}: " . $verify->getStatus()->getMessage());
                }

                if (!$verify->getCodeRequest()->isAccepted()) {
                    throw new Exception("La solicitud {$requestId} fue rechazada: " . $verify->getCodeRequest()->getMessage());
                }

                $statusRequest = $verify->getStatusRequest();
                if (!$statusRequest->isFinished()) {
                    throw new Exception("La solicitud {$requestId} aún no está lista para descargar");
                }

                // Obtener los IDs de los paquetes
                $packagesIds = $verify->getPackagesIds();
                if (empty($packagesIds)) {
                    throw new Exception("No se encontraron paquetes para la solicitud {$requestId}");
                }

                // Crear directorio para los paquetes si no existe
                $downloadDir = __DIR__ . '/downloads/' . $requestId;
                if (!file_exists($downloadDir)) {
                    mkdir($downloadDir, 0777, true);
                }

                // Descargar cada paquete
                $downloadResult = "Iniciando descarga de paquetes para la solicitud {$requestId}...\n\n";
                foreach ($packagesIds as $packageId) {
                    $download = $service->download($packageId);
                    if (!$download->getStatus()->isAccepted()) {
                        $downloadResult .= "El paquete {$packageId} no se ha podido descargar: {$download->getStatus()->getMessage()}\n";
                        continue;
                    }

                    $zipfile = $downloadDir . '/' . $packageId . '.zip';
                    file_put_contents($zipfile, $download->getPackageContent());
                    $downloadResult .= "El paquete {$packageId} se ha almacenado en: {$zipfile}\n";

                    // Leer el contenido del paquete
                    try {
                        $metadataReader = MetadataPackageReader::createFromFile($zipfile);
                        $downloadResult .= "\nContenido del paquete {$packageId}:\n";
                        foreach ($metadataReader->metadata() as $uuid => $metadata) {
                            $downloadResult .= "UUID: {$metadata->uuid}\n";
                            $downloadResult .= "Fecha Emisión: {$metadata->fechaEmision}\n";
                            $downloadResult .= "Rfc Emisor: {$metadata->rfcEmisor}\n";
                            $downloadResult .= "Nombre Emisor: {$metadata->nombreEmisor}\n";
                            $downloadResult .= "Rfc Receptor: {$metadata->rfcReceptor}\n";
                            $downloadResult .= "Nombre Receptor: {$metadata->nombreReceptor}\n";
                            $downloadResult .= "Total: " . number_format((float)$metadata->total, 2) . "\n";
                            $downloadResult .= "Efecto: " . ($metadata->efecto ?? 'No especificado') . "\n";
                            $downloadResult .= "Estado: " . ($metadata->estado ?? 'No especificado') . "\n";
                            $downloadResult .= "Fecha Cancelación: " . ($metadata->fechaCancelacion ?? 'No cancelado') . "\n";
                            $downloadResult .= "----------------------------------------\n";

                            // Leer el contenido XML del CFDI
                            $zip = new ZipArchive();
                            if ($zip->open($zipfile) === TRUE) {
                                $xmlFileName = $uuid . '.xml';
                                $downloadResult .= "\nBuscando archivo XML: {$xmlFileName}\n";
                                
                                if ($zip->locateName($xmlFileName) !== false) {
                                    $downloadResult .= "Archivo XML encontrado\n";
                                    $xmlContent = $zip->getFromName($xmlFileName);
                                    if ($xmlContent !== false) {
                                        try {
                                            $xml = new SimpleXMLElement($xmlContent);
                                            $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
                                            
                                            // Extraer atributos del Comprobante
                                            $comprobante = $xml->xpath('//cfdi:Comprobante')[0];
                                            $downloadResult .= "\nDatos del Comprobante:\n";
                                            $downloadResult .= "CondicionesDePago: " . ($comprobante['CondicionesDePago'] ?? 'N/A') . "\n";
                                            $downloadResult .= "SubTotal: " . ($comprobante['SubTotal'] ?? 'N/A') . "\n";
                                            $downloadResult .= "Moneda: " . ($comprobante['Moneda'] ?? 'N/A') . "\n";
                                            $downloadResult .= "Total: " . ($comprobante['Total'] ?? 'N/A') . "\n";
                                            $downloadResult .= "TipoDeComprobante: " . ($comprobante['TipoDeComprobante'] ?? 'N/A') . "\n";
                                            $downloadResult .= "Exportacion: " . ($comprobante['Exportacion'] ?? 'N/A') . "\n";
                                            $downloadResult .= "MetodoPago: " . ($comprobante['MetodoPago'] ?? 'N/A') . "\n";
                                            $downloadResult .= "LugarExpedicion: " . ($comprobante['LugarExpedicion'] ?? 'N/A') . "\n";
                                            
                                            // Extraer datos del Emisor
                                            $emisor = $xml->xpath('//cfdi:Emisor')[0];
                                            $downloadResult .= "\nDatos del Emisor:\n";
                                            $downloadResult .= "RFC: " . ($emisor['Rfc'] ?? 'N/A') . "\n";
                                            $downloadResult .= "Nombre: " . ($emisor['Nombre'] ?? 'N/A') . "\n";
                                            $downloadResult .= "RegimenFiscal: " . ($emisor['RegimenFiscal'] ?? 'N/A') . "\n";
                                            
                                            // Extraer datos del Receptor
                                            $receptor = $xml->xpath('//cfdi:Receptor')[0];
                                            $downloadResult .= "\nDatos del Receptor:\n";
                                            $downloadResult .= "RFC: " . ($receptor['Rfc'] ?? 'N/A') . "\n";
                                            $downloadResult .= "Nombre: " . ($receptor['Nombre'] ?? 'N/A') . "\n";
                                            $downloadResult .= "DomicilioFiscalReceptor: " . ($receptor['DomicilioFiscalReceptor'] ?? 'N/A') . "\n";
                                            $downloadResult .= "RegimenFiscalReceptor: " . ($receptor['RegimenFiscalReceptor'] ?? 'N/A') . "\n";
                                            $downloadResult .= "UsoCFDI: " . ($receptor['UsoCFDI'] ?? 'N/A') . "\n";
                                            
                                            // Extraer datos de los Conceptos
                                            $conceptos = $xml->xpath('//cfdi:Concepto');
                                            $downloadResult .= "\nDatos de los Conceptos:\n";
                                            foreach ($conceptos as $concepto) {
                                                $downloadResult .= "ClaveProdServ: " . ($concepto['ClaveProdServ'] ?? 'N/A') . "\n";
                                                $downloadResult .= "NoIdentificacion: " . ($concepto['NoIdentificacion'] ?? 'N/A') . "\n";
                                                $downloadResult .= "Cantidad: " . ($concepto['Cantidad'] ?? 'N/A') . "\n";
                                                $downloadResult .= "ClaveUnidad: " . ($concepto['ClaveUnidad'] ?? 'N/A') . "\n";
                                                $downloadResult .= "Unidad: " . ($concepto['Unidad'] ?? 'N/A') . "\n";
                                                $downloadResult .= "Descripcion: " . ($concepto['Descripcion'] ?? 'N/A') . "\n";
                                                $downloadResult .= "ValorUnitario: " . ($concepto['ValorUnitario'] ?? 'N/A') . "\n";
                                                $downloadResult .= "Importe: " . ($concepto['Importe'] ?? 'N/A') . "\n";
                                                $downloadResult .= "ObjetoImp: " . ($concepto['ObjetoImp'] ?? 'N/A') . "\n";
                                                $downloadResult .= "----------------------------------------\n";
                                            }
                                            
                                            // Extraer datos de Impuestos
                                            $impuestos = $xml->xpath('//cfdi:Impuestos')[0];
                                            $downloadResult .= "\nDatos de Impuestos:\n";
                                            $downloadResult .= "TotalImpuestosTrasladados: " . ($impuestos['TotalImpuestosTrasladados'] ?? 'N/A') . "\n";
                                            
                                        } catch (Exception $e) {
                                            $downloadResult .= "Error al procesar el XML: " . $e->getMessage() . "\n";
                                        }
                                    } else {
                                        $downloadResult .= "Error al leer el contenido del archivo XML\n";
                                    }
                                } else {
                                    // Intentar buscar cualquier archivo XML en el paquete
                                    $downloadResult .= "Archivo específico no encontrado, buscando cualquier archivo XML...\n";
                                    for ($i = 0; $i < $zip->numFiles; $i++) {
                                        $filename = $zip->getNameIndex($i);
                                        if (pathinfo($filename, PATHINFO_EXTENSION) === 'xml') {
                                            $downloadResult .= "Encontrado archivo XML: {$filename}\n";
                                            $xmlContent = $zip->getFromName($filename);
                                            if ($xmlContent !== false) {
                                                try {
                                                    $xml = new SimpleXMLElement($xmlContent);
                                                    $xml->registerXPathNamespace('cfdi', 'http://www.sat.gob.mx/cfd/4');
                                                    
                                                    // Extraer atributos del Comprobante
                                                    $comprobante = $xml->xpath('//cfdi:Comprobante')[0];
                                                    $downloadResult .= "\nDatos del Comprobante:\n";
                                                    $downloadResult .= "CondicionesDePago: " . ($comprobante['CondicionesDePago'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "SubTotal: " . ($comprobante['SubTotal'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "Moneda: " . ($comprobante['Moneda'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "Total: " . ($comprobante['Total'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "TipoDeComprobante: " . ($comprobante['TipoDeComprobante'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "Exportacion: " . ($comprobante['Exportacion'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "MetodoPago: " . ($comprobante['MetodoPago'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "LugarExpedicion: " . ($comprobante['LugarExpedicion'] ?? 'N/A') . "\n";
                                                    
                                                    // Extraer datos del Emisor
                                                    $emisor = $xml->xpath('//cfdi:Emisor')[0];
                                                    $downloadResult .= "\nDatos del Emisor:\n";
                                                    $downloadResult .= "RFC: " . ($emisor['Rfc'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "Nombre: " . ($emisor['Nombre'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "RegimenFiscal: " . ($emisor['RegimenFiscal'] ?? 'N/A') . "\n";
                                                    
                                                    // Extraer datos del Receptor
                                                    $receptor = $xml->xpath('//cfdi:Receptor')[0];
                                                    $downloadResult .= "\nDatos del Receptor:\n";
                                                    $downloadResult .= "RFC: " . ($receptor['Rfc'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "Nombre: " . ($receptor['Nombre'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "DomicilioFiscalReceptor: " . ($receptor['DomicilioFiscalReceptor'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "RegimenFiscalReceptor: " . ($receptor['RegimenFiscalReceptor'] ?? 'N/A') . "\n";
                                                    $downloadResult .= "UsoCFDI: " . ($receptor['UsoCFDI'] ?? 'N/A') . "\n";
                                                    
                                                    // Extraer datos de los Conceptos
                                                    $conceptos = $xml->xpath('//cfdi:Concepto');
                                                    $downloadResult .= "\nDatos de los Conceptos:\n";
                                                    foreach ($conceptos as $concepto) {
                                                        $downloadResult .= "ClaveProdServ: " . ($concepto['ClaveProdServ'] ?? 'N/A') . "\n";
                                                        $downloadResult .= "NoIdentificacion: " . ($concepto['NoIdentificacion'] ?? 'N/A') . "\n";
                                                        $downloadResult .= "Cantidad: " . ($concepto['Cantidad'] ?? 'N/A') . "\n";
                                                        $downloadResult .= "ClaveUnidad: " . ($concepto['ClaveUnidad'] ?? 'N/A') . "\n";
                                                        $downloadResult .= "Unidad: " . ($concepto['Unidad'] ?? 'N/A') . "\n";
                                                        $downloadResult .= "Descripcion: " . ($concepto['Descripcion'] ?? 'N/A') . "\n";
                                                        $downloadResult .= "ValorUnitario: " . ($concepto['ValorUnitario'] ?? 'N/A') . "\n";
                                                        $downloadResult .= "Importe: " . ($concepto['Importe'] ?? 'N/A') . "\n";
                                                        $downloadResult .= "ObjetoImp: " . ($concepto['ObjetoImp'] ?? 'N/A') . "\n";
                                                        $downloadResult .= "----------------------------------------\n";
                                                    }
                                                    
                                                    // Extraer datos de Impuestos
                                                    $impuestos = $xml->xpath('//cfdi:Impuestos')[0];
                                                    $downloadResult .= "\nDatos de Impuestos:\n";
                                                    $downloadResult .= "TotalImpuestosTrasladados: " . ($impuestos['TotalImpuestosTrasladados'] ?? 'N/A') . "\n";
                                                    
                                                } catch (Exception $e) {
                                                    $downloadResult .= "Error al procesar el XML: " . $e->getMessage() . "\n";
                                                }
                                            }
                                        }
                                    }
                                }
                                $zip->close();
                            } else {
                                $downloadResult .= "Error al abrir el archivo ZIP\n";
                            }
                        }
                    } catch (OpenZipFileException $exception) {
                        $downloadResult .= "Error al leer el paquete {$packageId}: {$exception->getMessage()}\n";
                    }
                }

                $downloadResult .= "\nDescarga completada. Se descargaron " . count($packagesIds) . " paquetes.";

                // Limpiar archivos temporales
                @unlink($cerFile);
                @unlink($keyFile);

            } catch (Exception $e) {
                // Limpiar archivos temporales en caso de error
                @unlink($cerFile);
                @unlink($keyFile);
                throw $e;
            }
        } else {
            $error = "No se encontró el registro con el ID de solicitud especificado";
        }
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Descarga de Paquetes SAT</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background-color: #f5f5f5;
        }
        tr:hover {
            background-color: #f9f9f9;
        }
        .download-link {
            color: #0066cc;
            text-decoration: none;
        }
        .download-link:hover {
            text-decoration: underline;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f9f9f9;
        }
        .error {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 10px;
            margin-top: 10px;
            border-radius: 4px;
        }
        .status {
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 0.9em;
        }
        .status.error {
            background-color: #f8d7da;
            color: #721c24;
        }
        .status.success {
            background-color: #d4edda;
            color: #155724;
        }
        .status.processing {
            background-color: #fff3cd;
            color: #856404;
        }
        .read-link {
            color: #28a745;
            text-decoration: none;
        }
        .read-link:hover {
            text-decoration: underline;
        }
        .download-zip-link {
            color: #dc3545;
            text-decoration: none;
            margin-right: 5px;
        }
        .download-zip-link:hover {
            text-decoration: underline;
        }
    </style>
    <script>
    function leerPaquetes(requestId) {
        // Crear un elemento div para mostrar los resultados
        var resultDiv = document.createElement('div');
        resultDiv.className = 'result';
        resultDiv.innerHTML = '<h2>Leyendo paquetes...</h2><div id="loading">Procesando...</div>';
        document.querySelector('.container').insertBefore(resultDiv, document.querySelector('table'));

        // Realizar la petición AJAX
        fetch('leer_paquetes.php?id=' + encodeURIComponent(requestId))
            .then(response => response.text())
            .then(data => {
                document.getElementById('loading').remove();
                resultDiv.innerHTML = '<h2>Resultado de la Lectura:</h2><pre style="white-space: pre-wrap; word-wrap: break-word;">' + data + '</pre>';
            })
            .catch(error => {
                document.getElementById('loading').remove();
                resultDiv.innerHTML = '<h2>Error:</h2><pre style="white-space: pre-wrap; word-wrap: break-word;">Error al leer los paquetes: ' + error + '</pre>';
            });
    }
    </script>
</head>
<body>
    <div class="container">
        <h1>Descarga de Paquetes SAT</h1>

        <?php if ($downloadResult): ?>
            <div class="result">
                <h2>Resultado de la Descarga:</h2>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($downloadResult); ?></pre>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error">
                <h2>Error:</h2>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($error); ?></pre>
            </div>
        <?php endif; ?>

        <?php if ($readResult): ?>
            <div class="result">
                <h2>Resultado de la Lectura:</h2>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($readResult); ?></pre>
            </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>RFC</th>
                    <th>Tipo Servicio</th>
                    <th>ID Solicitud</th>
                    <th>Estado</th>
                    <th>Mensaje</th>
                    <th>Fecha</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($record['id']); ?></td>
                        <td><?php echo htmlspecialchars($record['rfc']); ?></td>
                        <td><?php echo htmlspecialchars($record['tipo_servicio']); ?></td>
                        <td><?php echo htmlspecialchars($record['id_solicitud']); ?></td>
                        <td>
                            <span class="status <?php echo $record['estado'] === 'ERROR' ? 'error' : 'success'; ?>">
                                <?php echo htmlspecialchars($record['estado']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($record['mensaje']); ?></td>
                        <td><?php echo htmlspecialchars($record['fecha_consulta']); ?></td>
                        <td>
                            <?php if (!empty($record['id_solicitud'])): ?>
                                <a href="?download_id=<?php echo urlencode($record['id_solicitud']); ?>" class="download-link">
                                    Descargar Paquetes
                                </a>
                                <?php if (file_exists(__DIR__ . '/downloads/' . $record['id_solicitud'])): ?>
                                    | 
                                    <a href="javascript:void(0);" onclick="leerPaquetes('<?php echo urlencode($record['id_solicitud']); ?>')" class="read-link">
                                        Leer Paquetes
                                    </a>
                                    | 
                                    <?php
                                    $zipFiles = glob(__DIR__ . '/downloads/' . $record['id_solicitud'] . '/*.zip');
                                    if (!empty($zipFiles)) {
                                        foreach ($zipFiles as $zipFile) {
                                            $fileName = basename($zipFile);
                                            echo '<a href="downloads/' . urlencode($record['id_solicitud']) . '/' . urlencode($fileName) . '" class="download-zip-link" download>';
                                            echo 'Descargar ' . htmlspecialchars($fileName);
                                            echo '</a> | ';
                                        }
                                    }
                                    ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html> 