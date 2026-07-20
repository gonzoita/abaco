<?php
// C:\laragon\www\control-finanzas\backend\api\loans.php
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

// Helper para calcular fechas de pago
function getNextPaymentDatePHP($startDateStr, $index, $frequency) {
    $date = new DateTime($startDateStr);
    switch ($frequency) {
        case 'diario':
            $date->modify("+$index day");
            break;
        case 'semanal':
            $days = $index * 7;
            $date->modify("+$days day");
            break;
        case 'quincenal':
            $days = $index * 15;
            $date->modify("+$days day");
            break;
        case 'mensual':
            $date->modify("+$index month");
            break;
        default:
            $date->modify("+$index day");
    }
    return $date->format('Y-m-d');
}

// Lógica de Amortización en PHP (Coincide 100% con JS)
function calculateAmortizationPHP($principal, $interestRate, $rateType, $installmentsCount, $frequency, $method, $startDate) {
    $rateFraction = $interestRate / 100;
    $r = 0;
    
    // Tasa periódica
    if ($rateType === 'periodo') {
        $r = $rateFraction;
    } elseif ($rateType === 'mensual') {
        switch ($frequency) {
            case 'diario': $r = $rateFraction / 30; break;
            case 'semanal': $r = $rateFraction / 4; break;
            case 'quincenal': $r = $rateFraction / 2; break;
            case 'mensual': $r = $rateFraction; break;
            default: $r = $rateFraction;
        }
    } else { // anual
        switch ($frequency) {
            case 'diario': $r = $rateFraction / 360; break;
            case 'semanal': $r = $rateFraction / 52; break;
            case 'quincenal': $r = $rateFraction / 24; break;
            case 'mensual': $r = $rateFraction / 12; break;
            default: $r = $rateFraction / 12;
        }
    }
    
    $n = $installmentsCount;
    $schedule = [];
    $balance = $principal;
    
    if ($method === 'frances') {
        $installmentAmount = 0;
        if ($r == 0) {
            $installmentAmount = $principal / $n;
        } else {
            $installmentAmount = $principal * ($r * pow(1 + $r, $n)) / (pow(1 + $r, $n) - 1);
        }
        
        for ($i = 1; $i <= $n; $i++) {
            $interest = $balance * $r;
            $principalPaid = $installmentAmount - $interest;
            $balance = max(0, $balance - $principalPaid);
            
            $schedule[] = [
                'number' => $i,
                'date' => getNextPaymentDatePHP($startDate, $i, $frequency),
                'installment' => round($installmentAmount),
                'interest' => round($interest),
                'principalPaid' => round($principalPaid),
                'remainingBalance' => round($balance)
            ];
        }
    } 
    elseif ($method === 'aleman') {
        $principalPaid = $principal / $n;
        for ($i = 1; $i <= $n; $i++) {
            $interest = $balance * $r;
            $installmentAmount = $principalPaid + $interest;
            $balance = max(0, $balance - $principalPaid);
            
            $schedule[] = [
                'number' => $i,
                'date' => getNextPaymentDatePHP($startDate, $i, $frequency),
                'installment' => round($installmentAmount),
                'interest' => round($interest),
                'principalPaid' => round($principalPaid),
                'remainingBalance' => round($balance)
            ];
        }
    } 
    elseif ($method === 'americano') {
        $interest = $principal * $r;
        for ($i = 1; $i <= $n; $i++) {
            $isLast = ($i === $n);
            $principalPaid = $isLast ? $principal : 0;
            $installmentAmount = $interest + $principalPaid;
            $balance = $isLast ? 0 : $principal;
            
            $schedule[] = [
                'number' => $i,
                'date' => getNextPaymentDatePHP($startDate, $i, $frequency),
                'installment' => round($installmentAmount),
                'interest' => round($interest),
                'principalPaid' => round($principalPaid),
                'remainingBalance' => round($balance)
            ];
        }
    } 
    elseif ($method === 'simple') {
        $principalPaid = $principal / $n;
        $interest = $principal * $r;
        $installmentAmount = $principalPaid + $interest;
        for ($i = 1; $i <= $n; $i++) {
            $balance = max(0, $balance - $principalPaid);
            
            $schedule[] = [
                'number' => $i,
                'date' => getNextPaymentDatePHP($startDate, $i, $frequency),
                'installment' => round($installmentAmount),
                'interest' => round($interest),
                'principalPaid' => round($principalPaid),
                'remainingBalance' => round($balance)
            ];
        }
    }
    
    return $schedule;
}

$workspace = get_active_workspace();

// Ejecutar solicitudes
if ($method === 'GET') {
    
    // 1. Obtener Clientes
    if ($action === 'get_clients') {
        try {
            $stmt = $db->prepare("SELECT * FROM loan_clients WHERE user_id = ? ORDER BY name ASC");
            $stmt->execute([$userId]);
            echo json_encode($stmt->fetchAll());
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al obtener clientes: " . $e->getMessage()]);
        }
        exit();
    }
    
    // 2. Obtener Préstamos (Listado General)
    if ($action === 'get_loans') {
        try {
            $stmt = $db->prepare("
                SELECT l.*, c.name as client_name, c.document as client_document 
                FROM loans l 
                JOIN loan_clients c ON l.client_id = c.id 
                WHERE l.user_id = ? AND (l.workspace IS NULL OR l.workspace = ?)
                ORDER BY l.created_at DESC
            ");
            $stmt->execute([$userId, $workspace]);
            $loans = $stmt->fetchAll();
            
            // Para cada préstamo, adjuntar el resumen del plan (cuotas pagadas, saldo pendiente)
            foreach ($loans as &$loan) {
                $stmtInst = $db->prepare("
                    SELECT 
                        SUM(installment) as total_debt,
                        SUM(paid_amount) as total_paid
                    FROM loan_installments 
                    WHERE loan_id = ?
                ");
                $stmtInst->execute([$loan['id']]);
                $summary = $stmtInst->fetch();
                $loan['total_debt'] = floatval($summary['total_debt']);
                $loan['total_paid'] = floatval($summary['total_paid']);
                $loan['remaining_balance'] = max(0, $loan['total_debt'] - $loan['total_paid']);
            }
            
            echo json_encode($loans);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al obtener préstamos: " . $e->getMessage()]);
        }
        exit();
    }
    
    // 3. Obtener un Préstamo en Específico (Con cuotas e historial)
    if ($action === 'get_loan') {
        $loanId = isset($_GET['loan_id']) ? intval($_GET['loan_id']) : 0;
        try {
            $stmt = $db->prepare("
                SELECT l.*, c.name as client_name, c.document as client_document, c.phone as client_phone, c.email as client_email, c.address as client_address
                FROM loans l 
                JOIN loan_clients c ON l.client_id = c.id 
                WHERE l.id = ? AND l.user_id = ?
            ");
            $stmt->execute([$loanId, $userId]);
            $loan = $stmt->fetch();
            
            if (!$loan) {
                http_response_code(404);
                echo json_encode(["error" => "Préstamo no encontrado"]);
                exit();
            }
            
            // Obtener plan de amortización
            $stmtInst = $db->prepare("SELECT * FROM loan_installments WHERE loan_id = ? ORDER BY number ASC");
            $stmtInst->execute([$loanId]);
            $loan['schedule'] = $stmtInst->fetchAll();
            
            // Obtener historial de recaudos
            $stmtTxs = $db->prepare("SELECT * FROM loan_transactions WHERE loan_id = ? ORDER BY date DESC, id DESC");
            $stmtTxs->execute([$loanId]);
            $loan['transactions'] = $stmtTxs->fetchAll();
            
            echo json_encode($loan);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al obtener detalles: " . $e->getMessage()]);
        }
        exit();
    }
    
    // 4. Historial General de recaudos
    if ($action === 'get_transactions') {
        try {
            $stmt = $db->prepare("
                SELECT t.*, c.name as client_name 
                FROM loan_transactions t
                JOIN loans l ON t.loan_id = l.id
                JOIN loan_clients c ON l.client_id = c.id
                WHERE l.user_id = ?
                ORDER BY t.date DESC, t.id DESC
            ");
            $stmt->execute([$userId]);
            echo json_encode($stmt->fetchAll());
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al obtener transacciones: " . $e->getMessage()]);
        }
        exit();
    }
}

if ($method === 'POST') {
    
    // 1. Crear Cliente
    if ($action === 'create_client') {
        $name = trim($input['name'] ?? '');
        $document = trim($input['document'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $email = trim($input['email'] ?? null);
        $address = trim($input['address'] ?? null);
        
        if (empty($name) || empty($document) || empty($phone)) {
            http_response_code(400);
            echo json_encode(["error" => "Nombre, documento y teléfono son obligatorios."]);
            exit();
        }
        
        try {
            $stmt = $db->prepare("INSERT INTO loan_clients (user_id, name, document, phone, email, address) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $name, $document, $phone, $email, $address]);
            
            $clientId = $db->lastInsertId();
            echo json_encode(["success" => true, "id" => $clientId]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al guardar cliente: " . $e->getMessage()]);
        }
        exit();
    }
    
    // 2. Crear Préstamo
    if ($action === 'create_loan') {
        $clientId = intval($input['client_id'] ?? 0);
        $principal = floatval($input['principal'] ?? 0);
        $interestRate = floatval($input['interest_rate'] ?? 0);
        $rateType = trim($input['rate_type'] ?? 'mensual');
        $installmentsCount = intval($input['installments_count'] ?? 0);
        $frequency = trim($input['frequency'] ?? 'semanal');
        $methodLoan = trim($input['method'] ?? 'frances');
        $startDate = trim($input['start_date'] ?? '');
        
        if ($clientId <= 0 || $principal <= 5000 || $interestRate < 0 || $installmentsCount <= 0 || empty($startDate)) {
            http_response_code(400);
            echo json_encode(["error" => "Datos de préstamo inválidos o incompletos."]);
            exit();
        }
        
        try {
            $db->beginTransaction();
            
            // Insertar préstamo
            $stmt = $db->prepare("INSERT INTO loans (user_id, client_id, principal, interest_rate, rate_type, installments_count, frequency, method, start_date, workspace) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $clientId, $principal, $interestRate, $rateType, $installmentsCount, $frequency, $methodLoan, $startDate, $workspace]);
            $loanId = $db->lastInsertId();
            
            // Calcular cuotas
            $schedule = calculateAmortizationPHP($principal, $interestRate, $rateType, $installmentsCount, $frequency, $methodLoan, $startDate);
            
            // Guardar cuotas
            $stmtInst = $db->prepare("INSERT INTO loan_installments (loan_id, number, date, installment, interest, principal_paid, remaining_balance) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($schedule as $inst) {
                $stmtInst->execute([
                    $loanId,
                    $inst['number'],
                    $inst['date'],
                    $inst['installment'],
                    $inst['interest'],
                    $inst['principalPaid'],
                    $inst['remainingBalance']
                ]);
            }
            
            $db->commit();
            echo json_encode(["success" => true, "id" => $loanId]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(["error" => "Error al guardar préstamo: " . $e->getMessage()]);
        }
        exit();
    }
    
    // 3. Registrar Recaudo de Cuota
    if ($action === 'record_payment') {
        $loanId = intval($input['loan_id'] ?? 0);
        $installmentNumber = intval($input['installment_number'] ?? 0);
        $amount = floatval($input['amount'] ?? 0);
        $date = trim($input['date'] ?? date('Y-m-d'));
        $note = trim($input['note'] ?? '');
        
        if ($loanId <= 0 || $installmentNumber <= 0 || $amount <= 0) {
            http_response_code(400);
            echo json_encode(["error" => "Monto, préstamo y número de cuota son obligatorios y deben ser mayores a 0."]);
            exit();
        }
        
        try {
            $db->beginTransaction();
            
            // Obtener cuota actual
            $stmtInst = $db->prepare("SELECT * FROM loan_installments WHERE loan_id = ? AND number = ?");
            $stmtInst->execute([$loanId, $installmentNumber]);
            $inst = $stmtInst->fetch();
            
            if (!$inst) {
                http_response_code(404);
                echo json_encode(["error" => "Cuota no encontrada."]);
                exit();
            }
            
            // Calcular nuevo total pagado en la cuota
            $newPaid = $inst['paid_amount'] + $amount;
            $status = ($newPaid >= $inst['installment']) ? 'pagado' : 'parcial';
            
            // Actualizar cuota
            $stmtUpdate = $db->prepare("UPDATE loan_installments SET paid_amount = ?, status = ? WHERE id = ?");
            $stmtUpdate->execute([$newPaid, $status, $inst['id']]);
            
            // Registrar transacción
            $stmtTx = $db->prepare("INSERT INTO loan_transactions (loan_id, installment_number, amount, date, note) VALUES (?, ?, ?, ?, ?)");
            $stmtTx->execute([$loanId, $installmentNumber, $amount, $date, $note]);
            $txId = $db->lastInsertId();
            
            // Verificar si el préstamo se liquidó por completo
            $stmtCheck = $db->prepare("SELECT COUNT(*) as unpaid FROM loan_installments WHERE loan_id = ? AND status != 'pagado'");
            $stmtCheck->execute([$loanId]);
            $unpaidCount = $stmtCheck->fetch();
            
            if (intval($unpaidCount['unpaid']) === 0) {
                $stmtUpdateLoan = $db->prepare("UPDATE loans SET status = 'finalizado' WHERE id = ?");
                $stmtUpdateLoan->execute([$loanId]);
            } else {
                // Actualizar estado 'vencido' vs 'activo'
                $today = date('Y-m-d');
                $stmtMora = $db->prepare("SELECT COUNT(*) as count FROM loan_installments WHERE loan_id = ? AND status != 'pagado' AND date < ?");
                $stmtMora->execute([$loanId, $today]);
                $moraCount = $stmtMora->fetch();
                
                $newStatus = (intval($moraCount['count']) > 0) ? 'vencido' : 'activo';
                $stmtUpdateLoan = $db->prepare("UPDATE loans SET status = ? WHERE id = ?");
                $stmtUpdateLoan->execute([$newStatus, $loanId]);
            }
            
            $db->commit();
            echo json_encode(["success" => true, "transaction_id" => $txId]);
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            echo json_encode(["error" => "Error al registrar recaudo: " . $e->getMessage()]);
        }
        exit();
    }
}

if ($method === 'PUT') {
    
    // 1. Modificar Cliente
    if ($action === 'update_client') {
        $clientId = intval($_GET['client_id'] ?? 0);
        $name = trim($input['name'] ?? '');
        $document = trim($input['document'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $email = trim($input['email'] ?? null);
        $address = trim($input['address'] ?? null);
        
        if ($clientId <= 0 || empty($name) || empty($document) || empty($phone)) {
            http_response_code(400);
            echo json_encode(["error" => "ID, Nombre, documento y teléfono son obligatorios."]);
            exit();
        }
        
        try {
            $stmt = $db->prepare("UPDATE loan_clients SET name = ?, document = ?, phone = ?, email = ?, address = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$name, $document, $phone, $email, $address, $clientId, $userId]);
            
            echo json_encode(["success" => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al actualizar cliente: " . $e->getMessage()]);
        }
        exit();
    }
}

if ($method === 'DELETE') {
    
    // 1. Eliminar Cliente
    if ($action === 'delete_client') {
        $clientId = intval($_GET['client_id'] ?? 0);
        if ($clientId <= 0) {
            http_response_code(400);
            echo json_encode(["error" => "ID de cliente inválido."]);
            exit();
        }
        
        try {
            // Verificar si tiene préstamos vigentes
            $stmtCheck = $db->prepare("SELECT COUNT(*) as count FROM loans WHERE client_id = ? AND status != 'finalizado'");
            $stmtCheck->execute([$clientId]);
            $res = $stmtCheck->fetch();
            
            if (intval($res['count']) > 0) {
                http_response_code(400);
                echo json_encode(["error" => "No se puede eliminar el cliente porque tiene préstamos activos."]);
                exit();
            }
            
            $stmt = $db->prepare("DELETE FROM loan_clients WHERE id = ? AND user_id = ?");
            $stmt->execute([$clientId, $userId]);
            
            echo json_encode(["success" => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al eliminar cliente: " . $e->getMessage()]);
        }
        exit();
    }
    
    // 2. Eliminar Préstamo
    if ($action === 'delete_loan') {
        $loanId = intval($_GET['loan_id'] ?? 0);
        if ($loanId <= 0) {
            http_response_code(400);
            echo json_encode(["error" => "ID de préstamo inválido."]);
            exit();
        }
        
        try {
            $stmt = $db->prepare("DELETE FROM loans WHERE id = ? AND user_id = ?");
            $stmt->execute([$loanId, $userId]);
            echo json_encode(["success" => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Error al eliminar préstamo: " . $e->getMessage()]);
        }
        exit();
    }
}

http_response_code(404);
echo json_encode(["error" => "Acción o método no válido."]);
exit();
