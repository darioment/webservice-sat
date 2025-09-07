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
            password
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
            :password
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
            'password' => $data['password']
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

// Procesar el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Verificar si se enviaron los archivos
        if (!isset($_FILES['certificate']) || !isset($_FILES['privateKey'])) {
            throw new Exception('Se requieren el certificado y la llave privada');
        }

        // Obtener la contraseña
        $password = $_POST['password'] ?? '';
        if (empty($password)) {
            throw new Exception('Se requiere la contraseña');
        }

        // Obtener el tipo de servicio
        $serviceType = 'cfdi';

        // Obtener las fechas
        $startDate = $_POST['startDate'] ?? '';
        $endDate = $_POST['endDate'] ?? '';

        // Crear archivos temporales para los archivos subidos
        $cerFile = tempnam(sys_get_temp_dir(), 'cer');
        $keyFile = tempnam(sys_get_temp_dir(), 'key');

        // Mover los archivos subidos a ubicaciones temporales
        move_uploaded_file($_FILES['certificate']['tmp_name'], $cerFile);
        move_uploaded_file($_FILES['privateKey']['tmp_name'], $keyFile);

        // Guardar los contenidos de los archivos antes de usarlos
        $certificateContent = file_get_contents($cerFile);
        $keyContent = file_get_contents($keyFile);

        // Crear objeto Fiel
        $fiel = Fiel::create(
            $certificateContent,
            $keyContent,
            $password
        );

        // Verificar que la FIEL sea válida
        if (!$fiel->isValid()) {
            throw new Exception('La FIEL no es válida. Verifica que los archivos correspondan a una FIEL vigente.');
        }

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

        // Crear el servicio según el tipo seleccionado
        $service = new Service(
            $requestBuilder, 
            $webClient, 
            null, 
            ServiceEndpoints::cfdi()
        );

        // Intentar autenticación
        $token = $service->authenticate();
        
        // Preparar resultado de autenticación
        $result = "Autenticación exitosa:\n";
        $result .= "RFC: " . $fiel->getRfc() . "\n";
        $result .= "Tipo de servicio: " . ($serviceType === 'retenciones' ? 'Retenciones' : 'CFDI') . "\n";
        $result .= "Token válido hasta: " . $token->getExpires()->format('Y-m-d H:i:s') . "\n";
        $result .= "Token creado: " . $token->getCreated()->format('Y-m-d H:i:s') . "\n\n";

        // Si se proporcionaron fechas, realizar la consulta
        if (!empty($startDate) && !empty($endDate)) {
            try {
                // Obtener el tipo de documento y descarga
                $documentType = $_POST['documentType'] ?? 'egreso';
                $downloadType = $_POST['downloadType'] ?? 'received';

                // Crear la consulta con los parámetros
                $request = QueryParameters::create()
                    ->withPeriod(DateTimePeriod::createFromValues($startDate . ' 00:00:00', $endDate . ' 23:59:59'))
                    ->withRequestType(RequestType::xml())
                    ->withDownloadType($downloadType === 'issued' ? DownloadType::issued() : DownloadType::received())
                    ->withDocumentType($documentType === 'ingreso' ? DocumentType::ingreso() : DocumentType::egreso())
                    ->withDocumentStatus(DocumentStatus::active());

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
                            'certificado' => $certificateContent,
                            'llave' => $keyContent,
                            'password' => $password
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
                    $queryResult .= "Tipo de Descarga: " . ($downloadType === 'issued' ? 'Emitidos' : 'Recibidos') . "\n";

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
                                'certificado' => $certificateContent,
                                'llave' => $keyContent,
                                'password' => $password
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
            } catch (Exception $e) {
                $queryResult = "Error en la consulta: " . $e->getMessage();
            }
        }

        // Limpiar archivos temporales
        @unlink($cerFile);
        @unlink($keyFile);

    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
        
        // Limpiar archivos temporales si existen
        if (isset($cerFile) && file_exists($cerFile)) {
            @unlink($cerFile);
        }
        if (isset($keyFile) && file_exists($keyFile)) {
            @unlink($keyFile);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Servicio de Autenticación SAT</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="file"],
        input[type="password"],
        input[type="date"],
        select {
            width: 100%;
            padding: 8px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background-color: #45a049;
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
        .date-group {
            display: flex;
            gap: 10px;
        }
        .date-group .form-group {
            flex: 1;
        }
        .status {
            margin-top: 10px;
            padding: 10px;
            border-radius: 4px;
        }
        .status.processing {
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
        }
        .status.success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .status.error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .db-result {
            margin-top: 20px;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #e8f4f8;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Servicio de Autenticación SAT</h1>
        
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

        <?php if ($verifyResult): ?>
            <div class="result">
                <h2>Estado de la Verificación:</h2>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($verifyResult); ?></pre>
            </div>
        <?php endif; ?>

        <?php if ($dbResult): ?>
            <div class="db-result">
                <h2>Base de Datos:</h2>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($dbResult); ?></pre>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error">
                <h2>Error:</h2>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($error); ?></pre>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="documentType">Tipo de Documento:</label>
                <select id="documentType" name="documentType">
                    <option value="ingreso">Ingresos</option>
                    <option value="egreso">Egresos</option>
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
                <label for="certificate">Certificado (.cer):</label>
                <input type="file" id="certificate" name="certificate" accept=".cer" required>
            </div>

            <div class="form-group">
                <label for="privateKey">Llave privada (.key):</label>
                <input type="file" id="privateKey" name="privateKey" accept=".key" required>
            </div>

            <div class="form-group">
                <label for="password">Contraseña:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="date-group">
                <div class="form-group">
                    <label for="startDate">Fecha de inicio:</label>
                    <input type="date" id="startDate" name="startDate">
                </div>

                <div class="form-group">
                    <label for="endDate">Fecha de fin:</label>
                    <input type="date" id="endDate" name="endDate">
                </div>
            </div>

            <button type="submit">Procesar</button>
        </form>
    </div>
</body>
</html> 