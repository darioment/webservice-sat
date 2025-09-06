<?php
/**
 * Servicio para autenticación con el SAT usando FIEL
 * Basado en la librería phpcfdi/sat-ws-descarga-masiva
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
use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\RequestBuilderInterface;
use PhpCfdi\SatWsDescargaMasiva\Shared\ServiceEndpoints;
use PhpCfdi\SatWsDescargaMasiva\Services\Query\QueryParameters;
use PhpCfdi\SatWsDescargaMasiva\Shared\DateTimePeriod;
use PhpCfdi\SatWsDescargaMasiva\Shared\DocumentType;
use PhpCfdi\SatWsDescargaMasiva\Shared\DownloadType;
use PhpCfdi\SatWsDescargaMasiva\Shared\RequestType;
use PhpCfdi\SatWsDescargaMasiva\Shared\DocumentStatus;

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


// Función para guardar los datos en la base de datos
function saveSatData($pdo, $data) {
    try {
        $sql = "INSERT INTO sat_consultas (
            rfc,
            tipo_servicio,
            token_valido_hasta,
            token_creado,
            id_solicitud,
            estado,
            mensaje,
            fecha_consulta,
            certificado,
            llave,
            password,
            request
        ) VALUES (
            :rfc,
            :tipo_servicio,
            :token_valido_hasta,
            :token_creado,
            :id_solicitud,
            :estado,
            :mensaje,
            NOW(),
            :certificado,
            :llave,
            :password,
            :request
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'rfc' => $data['rfc'],
            'tipo_servicio' => $data['tipo_servicio'],
            'token_valido_hasta' => $data['token_valido_hasta'],
            'token_creado' => $data['token_creado'],
            'id_solicitud' => $data['id_solicitud'],
            'estado' => $data['estado'],
            'mensaje' => $data['mensaje'],
            'certificado' => $data['certificado'],
            'llave' => $data['llave'],
            'password' => $data['password'],
            'request' => $data['request']
        ]);

        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        throw new Exception("Error al guardar los datos: " . $e->getMessage());
    }
}

// Variables para almacenar resultados y errores
$result = null;
$error = null;
$queryResult = null;
$verifyResult = null;
$dbResult = null;
$records = [];

try {
    // Obtener registros de la base de datos para mostrar opciones al usuario
    $pdo = connectDB($dbConfig);
    $stmt = $pdo->query("SELECT * FROM sat_consultas ORDER BY fecha_consulta DESC");
    $records = $stmt->fetchAll();

    // Procesar el formulario de selección
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registro_id'])) {
        $registroId = $_POST['registro_id'];
        // Obtener el registro específico
        $stmt = $pdo->prepare("SELECT * FROM sat_consultas WHERE id = ?");
        $stmt->execute([$registroId]);
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

                if (!$fiel->isValid()) {
                    throw new Exception('La FIEL no es válida. Verifica que los archivos correspondan a una FIEL vigente.');
                }

                // creación del web client basado en Guzzle que implementa WebClientInterface
                // para usarlo necesitas instalar guzzlehttp/guzzle, pues no es una dependencia directa
                $webClient = new GuzzleWebClient();

                // Crear constructor de solicitudes con la FIEL
                $requestBuilder = new FielRequestBuilder($fiel);

                // Crear el servicio según el tipo guardado
                $service = new Service(
                    $requestBuilder, 
                    $webClient, 
                    null, 
                    $record['tipo_servicio'] === 'Retenciones' ? ServiceEndpoints::retenciones() : null
                );

                // Intentar autenticación
                $token = $service->authenticate();
                $result = "Autenticación exitosa:\n";
                $result .= "RFC: " . $fiel->getRfc() . "\n";
                $result .= "Tipo de servicio: " . ($record['tipo_servicio'] === 'Retenciones' ? 'Retenciones' : 'CFDI') . "\n";
                $result .= "Token válido hasta: " . $token->getExpires()->format('Y-m-d H:i:s') . "\n";
                $result .= "Token creado: " . $token->getCreated()->format('Y-m-d H:i:s') . "\n\n";

                // Si se proporcionaron fechas, realizar la consulta
                if (!empty($_POST['startDate']) && !empty($_POST['endDate'])) {
                    $startDate = $_POST['startDate'];
                    $endDate = $_POST['endDate'];
                    $documentType = $_POST['documentType'] ?? 'undefined';
                    $downloadType = $_POST['downloadType'] ?? 'received';
                    $documentStatus = $_POST['documentStatus'] ?? 'undefined';
                    $requestType = $_POST['requestType'] ?? 'metadata';

                    $request = QueryParameters::create()
                        ->withPeriod(DateTimePeriod::createFromValues($startDate . ' 00:00:00', $endDate . ' 23:59:59'))
                        ->withDownloadType($downloadType === 'issued' ? DownloadType::issued() : DownloadType::received());

                    // Aplicar el tipo de solicitud según la selección
                    switch ($requestType) {
                        case 'xml':
                            $request = $request->withRequestType(RequestType::xml());
                            break;
                        case 'metadata':
                        default:
                            $request = $request->withRequestType(RequestType::metadata());
                            break;
                    }

                    // Aplicar el tipo de documento según la selección
                    switch ($documentType) {
                        case 'ingreso':
                            $request = $request->withDocumentType(DocumentType::ingreso());
                            break;
                        case 'egreso':
                            $request = $request->withDocumentType(DocumentType::egreso());
                            break;
                        case 'traslado':
                            $request = $request->withDocumentType(DocumentType::traslado());
                            break;
                        case 'nomina':
                            $request = $request->withDocumentType(DocumentType::nomina());
                            break;
                        case 'pago':
                            $request = $request->withDocumentType(DocumentType::pago());
                            break;
                        case 'undefined':
                        default:
                            $request = $request->withDocumentType(DocumentType::undefined());
                            break;
                    }

                    // Aplicar el estado del documento según la selección
                    switch ($documentStatus) {
                        case 'active':
                            $request = $request->withDocumentStatus(DocumentStatus::active());
                            break;
                        case 'cancelled':
                            $request = $request->withDocumentStatus(DocumentStatus::cancelled());
                            break;
                        case 'undefined':
                        default:
                            $request = $request->withDocumentStatus(DocumentStatus::undefined());
                            break;
                    }

                // Presentar la consulta
                $query = $service->query($request);

                // Verificar que el proceso de consulta fue correcto
                if (!$query->getStatus()->isAccepted()) {
                    $queryResult = "Fallo al presentar la consulta: " . $query->getStatus()->getMessage();
                    // guarda en la base de datos el error
                    try {
                        $pdo = connectDB($dbConfig);
                        
                        $data = [
                            'rfc' => $fiel->getRfc(),
                            'tipo_servicio' => 'CFDI',
                            'token_valido_hasta' => $token->getExpires()->format('Y-m-d H:i:s'),
                            'token_creado' => $token->getCreated()->format('Y-m-d H:i:s'),
                            'id_solicitud' => '',
                            'estado' => 'ERROR',
                            'mensaje' => $query->getStatus()->getMessage(),
                            'certificado' => $record['certificado'],
                            'llave' => $record['llave'],
                            'password' => $record['password'],
                            'request' => $requestType . "-" . $documentType . "-" . $downloadType . "-" . $documentStatus . "-" . $startDate . "-" . $endDate
                        ];

                        $insertId = saveSatData($pdo, $data);
                        $dbResult = "Datos guardados en la base de datos con ID: " . $insertId;
                    } catch (Exception $e) {
                        $dbResult = "Error al guardar en la base de datos: " . $e->getMessage();
                    }
                } else {
                    $queryResult = "Consulta exitosa:\n";
                    $queryResult .= "ID de solicitud: " . $query->getRequestId() . "\n";
                    $queryResult .= "Estado: " . $query->getStatus()->getCode() . "\n";
                    $queryResult .= "Mensaje: " . $query->getStatus()->getMessage() . "\n";
                    $queryResult .= "Tipo de Solicitud: " . ($requestType === 'xml' ? 'XML' : 'Metadatos') . "\n";
                    $queryResult .= "Tipo de Descarga: " . ($downloadType === 'issued' ? 'Emitidos' : 'Recibidos') . "\n";
                    $queryResult .= "Tipo de Documento: " . $documentType . "\n";
                    $queryResult .= "Estado del Documento: " . $documentStatus . "\n";
                    $queryResult .= "Fecha Inicial: " . $startDate . "\n";
                    $queryResult .= "Fecha Final: " . $endDate . "\n";

                    // Si la consulta fue exitosa, guardar en la base de datos
                    if ($query->getStatus()->isAccepted()) {
                        try {
                            $pdo = connectDB($dbConfig);
                            
                            $data = [
                                'rfc' => $fiel->getRfc(),
                                'tipo_servicio' => 'CFDI',
                                'token_valido_hasta' => $token->getExpires()->format('Y-m-d H:i:s'),
                                'token_creado' => $token->getCreated()->format('Y-m-d H:i:s'),
                                'id_solicitud' => $query->getRequestId(),
                                'estado' => $query->getStatus()->getCode(),
                                'mensaje' => $query->getStatus()->getMessage(),
                                'certificado' => $record['certificado'],
                                'llave' => $record['llave'],
                                'password' => $record['password'],
                                'request' => $requestType . "-" . $documentType . "-" . $downloadType . "-" . $documentStatus . "-" . $startDate . "-" . $endDate
                            ];

                            $insertId = saveSatData($pdo, $data);
                            $dbResult = "Datos guardados en la base de datos con ID: " . $insertId;
                        } catch (Exception $e) {
                            $dbResult = "Error al guardar en la base de datos: " . $e->getMessage();
                        }
                    }

                    // Realizar la verificación
                    $requestId = $query->getRequestId();
                    $verify = $service->verify($requestId);

                    // Revisar que el proceso de verificación fue correcto
                    if (!$verify->getStatus()->isAccepted()) {
                        $verifyResult = "Fallo al verificar la consulta {$requestId}: " . $verify->getStatus()->getMessage();
                    } else {
                        // Revisar que la consulta no haya sido rechazada
                        if (!$verify->getCodeRequest()->isAccepted()) {
                            $verifyResult = "La solicitud {$requestId} fue rechazada: " . $verify->getCodeRequest()->getMessage();
                        } else {
                            // Revisar el progreso de la generación de los paquetes
                            $statusRequest = $verify->getStatusRequest();
                            if ($statusRequest->isExpired() || $statusRequest->isFailure() || $statusRequest->isRejected()) {
                                $verifyResult = "La solicitud {$requestId} no se puede completar";
                            } elseif ($statusRequest->isInProgress() || $statusRequest->isAccepted()) {
                                $verifyResult = "La solicitud {$requestId} se está procesando";
                            } elseif ($statusRequest->isFinished()) {
                                $verifyResult = "La solicitud {$requestId} está lista\n";
                                $verifyResult .= "Se encontraron " . $verify->countPackages() . " paquetes\n";
                                foreach ($verify->getPackagesIds() as $packageId) {
                                    $verifyResult .= " > {$packageId}\n";
                                }
                            }
                        }
                    }
                }
            }

                @unlink($cerFile);
                @unlink($keyFile);
            } catch (Exception $e) {
                @unlink($cerFile);
                @unlink($keyFile);
                $error = 'Error: ' . $e->getMessage();
            }
        } else {
            $error = "No se encontró el registro seleccionado.";
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
    <title>Servicio de Autenticación SAT (desde BD)</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 800px; margin: 0 auto; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        select, input[type="date"] { width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 4px; }
        button { background-color: #4CAF50; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background-color: #45a049; }
        .result { margin-top: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background-color: #f9f9f9; }
        .error { color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin-top: 10px; border-radius: 4px; }
        .date-group { display: flex; gap: 10px; }
        .date-group .form-group { flex: 1; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Servicio de Autenticación SAT (desde BD)</h1>

        <?php if ($result): ?>
            <div class="result">
                <h2>Resultado de Autenticación:</h2>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($result); ?></pre>
            </div>
        <?php endif; ?>

        <?php if ($queryResult): ?>
            <div class="result">
                <h2>Resultado de Consulta:</h2>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($queryResult); ?></pre>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error">
                <h2>Error:</h2>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($error); ?></pre>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="form-group">
                <label for="registro_id">Selecciona un registro de FIEL guardado:</label>
                <select id="registro_id" name="registro_id" required>
                    <option value="">-- Selecciona --</option>
                    <?php foreach ($records as $rec): ?>
                        <option value="<?php echo htmlspecialchars($rec['id']); ?>">
                            <?php echo htmlspecialchars($rec['id'] . ' | ' . $rec['rfc'] . ' | ' . $rec['fecha_consulta']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="documentType">Tipo de Documento:</label>
                <select id="documentType" name="documentType">
                    <option value="undefined" selected>Cualquiera (Sin filtro)</option>
                    <option value="ingreso">Ingresos</option>
                    <option value="egreso">Egresos</option>
                    <option value="traslado">Traslados</option>
                    <option value="nomina">Nómina</option>
                    <option value="pago">Pagos</option>
                </select>
            </div>
            <div class="form-group">
                <label for="downloadType">Tipo de Descarga:</label>
                <select id="downloadType" name="downloadType">
                    <option value="received">Recibidos</option>
                    <option value="issued">Emitidos</option>
                </select>
            </div>
            <div class="form-group">
                <label for="documentStatus">Estado del Documento:</label>
                <select id="documentStatus" name="documentStatus">
                    <option value="undefined" selected>Cualquiera (Sin filtro)</option>
                    <option value="active">Vigente</option>
                    <option value="cancelled">Cancelado</option>
                </select>
            </div>
            <div class="form-group">
                <label for="requestType">Tipo de Solicitud:</label>
                <select id="requestType" name="requestType">
                    <option value="metadata" selected>Metadatos</option>
                    <option value="xml">XML</option>
                </select>
            </div>
            <div class="date-group">
                <div class="form-group">
                    <label for="startDate">Fecha de inicio:</label>
                    <input type="date" id="startDate" name="startDate" value="2023-01-02">
                </div>
                <div class="form-group">
                    <label for="endDate">Fecha de fin:</label>
                    <input type="date" id="endDate" name="endDate" value="2023-01-30">
                </div>
            </div>
            <button type="submit">Procesar</button>
        </form>
    </div>
</body>
</html> 