<?php

// Configurar headers CORS para permitir localhost:5173
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Manejar solicitudes OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../bootstrap.php';

use SatApi\Services\SatService;
use SatApi\Services\DatabaseService;
use SatApi\Utils\FileHandler;
use SatApi\Utils\ApiResponse;
use SatApi\Utils\Logger;

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ApiResponse::methodNotAllowed('POST');
    exit();
}

// Get the request URI and parse the route
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = '/api';

// Remove query string if present
$requestUri = strtok($requestUri, '?');

// Remove base path
if (strpos($requestUri, $basePath) === 0) {
    $route = substr($requestUri, strlen($basePath));
} else {
    $route = $requestUri;
}

// Route handling
switch ($route) {
    case '/sat/authenticate':
        handleSatAuthenticate();
        break;
    
    default:
        ApiResponse::notFound("Endpoint '{$route}'");
        break;
}

function handleSatAuthenticate(): void
{
    $fileHandler = new FileHandler();
    $satService = new SatService();
    $databaseService = new DatabaseService();

    try {
        // Validate content type
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'multipart/form-data') === false) {
            ApiResponse::error('Content-Type debe ser multipart/form-data', 'INVALID_CONTENT_TYPE');
            return;
        }

        // Validate required files and parameters
        $validationErrors = [];
        if (empty($_FILES['certificate'])) $validationErrors['certificate'] = 'Se requiere el archivo .cer';
        if (empty($_FILES['privateKey'])) $validationErrors['privateKey'] = 'Se requiere el archivo .key';
        if (empty($_POST['password'])) $validationErrors['password'] = 'Se requiere la contraseña';
        
        if (!empty($validationErrors)) {
            ApiResponse::validationError($validationErrors);
            return;
        }

        // Validate uploaded files
        $certFile = $_FILES['certificate'];
        $keyFile = $_FILES['privateKey'];
        $password = $_POST['password'];

        $certErrors = $fileHandler->validateUploadedFile($certFile);
        if (!empty($certErrors)) {
            ApiResponse::fileUploadError('Error en el certificado: ' . implode(', ', $certErrors));
            return;
        }

        $keyErrors = $fileHandler->validateUploadedFile($keyFile);
        if (!empty($keyErrors)) {
            ApiResponse::fileUploadError('Error en la llave privada: ' . implode(', ', $keyErrors));
            return;
        }

        // Read file content and create tracked temp files
        $certificateContent = $fileHandler->readFileContent($certFile['tmp_name']);
        $keyContent = $fileHandler->readFileContent($keyFile['tmp_name']);
        
        $fileHandler->createTempFile($certificateContent, 'cert_');
        $fileHandler->createTempFile($keyContent, 'key_');

        // Authenticate with SAT
        $authResult = $satService->authenticate($certificateContent, $keyContent, $password);
        if (!$authResult['success']) {
            ApiResponse::authenticationError($authResult['error'] ?? 'Error en la autenticación con el SAT');
            return;
        }

        // Process query if dates are provided
        $startDate = $_POST['startDate'] ?? '';
        $endDate = $_POST['endDate'] ?? '';
        $queryResult = null;
        $verifyResult = null;

        if (!empty($startDate) && !empty($endDate)) {
            $queryParams = [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'documentType' => $_POST['documentType'] ?? 'egreso',
                'downloadType' => $_POST['downloadType'] ?? 'received'
            ];
            $queryResult = $satService->processQuery($queryParams);

            if ($queryResult['success']) {
                $verifyResult = $satService->verifyRequest($queryResult['requestId']);
            }
        }

        // Format response and save to database
        $responseData = ApiResponse::formatSatResponse($authResult, $queryResult, $verifyResult);
        $dbData = ApiResponse::formatDatabaseData($authResult, $queryResult);
        $dbData['certificado'] = $certificateContent;
        $dbData['llave'] = $keyContent;
        $dbData['password'] = $password;

        $dbId = $databaseService->saveSatData($dbData);

        // Send success response
        ApiResponse::success($responseData, 200, $dbId);

    } catch (Exception $e) {
        Logger::log('API Error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        // Determine appropriate error response based on exception message
        $message = $e->getMessage();
        if (strpos($message, 'autenticación') !== false || strpos($message, 'FIEL') !== false) {
            ApiResponse::authenticationError($message);
        } elseif (strpos($message, 'base de datos') !== false) {
            ApiResponse::databaseError($message);
        } elseif (strpos($message, 'SAT') !== false) {
            ApiResponse::satServiceError($message);
        } else {
            ApiResponse::error($message, 'INTERNAL_SERVER_ERROR', 'Ocurrió un error inesperado');
        }
    } finally {
        // Ensure temporary files are always cleaned up
        $fileHandler->cleanupTempFiles();
    }
}