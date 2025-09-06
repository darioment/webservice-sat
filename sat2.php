<?php
/**
 * Página para verificar el estado de las solicitudes al SAT
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
$verifyResult = null;
$records = [];

try {
    // Obtener registros de la base de datos
    $pdo = connectDB($dbConfig);
    $stmt = $pdo->query("SELECT * FROM sat_consultas ORDER BY fecha_consulta DESC");
    $records = $stmt->fetchAll();

    // Si se solicitó verificar un ID específico
    if (isset($_GET['verify_id']) && !empty($_GET['verify_id'])) {
        $requestId = $_GET['verify_id'];
        
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

                // Realizar la verificación
                $verify = $service->verify($requestId);

                // Preparar el resultado
                if (!$verify->getStatus()->isAccepted()) {
                    $verifyResult = "Fallo al verificar la consulta {$requestId}: " . $verify->getStatus()->getMessage();
                } else {
                    if (!$verify->getCodeRequest()->isAccepted()) {
                        $verifyResult = "La solicitud {$requestId} fue rechazada: " . $verify->getCodeRequest()->getMessage();
                    } else {
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
    <title>Verificación de Solicitudes SAT</title>
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
            font-size: 0.9em;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            vertical-align: top;
        }
        th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        tr:hover {
            background-color: #f9f9f9;
        }
        .verify-link {
            color: #0066cc;
            text-decoration: none;
        }
        .verify-link:hover {
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
        .params-cell {
            max-width: 200px;
            word-wrap: break-word;
        }
        .params-cell strong {
            color: #555;
            font-size: 0.85em;
        }
        .params-cell small {
            color: #666;
            font-size: 0.8em;
            line-height: 1.3;
        }
        .params-cell .error-text {
            color: #d9534f;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Verificación de Solicitudes SAT</h1>

        <?php if ($verifyResult): ?>
            <div class="result">
                <h2>Resultado de la Verificación:</h2>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($verifyResult); ?></pre>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error">
                <h2>Error:</h2>
                <pre style="white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($error); ?></pre>
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
                    <th>Parámetros de Consulta</th>
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
                        <td>
                            <?php if (!empty($record['request'])): ?>
                                <?php echo htmlspecialchars($record['request']); ?>
                            <?php else: ?>
                                <em>No disponible</em>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($record['fecha_consulta']); ?></td>
                        <td>
                            <?php if (!empty($record['id_solicitud'])): ?>
                                <a href="?verify_id=<?php echo urlencode($record['id_solicitud']); ?>" class="verify-link">
                                    Verificar
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html> 