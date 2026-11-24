<?php

use PHPUnit\Framework\TestCase;

/**
 * Cubre backend/lib/loans_cash.php: el dinero de un préstamo otorgado sale de
 * UNA cuenta del espacio activo, vuelve con los cobros, y nunca cuenta como
 * gasto ni como ingreso (salvo los intereses).
 */
final class LoansCashTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, type TEXT, balance REAL DEFAULT 0, workspace TEXT DEFAULT \'personal\')');
        $this->db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, account_id INTEGER, category_id INTEGER, type TEXT, amount REAL, description TEXT, date TEXT, workspace TEXT DEFAULT \'personal\')');
        $this->db->exec("INSERT INTO accounts (id, user_id, name, type, balance, workspace) VALUES
            (1, 7, 'Nu Bank', 'banco', 200000, 'business'),
            (2, 7, 'Efectivo', 'efectivo', 500000, 'personal'),
            (3, 7, 'Visa', 'tarjeta_credito', -100000, 'business'),
            (4, 8, 'Ajena', 'banco', 900000, 'business')");
    }

    private function balance(int $id): float
    {
        return (float) $this->db->query("SELECT balance FROM accounts WHERE id = {$id}")->fetchColumn();
    }

    private function movements(): array
    {
        return $this->db->query('SELECT type, amount, description, workspace FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testDisbursementLowersTheChosenAccountAndLeavesATrace(): void
    {
        loans_disburse($this->db, 7, 'business', 1, 'Pipe', 80000, '2026-11-24');

        $this->assertSame(120000.0, $this->balance(1));
        $this->assertSame(500000.0, $this->balance(2)); // la personal no se toca
        $rows = $this->movements();
        $this->assertCount(1, $rows);
        $this->assertSame('egreso', $rows[0]['type']);
        $this->assertSame('Desembolso de préstamo: Pipe', $rows[0]['description']);
        $this->assertSame('business', $rows[0]['workspace']);
    }

    public function testCannotLendFromTheOtherWorkspaceAccount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        loans_disburse($this->db, 7, 'business', 2, 'Pipe', 1000, '2026-11-24');
    }

    public function testCannotLendFromAnotherUsersAccount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        loans_disburse($this->db, 7, 'business', 4, 'Pipe', 1000, '2026-11-24');
    }

    public function testCannotLendFromACreditCard(): void
    {
        $this->expectException(InvalidArgumentException::class);
        loans_disburse($this->db, 7, 'business', 3, 'Pipe', 1000, '2026-11-24');
    }

    public function testRejectsAnAccountWithoutEnoughBalanceAndChangesNothing(): void
    {
        try {
            loans_disburse($this->db, 7, 'business', 1, 'Pipe', 300000, '2026-11-24');
            $this->fail('Debió rechazar el préstamo por saldo insuficiente');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('saldo suficiente', $e->getMessage());
        }
        $this->assertSame(200000.0, $this->balance(1));
        $this->assertCount(0, $this->movements());
    }

    public function testCollectionReturnsMoneyAndSplitsCapitalFromInterest(): void
    {
        // Cuota de 110.000 con 10.000 de intereses, cobrada completa.
        loans_collect($this->db, 7, 'business', 1, 'Pipe', 110000, 110000, 10000, '2026-12-01');

        $this->assertSame(310000.0, $this->balance(1));
        $rows = $this->movements();
        $this->assertCount(2, $rows);
        $this->assertSame('Recaudo de capital: Pipe', $rows[0]['description']);
        $this->assertSame(100000.0, (float) $rows[0]['amount']);
        $this->assertSame('Intereses de préstamo: Pipe', $rows[1]['description']);
        $this->assertSame(10000.0, (float) $rows[1]['amount']);
    }

    public function testPartialCollectionSplitsProportionally(): void
    {
        loans_collect($this->db, 7, 'business', 1, 'Pipe', 55000, 110000, 10000, '2026-12-01');

        $this->assertSame(255000.0, $this->balance(1));
        $this->assertSame(5000.0, (float) $this->db->query("SELECT amount FROM transactions WHERE description LIKE 'Intereses%'")->fetchColumn());
    }

    public function testCollectionWithoutAccountDoesNothing(): void
    {
        loans_collect($this->db, 7, 'business', null, 'Pipe', 50000, 100000, 0, '2026-12-01');

        $this->assertSame(200000.0, $this->balance(1));
        $this->assertCount(0, $this->movements());
    }

    public function testCapitalMovementsAreExcludedFromExpensesAndIncomeButInterestIsNot(): void
    {
        loans_disburse($this->db, 7, 'business', 1, 'Pipe', 80000, '2026-11-24');
        loans_collect($this->db, 7, 'business', 1, 'Pipe', 110000, 110000, 10000, '2026-12-01');
        $this->db->exec("INSERT INTO transactions (user_id, account_id, type, amount, description, date, workspace) VALUES (7, 1, 'egreso', 25000, 'Hosting', '2026-11-25', 'business')");

        $excl = loans_cash_exclusion_sql('description');
        $sum = fn(string $type) => (float) $this->db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type = '{$type}' AND {$excl}")->fetchColumn();

        $this->assertSame(25000.0, $sum('egreso'));   // el desembolso no es gasto
        $this->assertSame(10000.0, $sum('ingreso'));  // solo los intereses son ingreso
    }
}
