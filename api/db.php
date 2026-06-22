<?php
require_once __DIR__ . '/config.php';

function getPDO() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    // DB_DRIVER defaults to 'mysql' (local XAMPP dev, unchanged). Set
    // DB_DRIVER to 'pgsql' in config.local.php for a Postgres-backed
    // deployment (e.g. Render's free managed Postgres) - DB_PORT defaults
    // sensibly per driver if not explicitly set.
    if (DB_DRIVER === 'pgsql') {
        $port = defined('DB_PORT') && DB_PORT ? DB_PORT : 5432;
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME;
    } else {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    }
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'DB connection failed', 'message' => $e->getMessage()]);
        exit;
    }
}

?>
