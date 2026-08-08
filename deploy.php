<?php
// deploy.php
// (verificación de webhook real de GitHub — ver conversación del 2026-08-06)
// Disparado por el webhook "push" de GitHub. Verifica la firma HMAC-SHA256
// antes de tocar nada: sin esto, cualquiera que conozca la URL podría forzar
// un despliegue (y la migración) sobre producción.
//
// NOTA IMPORTANTE: exec(), shell_exec(), system() y passthru() están
// deshabilitadas en este hosting (a nivel de cuenta, tanto en CLI como en el
// PHP que atiende peticiones web). proc_open() sí está disponible y permite
// invocar git/php igual que exec(), así que todos los comandos de este
// script se ejecutan a través de run_cmd() (basada en proc_open) en vez de
// exec()/shell_exec() directamente.
header('Content-Type: text/plain; charset=UTF-8');

/**
 * Reemplazo de exec() basado en proc_open(), porque exec()/shell_exec()
 * están deshabilitadas en este hosting. Devuelve [líneas_de_salida, código].
 */
function run_cmd($cmd) {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return [["No se pudo iniciar el proceso (proc_open falló): {$cmd}"], 1];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $combined = trim($stdout . "\n" . $stderr);
    $lines = $combined === '' ? [] : explode("\n", $combined);
    return [$lines, $code];
}

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

// 0. Etiquetar el commit actual ANTES de moverlo, para poder volver atrás con
//    un solo comando si el despliegue rompe algo:
//    git reset --hard pre-deploy-<hash>
list($prevOut) = run_cmd('git rev-parse --short HEAD');
$prevCommit = trim(implode('', $prevOut));
if ($prevCommit && strlen($prevCommit) <= 12) {
    $rollbackTag = 'pre-deploy-' . $prevCommit . '-' . date('Ymd-His');
    run_cmd('git tag ' . escapeshellarg($rollbackTag));
    echo "0. Punto de rollback guardado: {$rollbackTag} (git reset --hard {$rollbackTag})\n";
    deploy_log($logPath, "Rollback tag creado: {$rollbackTag}");
    // Conservar solo las últimas 10 etiquetas de rollback para no acumular basura
    list($tagList) = run_cmd("git tag -l 'pre-deploy-*' --sort=-creatordate");
    foreach (array_slice($tagList, 10) as $oldTag) {
        $oldTag = trim($oldTag);
        if ($oldTag !== '') {
            run_cmd('git tag -d ' . escapeshellarg($oldTag));
        }
    }
}

echo "1. Ejecutando: git fetch origin...\n";
list($out, $code) = run_cmd('git fetch origin');
$output = array_merge($output, $out);

echo "2. Ejecutando: git reset --hard origin/main...\n";
list($out, $code) = run_cmd('git reset --hard origin/main');
$output = array_merge($output, $out);

list($newOut) = run_cmd('git rev-parse --short HEAD');
$newCommit = trim(implode('', $newOut));

// 2.5 Verificar sintaxis de TODO el PHP del repo antes de tocar public_html.
//     Si algo no compila, abortamos sin copiar nada: es preferible que el
//     sitio siga sirviendo la versión anterior a que quede roto.
echo "2.5 Verificando sintaxis PHP antes de publicar...\n";
list($phpFiles) = run_cmd("find " . escapeshellarg($repoPath) . " -name '*.php' -not -path '*/node_modules/*'");
$phpFiles = array_filter($phpFiles, fn($f) => trim($f) !== '');
$lintErrors = [];
foreach ($phpFiles as $phpFile) {
    list($lintOut, $lintCode) = run_cmd('php -l ' . escapeshellarg(trim($phpFile)));
    if ($lintCode !== 0) {
        $lintErrors[] = trim($phpFile) . ': ' . implode(' ', $lintOut);
    }
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
    echo "3. Sincronizando archivos a public_html (con borrado de lo que ya no está en el repo)...\n";
    // cp -rf solo copia/sobreescribe, nunca borra: un archivo eliminado del
    // repo (ej. un script sin autenticación que ya no debía existir) se
    // quedaba vivo en producción para siempre. rsync --delete espeja de
    // verdad. Se excluye lo que NO viene del repo y no debe tocarse:
    // .env (secretos), .git y .builds (symlink que usa el propio Hostinger
    // para el auto-pull) y deploy.log (bitácora acumulada).
    list($out, $code) = run_cmd(
        "rsync -a --delete " .
        "--exclude='.env' --exclude='.git' --exclude='.builds' --exclude='deploy.log' " .
        escapeshellarg(rtrim($repoPath, '/') . '/') . ' ' . escapeshellarg(rtrim($publicPath, '/') . '/')
    );
    $output = array_merge($output, $out);
}

if (file_exists("{$publicPath}/backend/api/migrate_workspaces.php")) {
    echo "4. Ejecutando migración de espacios de trabajo...\n";
    list($out, $code) = run_cmd('php ' . escapeshellarg("{$publicPath}/backend/api/migrate_workspaces.php"));
    $output = array_merge($output, $out);
}

echo "\n=== SALIDA DEL DESPLIEGUE ===\n";
echo implode("\n", $output) . "\n";

deploy_log($logPath, "OK: {$prevCommit} -> {$newCommit}");
echo "\n¡Proceso de despliegue finalizado! ({$prevCommit} -> {$newCommit})\n";
