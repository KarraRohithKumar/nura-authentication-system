<?php
/**
 * NURA AI LABS - Database Architecture & Connections
 * Handles MySQL (PDO), Redis (Predis / Socket), and MongoDB connections
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Obtain Singleton MySQL PDO instance
 */
function get_mysql_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = env('MYSQL_HOST', 'localhost');
    $port = env('MYSQL_PORT', '3306');
    $dbname = env('MYSQL_DATABASE', 'nura_auth');
    $user = env('MYSQL_USERNAME', 'root');
    $pass = env('MYSQL_PASSWORD', '');

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        return $pdo;
    } catch (PDOException $e) {
        error_log("[NURA_MYSQL_ERROR] Failed to connect: " . $e->getMessage());
        throw new RuntimeException("Database connection error. Please verify MySQL configuration.");
    }
}

/**
 * Lightweight Standalone Redis Client using raw RESP sockets (Fallback if Predis is not present)
 */
class SocketRedisClient {
    private mixed $socket = null;
    private string $host;
    private int $port;
    private ?string $password;

    public function __construct(string $host = '127.0.0.1', int $port = 6379, ?string $password = null) {
        $this->host = $host;
        $this->port = $port;
        $this->password = !empty($password) ? $password : null;
        $this->connect();
    }

    private function connect(): void {
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, 2.5);
        if (!$fp) {
            throw new RuntimeException("Could not connect to Redis at {$this->host}:{$this->port}: {$errstr}");
        }
        $this->socket = $fp;
        if ($this->password !== null) {
            $this->execute(['AUTH', $this->password]);
        }
    }

    public function execute(array $args): mixed {
        if (!$this->socket) {
            $this->connect();
        }
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $argStr = (string)$arg;
            $cmd .= '$' . strlen($argStr) . "\r\n" . $argStr . "\r\n";
        }
        fwrite($this->socket, $cmd);
        return $this->parseResponse();
    }

    private function parseResponse(): mixed {
        $line = fgets($this->socket);
        if ($line === false) {
            return null;
        }
        $type = $line[0];
        $payload = substr($line, 1, -2);

        switch ($type) {
            case '+': // Simple string
                return $payload;
            case '-': // Error
                throw new RuntimeException("Redis error: " . $payload);
            case ':': // Integer
                return (int)$payload;
            case '$': // Bulk string
                $len = (int)$payload;
                if ($len === -1) {
                    return null;
                }
                $data = '';
                $remaining = $len;
                while ($remaining > 0) {
                    $chunk = fread($this->socket, $remaining);
                    $data .= $chunk;
                    $remaining -= strlen($chunk);
                }
                fread($this->socket, 2); // Read trailing \r\n
                return $data;
            case '*': // Array
                $count = (int)$payload;
                if ($count === -1) {
                    return null;
                }
                $arr = [];
                for ($i = 0; $i < $count; $i++) {
                    $arr[] = $this->parseResponse();
                }
                return $arr;
            default:
                return $payload;
        }
    }

    public function get(string $key): ?string {
        return $this->execute(['GET', $key]);
    }

    public function set(string $key, string $val): bool {
        $res = $this->execute(['SET', $key, $val]);
        return $res === 'OK';
    }

    public function setex(string $key, int $ttl, string $val): bool {
        $res = $this->execute(['SETEX', $key, (string)$ttl, $val]);
        return $res === 'OK';
    }

    public function del(string|array $keys): int {
        $k = is_array($keys) ? $keys : [$keys];
        return (int)$this->execute(array_merge(['DEL'], $k));
    }

    public function expire(string $key, int $ttl): bool {
        return (bool)$this->execute(['EXPIRE', $key, (string)$ttl]);
    }

    public function ping(): string {
        return (string)$this->execute(['PING']);
    }

    public function __destruct() {
        if ($this->socket) {
            @fclose($this->socket);
        }
    }
}

/**
 * Obtain Singleton Redis Client instance
 */
function get_redis_client(): object {
    static $client = null;
    if ($client !== null) {
        return $client;
    }

    $host = (string)env('REDIS_HOST', '127.0.0.1');
    $port = (int)env('REDIS_PORT', 6379);
    $pass = env('REDIS_PASSWORD', null);

    try {
        if (class_exists(\Predis\Client::class)) {
            $params = [
                'scheme'   => 'tcp',
                'host'     => $host,
                'port'     => $port,
                'timeout'  => 2.5,
            ];
            if (!empty($pass)) {
                $params['password'] = $pass;
            }
            $client = new \Predis\Client($params);
            // Test connection
            $client->ping();
            return $client;
        }

        // Fallback to custom socket client
        $client = new SocketRedisClient($host, $port, !empty($pass) ? (string)$pass : null);
        return $client;
    } catch (\Throwable $e) {
        error_log("[NURA_REDIS_ERROR] Redis connection failed: " . $e->getMessage());
        throw new RuntimeException("Redis connection error. Session service unavailable.");
    }
}

/**
 * Obtain Singleton MongoDB Client instance
 */
function get_mongo_client(): \MongoDB\Client {
    static $client = null;
    if ($client !== null) {
        return $client;
    }

    $uri = (string)env('MONGODB_URI', 'mongodb://localhost:27017');

    try {
        if (class_exists(\MongoDB\Client::class)) {
            $client = new \MongoDB\Client($uri);
            return $client;
        }

        throw new RuntimeException("MongoDB PHP library is not installed.");
    } catch (\Throwable $e) {
        error_log("[NURA_MONGO_ERROR] MongoDB connection failed: " . $e->getMessage());
        throw new RuntimeException("MongoDB connection error. Profile storage unavailable.");
    }
}

/**
 * Obtain MongoDB Database instance
 */
function get_mongo_database(): \MongoDB\Database {
    $client = get_mongo_client();
    $dbName = (string)env('MONGODB_DATABASE', 'nura_profiles');
    return $client->selectDatabase($dbName);
}

/**
 * Obtain MongoDB Profiles Collection
 */
function get_mongo_profiles_collection(): \MongoDB\Collection {
    $db = get_mongo_database();
    return $db->selectCollection('profiles');
}
