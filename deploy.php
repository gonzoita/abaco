<?php
// deploy.php
// Disparado por el webhook "push" de GitHub. Verifica la firma HMAC-SHA256
// antes de tocar nada: sin esto, cualquiera que conozca la URL podría forzar
// un despliegue (y la migración) sobre producción.
header('Content-Type: text/plain; charset=UTF-8');

$envPaths = [__DIR__ . '/.env', __DIR__ . '/../.env'];
foreach ($envPaths as $envPath) {
    if (file_exists($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
            list($name, $value) = explode('=', $line, 2);
            putenv(trim($name) . '=' . trim(trim($value), "\"'"));
        }
        break;
    }
}

$secret = getenv('GITHUB_WEBHOOK_SECRET');
if (empty($secret)) {
    http_response_code(500);
    echo "GITHUB_WEBHOOK_SECRET no está configurado en el servidor. Abortando por seguridad.\n";
    exit();
}

$payload = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!$signatureHeader || !hash_equals($expected, $signatureHeader)) {
    http_response_code(403);
    echo "Firma inválida. Petición rechazada.\n";
    exit();
}

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

