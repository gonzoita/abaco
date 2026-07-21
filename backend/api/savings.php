<?php
// C:\laragon\www\control-finanzas\backend\api\savings.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';

$userData = authenticate();
$userId = $userData['user_id'];
$db = Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$workspace = get_active_workspace();

if ($method === 'GET') {
    try {
        $gWsCond = get_workspace_sql_clause('g.workspace');
        // Obtener metas de ahorro con el nombre de la cuenta vinculada
        $stmt = $db->prepare("
            SELECT g.*, a.name as account_name, a.bank_name
            FROM savings_goals g
            LEFT JOIN accounts a ON g.account_id = a.id
            WHERE g.user_id = ? AND {$gWsCond}
            ORDER BY g.id DESC
        ");
        $stmt->execute([$userId]);
        $goals = $stmt->fetchAll();
        
        foreach ($goals as &$goal) {
            $goal['target_amount'] = floatval($goal['target_amount']);
            $goal['current_amount'] = floatval($goal['current_amount']);
            $goal['account_id'] = $goal['account_id'] ? intval($goal['account_id']) : null;
        }
        
        echo json_encode($goals);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al obtener metas: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'POST') {
    $name = trim($input['name'] ?? '');
    $targetAmount = floatval($input['target_amount'] ?? 0.00);
    $currentAmount = floatval($input['current_amount'] ?? 0.00);
    $targetDate = !empty($input['target_date']) ? trim($input['target_date']) : null;
    $accountId = isset($input['account_id']) && $input['account_id'] !== '' ? intval($input['account_id']) : null;

    if (empty($name) || $targetAmount <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "El nombre de la meta y el monto objetivo mayor a cero son obligatorios."]);
        exit();
    }

    try {
        $stmt = $db->prepare("INSERT INTO savings_goals (user_id, name, target_amount, current_amount, target_date, account_id, workspace) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $name, $targetAmount, $currentAmount, $targetDate, $accountId, $workspace]);
        
        $newId = $db->lastInsertId();
        echo json_encode([
            "message" => "Meta de ahorro creada con éxito.",
            "goal" => [
                "id" => $newId,
                "name" => $name,
                "target_amount" => $targetAmount,
                "current_amount" => $currentAmount,
                "target_date" => $targetDate,
                "account_id" => $accountId
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al crear la meta de ahorro: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'PUT') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Falta especificar el ID de la meta."]);
        exit();
    }

    $action = isset($_GET['action']) ? $_GET['action'] : '';

    try {
        if ($action === 'add_funds') {
            $amount = floatval($input['amount'] ?? 0.00);
            if ($amount <= 0) {
                throw new Exception("Monto a abonar debe ser mayor a cero.");
            }
            
            $stmt = $db->prepare("UPDATE savings_goals SET current_amount = current_amount + ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$amount, $id, $userId]);
            
            echo json_encode(["message" => "Fondos agregados a la meta exitosamente."]);
        } else {
            // Actualización general
            $name = trim($input['name'] ?? '');
            $targetAmount = floatval($input['target_amount'] ?? 0.00);
            $currentAmount = floatval($input['current_amount'] ?? 0.00);
            $targetDate = !empty($input['target_date']) ? trim($input['target_date']) : null;
            $accountId = isset($input['account_id']) && $input['account_id'] !== '' ? intval($input['account_id']) : null;

            $stmt = $db->prepare("UPDATE savings_goals SET name = ?, target_amount = ?, current_amount = ?, target_date = ?, account_id = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$name, $targetAmount, $currentAmount, $targetDate, $accountId, $id, $userId]);
            
            echo json_encode(["message" => "Meta de ahorro actualizada con éxito."]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al actualizar la meta: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'DELETE') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Falta especificar el ID de la meta."]);
        exit();
    }

    try {
        $stmt = $db->prepare("DELETE FROM savings_goals WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        
        echo json_encode(["message" => "Meta de ahorro eliminada con éxito."]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al eliminar la meta: " . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Método HTTP no permitido."]);
