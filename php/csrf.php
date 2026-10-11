<?php
/**
 * NURA AI LABS - CSRF Protection Layer
 * Issues and validates Double Submit Cookie / Anti-CSRF tokens for AJAX workflows
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Generate or retrieve current CSRF token
 */
function get_or_create_csrf_token(): string {
    $cookieName = (string)env('CSRF_COOKIE_NAME', 'nura_csrf_token');
    $existing = $_COOKIE[$cookieName] ?? null;

    if (!empty($existing) && is_string($existing) && preg_match('/^[a-f0-9]{64}$/i', $existing)) {
        return $existing;
    }

    $newToken = bin2hex(random_bytes(32));
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string)env('COOKIE_SECURE', 'false')) === 'true');
    $sameSite = (string)env('COOKIE_SAMESITE', 'Lax');

    // Note: HttpOnly is false for CSRF cookie so JavaScript can read it to attach to request headers
    if (!headers_sent()) {
        setcookie($cookieName, $newToken, [
            'expires'  => time() + (int)env('SESSION_TTL', 3600),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => false,
            'samesite' => $sameSite,
        ]);
    }
    $_COOKIE[$cookieName] = $newToken;

    return $newToken;
}

/**
 * Validate incoming CSRF token against cookie
 */
function validate_csrf_token(): bool {
    // Only state-changing methods strictly require CSRF check
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true)) {
        return true;
    }

    $cookieName = (string)env('CSRF_COOKIE_NAME', 'nura_csrf_token');
    $cookieToken = $_COOKIE[$cookieName] ?? '';

    // Check header X-CSRF-Token or POST body
    $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    $headerToken = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    
    if (empty($headerToken)) {
        $data = get_request_data();
        $headerToken = $data['csrf_token'] ?? '';
    }

    if (empty($cookieToken) || empty($headerToken)) {
        return false;
    }

    return hash_equals($cookieToken, $headerToken);
}

/**
 * Enforce CSRF verification for state-modifying requests
 */
function require_csrf(): void {
    if (!validate_csrf_token()) {
        json_response(false, 'Security token validation failed (CSRF). Please refresh and try again.', null, 403);
    }
}

// If this file is called directly via GET, output the token
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'csrf.php') {
    $token = get_or_create_csrf_token();
    json_response(true, 'CSRF token retrieved', ['csrf_token' => $token]);
}
