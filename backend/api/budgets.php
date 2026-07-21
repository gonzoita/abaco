<?php
// C:\laragon\www\control-finanzas\backend\api\budgets.php
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
        $month = isset($_GET['month']) ? intval($_GET['month']) : intval(date('m'));
        $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
        $bWsCond = get_workspace_sql_clause('b.workspace');

        // Obtener presupuestos del usuario para el mes, año y workspace dados
        $stmt = $db->prepare("
            SELECT b.*, c.name as category_name, c.color as category_color, c.icon as category_icon
            FROM budgets b
            LEFT JOIN categories c ON b.category_id = c.id
            WHERE b.user_id = ? AND {$bWsCond} AND b.month = ? AND b.year = ?
            ORDER BY b.category_id IS NULL DESC, c.name ASC
        ");
        $stmt->execute([$userId, $month, $year]);
        $budgets = $stmt->fetchAll();

        // Convertir montos a float y decodificar items_json
        foreach ($budgets as &$b) {
            $b['amount'] = floatval($b['amount']);
            $b['items'] = !empty($b['items_json']) ? json_decode($b['items_json'], true) : [];
        }

        echo json_encode($budgets);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al obtener presupuestos: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'POST') {
    $categoryId = isset($input['category_id']) && $input['category_id'] !== '' ? intval($input['category_id']) : null;
    $amount = floatval($input['amount'] ?? 0.00);
    $month = isset($input['month']) ? intval($input['month']) : intval(date('m'));
    $year = isset($input['year']) ? intval($input['year']) : intval(date('Y'));

    $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];
    $itemsJson = !empty($items) ? json_encode($items, JSON_UNESCAPED_UNICODE) : null;

    // Si hay ítems definidos, el monto total puede calcularse automáticamente
    if (!empty($items)) {
        $sumItems = 0;
        foreach ($items as $item) {
            $sumItems += floatval($item['amount'] ?? 0);
        }
        if ($sumItems > 0) {
            $amount = $sumItems;
        }
    }

    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "El monto del presupuesto debe ser mayor a cero."]);
        exit();
    }

    try {
        // Verificar si ya existe un registro de presupuesto para ese período, workspace y categoría
        if ($categoryId === null) {
            $stmtCheck = $db->prepare("SELECT id FROM budgets WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND category_id IS NULL AND month = ? AND year = ?");
            $stmtCheck->execute([$userId, $workspace, $month, $year]);
        } else {
            $stmtCheck = $db->prepare("SELECT id FROM budgets WHERE user_id = ? AND (workspace IS NULL OR workspace = ?) AND category_id = ? AND month = ? AND year = ?");
            $stmtCheck->execute([$userId, $workspace, $categoryId, $month, $year]);
        }

        $existing = $stmtCheck->fetch();

        if ($existing) {
            // Actualizar existente
            $stmtUpdate = $db->prepare("UPDATE budgets SET amount = ?, items_json = ? WHERE id = ?");
            $stmtUpdate->execute([$amount, $itemsJson, $existing['id']]);
            $budgetId = $existing['id'];
            $message = "Presupuesto actualizado con éxito.";
        } else {
            // Crear nuevo con workspace
            $stmtInsert = $db->prepare("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace, items_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$userId, $categoryId, $amount, $month, $year, $workspace, $itemsJson]);
            $budgetId = $db->lastInsertId();
            $message = "Presupuesto creado con éxito.";
        }

        echo json_encode([
            "message" => $message,
            "budget" => [
                "id" => $budgetId,
                "category_id" => $categoryId,
                "amount" => $amount,
                "items" => $items,
                "month" => $month,
                "year" => $year
            ]
        ]);
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
        $stmt = $db->prepare("DELETE FROM budgets WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);

        if ($stmt->rowCount() === 0) {
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
