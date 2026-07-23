<?php
/**
 * Auto-initialise the database schema on container startup.
 * Idempotent: every statement is CREATE TABLE/INDEX IF NOT EXISTS,
 * so running it on every boot is safe. Only runs for the pgsql driver.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (DB_DRIVER !== 'pgsql') {
    fwrite(STDERR, "[bootstrap] DB_DRIVER is not pgsql — skipping auto-init.\n");
    exit(0);
}

$schemaFile = __DIR__ . '/../schema_postgres.sql';
if (!file_exists($schemaFile)) {
    fwrite(STDERR, "[bootstrap] schema_postgres.sql not found — skipping.\n");
    exit(0);
}

try {
    $pdo = getPDO();
    $sql = file_get_contents($schemaFile);
    $pdo->exec($sql);
    fwrite(STDERR, "[bootstrap] Schema ensured OK.\n");

    // Promote any already-registered admin emails (idempotent).
    $emails = admin_emails();
    if ($emails) {
        $ph = implode(',', array_fill(0, count($emails), '?'));
        $upd = $pdo->prepare("UPDATE users SET is_admin=1 WHERE LOWER(email) IN ($ph)");
        $upd->execute($emails);
        fwrite(STDERR, "[bootstrap] Admin emails promoted: " . implode(', ', $emails) . " (rows: " . $upd->rowCount() . ")\n");
    }
} catch (\Throwable $e) {
    // Don't crash the container if DB isn't reachable yet; Apache still starts.
    fwrite(STDERR, "[bootstrap] Schema init warning: " . $e->getMessage() . "\n");
}
exit(0);
