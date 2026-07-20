<?php
// C:\laragon\www\control-finanzas\backend\api\settings.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';

$userData = authenticate();
$userId = $userData['user_id'];
$db = Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'GET') {
    if ($action === 'export_data') {
        try {
            // Obtener perfil
            $stmtUser = $db->prepare("SELECT id, name, email, currency, reminder_days_before FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $user = $stmtUser->fetch();

            // Obtener cuentas
            $stmtAcc = $db->prepare("SELECT * FROM accounts WHERE user_id = ?");
            $stmtAcc->execute([$userId]);
            $accounts = $stmtAcc->fetchAll();

            // Obtener transacciones
            $stmtTx = $db->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY date DESC");
            $stmtTx->execute([$userId]);
            $transactions = $stmtTx->fetchAll();

            // Obtener presupuestos
            $stmtBudgets = $db->prepare("SELECT * FROM budgets WHERE user_id = ?");
            $stmtBudgets->execute([$userId]);
            $budgets = $stmtBudgets->fetchAll();

            // Obtener metas
            $stmtGoals = $db->prepare("SELECT * FROM savings_goals WHERE user_id = ?");
            $stmtGoals->execute([$userId]);
            $goals = $stmtGoals->fetchAll();

            // Obtener recordatorios
            $stmtRem = $db->prepare("SELECT * FROM reminders WHERE user_id = ?");
            $stmtRem->execute([$userId]);
            $reminders = $stmtRem->fetchAll();

            echo json_encode([
                "export_date" => date('Y-m-d H:i:s'),
                "user" => $user,
                "accounts" => $accounts,
                "transactions" => $transactions,
                "budgets" => $budgets,
                "savings_goals" => $goals,
                "reminders" => $reminders
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al exportar datos: " . $e->getMessage()]);
        }
        exit();
    }

    try {
        $stmt = $db->prepare("SELECT id, name, email, currency, reminder_days_before, business_name, subscription_status, subscription_expires_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            http_response_code(404);
            echo json_encode(["error" => "Usuario no encontrado."]);
            exit();
        }

        if (empty($user['business_name'])) {
            $user['business_name'] = 'Mi Negocio';
        }

        echo json_encode($user);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al obtener la configuración: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'PUT') {
    $name = trim($input['name'] ?? '');
    $currency = trim($input['currency'] ?? 'COP');
    $reminderDaysBefore = isset($input['reminder_days_before']) ? intval($input['reminder_days_before']) : 5;
    $businessName = isset($input['business_name']) ? trim($input['business_name']) : 'Mi Negocio';

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(["error" => "El nombre no puede estar vacío."]);
        exit();
    }

    if ($reminderDaysBefore < 1 || $reminderDaysBefore > 31) {
        http_response_code(400);
        echo json_encode(["error" => "Los días de recordatorio deben estar entre 1 y 31."]);
        exit();
    }

    try {
        $stmt = $db->prepare("UPDATE users SET name = ?, currency = ?, reminder_days_before = ?, business_name = ? WHERE id = ?");
        $stmt->execute([$name, $currency, $reminderDaysBefore, $businessName, $userId]);

        echo json_encode([
            "message" => "Configuración actualizada con éxito.",
            "user" => [
                "name" => $name,
                "currency" => $currency,
                "reminder_days_before" => $reminderDaysBefore,
                "business_name" => $businessName
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al guardar la configuración: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'POST') {
    if ($action === 'reset_db') {
        try {
            $db->beginTransaction();

            // Eliminar todos los datos transaccionales y de configuración del usuario
            $db->prepare("DELETE FROM transactions WHERE user_id = ?")->execute([$userId]);
            $db->prepare("DELETE FROM budgets WHERE user_id = ?")->execute([$userId]);
            $db->prepare("DELETE FROM savings_goals WHERE user_id = ?")->execute([$userId]);
            $db->prepare("DELETE FROM reminders WHERE user_id = ?")->execute([$userId]);
            $db->prepare("DELETE FROM accounts WHERE user_id = ?")->execute([$userId]);
            
            // Eliminar categorías personalizadas (las del sistema tienen user_id NULL)
            $db->prepare("DELETE FROM categories WHERE user_id = ?")->execute([$userId]);

            // Recrear cuentas predeterminadas
            $stmtUser = $db->prepare("SELECT currency FROM users WHERE id = ?");
            $stmtUser->execute([$userId]);
            $user = $stmtUser->fetch();
            $currency = $user ? $user['currency'] : 'COP';

            $stmtAcc = $db->prepare("INSERT INTO accounts (user_id, name, type, balance, currency, tax_exempt) VALUES (?, ?, ?, ?, ?, ?)");
            // Efectivo (no exenta)
            $stmtAcc->execute([$userId, 'Efectivo', 'efectivo', 0.00, $currency, 0]);
            // Banco (exenta de 4x1000 por defecto de Laragon)
            $stmtAcc->execute([$userId, 'Mi Cuenta de Ahorros', 'banco', 0.00, $currency, 1]);

            $db->commit();
            echo json_encode(["message" => "Base de datos restablecida con éxito. Se han recreado tus cuentas por defecto."]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["error" => "Error al restablecer la base de datos: " . $e->getMessage()]);
        }
        exit();
    }
}

http_response_code(405);
echo json_encode(["error" => "Método HTTP no permitido."]);
