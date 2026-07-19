<?php
// C:\laragon\www\control-finanzas\backend\api\auth.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/auth_helper.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';
$db = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'activate') {
        $token = isset($_GET['token']) ? trim($_GET['token']) : '';
        if (empty($token)) {
            http_response_code(400);
            echo "<h1>Token de activación no proporcionado.</h1>";
            exit();
        }

        // Detectar URL dinámica del frontend para redirecciones
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $requestUri = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
        $basePath = explode('/backend/', $requestUri, 2)[0];
        $frontendUrl = $protocol . $host . $basePath . "/";

        $stmt = $db->prepare("SELECT id, name FROM users WHERE activation_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(400);
            echo "
            <!DOCTYPE html>
            <html lang='es'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Enlace no válido - Ábaco</title>
                <style>
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                        background-color: #f2f2f7;
                        color: #000000;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        height: 100vh;
                        margin: 0;
                    }
                    .card {
                        background: white;
                        padding: 40px;
                        border-radius: 20px;
                        box-shadow: 0 8px 24px rgba(0,0,0,0.04);
                        text-align: center;
                        max-width: 400px;
                    }
                    h1 { color: #ff453a; margin-bottom: 10px; font-size: 24px; }
                    p { color: rgba(0,0,0,0.6); font-size: 16px; line-height: 1.5; margin-bottom: 25px; }
                    .btn {
                        background-color: #0a84ff;
                        color: white;
                        text-decoration: none;
                        padding: 12px 24px;
                        border-radius: 10px;
                        font-weight: 500;
                        display: inline-block;
                    }
                </style>
            </head>
            <body>
                <div class='card'>
                    <h1>Enlace no válido</h1>
                    <p>El token de activación ha expirado o no es válido. Intenta registrarte nuevamente.</p>
                    <a href='{$frontendUrl}#/register' class='btn'>Volver a Registrarse</a>
                </div>
            </body>
            </html>
            ";
            exit();
        }

        // Activar usuario
        $stmtUpdate = $db->prepare("UPDATE users SET is_active = 1, activation_token = NULL WHERE id = ?");
        $stmtUpdate->execute([$user['id']]);

        // Renderizar confirmación
        echo "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Cuenta Activada - Ábaco</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background-color: #f2f2f7;
                    color: #000000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 100vh;
                    margin: 0;
                }
                .card {
                    background: white;
                    padding: 40px;
                    border-radius: 20px;
                    box-shadow: 0 8px 24px rgba(0,0,0,0.04);
                    text-align: center;
                    max-width: 400px;
                }
                h1 { color: #30d158; margin-bottom: 10px; font-size: 24px; }
                p { color: rgba(0,0,0,0.6); font-size: 16px; line-height: 1.5; margin-bottom: 25px; }
                .btn {
                    background-color: #0a84ff;
                    color: white;
                    text-decoration: none;
                    padding: 12px 24px;
                    border-radius: 10px;
                    font-weight: 500;
                    display: inline-block;
                    transition: background-color 0.2s;
                }
                .btn:hover { background-color: #0076e5; }
            </style>
        </head>
        <body>
            <div class='card'>
                <h1>¡Cuenta Activada!</h1>
                <p>Hola <strong>" . htmlspecialchars($user['name']) . "</strong>, tu cuenta ha sido verificada y activada correctamente. Ya puedes iniciar sesión en la aplicación.</p>
                <a href='{$frontendUrl}#/login' class='btn'>Iniciar Sesión</a>
            </div>
        </body>
        </html>
        ";
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    if ($action === 'register') {
        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $currency = trim($input['currency'] ?? 'COP');

        if (empty($name) || empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(["error" => "Por favor complete todos los campos obligatorios."]);
            exit();
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["error" => "El correo electrónico no es válido."]);
            exit();
        }

        // Verificar si el correo ya existe
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(["error" => "El correo electrónico ya está registrado."]);
            exit();
        }

        // Hashear la contraseña
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        try {
            $db->beginTransaction();

            // Insertar usuario
            // Suscripción por defecto de 30 días de prueba (trial)
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
            $activationToken = bin2hex(random_bytes(16));
            $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, currency, subscription_status, subscription_expires_at, is_active, activation_token) VALUES (?, ?, ?, ?, 'trial', ?, 0, ?)");
            $stmt->execute([$name, $email, $passwordHash, $currency, $expiresAt, $activationToken]);
            $userId = $db->lastInsertId();

            // Crear cuentas predeterminadas
            $stmtAcc = $db->prepare("INSERT INTO accounts (user_id, name, type, balance, currency) VALUES (?, ?, ?, ?, ?)");
            $stmtAcc->execute([$userId, 'Efectivo', 'efectivo', 0.00, $currency]);
            $stmtAcc->execute([$userId, 'Mi Cuenta de Ahorros', 'banco', 0.00, $currency]);

            $db->commit();

            // Enviar correo de activación
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'];
            $requestUri = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
            $activationLink = $protocol . $host . $requestUri . "?action=activate&token=" . $activationToken;
            
            $to = $email;
            $subject = "Activa tu cuenta de Abaco, finanzas";
            $message = "
            <html>
            <head>
                <meta charset='UTF-8'>
                <title>Activa tu cuenta - Ábaco</title>
            </head>
            <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, sans-serif; background-color: #f2f2f7; padding: 40px; margin: 0;'>
                <div style='background-color: #ffffff; max-width: 500px; margin: 0 auto; padding: 40px; border-radius: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); text-align: center;'>
                    <h2 style='color: #000000; font-size: 22px; font-weight: 600; margin-bottom: 10px;'>¡Bienvenido a Ábaco, finanzas!</h2>
                    <p style='color: #636366; font-size: 15px; line-height: 1.5; margin-bottom: 30px;'>Hola {$name}, gracias por registrarte. Para comenzar a administrar tus finanzas de forma inteligente y segura, activa tu cuenta haciendo clic en el botón de abajo:</p>
                    <a href='{$activationLink}' style='background-color: #0a84ff; color: #ffffff; text-decoration: none; padding: 12px 30px; border-radius: 10px; font-weight: 500; display: inline-block; font-size: 15px;'>Activar mi Cuenta</a>
                    <hr style='border: 0; border-top: 1px solid #e5e5ea; margin: 30px 0;'>
                    <p style='color: #8e8e93; font-size: 12px;'>Si no has creado una cuenta en Ábaco, puedes omitir este correo electrónico de forma segura.</p>
                </div>
            </body>
            </html>
            ";
            
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= "From: no-reply@abaco.finance" . "\r\n";
            $headers .= "Reply-To: no-reply@abaco.finance" . "\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            @mail($to, $subject, $message, $headers);

            echo json_encode([
                "message" => "Usuario registrado con éxito. Se ha enviado un correo de verificación.",
                "user" => [
                    "id" => $userId,
                    "name" => $name,
                    "email" => $email,
                    "currency" => $currency
                ]
            ]);

        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["error" => "Error al registrar el usuario: " . $e->getMessage()]);
        }
        exit();
    }

    if ($action === 'login') {
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(["error" => "Debe ingresar el correo y la contraseña."]);
            exit();
        }

        $stmt = $db->prepare("SELECT id, name, email, password_hash, currency, subscription_status, subscription_expires_at, is_active, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(["error" => "Credenciales incorrectas."]);
            exit();
        }

        if (isset($user['is_active']) && intval($user['is_active']) === 0) {
            http_response_code(403);
            echo json_encode(["error" => "Por favor activa tu cuenta antes de iniciar sesión. Revisa tu correo de confirmación."]);
            exit();
        }

        // Gestión Automática de Expiración de Suscripción al Iniciar Sesión
        $now = date('Y-m-d H:i:s');
        if ($user['subscription_expires_at'] !== null && $user['subscription_expires_at'] < $now && $user['subscription_status'] !== 'expired') {
            $stmtExp = $db->prepare("UPDATE users SET subscription_status = 'expired' WHERE id = ?");
            $stmtExp->execute([$user['id']]);
            $user['subscription_status'] = 'expired';
        }

        // Generar JWT
        $payload = [
            "user_id" => $user['id'],
            "name" => $user['name'],
            "email" => $user['email'],
            "currency" => $user['currency'],
            "subscription_status" => $user['subscription_status'],
            "role" => $user['role'] ?? 'user',
            "exp" => time() + (60 * 60 * 24 * 30) // Expiración en 30 días
        ];

        $token = JWT::encode($payload, JWT_SECRET);

        echo json_encode([
            "message" => "Inicio de sesión exitoso.",
            "token" => $token,
            "user" => [
                "id" => $user['id'],
                "name" => $user['name'],
                "email" => $user['email'],
                "currency" => $user['currency'],
                "subscription_status" => $user['subscription_status'],
                "subscription_expires_at" => $user['subscription_expires_at'],
                "role" => $user['role'] ?? 'user'
            ]
        ]);
        exit();
    }

    if ($action === 'google_login') {
        $token = trim($input['token'] ?? '');

        if (empty($token)) {
            http_response_code(400);
            echo json_encode(["error" => "Token de Google no provisto."]);
            exit();
        }

        // Validar el token contra los servidores oficiales de Google
        $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($token);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$responseJson) {
            http_response_code(401);
            echo json_encode(["error" => "Autenticación de Google inválida o expirada."]);
            exit();
        }

        $googleUser = json_decode($responseJson, true);
        
        if (empty($googleUser['email'])) {
            http_response_code(401);
            echo json_encode(["error" => "No se pudo recuperar el correo desde Google."]);
            exit();
        }

        $email = trim($googleUser['email']);
        $name = trim($googleUser['name'] ?? $googleUser['given_name'] ?? 'Usuario Google');

        // Buscar si el usuario ya existe en la base de datos
        $stmt = $db->prepare("SELECT id, name, email, currency, subscription_status, subscription_expires_at, is_active, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && isset($user['is_active']) && intval($user['is_active']) === 0) {
            // Si ya existe pero estaba inactivo, lo activamos automáticamente ya que Google certifica la propiedad del correo
            $stmtAct = $db->prepare("UPDATE users SET is_active = 1, activation_token = NULL WHERE id = ?");
            $stmtAct->execute([$user['id']]);
            $user['is_active'] = 1;
        }

        if (!$user) {
            // Registrar usuario dinámicamente si no existe (Auto-Signup OAuth) - Activado directamente
            try {
                $db->beginTransaction();

                $randomPassword = bin2hex(random_bytes(16));
                $passwordHash = password_hash($randomPassword, PASSWORD_BCRYPT);
                $currency = 'COP';
                $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

                $stmtInsert = $db->prepare("INSERT INTO users (name, email, password_hash, currency, subscription_status, subscription_expires_at, is_active, activation_token) VALUES (?, ?, ?, ?, 'trial', ?, 1, NULL)");
                $stmtInsert->execute([$name, $email, $passwordHash, $currency, $expiresAt]);
                $userId = $db->lastInsertId();

                // Crear cuentas predeterminadas
                $stmtAcc = $db->prepare("INSERT INTO accounts (user_id, name, type, balance, currency) VALUES (?, ?, ?, ?, ?)");
                $stmtAcc->execute([$userId, 'Efectivo', 'efectivo', 0.00, $currency]);
                $stmtAcc->execute([$userId, 'Mi Cuenta de Ahorros', 'banco', 0.00, $currency]);

                $db->commit();

                // Crear el objeto user para la respuesta
                $user = [
                    'id' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'currency' => $currency,
                    'subscription_status' => 'trial',
                    'subscription_expires_at' => $expiresAt,
                    'is_active' => 1,
                    'role' => 'user'
                ];

            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                http_response_code(500);
                echo json_encode(["error" => "Error al registrar usuario vía Google: " . $e->getMessage()]);
                exit();
            }
        }

        // Gestión Automática de Expiración de Suscripción al Iniciar Sesión con Google
        $now = date('Y-m-d H:i:s');
        if ($user['subscription_expires_at'] !== null && $user['subscription_expires_at'] < $now && $user['subscription_status'] !== 'expired') {
            $stmtExp = $db->prepare("UPDATE users SET subscription_status = 'expired' WHERE id = ?");
            $stmtExp->execute([$user['id']]);
            $user['subscription_status'] = 'expired';
        }

        // Generar JWT y retornar sesión
        $payload = [
            "user_id" => $user['id'],
            "name" => $user['name'],
            "email" => $user['email'],
            "currency" => $user['currency'],
            "subscription_status" => $user['subscription_status'],
            "role" => $user['role'] ?? 'user',
            "exp" => time() + (60 * 60 * 24 * 30) // 30 días
        ];

        $token = JWT::encode($payload, JWT_SECRET);

        echo json_encode([
            "message" => "Inicio de sesión vía Google exitoso.",
            "token" => $token,
            "user" => [
                "id" => $user['id'],
                "name" => $user['name'],
                "email" => $user['email'],
                "currency" => $user['currency'],
                "subscription_status" => $user['subscription_status'],
                "subscription_expires_at" => $user['subscription_expires_at'],
                "role" => $user['role'] ?? 'user'
            ]
        ]);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'me') {
        $userData = authenticate();
        
        // Consultar info actualizada
        $stmt = $db->prepare("SELECT id, name, email, currency, subscription_status, subscription_expires_at, role FROM users WHERE id = ?");
        $stmt->execute([$userData['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(404);
            echo json_encode(["error" => "Usuario no encontrado."]);
            exit();
        }

        echo json_encode([
            "user" => $user
        ]);
        exit();
    }
}

http_response_code(404);
echo json_encode(["error" => "Acción no encontrada."]);
