<?php
// backend/lib/loans_cash.php
//
// El dinero de los préstamos que el usuario OTORGA (cartera por cobrar) tiene
// que moverse en sus cuentas: al desembolsar sale de una cuenta, y cada cuota
// cobrada entra de nuevo. Pero prestar no es gastar ni cobrar capital es
// ganar: esos movimientos se marcan con un prefijo en la descripción (igual
// que los abonos a metas de ahorro) y quedan fuera de gastos, ingresos y
// presupuestos. Solo los intereses cobrados cuentan como ingreso.
// Funciones puras sobre PDO; el llamador abre y cierra la transacción.

require_once __DIR__ . '/workspace_data.php';

const LOANS_DISBURSEMENT_PREFIX = 'Desembolso de préstamo';
const LOANS_CAPITAL_PREFIX = 'Recaudo de capital';
const LOANS_INTEREST_PREFIX = 'Intereses de préstamo';

/** Tipos de cuenta de los que no se puede prestar (son deudas del usuario). */
const LOANS_DEBT_ACCOUNT_TYPES = ['tarjeta_credito', 'prestamo_pagar'];

/**
 * Fragmento SQL que deja fuera los movimientos de capital de préstamos.
 * $column es la columna de descripción (con alias si hace falta).
 */
function loans_cash_exclusion_sql($column = 'description') {
    return "COALESCE({$column}, '') NOT LIKE '" . LOANS_DISBURSEMENT_PREFIX . "%' "
         . "AND COALESCE({$column}, '') NOT LIKE '" . LOANS_CAPITAL_PREFIX . "%'";
}

/**
 * Cuenta del usuario, del espacio activo y de la que se puede prestar.
 * Devuelve la fila o lanza InvalidArgumentException con un mensaje claro.
 */
function loans_pick_account(PDO $db, $userId, $workspace, $accountId) {
    $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND user_id = ? AND " . ws_sql('workspace') . " = ?");
    $stmt->execute([$accountId, $userId, ws_norm($workspace)]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) {
        throw new InvalidArgumentException("Esa cuenta no existe en este espacio.");
    }
    if (in_array($account['type'], LOANS_DEBT_ACCOUNT_TYPES, true)) {
        throw new InvalidArgumentException("No se puede prestar desde una tarjeta de crédito ni desde una deuda: elige una cuenta con dinero.");
    }
    return $account;
}

/** Saca el dinero del préstamo de la cuenta y deja el movimiento. */
function loans_disburse(PDO $db, $userId, $workspace, $accountId, $clientName, $amount, $date) {
    $account = loans_pick_account($db, $userId, $workspace, $accountId);
    if (floatval($account['balance']) < $amount) {
        throw new InvalidArgumentException("La cuenta \"{$account['name']}\" no tiene saldo suficiente para este préstamo.");
    }
    $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ? AND user_id = ?")
       ->execute([$amount, $accountId, $userId]);
    loans_insert_movement($db, $userId, $workspace, $accountId, 'egreso', $amount, LOANS_DISBURSEMENT_PREFIX . ': ' . $clientName, $date);
}

/**
 * Entra a la cuenta lo cobrado de una cuota. La parte de intereses (según la
 * proporción de la cuota) cuenta como ingreso; la de capital es solo devolución.
 * Si la cuenta ya no existe, no hace nada: el recaudo igual queda registrado.
 */
function loans_collect(PDO $db, $userId, $workspace, $accountId, $clientName, $paid, $installment, $interest, $date) {
    if (!$accountId || $paid <= 0) return;
    $stmt = $db->prepare("SELECT id FROM accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$accountId, $userId]);
    if (!$stmt->fetch()) return;

    $interestShare = ($installment > 0) ? round($paid * min(1, max(0, $interest / $installment)), 2) : 0.0;
    $capitalShare = round($paid - $interestShare, 2);

    $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ? AND user_id = ?")
       ->execute([$paid, $accountId, $userId]);
    if ($capitalShare > 0) {
        loans_insert_movement($db, $userId, $workspace, $accountId, 'ingreso', $capitalShare, LOANS_CAPITAL_PREFIX . ': ' . $clientName, $date);
    }
    if ($interestShare > 0) {
        loans_insert_movement($db, $userId, $workspace, $accountId, 'ingreso', $interestShare, LOANS_INTEREST_PREFIX . ': ' . $clientName, $date);
    }
}

function loans_insert_movement(PDO $db, $userId, $workspace, $accountId, $type, $amount, $description, $date) {
    $db->prepare("INSERT INTO transactions (user_id, account_id, type, amount, description, date, workspace) VALUES (?, ?, ?, ?, ?, ?, ?)")
       ->execute([$userId, $accountId, $type, $amount, $description, $date, ws_norm($workspace)]);
}
