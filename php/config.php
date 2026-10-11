<?php
/**
 * NURA AI LABS - Authentication System
 * Configuration & Core Helpers
 */

declare(strict_types=1);

// Prevent direct execution error leakage
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Load Composer Autoloader if present
$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

/**
 * Load environment variables from .env file
 */
function load_environment(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $envPath = dirname(__DIR__) . '/.env';
    if (!file_exists($envPath)) {
        $envPath = dirname(__DIR__) . '/.env.example';
    }

    if (file_exists($envPath)) {
        if (class_exists(\Dotenv\Dotenv::class)) {
            try {
                $dotenv = \Dotenv\Dotenv::createImmutable(dirname($envPath), basename($envPath));
                $dotenv->safeLoad();
                $loaded = true;
                return;
            } catch (\Throwable $e) {
                // Fallback to manual parser below
            }
        }

        // Lightweight fallback .env parser
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    // Remove surrounding quotes if any
                    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                        (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                        $value = substr($value, 1, -1);
                    }
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $value;
                        putenv("{$key}={$value}");
                    }
                }
            }
        }
    }
    $loaded = true;
}

// Initialize environment
load_environment();

/**
 * Retrieve environment variable with default fallback
 */
function env(string $key, mixed $default = null): mixed {
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    $val = getenv($key);
    if ($val !== false) {
        return $val;
    }
    return $default;
}

/**
 * Send standard JSON response and terminate
 */
function json_response(bool $success, string $message, mixed $data = null, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    
    // Apply standard security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $response = [
        'success' => $success,
        'message' => $message
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Parse JSON request body or fallback to $_POST
 */
function get_request_data(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    return $_POST;
}

/**
 * Global exception handler to prevent leaking database credentials or traces
 */
set_exception_handler(function (\Throwable $e) {
    error_log("[NURA_AUTH_EXCEPTION] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    $isDev = strtolower((string)env('APP_ENV', 'development')) === 'development' && env('APP_DEBUG', 'false') === 'true';
    
    // In production, never reveal internal errors
    $userMsg = 'An unexpected internal server error occurred. Please try again later.';
    json_response(false, $userMsg, null, 500);
});
