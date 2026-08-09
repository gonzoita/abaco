<?php
// backend/lib/import_insert_row.php
// Extraído de settings.php (acción import_data) para que sea una función
// pura, testeable de forma aislada sin arrastrar authenticate()/cors.php.

/**
 * Inserta una fila (array asociativo tomado del JSON de respaldo) en $table,
 * quitando 'id' para dejar que el AUTO_INCREMENT asigne uno nuevo. Devuelve
 * el nuevo ID insertado. Usa columnas dinámicas (no una lista fija) porque
 * el export también usa SELECT *, así que ambos lados quedan en sincro
 * aunque el esquema gane columnas nuevas más adelante.
 */
function import_insert_row($db, $table, $row) {
    unset($row['id']);
    if (empty($row)) return null;
    $cols = array_keys($row);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES ({$placeholders})";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_values($row));
    return $db->lastInsertId();
}
