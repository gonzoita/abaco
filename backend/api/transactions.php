<?php
// C:\laragon\www\control-finanzas\backend\api\transactions.php
require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_helper.php';

function get_db_columns($db, $tableName) {
    static $colsCache = [];
    if (!isset($colsCache[$tableName])) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM {$tableName}");
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $colsCache[$tableName] = array_map('strtolower', $cols);
        } catch (Exception $e) {
            $colsCache[$tableName] = [];
        }
    }
    return $colsCache[$tableName];
}

function insert_tax_transaction($db, $userId, $accountId, $bankCatId, $taxAmount, $taxDesc, $date, $workspace) {
    $dataToInsert = [
        'user_id' => $userId,
        'account_id' => $accountId,
        'category_id' => $bankCatId,
        'type' => 'egreso',
        'amount' => $taxAmount,
        'description' => $taxDesc,
        'date' => $date,
        'installments_total' => 1,
        'installments_current' => 1,
        'workspace' => $workspace
    ];

    $dbCols = get_db_columns($db, 'transactions');
    $fields = [];
    $values = [];
    $placeholders = [];

    foreach ($dataToInsert as $colName => $colValue) {
        if (empty($dbCols) || in_array(strtolower($colName), $dbCols)) {
            $fields[] = $colName;
            $values[] = $colValue;
            $placeholders[] = '?';
        }
    }

    $sqlInsert = "INSERT INTO transactions (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    $stmtInsert = $db->prepare($sqlInsert);
    $stmtInsert->execute($values);
}

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
    $tWsCond = get_workspace_sql_clause('t.workspace');
    if ($id) {
        $stmt = $db->prepare("SELECT t.*, COALESCE(a.name, 'Cuenta') as account_name, c.name as category_name, c.color as category_color 
                              FROM transactions t 
                              LEFT JOIN accounts a ON t.account_id = a.id 
                              LEFT JOIN categories c ON t.category_id = c.id 
                              WHERE t.id = ? AND t.user_id = ? AND {$tWsCond}");
        $stmt->execute([$id, $userId]);
        $transaction = $stmt->fetch();
        if (!$transaction) {
            http_response_code(404);
            echo json_encode(["error" => "Transacción no encontrada."]);
            exit();
        }
        echo json_encode($transaction);
    } else {
        // Filtros opcionales
        $accountId = isset($_GET['account_id']) ? intval($_GET['account_id']) : null;
        $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
        $type = isset($_GET['type']) ? trim($_GET['type']) : null;
        $startDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : null;
        $endDate = isset($_GET['end_date']) ? trim($_GET['end_date']) : null;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;

        $sql = "SELECT t.*, COALESCE(a.name, 'Cuenta') as account_name, c.name as category_name, c.color as category_color, c.icon as category_icon
                FROM transactions t 
                LEFT JOIN accounts a ON t.account_id = a.id 
                LEFT JOIN categories c ON t.category_id = c.id 
                WHERE t.user_id = :user_id AND {$tWsCond}";
        
        $params = [':user_id' => $userId];

        if ($accountId) {
            $sql .= " AND t.account_id = :account_id";
            $params[':account_id'] = $accountId;
        }
        if ($categoryId) {
            $sql .= " AND t.category_id = :category_id";
            $params[':category_id'] = $categoryId;
        }
        if ($type) {
            $sql .= " AND t.type = :type";
            $params[':type'] = $type;
        }
        if ($startDate) {
            $sql .= " AND t.date >= :start_date";
            $params[':start_date'] = $startDate;
        }
        if ($endDate) {
            $sql .= " AND t.date <= :end_date";
            $params[':end_date'] = $endDate;
        }

        $sql .= " ORDER BY t.date DESC, t.id DESC LIMIT :limit";
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        if ($accountId) $stmt->bindValue(':account_id', $accountId, PDO::PARAM_INT);
        if ($categoryId) $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        if ($type) $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        if ($startDate) $stmt->bindValue(':start_date', $startDate, PDO::PARAM_STR);
        if ($endDate) $stmt->bindValue(':end_date', $endDate, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

        $stmt->execute();
        $transactions = $stmt->fetchAll();
        echo json_encode($transactions);
    }
    exit();
}

if ($method === 'POST') {
    $accountId = intval($input['account_id'] ?? 0);
    $rawCatId = isset($input['category_id']) ? intval($input['category_id']) : 0;
    $categoryId = ($rawCatId > 0) ? $rawCatId : null;

    $type = trim($input['type'] ?? ''); // ingreso, egreso, transferencia
    $amount = floatval($input['amount'] ?? 0.00);
    $description = trim($input['description'] ?? '');
    $date = trim($input['date'] ?? date('Y-m-d'));
    $receiptUrl = trim($input['receipt_url'] ?? '');
    $tags = trim($input['tags'] ?? '');
    if (empty($tags) && !empty($description)) {
        if (preg_match_all('/#[\wñáéíóúÁÉÍÓÚ]+/u', $description, $matches)) {
            $tags = implode(', ', $matches[0]);
        }
    }
    
    // Específico para transferencias
    $rawTransDest = isset($input['transfer_to_account_id']) ? intval($input['transfer_to_account_id']) : 0;
    $transferToAccountId = ($rawTransDest > 0) ? $rawTransDest : null;
    
    // Específico para compras diferidas en cuotas (tarjetas)
    $installmentsTotal = intval($input['installments_total'] ?? 1);

    if ($accountId <= 0 || empty($type) || $amount <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "Cuenta, tipo y monto mayor a cero son obligatorios."]);
        exit();
    }

    try {
        $db->beginTransaction();

        // Validar que la cuenta origen pertenezca al usuario
        $stmtAcc = $db->prepare("SELECT balance, type, tax_exempt FROM accounts WHERE id = ? AND user_id = ?");
        $stmtAcc->execute([$accountId, $userId]);
        $accountSource = $stmtAcc->fetch();
        if (!$accountSource) {
            throw new Exception("Cuenta de origen no válida o inexistente.");
        }

        // Si es transferencia, validar la cuenta destino
        if ($type === 'transferencia') {
            if (!$transferToAccountId) {
                throw new Exception("Debe especificar la cuenta destino de la transferencia.");
            }
            $stmtDest = $db->prepare("SELECT balance FROM accounts WHERE id = ? AND user_id = ?");
            $stmtDest->execute([$transferToAccountId, $userId]);
            if (!$stmtDest->fetch()) {
                throw new Exception("Cuenta destino no válida o inexistente.");
            }
        }

        // Insertar dinámicamente según las columnas reales existentes en la base de datos
        $dataToInsert = [
            'user_id' => $userId,
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'tags' => $tags ?: null,
            'date' => $date,
            'receipt_url' => $receiptUrl ?: null,
            'installments_total' => $installmentsTotal,
            'installments_current' => 1,
            'transfer_to_account_id' => $transferToAccountId,
            'workspace' => $workspace
        ];

        $dbCols = get_db_columns($db, 'transactions');
        $fields = [];
        $values = [];
        $placeholders = [];

        foreach ($dataToInsert as $colName => $colValue) {
            if (empty($dbCols) || in_array(strtolower($colName), $dbCols)) {
                $fields[] = $colName;
                $values[] = $colValue;
                $placeholders[] = '?';
            }
        }

        $sqlInsert = "INSERT INTO transactions (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmtInsert = $db->prepare($sqlInsert);
        $stmtInsert->execute($values);
        $newTransactionId = $db->lastInsertId();

        // Actualizar los saldos de las cuentas involucradas
        if ($type === 'ingreso') {
            $stmtUpdateBalance = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmtUpdateBalance->execute([$amount, $accountId]);
        } elseif ($type === 'egreso') {
            $stmtUpdateBalance = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmtUpdateBalance->execute([$amount, $accountId]);
        } elseif ($type === 'transferencia') {
            // Restar de origen
            $stmtUpdateBalanceSrc = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmtUpdateBalanceSrc->execute([$amount, $accountId]);
            // Sumar a destino
            $stmtUpdateBalanceDst = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmtUpdateBalanceDst->execute([$amount, $transferToAccountId]);
        }

        // --- CÁLCULO E INSERCIÓN DEL IMPUESTO 4x1000 (GMF) ---
        // Si no está exenta (tax_exempt = 0) y es un egreso o transferencia
        if (isset($accountSource['tax_exempt']) && $accountSource['tax_exempt'] == 0 && ($type === 'egreso' || $type === 'transferencia')) {
            $taxAmount = $amount * 0.004; // 0.4%
            if ($taxAmount > 0) {
                // Buscar la categoría "Gastos Bancarios"
                $stmtGetCat = $db->prepare("SELECT id FROM categories WHERE name = 'Gastos Bancarios' AND (user_id IS NULL OR user_id = ?) LIMIT 1");
                $stmtGetCat->execute([$userId]);
                $bankCat = $stmtGetCat->fetch();
                $bankCatId = $bankCat ? $bankCat['id'] : null;

                // Insertar transacción de impuesto de forma dinámica y segura
                $taxDesc = "GMF 4x1000 - " . (empty($description) ? "Impuesto Financiero" : $description);
                insert_tax_transaction($db, $userId, $accountId, $bankCatId, $taxAmount, $taxDesc, $date, $workspace);

                // Descontar de la cuenta
                $stmtUpdateTaxBalance = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
                $stmtUpdateTaxBalance->execute([$taxAmount, $accountId]);
            }
        }

        $db->commit();

        echo json_encode([
            "message" => "Transacción agregada y saldos actualizados.",
            "transaction_id" => $newTransactionId
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(["error" => "Error al agregar la transacción: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'PUT') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Falta especificar el ID de la transacción."]);
        exit();
    }

    try {
        $db->beginTransaction();

        // 1. Obtener la transacción original
        $stmtOld = $db->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
        $stmtOld->execute([$id, $userId]);
        $oldTx = $stmtOld->fetch();
        if (!$oldTx) {
            throw new Exception("Transacción original no encontrada o sin permisos.");
        }

        // Obtener exención de impuestos de la cuenta origen original
        $stmtOldAcc = $db->prepare("SELECT tax_exempt FROM accounts WHERE id = ?");
        $stmtOldAcc->execute([$oldTx['account_id']]);
        $oldAcc = $stmtOldAcc->fetch();
        $oldTaxExempt = $oldAcc ? $oldAcc['tax_exempt'] : 0;

        // 2. REVERSIÓN: Deshacer impacto de saldo de la transacción original
        $oldAmount = floatval($oldTx['amount']);
        $oldAccountId = intval($oldTx['account_id']);
        $oldType = $oldTx['type'];
        $oldTransferToAccountId = $oldTx['transfer_to_account_id'];

        if ($oldType === 'ingreso') {
            $stmtRevert = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmtRevert->execute([$oldAmount, $oldAccountId]);
        } elseif ($oldType === 'egreso') {
            $stmtRevert = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmtRevert->execute([$oldAmount, $oldAccountId]);
        } elseif ($oldType === 'transferencia') {
            // Revertir origen (sumar)
            $stmtRevertSrc = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmtRevertSrc->execute([$oldAmount, $oldAccountId]);
            // Revertir destino (restar)
            $stmtRevertDst = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmtRevertDst->execute([$oldAmount, $oldTransferToAccountId]);
        }

        // Eliminar cobro del 4x1000 original si existió
        if ($oldTaxExempt == 0 && ($oldType === 'egreso' || $oldType === 'transferencia')) {
            $oldTaxAmount = $oldAmount * 0.004;
            if ($oldTaxAmount > 0) {
                $oldTaxDesc = "GMF 4x1000 - " . (empty($oldTx['description']) ? "Impuesto Financiero" : $oldTx['description']);
                // Eliminar la transacción de impuesto
                $stmtDelTax = $db->prepare("DELETE FROM transactions WHERE user_id = ? AND account_id = ? AND type = 'egreso' AND description = ? AND date = ?");
                $stmtDelTax->execute([$userId, $oldAccountId, $oldTaxDesc, $oldTx['date']]);
                
                // Devolver el valor del impuesto a la cuenta
                $stmtRefundTax = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
                $stmtRefundTax->execute([$oldTaxAmount, $oldAccountId]);
            }
        }

        // 3. ACTUALIZACIÓN: Leer nuevos valores y actualizar la transacción
        $accountId = intval($input['account_id'] ?? 0);
        $rawCatId = isset($input['category_id']) ? intval($input['category_id']) : 0;
        $categoryId = ($rawCatId > 0) ? $rawCatId : null;

        $type = trim($input['type'] ?? '');
        $amount = floatval($input['amount'] ?? 0.00);
        $description = trim($input['description'] ?? '');
        $date = trim($input['date'] ?? '');
        $installmentsTotal = isset($input['installments_total']) ? intval($input['installments_total']) : 1;
        $rawTransDest = isset($input['transfer_to_account_id']) ? intval($input['transfer_to_account_id']) : 0;
        $transferToAccountId = ($rawTransDest > 0) ? $rawTransDest : null;
        $receiptUrl = isset($input['receipt_url']) ? trim($input['receipt_url']) : null;

        if ($accountId <= 0 || empty($type) || $amount <= 0 || empty($date)) {
            throw new Exception("Cuenta, tipo, fecha y monto mayor a cero son obligatorios.");
        }

        // Cargar datos de la nueva cuenta
        $stmtNewAcc = $db->prepare("SELECT tax_exempt FROM accounts WHERE id = ? AND user_id = ?");
        $stmtNewAcc->execute([$accountId, $userId]);
        $newAcc = $stmtNewAcc->fetch();
        if (!$newAcc) {
            throw new Exception("Cuenta de origen nueva no válida o inexistente.");
        }

        // Validar destino de transferencia si aplica
        if ($type === 'transferencia') {
            if (!$transferToAccountId) {
                throw new Exception("Debe especificar la cuenta destino de la transferencia.");
            }
            $stmtDest = $db->prepare("SELECT balance FROM accounts WHERE id = ? AND user_id = ?");
            $stmtDest->execute([$transferToAccountId, $userId]);
            if (!$stmtDest->fetch()) {
                throw new Exception("Cuenta destino no válida o inexistente.");
            }
        }

        // Actualizar registro original dinámicamente según las columnas reales
        $dataToUpdate = [
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
            'date' => $date,
            'receipt_url' => $receiptUrl ?: null,
            'installments_total' => $installmentsTotal,
            'transfer_to_account_id' => $transferToAccountId
        ];

        $dbCols = get_db_columns($db, 'transactions');
        $setClauses = [];
        $updateValues = [];

        foreach ($dataToUpdate as $colName => $colValue) {
            if (empty($dbCols) || in_array(strtolower($colName), $dbCols)) {
                $setClauses[] = "{$colName} = ?";
                $updateValues[] = $colValue;
            }
        }

        $updateValues[] = $id;
        $updateValues[] = $userId;

        $sqlUpdate = "UPDATE transactions SET " . implode(', ', $setClauses) . " WHERE id = ? AND user_id = ?";
        $stmtUpdate = $db->prepare($sqlUpdate);
        $stmtUpdate->execute($updateValues);

        // 4. APLICACIÓN: Aplicar nuevos impactos de saldo
        if ($type === 'ingreso') {
            $stmtUpdateBalance = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmtUpdateBalance->execute([$amount, $accountId]);
        } elseif ($type === 'egreso') {
            $stmtUpdateBalance = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmtUpdateBalance->execute([$amount, $accountId]);
        } elseif ($type === 'transferencia') {
            // Restar de origen
            $stmtUpdateBalanceSrc = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmtUpdateBalanceSrc->execute([$amount, $accountId]);
            // Sumar a destino
            $stmtUpdateBalanceDst = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmtUpdateBalanceDst->execute([$amount, $transferToAccountId]);
        }

        // Calcular e insertar nuevo impuesto 4x1000 si aplica
        if ($newAcc['tax_exempt'] == 0 && ($type === 'egreso' || $type === 'transferencia')) {
            $taxAmount = $amount * 0.004;
            if ($taxAmount > 0) {
                $stmtGetCat = $db->prepare("SELECT id FROM categories WHERE name = 'Gastos Bancarios' AND (user_id IS NULL OR user_id = ?) LIMIT 1");
                $stmtGetCat->execute([$userId]);
                $bankCat = $stmtGetCat->fetch();
                $bankCatId = $bankCat ? $bankCat['id'] : null;

                $taxDesc = "GMF 4x1000 - " . (empty($description) ? "Impuesto Financiero" : $description);
                insert_tax_transaction($db, $userId, $accountId, $bankCatId, $taxAmount, $taxDesc, $date, $workspace);

                $stmtUpdateTaxBalance = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
                $stmtUpdateTaxBalance->execute([$taxAmount, $accountId]);
            }
        }

        $db->commit();
        echo json_encode(["message" => "Transacción actualizada y saldos recalculados con éxito."]);

    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(["error" => "Error al actualizar la transacción: " . $e->getMessage()]);
    }
    exit();
}

if ($method === 'DELETE') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Falta especificar el ID de la transacción."]);
        exit();
    }

    try {
        $db->beginTransaction();

        // Obtener datos de la transacción antes de eliminarla para revertir saldos
        $stmtTx = $db->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
        $stmtTx->execute([$id, $userId]);
        $tx = $stmtTx->fetch();

        if (!$tx) {
            throw new Exception("Transacción no encontrada o sin permisos.");
        }

        $amount = $tx['amount'];
        $accountId = $tx['account_id'];
        $type = $tx['type'];
        $transferToAccountId = $tx['transfer_to_account_id'];

        // Revertir saldos de cuentas
        if ($type === 'ingreso') {
            $stmtRevert = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmtRevert->execute([$amount, $accountId]);
        } elseif ($type === 'egreso') {
            $stmtRevert = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmtRevert->execute([$amount, $accountId]);
        } elseif ($type === 'transferencia') {
            // Revertir origen (sumarle lo restado)
            $stmtRevertSrc = $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?");
            $stmtRevertSrc->execute([$amount, $accountId]);
            // Revertir destino (restarle lo sumado)
            $stmtRevertDst = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?");
            $stmtRevertDst->execute([$amount, $transferToAccountId]);
        }

        // Eliminar la transacción
        $stmtDel = $db->prepare("DELETE FROM transactions WHERE id = ?");
        $stmtDel->execute([$id]);

        $db->commit();
        echo json_encode(["message" => "Transacción eliminada y saldos revertidos con éxito."]);

    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(["error" => "Error al eliminar la transacción: " . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Método HTTP no permitido."]);
