<?php
// backend/lib/savings_logic.php
// Lógica de metas de ahorro extraída de savings.php para que sea testeable
// de forma aislada (sin authenticate()/cors.php). Cada función recibe $db
// (PDO) y datos ya validados/parseados por el endpoint HTTP; lanza
// InvalidArgumentException para errores de validación del usuario (-> 400) y
// deja que cualquier otra excepción de PDO se propague (-> 500).

function savings_get_goals($db, $userId, $workspaceCondition) {
    $stmt = $db->prepare("
        SELECT g.*, a.name as account_name, a.bank_name
        FROM savings_goals g
        LEFT JOIN accounts a ON g.account_id = a.id
        WHERE g.user_id = ? AND {$workspaceCondition}
        ORDER BY g.id DESC
    ");
    $stmt->execute([$userId]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($goals as &$goal) {
        $goal['target_amount'] = floatval($goal['target_amount']);
        $goal['current_amount'] = floatval($goal['current_amount']);
        $goal['account_id'] = $goal['account_id'] ? intval($goal['account_id']) : null;
    }

    return $goals;
}

function savings_create_goal($db, $userId, $workspace, $name, $targetAmount, $currentAmount, $targetDate, $accountId) {
    if (empty($name) || $targetAmount <= 0) {
        throw new InvalidArgumentException("El nombre de la meta y el monto objetivo mayor a cero son obligatorios.");
    }

    $stmt = $db->prepare("INSERT INTO savings_goals (user_id, name, target_amount, current_amount, target_date, account_id, workspace) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $name, $targetAmount, $currentAmount, $targetDate, $accountId, $workspace]);
    $newId = $db->lastInsertId();

    return [
        "id" => $newId,
        "name" => $name,
        "target_amount" => $targetAmount,
        "current_amount" => $currentAmount,
        "target_date" => $targetDate,
        "account_id" => $accountId
    ];
}

/**
 * El corazón del bug real que ya corregimos una vez: abonar a una meta de
 * ahorro DEBE descontar el monto de la cuenta de origen y dejar registrada
 * la transacción, todo dentro de una sola transacción SQL (todo o nada).
 */
function savings_add_funds($db, $userId, $goalId, $amount, $sourceAccountId, $workspace) {
    if ($amount <= 0) {
        throw new InvalidArgumentException("El monto a abonar debe ser mayor a cero.");
    }
    if (!$sourceAccountId) {
        throw new InvalidArgumentException("Debe seleccionar la cuenta de origen de los fondos.");
    }

    $stmtAcc = $db->prepare("SELECT name, balance FROM accounts WHERE id = ? AND user_id = ?");
    $stmtAcc->execute([$sourceAccountId, $userId]);
    $acc = $stmtAcc->fetch(PDO::FETCH_ASSOC);
    if (!$acc) {
        throw new InvalidArgumentException("La cuenta de origen no es válida o no existe.");
    }

    $stmtGoal = $db->prepare("SELECT name FROM savings_goals WHERE id = ? AND user_id = ?");
    $stmtGoal->execute([$goalId, $userId]);
    $goal = $stmtGoal->fetch(PDO::FETCH_ASSOC);
    if (!$goal) {
        throw new InvalidArgumentException("La meta de ahorro no fue encontrada.");
    }

    $db->beginTransaction();
    try {
        // 1. Sumar a la meta de ahorro
        $stmt = $db->prepare("UPDATE savings_goals SET current_amount = current_amount + ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$amount, $goalId, $userId]);

        // 2. Restar del saldo de la cuenta origen
        $stmtSub = $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ? AND user_id = ?");
        $stmtSub->execute([$amount, $sourceAccountId, $userId]);

        // 3. Registrar la transacción de egreso
        $desc = "Abono a meta de ahorro: " . $goal['name'];
        $date = date('Y-m-d');
        $stmtTx = $db->prepare("INSERT INTO transactions (user_id, account_id, type, amount, description, date, workspace) VALUES (?, ?, 'egreso', ?, ?, ?, ?)");
        $stmtTx->execute([$userId, $sourceAccountId, $amount, $desc, $date, $workspace]);

        $db->commit();
    } catch (Exception $e) {
        // El original no hacía rollback explícito en este punto (dejaba la
        // transacción abierta si algo fallaba a mitad de camino). Se agrega
        // aquí como mejora de seguridad: si algo falla, nada queda a medias.
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function savings_update_goal($db, $userId, $goalId, $name, $targetAmount, $currentAmount, $targetDate, $accountId) {
    $stmt = $db->prepare("UPDATE savings_goals SET name = ?, target_amount = ?, current_amount = ?, target_date = ?, account_id = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$name, $targetAmount, $currentAmount, $targetDate, $accountId, $goalId, $userId]);
}

function savings_delete_goal($db, $userId, $goalId) {
    $stmt = $db->prepare("DELETE FROM savings_goals WHERE id = ? AND user_id = ?");
    $stmt->execute([$goalId, $userId]);
    return $stmt->rowCount();
}
