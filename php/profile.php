<?php
/**
 * NURA AI LABS - User Profile API
 * Endpoints:
 *   GET  /php/profile.php - Retrieve authenticated user profile from MongoDB
 *   POST /php/profile.php - Update user profile information in MongoDB
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/csrf.php';

// 1. Authenticate via Redis session (Returns 401 if missing/invalid/expired)
$session = require_auth();
$userId = (int)$session['user_id'];

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

/**
 * Cleanly serialize MongoDB documents for JSON output
 */
function serialize_mongo_profile(mixed $doc): array {
    if (!$doc) {
        return [];
    }
    if (is_object($doc) && method_exists($doc, 'getArrayCopy')) {
        $data = $doc->getArrayCopy();
    } else {
        $data = (array)$doc;
    }

    if (isset($data['_id'])) {
        $data['_id'] = (string)$data['_id'];
    }

    if (isset($data['interests'])) {
        if (is_object($data['interests']) && method_exists($data['interests'], 'getArrayCopy')) {
            $data['interests'] = $data['interests']->getArrayCopy();
        } elseif (is_object($data['interests'])) {
            $data['interests'] = array_values((array)$data['interests']);
        } elseif (!is_array($data['interests'])) {
            $data['interests'] = [$data['interests']];
        }
    } else {
        $data['interests'] = [];
    }

    return $data;
}

try {
    $profilesCollection = get_mongo_profiles_collection();
    $pdo = get_mysql_pdo();

    // Fetch primary account record from MySQL
    $stmt = $pdo->prepare('SELECT id, username, email, created_at, updated_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $account = $stmt->fetch();

    if (!$account) {
        // Account was deleted from MySQL while session remained
        destroy_auth_session();
        json_response(false, 'User account not found. Session terminated.', null, 404);
    }

    // -------------------------------------------------------------
    // GET: Retrieve Profile from MongoDB
    // -------------------------------------------------------------
    if ($method === 'GET') {
        $profileDoc = $profilesCollection->findOne(['user_id' => $userId]);

        // If no MongoDB document exists yet, auto-provision default profile
        if (!$profileDoc) {
            $defaultProfile = [
                'user_id'      => $userId,
                'name'         => $account['username'],
                'age'          => null,
                'phone'        => '',
                'bio'          => 'Welcome to my Nura AI Labs profile!',
                'organization' => 'NURA AI Research Labs',
                'role'         => 'Member',
                'location'     => 'Bengaluru, India',
                'interests'    => ['Artificial Intelligence', 'Full Stack Development', 'Cloud Architecture'],
                'created_at'   => date('c'),
                'updated_at'   => date('c')
            ];
            $profilesCollection->insertOne($defaultProfile);
            $profileDoc = $defaultProfile;
        }

        $profileData = serialize_mongo_profile($profileDoc);

        json_response(true, 'Profile retrieved successfully.', [
            'account' => [
                'id'         => (int)$account['id'],
                'username'   => $account['username'],
                'email'      => $account['email'],
                'created_at' => $account['created_at'],
                'updated_at' => $account['updated_at']
            ],
            'profile' => [
                'name'         => $profileData['name'] ?? $account['username'],
                'age'          => isset($profileData['age']) && $profileData['age'] !== null ? (int)$profileData['age'] : null,
                'phone'        => $profileData['phone'] ?? '',
                'bio'          => $profileData['bio'] ?? '',
                'organization' => $profileData['organization'] ?? '',
                'role'         => $profileData['role'] ?? '',
                'location'     => $profileData['location'] ?? '',
                'interests'    => is_array($profileData['interests'] ?? null) ? array_values($profileData['interests']) : [],
                'updated_at'   => $profileData['updated_at'] ?? $account['updated_at']
            ],
            'session' => [
                'status'     => 'Active (Authenticated via Redis)',
                'ip_address' => $session['ip_address'] ?? '127.0.0.1',
                'created_at' => $session['created_at'] ?? date('c')
            ]
        ], 200);
    }

    // -------------------------------------------------------------
    // POST / PUT: Update Profile in MongoDB
    // -------------------------------------------------------------
    if ($method === 'POST' || $method === 'PUT') {
        // Enforce CSRF protection on updates
        require_csrf();

        $input = get_request_data();

        // Sanitize and validate inputs
        $name         = trim((string)($input['name'] ?? ''));
        $ageRaw       = $input['age'] ?? null;
        $phone        = trim((string)($input['phone'] ?? ''));
        $bio          = trim((string)($input['bio'] ?? ''));
        $organization = trim((string)($input['organization'] ?? ''));
        $role         = trim((string)($input['role'] ?? ''));
        $location     = trim((string)($input['location'] ?? ''));
        $interestsRaw = $input['interests'] ?? [];

        $errors = [];

        if (empty($name)) {
            $name = $account['username'];
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Full name must not exceed 100 characters.';
        }

        $age = null;
        if ($ageRaw !== null && $ageRaw !== '') {
            $ageVal = filter_var($ageRaw, FILTER_VALIDATE_INT);
            if ($ageVal === false || $ageVal < 1 || $ageVal > 130) {
                $errors[] = 'Please provide a valid age between 1 and 130.';
            } else {
                $age = (int)$ageVal;
            }
        }

        if (!empty($phone) && !preg_match('/^[0-9\+\-\s\(\)\.]{5,25}$/', $phone)) {
            $errors[] = 'Please provide a valid phone number format.';
        }

        if (mb_strlen($bio) > 1000) {
            $errors[] = 'Bio must not exceed 1000 characters.';
        }

        if (mb_strlen($organization) > 120) {
            $errors[] = 'Organization must not exceed 120 characters.';
        }

        if (mb_strlen($role) > 120) {
            $errors[] = 'Role title must not exceed 120 characters.';
        }

        if (mb_strlen($location) > 120) {
            $errors[] = 'Location must not exceed 120 characters.';
        }

        // Process interests (array or comma-delimited string)
        $interests = [];
        if (is_string($interestsRaw)) {
            $parts = explode(',', $interestsRaw);
            foreach ($parts as $p) {
                $pClean = trim(strip_tags($p));
                if (!empty($pClean) && mb_strlen($pClean) <= 50) {
                    $interests[] = $pClean;
                }
            }
        } elseif (is_array($interestsRaw)) {
            foreach ($interestsRaw as $p) {
                $pClean = trim(strip_tags((string)$p));
                if (!empty($pClean) && mb_strlen($pClean) <= 50) {
                    $interests[] = $pClean;
                }
            }
        }

        if (!empty($errors)) {
            json_response(false, implode(' ', $errors), ['errors' => $errors], 400);
        }

        // Prepare MongoDB update payload (Sanitize against Stored XSS)
        $updateFields = [
            'name'         => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'age'          => $age,
            'phone'        => htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'bio'          => htmlspecialchars($bio, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'organization' => htmlspecialchars($organization, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'role'         => htmlspecialchars($role, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'location'     => htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'interests'    => array_values(array_unique($interests)),
            'updated_at'   => date('c')
        ];

        $profilesCollection->updateOne(
            ['user_id' => $userId],
            ['$set' => $updateFields, '$setOnInsert' => ['user_id' => $userId, 'created_at' => date('c')]],
            ['upsert' => true]
        );

        json_response(true, 'Profile updated successfully!', [
            'profile' => $updateFields
        ], 200);
    }

    json_response(false, 'Method Not Allowed. Use GET or POST.', null, 405);

} catch (\Throwable $e) {
    error_log("[NURA_PROFILE_ERROR] " . $e->getMessage());
    json_response(false, 'Unable to process profile request. Please try again.', null, 500);
}
