<?php
// C:\laragon\www\control-finanzas\backend\api\savings.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../lib/savings_logic.php';

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
        $goals = savings_get_goals($db, $userId, $gWsCond);
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

    try {
        $goal = savings_create_goal($db, $userId, $workspace, $name, $targetAmount, $currentAmount, $targetDate, $accountId);
        echo json_encode([
            "message" => "Meta de ahorro creada con éxito.",
            "goal" => $goal
        ]);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(["error" => $e->getMessage()]);
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
            $sourceAccountId = isset($input['source_account_id']) && $input['source_account_id'] !== '' ? intval($input['source_account_id']) : null;

            savings_add_funds($db, $userId, $id, $amount, $sourceAccountId, $workspace);

            echo json_encode(["message" => "Fondos abonados y descontados de tu cuenta exitosamente."]);
        } else {
            // Actualización general
            $name = trim($input['name'] ?? '');
            $targetAmount = floatval($input['target_amount'] ?? 0.00);
            $currentAmount = floatval($input['current_amount'] ?? 0.00);
            $targetDate = !empty($input['target_date']) ? trim($input['target_date']) : null;
            $accountId = isset($input['account_id']) && $input['account_id'] !== '' ? intval($input['account_id']) : null;

            savings_update_goal($db, $userId, $id, $name, $targetAmount, $currentAmount, $targetDate, $accountId);

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
        savings_delete_goal($db, $userId, $id);
        echo json_encode(["message" => "Meta de ahorro eliminada con éxito."]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al eliminar la meta: " . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Método HTTP no permitido."]);
