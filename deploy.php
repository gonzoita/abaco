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
$logPath = __DIR__ . '/deploy.log';

function deploy_log($logPath, $line) {
    file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . "] {$line}\n", FILE_APPEND);
}

if (file_exists($repoPath)) {
    chdir($repoPath);
}

$output = [];
$returnVar = 0;

// 0. Etiquetar el commit actual ANTES de moverlo, para poder volver atrás con
//    un solo comando si el despliegue rompe algo:
//    git reset --hard pre-deploy-<hash>
$prevCommit = trim(shell_exec('git rev-parse --short HEAD 2>&1'));
if ($prevCommit) {
    $rollbackTag = 'pre-deploy-' . $prevCommit . '-' . date('Ymd-His');
    exec("git tag " . escapeshellarg($rollbackTag) . " 2>&1");
    echo "0. Punto de rollback guardado: {$rollbackTag} (git reset --hard {$rollbackTag})\n";
    deploy_log($logPath, "Rollback tag creado: {$rollbackTag}");
    // Conservar solo las últimas 10 etiquetas de rollback para no acumular basura
    exec("git tag -l 'pre-deploy-*' --sort=-creatordate 2>&1", $tagList);
    foreach (array_slice($tagList, 10) as $oldTag) {
        exec("git tag -d " . escapeshellarg(trim($oldTag)) . " 2>&1");
    }
}

echo "1. Ejecutando: git fetch origin...\n";
exec("git fetch origin 2>&1", $output, $returnVar);

echo "2. Ejecutando: git reset --hard origin/main...\n";
exec("git reset --hard origin/main 2>&1", $output, $returnVar);

$newCommit = trim(shell_exec('git rev-parse --short HEAD 2>&1'));

// 2.5 Verificar sintaxis de TODO el PHP del repo antes de tocar public_html.
//     Si algo no compila, abortamos sin copiar nada: es preferible que el
//     sitio siga sirviendo la versión anterior a que quede roto.
echo "2.5 Verificando sintaxis PHP antes de publicar...\n";
exec("find " . escapeshellarg($repoPath) . " -name '*.php' -not -path '*/node_modules/*' 2>&1", $phpFiles);
$lintErrors = [];
foreach ($phpFiles as $phpFile) {
    exec("php -l " . escapeshellarg($phpFile) . " 2>&1", $lintOut, $lintCode);
    if ($lintCode !== 0) {
        $lintErrors[] = $phpFile . ': ' . implode(' ', $lintOut);
    }
    $lintOut = [];
}

if (!empty($lintErrors)) {
    echo "\n!!! DESPLIEGUE ABORTADO: hay errores de sintaxis PHP !!!\n";
    echo implode("\n", $lintErrors) . "\n";
    deploy_log($logPath, "ABORTADO ({$newCommit}): " . implode(' | ', $lintErrors));
    echo "public_html NO se tocó. Sigue sirviendo el commit anterior ({$prevCommit}).\n";
    exit();
}
echo "   OK - " . count($phpFiles) . " archivos PHP sin errores de sintaxis.\n";

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

deploy_log($logPath, "OK: {$prevCommit} -> {$newCommit}");
echo "\n¡Proceso de despliegue finalizado! ({$prevCommit} -> {$newCommit})\n";

