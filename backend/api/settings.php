<?php
// C:\laragon\www\control-finanzas\backend\api\settings.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../lib/import_insert_row.php';
require_once __DIR__ . '/../lib/backup_logic.php';

$userData = authenticate();
$userId = $userData['user_id'];
$db = Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];
// Exportar, cargar y restablecer actúan solo sobre el espacio activo.
$workspace = get_active_workspace();
$workspaceLabel = $workspace === 'business' ? 'Negocio' : 'Personal';
$action = isset($_GET['action']) ? $_GET['action'] : '';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'GET') {
    if ($action === 'export_data') {
        try {
            echo json_encode(backup_export($db, $userId, $workspace));
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
    // Restaurar/cargar un respaldo JSON previamente exportado con export_data.
    // Es aditivo: NO borra lo que ya existe, inserta todo como registros
    // nuevos (con IDs nuevos) y remapea las referencias entre categorías,
    // cuentas y transacciones para que sigan apuntando correctamente.
    if ($action === 'import_data') {
        $backup = json_decode(file_get_contents('php://input'), true);

        if (!$backup || !is_array($backup) || (!isset($backup['accounts']) && !isset($backup['transactions']))) {
            http_response_code(400);
            echo json_encode(["error" => "El archivo de respaldo no es válido o está corrupto."]);
            exit();
        }

        // Solo se carga lo que corresponde al espacio activo (ver backup_prepare_import).
        $prepared = backup_prepare_import($backup, $workspace);
        if (isset($prepared['error'])) {
            http_response_code(400);
            echo json_encode(["error" => $prepared['error']]);
            exit();
        }
        $backup = $prepared['backup'];
        $ignored = $prepared['ignored'];

        try {
            $db->beginTransaction();

            $categoryMap = [];
            $accountMap = [];
            $imported = ["categories" => 0, "accounts" => 0, "transactions" => 0, "budgets" => 0, "savings_goals" => 0, "reminders" => 0];

            foreach (($backup['categories'] ?? []) as $cat) {
                $oldId = $cat['id'] ?? null;
                $cat['user_id'] = $userId;
                $newId = import_insert_row($db, 'categories', $cat);
                if ($oldId !== null && $newId !== null) $categoryMap[$oldId] = $newId;
                $imported['categories']++;
            }

            foreach (($backup['accounts'] ?? []) as $acc) {
                $oldId = $acc['id'] ?? null;
                $acc['user_id'] = $userId;
                $newId = import_insert_row($db, 'accounts', $acc);
                if ($oldId !== null && $newId !== null) $accountMap[$oldId] = $newId;
                $imported['accounts']++;
            }

            foreach (($backup['budgets'] ?? []) as $b) {
                $b['user_id'] = $userId;
                if (!empty($b['category_id']) && isset($categoryMap[$b['category_id']])) {
                    $b['category_id'] = $categoryMap[$b['category_id']];
                }
                import_insert_row($db, 'budgets', $b);
                $imported['budgets']++;
            }

            foreach (($backup['transactions'] ?? []) as $tx) {
                $tx['user_id'] = $userId;
                if (!empty($tx['account_id']) && isset($accountMap[$tx['account_id']])) {
                    $tx['account_id'] = $accountMap[$tx['account_id']];
                }
                if (!empty($tx['category_id']) && isset($categoryMap[$tx['category_id']])) {
                    $tx['category_id'] = $categoryMap[$tx['category_id']];
                }
                if (!empty($tx['transfer_to_account_id'])) {
                    $tx['transfer_to_account_id'] = $accountMap[$tx['transfer_to_account_id']] ?? null;
                }
                // Las recurrencias no forman parte del respaldo; se limpia el
                // vínculo para no dejarlo apuntando a un ID que no existe.
                if (array_key_exists('recurrence_id', $tx)) {
                    $tx['recurrence_id'] = null;
                }
                import_insert_row($db, 'transactions', $tx);
                $imported['transactions']++;
            }

            foreach (($backup['savings_goals'] ?? []) as $g) {
                $g['user_id'] = $userId;
                if (!empty($g['account_id']) && isset($accountMap[$g['account_id']])) {
                    $g['account_id'] = $accountMap[$g['account_id']];
                }
                import_insert_row($db, 'savings_goals', $g);
                $imported['savings_goals']++;
            }

            foreach (($backup['reminders'] ?? []) as $r) {
                $r['user_id'] = $userId;
                import_insert_row($db, 'reminders', $r);
                $imported['reminders']++;
            }

            $db->commit();
            $message = "Respaldo restaurado en el espacio {$workspaceLabel}.";
            if ($ignored > 0) {
                $message .= " Se omitieron {$ignored} registro(s) que eran del otro espacio.";
            }
            echo json_encode(["message" => $message, "imported" => $imported, "ignored" => $ignored]);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(["error" => "Error al restaurar el respaldo: " . $e->getMessage()]);
        }
        exit();
    }

    if ($action === 'reset_db') {
        try {
            $db->beginTransaction();

            // Solo el espacio activo: lo del otro espacio no se toca.
            backup_reset_workspace($db, $userId, $workspace);

            $db->commit();
            echo json_encode(["message" => "Se restablecieron los datos del espacio {$workspaceLabel} y se recrearon sus cuentas por defecto."]);
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
