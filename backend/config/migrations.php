<?php
// backend/config/migrations.php
// Registro simple de migraciones aplicadas, para que los scripts migrate_*.php
// no tengan que re-verificar columna por columna (SHOW COLUMNS) en cada
// despliegue. Cada paso de migración se identifica por un nombre único y se
// ejecuta como máximo una vez.

function ensure_migrations_table($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(191) PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
}

function migration_done($db, $name) {
    $stmt = $db->prepare("SELECT 1 FROM schema_migrations WHERE name = ?");
    $stmt->execute([$name]);
    return (bool) $stmt->fetch();
}

function mark_migration_done($db, $name) {
    $stmt = $db->prepare("INSERT IGNORE INTO schema_migrations (name) VALUES (?)");
    $stmt->execute([$name]);
}
