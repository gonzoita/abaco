<?php
// C:\laragon\www\control-finanzas\backend\api\budgets.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../lib/budgets_logic.php';

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
        $month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
        $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        $bWsCond = get_workspace_sql_clause('b.workspace');

        $budgets = budgets_get_for_period($db, $userId, $bWsCond, $month, $year, !isset($_GET['month']));

        echo json_encode($budgets);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al obtener presupuestos: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'POST') {
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    if ($action === 'copy_from_last_month') {
        try {
            $bWsCond = get_workspace_sql_clause('b.workspace');
            $currentMonth = intval(date('m'));
            $currentYear = intval(date('Y'));

            $result = budgets_copy_from_last_month($db, $userId, $workspace, $bWsCond, $currentMonth, $currentYear);

            echo json_encode([
                "message" => "Se han copiado {$result['copied_count']} presupuestos del período {$result['from_month']}/{$result['from_year']} al mes actual.",
                "copied_count" => $result['copied_count']
            ]);
        } catch (RuntimeException $e) {
            http_response_code(404);
            echo json_encode(["error" => $e->getMessage()]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al copiar presupuestos: " . $e->getMessage()]);
        }
        exit();
    }

    $categoryId = isset($input['category_id']) && $input['category_id'] !== '' ? intval($input['category_id']) : null;
    $amount = floatval($input['amount'] ?? 0.00);
    $month = isset($input['month']) ? intval($input['month']) : intval(date('m'));
    $year = isset($input['year']) ? intval($input['year']) : intval(date('Y'));

    $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];
    $itemsJson = !empty($items) ? json_encode($items, JSON_UNESCAPED_UNICODE) : null;
    $amount = budgets_calculate_amount_from_items($items, $amount);

    try {
        $result = budgets_upsert($db, $userId, $workspace, $categoryId, $amount, $month, $year, $itemsJson);

        echo json_encode([
            "message" => $result['created'] ? "Presupuesto creado con éxito." : "Presupuesto actualizado con éxito.",
            "budget" => [
                "id" => $result['id'],
                "category_id" => $categoryId,
                "amount" => $amount,
                "items" => $items,
                "month" => $month,
                "year" => $year
            ]
        ]);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(["error" => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al guardar el presupuesto: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'DELETE') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Falta especificar el ID del presupuesto."]);
        exit();
    }

    try {
        $deleted = budgets_delete($db, $userId, $id);

        if ($deleted === 0) {
            http_response_code(404);
            echo json_encode(["error" => "Presupuesto no encontrado o no tienes permisos."]);
            exit();
        }

        echo json_encode(["message" => "Presupuesto eliminado con éxito."]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al eliminar el presupuesto: " . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Método HTTP no permitido."]);
