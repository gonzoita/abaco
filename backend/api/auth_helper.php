<?php
// C:\laragon\www\control-finanzas\backend\api\auth_helper.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';

// Compatibilidad de getallheaders para cualquier servidor (Apache, Nginx, CGI)
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            } elseif (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))))] = $value;
            }
        }
        return $headers;
    }
}

/**
 * Autentica la solicitud mediante el token JWT en las cabeceras.
 * Retorna los datos del usuario decodificados si es exitoso, de lo contrario corta la ejecución con 401.
 */
function authenticate() {
    $headers = getallheaders();
    $authHeader = '';

    if (isset($headers['Authorization'])) {
        $authHeader = $headers['Authorization'];
    } elseif (isset($headers['authorization'])) {
        $authHeader = $headers['authorization'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_GET['token']) && !empty($_GET['token'])) {
        $authHeader = 'Bearer ' . trim($_GET['token']);
    }

    if (empty($authHeader)) {
        http_response_code(401);
        echo json_encode(["error" => "No autorizado. Token de sesión no proporcionado."]);
        exit();
    }

    $token = null;
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
    }

    if (!$token) {
        http_response_code(401);
        echo json_encode(["error" => "Formato de token de sesión inválido."]);
        exit();
    }

    $decoded = JWT::decode($token, JWT_SECRET);
    if (!$decoded) {
        http_response_code(401);
        echo json_encode(["error" => "Sesión inválida o expirada. Por favor inicie sesión nuevamente."]);
        exit();
    }

    // Marcar "última actividad" en cada petición autenticada, no solo al
    // hacer login: el JWT dura 30 días, así que un usuario activo a diario
    // puede no volver a pasar por /auth.php?action=login en semanas, y con
    // solo el evento de login se veía como "Nunca" aunque estuviera usando
    // la app en ese momento. Con guard de 1 hora en el propio UPDATE para
    // no escribir en cada una de las peticiones (el dashboard solo dispara 6
    // seguidas).
    if (isset($decoded['user_id'])) {
        try {
            $db = Database::getConnection();
            $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ? AND (last_login_at IS NULL OR last_login_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))")
               ->execute([$decoded['user_id']]);
        } catch (Exception $e) {}
    }

    return $decoded; // Contiene user_id, email, name, etc.
}

/**
 * Obtiene el espacio de trabajo activo ('personal' o 'business') enviado en la cabecera X-Workspace o por parámetro GET.
 */
function get_active_workspace() {
    $ws = '';

    // 1. Intentar desde $_GET o $_POST primero (máxima prioridad explícita)
    if (isset($_GET['workspace']) && !empty($_GET['workspace'])) {
        $ws = trim($_GET['workspace']);
    } elseif (isset($_POST['workspace']) && !empty($_POST['workspace'])) {
        $ws = trim($_POST['workspace']);
    }

    // 2. Intentar desde cabeceras HTTP si no vino por URL
    if (empty($ws)) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $val) {
            if (strtolower($key) === 'x-workspace') {
                $ws = trim($val);
                break;
            }
        }
    }

    // 3. Fallbacks de variables de servidor CGI/Nginx/Apache
    if (empty($ws) && isset($_SERVER['HTTP_X_WORKSPACE'])) {
        $ws = trim($_SERVER['HTTP_X_WORKSPACE']);
    }
    if (empty($ws) && isset($_SERVER['REDIRECT_HTTP_X_WORKSPACE'])) {
        $ws = trim($_SERVER['REDIRECT_HTTP_X_WORKSPACE']);
    }

    $ws = strtolower($ws);
    return in_array($ws, ['personal', 'business']) ? $ws : 'personal';
}

/**
 * Retorna la cláusula SQL de filtrado para el workspace activo.
 * Si es 'business', exige workspace = 'business'.
 * Si es 'personal', incluye personal, NULL y vacíos.
 */
function get_workspace_sql_clause($columnName = 'workspace') {
    $ws = get_active_workspace();
    if ($ws === 'business') {
        return "(LOWER(COALESCE({$columnName}, 'personal')) = 'business')";
    } else {
        return "(LOWER(COALESCE({$columnName}, 'personal')) = 'personal' OR {$columnName} IS NULL OR {$columnName} = '')";
    }
}
