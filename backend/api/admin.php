<?php
// C:\laragon\www\control-finanzas\backend\api\admin.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';

$userData = authenticate();
if (($userData['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(["error" => "Acceso restringido. Requiere privilegios de administrador."]);
    exit();
}

$db = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'GET') {
    if ($action === 'metrics') {
        try {
            // Usuarios Totales
            $stmt = $db->query("SELECT COUNT(*) FROM users");
            $totalUsers = intval($stmt->fetchColumn());

            // Usuarios Activos (Verificados)
            $stmt = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
            $activeUsers = intval($stmt->fetchColumn());

            // Usuarios Inactivos (No verificados)
            $stmt = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 0");
            $inactiveUsers = intval($stmt->fetchColumn());

            // Distribución de suscripciones
            $stmt = $db->query("SELECT subscription_status, COUNT(*) as count FROM users GROUP BY subscription_status");
            $subData = $stmt->fetchAll();
            $subs = [
                "trial" => 0,
                "active" => 0,
                "expired" => 0
            ];
            foreach ($subData as $row) {
                $status = $row['subscription_status'];
                if (isset($subs[$status])) {
                    $subs[$status] = intval($row['count']);
                }
            }

            // Volumen total de transacciones
            $stmt = $db->query("SELECT COUNT(*) FROM transactions");
            $totalTransactions = intval($stmt->fetchColumn());

            echo json_encode([
                "total_users" => $totalUsers,
                "active_users" => $activeUsers,
                "inactive_users" => $inactiveUsers,
                "subscriptions" => $subs,
                "total_transactions" => $totalTransactions
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al cargar métricas: " . $e->getMessage()]);
        }
        exit();
    }

    if ($action === 'users') {
        try {
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            if ($search !== '') {
                $stmt = $db->prepare("
                    SELECT id, name, email, subscription_status, subscription_expires_at, is_active, role, created_at 
                    FROM users 
                    WHERE name LIKE ? OR email LIKE ?
                    ORDER BY created_at DESC
                ");
                $stmt->execute(["%$search%", "%$search%"]);
            } else {
                $stmt = $db->query("
                    SELECT id, name, email, subscription_status, subscription_expires_at, is_active, role, created_at 
                    FROM users 
                    ORDER BY created_at DESC
                ");
            }
            $users = $stmt->fetchAll();
            
            // Cast types
            foreach ($users as &$u) {
                $u['id'] = intval($u['id']);
                $u['is_active'] = intval($u['is_active']);
            }

            echo json_encode($users);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al obtener usuarios: " . $e->getMessage()]);
        }
        exit();
    }
}

if ($method === 'POST') {
    if ($action === 'update_user') {
        $targetUserId = isset($input['user_id']) ? intval($input['user_id']) : null;
        $isActive = isset($input['is_active']) ? intval($input['is_active']) : null;
        $subStatus = isset($input['subscription_status']) ? trim($input['subscription_status']) : null;
        $subExpiresAt = isset($input['subscription_expires_at']) ? $input['subscription_expires_at'] : null;
        $role = isset($input['role']) ? trim($input['role']) : null;

        if (!$targetUserId) {
            http_response_code(400);
            echo json_encode(["error" => "ID de usuario objetivo requerido."]);
            exit();
        }

        // Evitar que el administrador se auto-desactive o se auto-quit el rol admin
        if ($targetUserId === intval($userData['user_id'])) {
            if ($isActive === 0 || $role === 'user') {
                http_response_code(400);
                echo json_encode(["error" => "No puedes desactivarte a ti mismo ni revocar tus propios privilegios de administrador."]);
                exit();
            }
        }

        try {
            // Construir consulta dinámica
            $fields = [];
            $params = [];

            if ($isActive !== null) {
                $fields[] = "is_active = ?";
                $params[] = $isActive;
            }
            if ($subStatus !== null) {
                $fields[] = "subscription_status = ?";
                $params[] = $subStatus;
            }
            if ($subExpiresAt !== null) {
                $fields[] = "subscription_expires_at = ?";
                $params[] = $subExpiresAt === '' ? null : $subExpiresAt;
            }
            if ($role !== null) {
                $fields[] = "role = ?";
                $params[] = $role;
            }

            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(["error" => "No se enviaron campos para actualizar."]);
                exit();
            }

            $params[] = $targetUserId;
            $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            echo json_encode(["message" => "Usuario actualizado con éxito."]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al actualizar usuario: " . $e->getMessage()]);
        }
        exit();
    }

    if ($action === 'delete_user') {
        $targetUserId = isset($input['user_id']) ? intval($input['user_id']) : null;

        if (!$targetUserId) {
            http_response_code(400);
            echo json_encode(["error" => "ID de usuario objetivo requerido."]);
            exit();
        }

        // Prevenir auto-eliminación
        if ($targetUserId === intval($userData['user_id'])) {
            http_response_code(400);
            echo json_encode(["error" => "No puedes eliminar tu propia cuenta de administrador."]);
            exit();
        }

        try {
            $db->beginTransaction();

            // 1. Eliminar transacciones ligadas a las cuentas del usuario
            $stmtTrans = $db->prepare("DELETE FROM transactions WHERE account_id IN (SELECT id FROM accounts WHERE user_id = ?)");
            $stmtTrans->execute([$targetUserId]);

            // 2. Eliminar cuotas de préstamos
            $stmtInst = $db->prepare("DELETE FROM loan_installments WHERE loan_id IN (SELECT id FROM loans WHERE user_id = ?)");
            $stmtInst->execute([$targetUserId]);

            // 3. Eliminar préstamos
            $stmtLoans = $db->prepare("DELETE FROM loans WHERE user_id = ?");
            $stmtLoans->execute([$targetUserId]);

            // 4. Eliminar presupuestos
            $stmtBud = $db->prepare("DELETE FROM budgets WHERE user_id = ?");
            $stmtBud->execute([$targetUserId]);

            // 5. Eliminar categorías personalizadas del usuario
            $stmtCats = $db->prepare("DELETE FROM categories WHERE user_id = ?");
            $stmtCats->execute([$targetUserId]);

            // 6. Eliminar cuentas
            $stmtAccs = $db->prepare("DELETE FROM accounts WHERE user_id = ?");
            $stmtAccs->execute([$targetUserId]);

            // 7. Eliminar al usuario
            $stmtUser = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmtUser->execute([$targetUserId]);

            $db->commit();

            echo json_encode(["message" => "Cuenta de usuario y datos financieros asociados eliminados con éxito en cascada."]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(["error" => "Error al eliminar usuario en cascada: " . $e->getMessage()]);
        }
        exit();
    }
}

http_response_code(404);
echo json_encode(["error" => "Acción no encontrada."]);
