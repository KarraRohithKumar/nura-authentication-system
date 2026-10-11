<?php
/**
 * NURA AI LABS - User Authentication API
 * Endpoint: POST /php/login.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';

// Ensure POST request
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(false, 'Method Not Allowed. Use POST.', null, 405);
}

// 0. Enforce CSRF protection
require_csrf();

// Parse input
$data = get_request_data();
$identifier = trim((string)($data['identifier'] ?? $data['username'] ?? $data['email'] ?? ''));
$password   = (string)($data['password'] ?? '');

// 1. Basic validation
if (empty($identifier) || empty($password)) {
    json_response(false, 'Please provide both username/email and password.', null, 400);
}

try {
    $pdo = get_mysql_pdo();

    // 2. Query MySQL for user account by username or email
    $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$identifier, strtolower($identifier)]);
    $user = $stmt->fetch();

    // Generic error message for security (prevents user enumeration)
    $authFailedMessage = 'Invalid username/email or password.';

    if (!$user) {
        // Sleep briefly to mitigate timing attacks
        usleep(rand(50000, 100000));
        json_response(false, $authFailedMessage, null, 401);
    }

    // 3. Verify password hash
    if (!password_verify($password, $user['password_hash'])) {
        usleep(rand(50000, 100000));
        json_response(false, $authFailedMessage, null, 401);
    }

    // 4. Check if password rehash is needed (e.g. if algorithm cost updated)
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
        $updateStmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $updateStmt->execute([$newHash, $user['id']]);
    }

    // 5. Rotate and create authenticated session in Redis
    destroy_auth_session(); // Invalidate any previous session before issuing new token

    $sessionData = [
        'user_id'  => (int)$user['id'],
        'username' => $user['username'],
        'email'    => $user['email'],
    ];

    create_auth_session((int)$user['id'], $sessionData);

    // 6. Return successful response (authentication managed securely via HttpOnly cookie)
    json_response(true, 'Login successful! Redirecting to profile...', [
        'user_id'    => (int)$user['id'],
        'username'   => $user['username'],
        'email'      => $user['email']
    ], 200);

} catch (\Throwable $e) {
    error_log("[NURA_LOGIN_ERROR] " . $e->getMessage());
    json_response(false, 'An internal error occurred during authentication. Please try again.', null, 500);
}
