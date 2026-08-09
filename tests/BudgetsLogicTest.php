<?php

use PHPUnit\Framework\TestCase;

/**
 * Cubre budgets_upsert(), budgets_copy_from_last_month() y
 * budgets_calculate_amount_from_items() -- la lógica detrás del bug
 * original donde los presupuestos "se borraban" cada mes.
 */
final class BudgetsLogicTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec('CREATE TABLE budgets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, category_id INTEGER, amount REAL, month INTEGER, year INTEGER, workspace TEXT, items_json TEXT)');
        $this->db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, color TEXT, icon TEXT)');
        $this->db->exec("INSERT INTO categories (id, name, color, icon) VALUES (1, 'Alimentación', '#EF4444', 'coffee')");
    }

    // ---- budgets_calculate_amount_from_items ----

    public function testUsesFallbackAmountWhenThereAreNoItems(): void
    {
        $this->assertSame(50000.0, budgets_calculate_amount_from_items([], 50000.0));
    }

    public function testSumsItemAmountsWhenItemsAreProvided(): void
    {
        $items = [['amount' => 20000], ['amount' => 15000], ['amount' => 5000]];
        $this->assertSame(40000.0, budgets_calculate_amount_from_items($items, 999));
    }

    public function testFallsBackToOriginalAmountWhenItemsSumToZero(): void
    {
        $items = [['amount' => 0], ['amount' => 0]];
        $this->assertSame(50000.0, budgets_calculate_amount_from_items($items, 50000.0));
    }

    // ---- budgets_upsert ----

    public function testRejectsNonPositiveAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        budgets_upsert($this->db, 7, 'personal', 1, 0, 8, 2026, null);
    }

    public function testCreatesANewBudgetWhenNoneExistsForThatPeriod(): void
    {
        $result = budgets_upsert($this->db, 7, 'personal', 1, 100000, 8, 2026, null);

        $this->assertTrue($result['created']);
        $count = (int) $this->db->query('SELECT COUNT(*) FROM budgets')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testUpdatesTheExistingBudgetInsteadOfDuplicatingIt(): void
    {
        budgets_upsert($this->db, 7, 'personal', 1, 100000, 8, 2026, null);
        $result = budgets_upsert($this->db, 7, 'personal', 1, 150000, 8, 2026, null);

        $this->assertFalse($result['created']);
        $count = (int) $this->db->query('SELECT COUNT(*) FROM budgets')->fetchColumn();
        $this->assertSame(1, $count, 'No debe crear un segundo registro para la misma categoría/mes/año.');

        $row = $this->db->query('SELECT amount FROM budgets')->fetch(PDO::FETCH_ASSOC);
        $this->assertEqualsWithDelta(150000, (float) $row['amount'], 0.001);
    }

    // ---- budgets_copy_from_last_month ----

    public function testThrowsWhenThereIsNoPreviousPeriodToCopyFrom(): void
    {
        $this->expectException(RuntimeException::class);
        budgets_copy_from_last_month($this->db, 7, 'personal', "workspace = 'personal'", 8, 2026);
    }

    public function testCopiesBudgetsFromTheLatestPreviousPeriod(): void
    {
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 1, 200000, 7, 2026, 'personal')");

        $result = budgets_copy_from_last_month($this->db, 7, 'personal', "(workspace IS NULL OR workspace = 'personal')", 8, 2026);

        $this->assertSame(1, $result['copied_count']);
        $this->assertSame(7, $result['from_month']);

        $copied = $this->db->query('SELECT * FROM budgets WHERE month = 8 AND year = 2026')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($copied);
        $this->assertEqualsWithDelta(200000, (float) $copied['amount'], 0.001);
    }

    public function testDoesNotDuplicateABudgetThatAlreadyExistsInTheTargetMonth(): void
    {
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 1, 200000, 7, 2026, 'personal')");
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 1, 999999, 8, 2026, 'personal')");

        $result = budgets_copy_from_last_month($this->db, 7, 'personal', "(workspace IS NULL OR workspace = 'personal')", 8, 2026);

        $this->assertSame(0, $result['copied_count'], 'Ya existía un presupuesto de esa categoría en agosto: no debe duplicarlo ni pisarlo.');
        $count = (int) $this->db->query('SELECT COUNT(*) FROM budgets WHERE month = 8 AND year = 2026')->fetchColumn();
        $this->assertSame(1, $count);
    }

    // ---- budgets_get_for_period ----

    public function testReturnsEmptyForAMonthWithNoBudgetsWhenInheritIsDisabled(): void
    {
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 1, 200000, 7, 2026, 'personal')");

        // Simula que el usuario pidió explícitamente agosto (?month=8): no
        // debe "heredar" nada de julio.
        $budgets = budgets_get_for_period($this->db, 7, "workspace = 'personal'", 8, 2026, false);

        $this->assertCount(0, $budgets);
    }

    public function testInheritsThePreviousMonthWhenNoneWasRequestedExplicitly(): void
    {
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 1, 200000, 7, 2026, 'personal')");

        $budgets = budgets_get_for_period($this->db, 7, "workspace = 'personal'", 8, 2026, true);

        $this->assertCount(1, $budgets, 'Sin presupuestos en agosto y sin mes explícito en la URL, debe mostrar los de julio como referencia.');
    }
}
