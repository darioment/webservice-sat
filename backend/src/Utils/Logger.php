<?php

namespace SatApi\Utils;

class Logger
{
    private static string $logFile = __DIR__ . '/../../downloads/error.log';

    public static function log(string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}";

        if (!empty($context)) {
            $logEntry .= " - Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        $logEntry .= PHP_EOL;

        file_put_contents(self::$logFile, $logEntry, FILE_APPEND);
    }
}
