<?php

use PHPUnit\Framework\TestCase;

/**
 * savings_add_funds() es exactamente la función que causó el bug original
 * reportado por el usuario: "cuando hago un ahorro el sistema debería
 * descontar el dinero de alguna cuenta". Estas pruebas fijan ese
 * comportamiento para que nunca se vuelva a romper en silencio.
 */
final class SavingsLogicTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, balance REAL)');
        $this->db->exec('CREATE TABLE savings_goals (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, target_amount REAL, current_amount REAL DEFAULT 0, target_date TEXT, account_id INTEGER, workspace TEXT)');
        $this->db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, account_id INTEGER, type TEXT, amount REAL, description TEXT, date TEXT, workspace TEXT)');

        $this->db->exec("INSERT INTO accounts (id, user_id, name, balance) VALUES (1, 7, 'Nequi', 500000)");
        $this->db->exec("INSERT INTO savings_goals (id, user_id, name, target_amount, current_amount) VALUES (1, 7, 'Viaje', 1000000, 0)");
    }

    public function testAddFundsDeductsFromSourceAccountAndCreditsTheGoal(): void
    {
        savings_add_funds($this->db, 7, 1, 50000, 1, 'personal');

        $account = $this->db->query('SELECT balance FROM accounts WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $goal = $this->db->query('SELECT current_amount FROM savings_goals WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

        $this->assertEqualsWithDelta(450000, (float) $account['balance'], 0.001, 'La cuenta origen debe quedar con 500.000 - 50.000.');
        $this->assertEqualsWithDelta(50000, (float) $goal['current_amount'], 0.001, 'La meta debe reflejar el abono.');
    }

    public function testAddFundsRecordsAnExpenseTransaction(): void
    {
        savings_add_funds($this->db, 7, 1, 50000, 1, 'personal');

        $tx = $this->db->query("SELECT * FROM transactions WHERE user_id = 7")->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($tx, 'Debe quedar un registro de la transacción de egreso.');
        $this->assertSame('egreso', $tx['type']);
        $this->assertEqualsWithDelta(50000, (float) $tx['amount'], 0.001);
        $this->assertSame(1, (int) $tx['account_id']);
        $this->assertStringContainsString('Viaje', $tx['description']);
    }

    public function testRejectsZeroOrNegativeAmountWithoutTouchingTheDatabase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        try {
            savings_add_funds($this->db, 7, 1, 0, 1, 'personal');
        } finally {
            $account = $this->db->query('SELECT balance FROM accounts WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            $this->assertEqualsWithDelta(500000, (float) $account['balance'], 0.001, 'Un monto inválido no debe tocar ningún saldo.');
        }
    }

    public function testRejectsMissingSourceAccount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cuenta de origen');
        savings_add_funds($this->db, 7, 1, 50000, null, 'personal');
    }

    public function testRejectsSourceAccountThatDoesNotBelongToTheUser(): void
    {
        $this->db->exec("INSERT INTO accounts (id, user_id, name, balance) VALUES (2, 999, 'Cuenta de otra persona', 999999)");

        $this->expectException(InvalidArgumentException::class);
        savings_add_funds($this->db, 7, 1, 50000, 2, 'personal');
    }

    public function testRejectsGoalThatDoesNotBelongToTheUser(): void
    {
        $this->db->exec("INSERT INTO savings_goals (id, user_id, name, target_amount) VALUES (2, 999, 'Meta ajena', 100000)");

        $this->expectException(InvalidArgumentException::class);
        savings_add_funds($this->db, 7, 2, 50000, 1, 'personal');
    }

    public function testCreateGoalRejectsEmptyNameOrNonPositiveTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        savings_create_goal($this->db, 7, 'personal', '', 100000, 0, null, null);
    }

    public function testCreateGoalInsertsAndReturnsTheNewGoal(): void
    {
        $goal = savings_create_goal($this->db, 7, 'personal', 'Emergencias', 300000, 0, null, null);

        $this->assertSame('Emergencias', $goal['name']);
        $count = (int) $this->db->query("SELECT COUNT(*) FROM savings_goals WHERE name = 'Emergencias'")->fetchColumn();
        $this->assertSame(1, $count);
    }
}
