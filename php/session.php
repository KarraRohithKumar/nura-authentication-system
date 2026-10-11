<?php
/**
 * NURA AI LABS - Redis Session Management
 * Authenticated session lifecycle using Redis key-value storage and secure cookies
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Check if the request is running over HTTPS
 */
function is_https_request(): bool {
    if (strtolower((string)env('COOKIE_SECURE', 'false')) === 'true') {
        return true;
    }
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/**
 * Set the authentication cookie with strict security flags
 */
function set_session_cookie(string $sessionId, int $ttl): void {
    $cookieName = (string)env('SESSION_COOKIE_NAME', 'nura_auth_session');
    $secure = is_https_request();
    $sameSite = (string)env('COOKIE_SAMESITE', 'Lax');

    if (!headers_sent()) {
        setcookie($cookieName, $sessionId, [
            'expires'  => time() + $ttl,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }
    $_COOKIE[$cookieName] = $sessionId;
}

/**
 * Clear the authentication cookie
 */
function clear_session_cookie(): void {
    $cookieName = (string)env('SESSION_COOKIE_NAME', 'nura_auth_session');
    $secure = is_https_request();
    $sameSite = (string)env('COOKIE_SAMESITE', 'Lax');

    if (!headers_sent()) {
        setcookie($cookieName, '', [
            'expires'  => time() - 86400,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }
    unset($_COOKIE[$cookieName]);
}

/**
 * Create a new authenticated Redis session
 */
function create_auth_session(int $userId, array $userData = []): string {
    $redis = get_redis_client();
    $ttl = (int)env('SESSION_TTL', 3600);

    // Generate cryptographically secure 64-character hex session token
    $sessionId = bin2hex(random_bytes(32));
    $redisKey = "auth_session:{$sessionId}";

    $payload = array_merge([
        'user_id'      => $userId,
        'created_at'   => date('c'),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
    ], $userData);

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

    // Save in Redis with expiration
    $redis->setex($redisKey, $ttl, $jsonPayload);

    // Issue secure HttpOnly cookie
    set_session_cookie($sessionId, $ttl);

    return $sessionId;
}

/**
 * Retrieve current authenticated session from Redis
 */
function get_current_session(): ?array {
    $cookieName = (string)env('SESSION_COOKIE_NAME', 'nura_auth_session');
    $sessionId = $_COOKIE[$cookieName] ?? null;

    if (empty($sessionId)) {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+([a-f0-9]{64})/i', (string)$authHeader, $matches)) {
            $sessionId = $matches[1];
        } else {
            $sessionId = $headers['X-Session-ID'] ?? $headers['x-session-id'] ?? ($_SERVER['HTTP_X_SESSION_ID'] ?? null);
        }
    }

    if (empty($sessionId) || !is_string($sessionId) || !preg_match('/^[a-f0-9]{64}$/i', $sessionId)) {
        return null;
    }

    $redis = get_redis_client();
    $redisKey = "auth_session:{$sessionId}";
    $data = $redis->get($redisKey);

    if (empty($data)) {
        return null;
    }

    $decoded = json_decode($data, true);
    if (!is_array($decoded) || !isset($decoded['user_id'])) {
        return null;
    }

    // Attach current session ID
    $decoded['session_id'] = $sessionId;

    return $decoded;
}

/**
 * Destroy current Redis session and expire cookie
 */
function destroy_auth_session(): bool {
    $cookieName = (string)env('SESSION_COOKIE_NAME', 'nura_auth_session');
    $sessionId = $_COOKIE[$cookieName] ?? null;

    if (empty($sessionId)) {
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+([a-f0-9]{64})/i', (string)$authHeader, $matches)) {
            $sessionId = $matches[1];
        } else {
            $sessionId = $headers['X-Session-ID'] ?? $headers['x-session-id'] ?? ($_SERVER['HTTP_X_SESSION_ID'] ?? null);
        }
    }

    if (!empty($sessionId) && is_string($sessionId) && preg_match('/^[a-f0-9]{64}$/i', $sessionId)) {
        try {
            $redis = get_redis_client();
            $redisKey = "auth_session:{$sessionId}";
            $redis->del($redisKey);
        } catch (\Throwable $e) {
            error_log("[NURA_SESSION_DESTROY_ERR] " . $e->getMessage());
        }
    }

    clear_session_cookie();
    return true;
}

/**
 * Middleware: Require authenticated Redis session or return HTTP 401
 */
function require_auth(): array {
    $session = get_current_session();
    if ($session === null) {
        json_response(false, 'Unauthorized. Please log in to continue.', null, 401);
    }
    return $session;
}
