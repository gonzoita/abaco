<?php
// C:\laragon\www\control-finanzas\backend\api\accounts.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';

$userData = authenticate();
$userId = $userData['user_id'];
$db = Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

// Obtener datos del cuerpo de la petición
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'GET') {
    if ($id) {
        // Obtener una cuenta específica
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $account = $stmt->fetch();
        
        if (!$account) {
            http_response_code(404);
            echo json_encode(["error" => "Cuenta no encontrada."]);
            exit();
        }
        echo json_encode($account);
    } else {
        // Obtener todas las cuentas del usuario
        $stmt = $db->prepare("SELECT * FROM accounts WHERE user_id = ? ORDER BY id DESC");
        $stmt->execute([$userId]);
        $accounts = $stmt->fetchAll();
        echo json_encode($accounts);
    }
    exit();
}

if ($method === 'POST') {
    $name = trim($input['name'] ?? '');
    $type = trim($input['type'] ?? '');
    $balance = floatval($input['balance'] ?? 0.00);
    $currency = trim($input['currency'] ?? 'COP');
    $creditLimit = isset($input['credit_limit']) ? floatval($input['credit_limit']) : 0.00;
    $billingDay = isset($input['billing_day']) ? intval($input['billing_day']) : null;
    $dueDay = isset($input['due_day']) ? intval($input['due_day']) : null;
    
    // Nuevos campos extendidos
    $bankName = isset($input['bank_name']) ? trim($input['bank_name']) : null;
    $accountNumber = isset($input['account_number']) ? trim($input['account_number']) : null;
    $taxExempt = isset($input['tax_exempt']) ? intval($input['tax_exempt']) : 0;
    
    // Campos para Préstamo por Pagar
    $interestRate = isset($input['interest_rate']) ? trim($input['interest_rate']) : null;
    $termMonths = isset($input['term_months']) && $input['term_months'] !== '' ? intval($input['term_months']) : null;
    $paymentConditions = isset($input['payment_conditions']) ? trim($input['payment_conditions']) : null;

    if (empty($name) || empty($type)) {
        http_response_code(400);
        echo json_encode(["error" => "El nombre de la cuenta y el tipo son obligatorios."]);
        exit();
    }

    if (!in_array($type, ['efectivo', 'banco', 'tarjeta_credito', 'otro', 'prestamo_pagar'])) {
        http_response_code(400);
        echo json_encode(["error" => "Tipo de cuenta no válido."]);
        exit();
    }

    try {
        $stmt = $db->prepare("INSERT INTO accounts (user_id, name, type, balance, currency, credit_limit, billing_day, due_day, bank_name, account_number, tax_exempt, interest_rate, term_months, payment_conditions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $name, $type, $balance, $currency, $creditLimit, $billingDay, $dueDay, $bankName, $accountNumber, $taxExempt, $interestRate, $termMonths, $paymentConditions]);
        
        $newId = $db->lastInsertId();
        echo json_encode([
            "message" => "Cuenta creada exitosamente.",
            "account" => [
                "id" => $newId,
                "name" => $name,
                "type" => $type,
                "balance" => $balance,
                "currency" => $currency,
                "credit_limit" => $creditLimit,
                "billing_day" => $billingDay,
                "due_day" => $dueDay,
                "bank_name" => $bankName,
                "account_number" => $accountNumber,
                "tax_exempt" => $taxExempt,
                "interest_rate" => $interestRate,
                "term_months" => $termMonths,
                "payment_conditions" => $paymentConditions
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al crear la cuenta: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'PUT') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Falta especificar el ID de la cuenta."]);
        exit();
    }

    // Verificar propiedad
    $stmt = $db->prepare("SELECT id FROM accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(["error" => "Cuenta no encontrada o no tienes permisos."]);
        exit();
    }

    $name = trim($input['name'] ?? '');
    $type = trim($input['type'] ?? '');
    $balance = isset($input['balance']) ? floatval($input['balance']) : null;
    $currency = trim($input['currency'] ?? '');
    $creditLimit = isset($input['credit_limit']) ? floatval($input['credit_limit']) : null;
    $billingDay = isset($input['billing_day']) ? intval($input['billing_day']) : null;
    $dueDay = isset($input['due_day']) ? intval($input['due_day']) : null;
    
    // Nuevos campos extendidos
    $bankName = isset($input['bank_name']) ? trim($input['bank_name']) : null;
    $accountNumber = isset($input['account_number']) ? trim($input['account_number']) : null;
    $taxExempt = isset($input['tax_exempt']) ? intval($input['tax_exempt']) : 0;

    // Campos para Préstamo por Pagar
    $interestRate = isset($input['interest_rate']) ? trim($input['interest_rate']) : null;
    $termMonths = isset($input['term_months']) && $input['term_months'] !== '' ? intval($input['term_months']) : null;
    $paymentConditions = isset($input['payment_conditions']) ? trim($input['payment_conditions']) : null;

    if (empty($name) || empty($type)) {
        http_response_code(400);
        echo json_encode(["error" => "El nombre y el tipo de cuenta no pueden quedar vacíos."]);
        exit();
    }

    try {
        $stmt = $db->prepare("UPDATE accounts SET name = ?, type = ?, balance = COALESCE(?, balance), currency = COALESCE(?, currency), credit_limit = COALESCE(?, credit_limit), billing_day = ?, due_day = ?, bank_name = ?, account_number = ?, tax_exempt = ?, interest_rate = ?, term_months = ?, payment_conditions = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$name, $type, $balance, $currency, $creditLimit, $billingDay, $dueDay, $bankName, $accountNumber, $taxExempt, $interestRate, $termMonths, $paymentConditions, $id, $userId]);
        
        echo json_encode(["message" => "Cuenta actualizada exitosamente."]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al actualizar la cuenta: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'DELETE') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Falta especificar el ID de la cuenta."]);
        exit();
    }

    try {
        $stmt = $db->prepare("DELETE FROM accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(["error" => "Cuenta no encontrada o no tienes permisos para eliminarla."]);
            exit();
        }
        
        echo json_encode(["message" => "Cuenta eliminada exitosamente."]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => "Error al eliminar la cuenta: " . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Método HTTP no permitido."]);
