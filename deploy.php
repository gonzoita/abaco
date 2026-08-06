<?php
// deploy.php
header('Content-Type: text/plain; charset=UTF-8');

echo "=== INICIANDO DESPLIEGUE AUTOMÁTICO ===\n";

$repoPath = '/home/u787912762/domains/abaco.briela.app/public_html/.builds/source/repository';
$publicPath = '/home/u787912762/domains/abaco.briela.app/public_html';

if (file_exists($repoPath)) {
    chdir($repoPath);
}

$output = [];
$returnVar = 0;

echo "1. Ejecutando: git fetch origin...\n";
exec("git fetch origin 2>&1", $output, $returnVar);

echo "2. Ejecutando: git reset --hard origin/main...\n";
exec("git reset --hard origin/main 2>&1", $output, $returnVar);

if (file_exists($repoPath)) {
    echo "3. Copiando archivos a public_html...\n";
    exec("cp -rf {$repoPath}/* {$publicPath}/ 2>&1", $output, $returnVar);
}

if (file_exists("{$publicPath}/backend/api/migrate_workspaces.php")) {
    echo "4. Ejecutando migración de espacios de trabajo...\n";
    exec("php {$publicPath}/backend/api/migrate_workspaces.php 2>&1", $output, $returnVar);
}

echo "\n=== SALIDA DEL DESPLIEGUE ===\n";
echo implode("\n", $output) . "\n";

echo "\n¡Proceso de despliegue finalizado!\n";

