<?php
// C:\laragon\www\control-finanzas\backend\api\categories.php
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
    // Obtener categorías: globales (user_id IS NULL) o del usuario para el workspace activo
    $stmt = $db->prepare("SELECT * FROM categories WHERE (user_id IS NULL OR user_id = ?) AND (workspace IS NULL OR workspace = '' OR workspace = ?) ORDER BY user_id ASC, name ASC");
    $stmt->execute([$userId, $workspace, $workspace]);
    $categories = $stmt->fetchAll();
    echo json_encode($categories);
    exit();
}

if ($method === 'POST') {
    $name = trim($input['name'] ?? '');
    $icon = trim($input['icon'] ?? 'fa-tag');
    $color = trim($input['color'] ?? '#cccccc');
    $type = trim($input['type'] ?? '');

    if (empty($name) || empty($type)) {
        http_response_code(400);
        echo json_encode(["error" => "El nombre y el tipo de categoría (ingreso/egreso) son obligatorios."]);
        exit();
    }

    if (!in_array($type, ['ingreso', 'egreso'])) {
        http_response_code(400);
        echo json_encode(["error" => "Tipo de categoría no válido."]);
        exit();
    }

    try {
        $stmt = $db->prepare("INSERT INTO categories (user_id, name, icon, color, type, workspace) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $name, $icon, $color, $type, $workspace]);
        
        $newId = $db->lastInsertId();
        echo json_encode([
            "message" => "Categoría creada con éxito.",
            "category" => [
                "id" => $newId,
                "user_id" => $userId,
                "name" => $name,
                "icon" => $icon,
                "color" => $color,
                "type" => $type
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al crear la categoría: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'PUT') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Falta especificar el ID de la categoría."]);
        exit();
    }

    $name = trim($input['name'] ?? '');
    $icon = trim($input['icon'] ?? 'fa-tag');
    $color = trim($input['color'] ?? '#cccccc');
    $type = trim($input['type'] ?? '');

    if (empty($name) || empty($type)) {
        http_response_code(400);
        echo json_encode(["error" => "El nombre y el tipo de categoría no pueden quedar vacíos."]);
        exit();
    }

    try {
        // Obtener la categoría
        $stmtCheck = $db->prepare("SELECT id, user_id FROM categories WHERE id = ?");
        $stmtCheck->execute([$id]);
        $category = $stmtCheck->fetch();
        
        if (!$category) {
            http_response_code(404);
            echo json_encode(["error" => "Categoría no encontrada."]);
            exit();
        }
        
        // Si no pertenece al usuario y no es de sistema (user_id IS NOT NULL), bloquear
        if ($category['user_id'] !== null && intval($category['user_id']) !== $userId) {
            http_response_code(403);
            echo json_encode(["error" => "No tienes permisos para editar esta categoría."]);
            exit();
        }

        // Si es de sistema o del usuario, se actualiza estableciendo su user_id al actual
        $stmt = $db->prepare("UPDATE categories SET name = ?, icon = ?, color = ?, type = ?, user_id = ? WHERE id = ?");
        $stmt->execute([$name, $icon, $color, $type, $userId, $id]);

        echo json_encode(["message" => "Categoría actualizada con éxito."]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al actualizar la categoría: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'DELETE') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Falta especificar el ID de la categoría."]);
        exit();
    }

    try {
        // Obtener la categoría
        $stmtCheck = $db->prepare("SELECT id, user_id FROM categories WHERE id = ?");
        $stmtCheck->execute([$id]);
        $category = $stmtCheck->fetch();
        
        if (!$category) {
            http_response_code(404);
            echo json_encode(["error" => "Categoría no encontrada."]);
            exit();
        }
        
        // Si no pertenece al usuario y no es de sistema, bloquear
        if ($category['user_id'] !== null && intval($category['user_id']) !== $userId) {
            http_response_code(403);
            echo json_encode(["error" => "No tienes permisos para eliminar esta categoría."]);
            exit();
        }
        
        // Opción A: Bloquear eliminación si tiene transacciones asociadas
        $stmtTxCheck = $db->prepare("SELECT COUNT(*) as count FROM transactions WHERE category_id = ? AND user_id = ?");
        $stmtTxCheck->execute([$id, $userId]);
        $txCount = $stmtTxCheck->fetch();
        
        if (intval($txCount['count']) > 0) {
            http_response_code(400);
            echo json_encode(["error" => "No se puede eliminar la categoría porque tiene movimientos asociados. Por favor, reasigna los movimientos primero."]);
            exit();
        }

        // Eliminar la categoría
        $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(["message" => "Categoría eliminada con éxito."]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al eliminar la categoría: " . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Método HTTP no permitido."]);
