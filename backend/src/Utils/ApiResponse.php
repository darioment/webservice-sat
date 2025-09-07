<?php

namespace SatApi\Utils;

class ApiResponse
{
    public static function success(array $data = [], int $statusCode = 200, ?int $dbId = null): void
    {
        http_response_code($statusCode);
        
        $response = [
            'success' => true,
            'data' => $data
        ];

        if ($dbId !== null) {
            $response['dbId'] = $dbId;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function error(
        string $message, 
        string $code = 'GENERIC_ERROR', 
        ?string $details = null, 
        int $statusCode = 400
    ): void {
        http_response_code($statusCode);
        
        $error = [
            'code' => $code,
            'message' => $message
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        $response = [
            'success' => false,
            'error' => $error
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function validationError(array $errors, string $message = 'Errores de validación'): void
    {
        http_response_code(400);
        
        $response = [
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => $message,
                'details' => $errors
            ]
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function authenticationError(string $message = 'Error de autenticación'): void
    {
        self::error($message, 'AUTHENTICATION_ERROR', 'Verifica tus credenciales FIEL', 401);
    }

    public static function satServiceError(string $message = 'Error del servicio SAT'): void
    {
        self::error($message, 'SAT_SERVICE_ERROR', 'Error al comunicarse con los servicios del SAT', 502);
    }

    public static function databaseError(string $message = 'Error de base de datos'): void
    {
        self::error($message, 'DATABASE_ERROR', 'Error interno del servidor', 500);
    }

    public static function fileUploadError(string $message = 'Error al subir archivo'): void
    {
        self::error($message, 'FILE_UPLOAD_ERROR', 'Verifica que el archivo sea válido', 400);
    }

    public static function methodNotAllowed(string $allowedMethods = 'POST'): void
    {
        http_response_code(405);
        header("Allow: {$allowedMethods}");
        
        $response = [
            'success' => false,
            'error' => [
                'code' => 'METHOD_NOT_ALLOWED',
                'message' => 'Método HTTP no permitido',
                'details' => "Solo se permiten los métodos: {$allowedMethods}"
            ]
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function notFound(string $resource = 'Recurso'): void
    {
        http_response_code(404);
        
        $response = [
            'success' => false,
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => "{$resource} no encontrado",
                'details' => 'Verifica la URL y vuelve a intentar'
            ]
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public static function serverError(string $message = 'Error interno del servidor'): void
    {
        self::error($message, 'INTERNAL_SERVER_ERROR', 'Contacta al administrador del sistema', 500);
    }

    public static function setCorsHeaders(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:5173');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
        header('Content-Type: application/json; charset=utf-8');
    }

    public static function handlePreflight(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            self::setCorsHeaders();
            http_response_code(200);
            exit();
        }
    }

    public static function formatSatResponse(array $authResult, ?array $queryResult = null, ?array $verifyResult = null): array
    {
        $response = [
            'rfc' => $authResult['rfc'],
            'tokenValidUntil' => $authResult['tokenValidUntil'],
            'tokenCreated' => $authResult['tokenCreated'],
            'serviceType' => 'CFDI'
        ];

        if ($queryResult !== null) {
            if ($queryResult['success']) {
                $response['requestId'] = $queryResult['requestId'];
                $response['status'] = $queryResult['status'];
                $response['message'] = $queryResult['message'];
                $response['downloadType'] = $queryResult['downloadType'];
            } else {
                $response['queryError'] = $queryResult['error'];
            }
        }

        if ($verifyResult !== null && $verifyResult['success']) {
            $response['verificationStatus'] = $verifyResult['status'];
            $response['verificationMessage'] = $verifyResult['message'];
            
            if (isset($verifyResult['packages'])) {
                $response['packagesCount'] = $verifyResult['packagesCount'];
                $response['packages'] = $verifyResult['packages'];
            }
        }

        return $response;
    }

    public static function formatDatabaseData(array $authResult, ?array $queryResult = null): array
    {
        return [
            'rfc' => $authResult['rfc'],
            'tipo_servicio' => 'CFDI',
            'token_valido_hasta' => $authResult['tokenValidUntil'],
            'token_creado' => $authResult['tokenCreated'],
            'id_solicitud' => $queryResult['requestId'] ?? '',
            'estado' => $queryResult['success'] ?? true ? 
                ($queryResult['status'] ?? 'AUTHENTICATED') : 'ERROR',
            'mensaje' => $queryResult['message'] ?? 
                $queryResult['error'] ?? 'Autenticación exitosa'
        ];
    }
}