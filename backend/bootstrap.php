<?php

// Bootstrap file for the SAT API Backend
require_once __DIR__ . '/vendor/autoload.php';

use SatApi\Config\Config;

// Load configuration
Config::load();

// Set error reporting based on environment
$debug = $_ENV['APP_DEBUG'] ?? false;
if ($debug === 'true' || $debug === true) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Set timezone
date_default_timezone_set('America/Mexico_City');