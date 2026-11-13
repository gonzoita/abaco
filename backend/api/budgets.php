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

if ($method === 'GET' && ($_GET['action'] ?? '') === 'list_periods') {
    try {
        $month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
        $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        echo json_encode(budgets_list_periods($db, $userId, get_workspace_sql_clause('b.workspace'), $month, $year));
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al listar los meses con presupuesto: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'GET') {
    try {
        $month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
        $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        $bWsCond = get_workspace_sql_clause('b.workspace');
        $implicitPeriod = !isset($_GET['month']);

        // Al entrar al mes actual sin pedir un mes concreto, el presupuesto del
        // período anterior se materializa si este mes todavía está vacío: así
        // el presupuesto está siempre, desde el día 1, sin depender de que el
        // usuario apriete un botón para recuperarlo.
        if ($implicitPeriod) {
            budgets_ensure_period_filled($db, $userId, $workspace, $bWsCond, $month, $year);
        }

        $budgets = budgets_get_for_period($db, $userId, $bWsCond, $month, $year, $implicitPeriod);

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

            $fromMonth = isset($_GET['from_month']) ? intval($_GET['from_month']) : null;
            $fromYear = isset($_GET['from_year']) ? intval($_GET['from_year']) : null;

            $result = budgets_copy_from_last_month($db, $userId, $workspace, $bWsCond, $currentMonth, $currentYear, $fromMonth, $fromYear);

            $message = $result['copied_count'] > 0
                ? "Se trajeron {$result['copied_count']} categoría(s) del presupuesto de {$result['from_month']}/{$result['from_year']}."
                : "Este mes ya tiene todas las categorías del presupuesto de {$result['from_month']}/{$result['from_year']}: no había nada que traer.";
            echo json_encode([
                "message" => $message,
                "copied_count" => $result['copied_count']
            ]);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(["error" => $e->getMessage()]);
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
        $bWsCond = get_workspace_sql_clause('b.workspace');
        $result = budgets_upsert($db, $userId, $workspace, $categoryId, $amount, $month, $year, $itemsJson, $bWsCond);

        $message = $result['created'] ? "Presupuesto creado con éxito." : "Presupuesto actualizado con éxito.";
        if ($result['carried_over_count'] > 0) {
            $message .= " Además trajimos {$result['carried_over_count']} categoría(s) de tu presupuesto del mes anterior.";
        }

        echo json_encode([
            "message" => $message,
            "carried_over_count" => $result['carried_over_count'],
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
        // El mes/año del que el cliente cree que está borrando (por defecto el
        // actual, que es el que muestra la pantalla de Presupuestos). Así una
        // fila heredada del mes pasado no se puede borrar por accidente desde
        // la vista del mes actual.
        $month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
        $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

        $deleted = budgets_delete($db, $userId, $id, $month, $year);

        if ($deleted === 0) {
            http_response_code(404);
            echo json_encode(["error" => "Ese presupuesto no corresponde al mes que estás viendo (puede venir heredado del mes anterior), o no tienes permisos."]);
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
