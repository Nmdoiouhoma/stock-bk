<?php

// Handle OPTIONS preflight before Symfony boots — POST/GET CORS headers are added by CorsListener
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://stock-ft.onrender.com', 'http://localhost:3000', 'http://127.0.0.1:3000'];

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 3600');
        header('Vary: Origin');
    }
    http_response_code(204);
    exit;
}

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
