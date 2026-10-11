<?php
/**
 * NURA AI LABS - User Registration API
 * Endpoint: POST /php/register.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';

// Ensure POST request
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(false, 'Method Not Allowed. Use POST.', null, 405);
}

// 0. Enforce CSRF protection
require_csrf();

// Parse input parameters
$data = get_request_data();

$username        = trim((string)($data['username'] ?? ''));
$email           = trim((string)($data['email'] ?? ''));
$password        = (string)($data['password'] ?? '');
$confirmPassword = (string)($data['confirm_password'] ?? '');

// 1. Server-side Validation
$errors = [];

if (empty($username)) {
    $errors[] = 'Username is required.';
} elseif (strlen($username) < 3 || strlen($username) > 30) {
    $errors[] = 'Username must be between 3 and 30 characters.';
} elseif (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $username)) {
    $errors[] = 'Username can only contain letters, numbers, underscores, dots, and hyphens.';
}

if (empty($email)) {
    $errors[] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide a valid email address.';
} elseif (strlen($email) > 255) {
    $errors[] = 'Email address cannot exceed 255 characters.';
}

if (empty($password)) {
    $errors[] = 'Password is required.';
} elseif (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters long.';
}

if ($password !== $confirmPassword) {
    $errors[] = 'Passwords do not match.';
}

if (!empty($errors)) {
    json_response(false, implode(' ', $errors), ['errors' => $errors], 400);
}

// Normalize email
$normalizedEmail = strtolower($email);

try {
    $pdo = get_mysql_pdo();

    // 2. Check if Username already exists
    $stmtUser = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmtUser->execute([$username]);
    if ($stmtUser->fetch()) {
        json_response(false, 'Username is already taken. Please choose another.', null, 409);
    }

    // 3. Check if Email already exists
    $stmtEmail = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmtEmail->execute([$normalizedEmail]);
    if ($stmtEmail->fetch()) {
        json_response(false, 'Email address is already registered. Please log in or use another email.', null, 409);
    }

    // 4. Secure Password Hashing (BCRYPT / Argon2 via PASSWORD_DEFAULT)
    $passwordHash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);

    // 5. Insert new user into MySQL
    $stmtInsert = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
    $stmtInsert->execute([$username, $normalizedEmail, $passwordHash]);
    $newUserId = (int)$pdo->lastInsertId();

    // 6. Initialize default profile document in MongoDB
    try {
        $profilesCollection = get_mongo_profiles_collection();
        $initialProfile = [
            'user_id'    => $newUserId,
            'name'       => $username,
            'age'        => null,
            'bio'        => 'Welcome to my Nura AI Labs profile!',
            'interests'  => ['Artificial Intelligence', 'Full Stack Development', 'Cloud Computing'],
            'created_at' => date('c'),
            'updated_at' => date('c')
        ];
        $profilesCollection->insertOne($initialProfile);
    } catch (\Throwable $mongoEx) {
        // Log MongoDB profile creation failure without breaking registration flow
        error_log("[NURA_MONGO_INIT_ERR] Could not create initial profile for user {$newUserId}: " . $mongoEx->getMessage());
    }

    json_response(true, 'Registration successful! You can now log in.', [
        'user_id'  => $newUserId,
        'username' => $username,
        'email'    => $normalizedEmail
    ], 201);

} catch (\PDOException $pdoEx) {
    // Check for duplicate key violation in race condition (SQLSTATE 23000 or error code 1062)
    if ((int)$pdoEx->getCode() === 23000 || (isset($pdoEx->errorInfo[1]) && (int)$pdoEx->errorInfo[1] === 1062)) {
        $msg = (str_contains($pdoEx->getMessage(), 'uq_email') || str_contains($pdoEx->getMessage(), 'email'))
            ? 'Email address is already registered. Please log in or use another email.'
            : 'Username is already taken. Please choose another.';
        json_response(false, $msg, null, 409);
    }
    error_log("[NURA_REGISTER_PDO_ERROR] " . $pdoEx->getMessage());
    json_response(false, 'Database error during registration. Please try again.', null, 500);
} catch (\Throwable $e) {
    error_log("[NURA_REGISTER_ERROR] " . $e->getMessage());
    json_response(false, 'Registration failed due to a server error. Please try again later.', null, 500);
}
