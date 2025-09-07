<?php

namespace SatApi\Utils;

use Exception;

class FileHandler
{
    private array $tempFiles = [];
    private const MAX_FILE_SIZE = 5242880; // 5MB
    private const ALLOWED_EXTENSIONS = ['cer', 'key'];

    public function createTempFile(string $content, string $prefix = 'sat_'): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), $prefix);
        
        if ($tempFile === false) {
            throw new Exception("No se pudo crear el archivo temporal");
        }

        $bytesWritten = file_put_contents($tempFile, $content);
        
        if ($bytesWritten === false) {
            @unlink($tempFile);
            throw new Exception("No se pudo escribir el contenido al archivo temporal");
        }

        // Track temp file for cleanup
        $this->tempFiles[] = $tempFile;
        
        return $tempFile;
    }

    public function validateFileType(string $filename, array $allowedTypes = null): bool
    {
        $allowedTypes = $allowedTypes ?? self::ALLOWED_EXTENSIONS;
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        return in_array($extension, $allowedTypes, true);
    }

    public function validateFileSize(int $fileSize, int $maxSize = null): bool
    {
        $maxSize = $maxSize ?? self::MAX_FILE_SIZE;
        return $fileSize <= $maxSize && $fileSize > 0;
    }

    public function validateUploadedFile(array $file): array
    {
        $errors = [];

        // Check if file was uploaded
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = "El archivo no fue subido correctamente";
            return $errors;
        }

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = $this->getUploadErrorMessage($file['error']);
            return $errors;
        }

        // Validate file size
        if (!$this->validateFileSize($file['size'])) {
            $maxSizeMB = self::MAX_FILE_SIZE / 1024 / 1024;
            $errors[] = "El archivo excede el tamaño máximo permitido de {$maxSizeMB}MB";
        }

        // Validate file type
        if (!$this->validateFileType($file['name'])) {
            $allowedExtensions = implode(', ', self::ALLOWED_EXTENSIONS);
            $errors[] = "Tipo de archivo no permitido. Solo se permiten: {$allowedExtensions}";
        }

        return $errors;
    }

    public function readFileContent(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new Exception("El archivo no existe: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new Exception("El archivo no es legible: {$filePath}");
        }

        $content = file_get_contents($filePath);
        
        if ($content === false) {
            throw new Exception("No se pudo leer el contenido del archivo: {$filePath}");
        }

        return $content;
    }

    public function sanitizeFilename(string $filename): string
    {
        // Remove directory traversal attempts
        $filename = basename($filename);
        
        // Remove or replace dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // Limit filename length
        if (strlen($filename) > 255) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = substr($name, 0, 250 - strlen($extension)) . '.' . $extension;
        }

        return $filename;
    }

    public function cleanupTempFiles(array $files = null): void
    {
        $filesToClean = $files ?? $this->tempFiles;
        
        foreach ($filesToClean as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        if ($files === null) {
            $this->tempFiles = [];
        }
    }

    public function addTempFile(string $filePath): void
    {
        $this->tempFiles[] = $filePath;
    }

    public function getTempFiles(): array
    {
        return $this->tempFiles;
    }

    private function getUploadErrorMessage(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return "El archivo es demasiado grande";
            case UPLOAD_ERR_PARTIAL:
                return "El archivo se subió parcialmente";
            case UPLOAD_ERR_NO_FILE:
                return "No se subió ningún archivo";
            case UPLOAD_ERR_NO_TMP_DIR:
                return "Falta el directorio temporal";
            case UPLOAD_ERR_CANT_WRITE:
                return "Error al escribir el archivo en disco";
            case UPLOAD_ERR_EXTENSION:
                return "Una extensión de PHP detuvo la subida del archivo";
            default:
                return "Error desconocido al subir el archivo";
        }
    }

    public function __destruct()
    {
        $this->cleanupTempFiles();
    }
}