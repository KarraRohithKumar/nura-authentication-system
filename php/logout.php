<?php
/**
 * NURA AI LABS - User Logout API
 * Endpoint: POST /php/logout.php (also supports GET for graceful fallback)
 * Destroys active Redis session and clears the session cookie
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';

try {
    // Enforce CSRF on state-changing POST requests
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_csrf();
    }

    destroy_auth_session();

    json_response(true, 'Logged out successfully.', null, 200);
} catch (\Throwable $e) {
    error_log("[NURA_LOGOUT_ERROR] " . $e->getMessage());
    // Even if error occurs in Redis, cookie was cleared
    json_response(true, 'Session cleared.', null, 200);
}
