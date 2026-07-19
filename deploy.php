<?php
// deploy.php
header('Content-Type: text/plain; charset=UTF-8');

echo "=== INICIANDO DESPLIEGUE AUTOMÁTICO ===\n";

// Ejecutar git fetch y git reset --hard
$output = [];
$returnVar = 0;

echo "Ejecutando: git fetch origin...\n";
exec("git fetch origin 2>&1", $output, $returnVar);

if ($returnVar !== 0) {
    echo "Error al hacer git fetch. Salida:\n";
    echo implode("\n", $output) . "\n";
    exit();
}

echo "Ejecutando: git reset --hard origin/main...\n";
exec("git reset --hard origin/main 2>&1", $output, $returnVar);

echo "\n=== SALIDA DE GIT ===\n";
echo implode("\n", $output) . "\n";

if ($returnVar === 0) {
    echo "\n¡ÉXITO! Tu servidor de producción se ha actualizado correctamente con la última versión de GitHub.\n";
} else {
    echo "\nERROR durante el despliegue. Revisa la salida de arriba.\n";
}
