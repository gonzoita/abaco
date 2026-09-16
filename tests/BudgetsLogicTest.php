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

    private const WS_COND = "(workspace IS NULL OR workspace = 'personal')";

    public function testRejectsNonPositiveAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        budgets_upsert($this->db, 7, 'personal', 1, 0, 8, 2026, null, self::WS_COND);
    }

    public function testCreatesANewBudgetWhenNoneExistsForThatPeriod(): void
    {
        $result = budgets_upsert($this->db, 7, 'personal', 1, 100000, 8, 2026, null, self::WS_COND);

        $this->assertTrue($result['created']);
        $count = (int) $this->db->query('SELECT COUNT(*) FROM budgets')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testUpdatesTheExistingBudgetInsteadOfDuplicatingIt(): void
    {
        budgets_upsert($this->db, 7, 'personal', 1, 100000, 8, 2026, null, self::WS_COND);
        $result = budgets_upsert($this->db, 7, 'personal', 1, 150000, 8, 2026, null, self::WS_COND);

        $this->assertFalse($result['created']);
        $count = (int) $this->db->query('SELECT COUNT(*) FROM budgets')->fetchColumn();
        $this->assertSame(1, $count, 'No debe crear un segundo registro para la misma categoría/mes/año.');

        $row = $this->db->query('SELECT amount FROM budgets')->fetch(PDO::FETCH_ASSOC);
        $this->assertEqualsWithDelta(150000, (float) $row['amount'], 0.001);
    }

    // ---- budgets_upsert: carry-over automático del mes anterior ----
    // Regresión del bug real: editar UNA categoría en un mes nuevo hacía
    // "desaparecer" (dejaba de heredarse) el resto del presupuesto.

    public function testFirstEditOfANewMonthCarriesOverTheRestOfLastMonthsCategories(): void
    {
        $this->db->exec("INSERT INTO categories (id, name, color, icon) VALUES (2, 'Transporte', '#3B82F6', 'car')");
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 1, 200000, 7, 2026, 'personal')");
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 2, 80000, 7, 2026, 'personal')");

        // El usuario solo edita la categoría 2 en agosto (mes sin ninguna fila todavía).
        $result = budgets_upsert($this->db, 7, 'personal', 2, 90000, 8, 2026, null, self::WS_COND);

        $this->assertSame(1, $result['carried_over_count'], 'Debe traer la categoría 1 (Alimentación) que no fue editada.');

        $august = $this->db->query('SELECT category_id, amount FROM budgets WHERE month = 8 AND year = 2026 ORDER BY category_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $august, 'Agosto debe terminar con las dos categorías, no solo la editada.');
        $this->assertEqualsWithDelta(200000, (float) $august[0]['amount'], 0.001, 'La categoría no editada debe traer el monto de julio.');
        $this->assertEqualsWithDelta(90000, (float) $august[1]['amount'], 0.001, 'La categoría editada debe quedar en el monto NUEVO, no el copiado.');
    }

    public function testDoesNotCarryOverAgainOnASecondEditOfTheSameMonth(): void
    {
        $this->db->exec("INSERT INTO categories (id, name, color, icon) VALUES (2, 'Transporte', '#3B82F6', 'car')");
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 1, 200000, 7, 2026, 'personal')");
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 2, 80000, 7, 2026, 'personal')");

        budgets_upsert($this->db, 7, 'personal', 2, 90000, 8, 2026, null, self::WS_COND);
        $second = budgets_upsert($this->db, 7, 'personal', 2, 95000, 8, 2026, null, self::WS_COND);

        $this->assertSame(0, $second['carried_over_count'], 'Agosto ya no está vacío, no debe volver a copiar.');
        $count = (int) $this->db->query('SELECT COUNT(*) FROM budgets WHERE month = 8 AND year = 2026')->fetchColumn();
        $this->assertSame(2, $count, 'No debe duplicar filas en la segunda edición.');
    }

    public function testReturnsZeroCarriedOverForABrandNewUserWithNoPreviousMonth(): void
    {
        $result = budgets_upsert($this->db, 99, 'personal', 1, 50000, 8, 2026, null, self::WS_COND);

        $this->assertSame(0, $result['carried_over_count']);
        $this->assertTrue($result['created']);
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

    // Regresión del bug real: editar UNA categoría en un mes nuevo hacía
    // "desaparecer" (dejaba de heredarse) el resto del presupuesto en la
    // vista, aunque los datos de julio seguían intactos en la BD.
    public function testFillsOnlyTheMissingCategoriesWhenTheMonthIsPartiallyConfigured(): void
    {
        $this->db->exec("INSERT INTO categories (id, name, color, icon) VALUES (2, 'Transporte', '#3B82F6', 'car')");
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 1, 200000, 7, 2026, 'personal')");
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 2, 80000, 7, 2026, 'personal')");
        // Agosto solo tiene la categoría 2 editada -- antes esto hacía
        // que la 1 dejara de mostrarse por completo.
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 2, 95000, 8, 2026, 'personal')");

        $budgets = budgets_get_for_period($this->db, 7, "workspace = 'personal'", 8, 2026, true);

        $this->assertCount(2, $budgets, 'Debe seguir mostrando ambas categorías, no solo la editada.');
        $byCategory = [];
        foreach ($budgets as $b) {
            $byCategory[intval($b['category_id'])] = $b;
        }
        $this->assertEqualsWithDelta(200000, $byCategory[1]['amount'], 0.001, 'La categoría no editada debe traer el valor heredado de julio.');
        $this->assertEqualsWithDelta(95000, $byCategory[2]['amount'], 0.001, 'La categoría editada debe conservar SU propio valor de agosto, no el de julio.');
    }

    public function testNeverInheritsWhenAnExplicitMonthWasRequestedEvenIfPartial(): void
    {
        $this->db->exec("INSERT INTO categories (id, name, color, icon) VALUES (2, 'Transporte', '#3B82F6', 'car')");
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 1, 200000, 7, 2026, 'personal')");
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 2, 80000, 7, 2026, 'personal')");
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 2, 95000, 8, 2026, 'personal')");

        $budgets = budgets_get_for_period($this->db, 7, "workspace = 'personal'", 8, 2026, false);

        $this->assertCount(1, $budgets, 'Con mes/año explícitos en la URL, nunca debe completar con el período anterior.');
    }

    // ---- budgets_merge_gap_categories ----

    public function testMergeReturnsAllPriorRowsWhenCurrentIsEmpty(): void
    {
        $prior = [['category_id' => 1, 'amount' => 200000], ['category_id' => 2, 'amount' => 80000]];
        $merged = budgets_merge_gap_categories([], $prior);
        $this->assertCount(2, $merged);
    }

    public function testMergeKeepsCurrentValueAndAddsOnlyMissingCategories(): void
    {
        $current = [['category_id' => 2, 'amount' => 95000]];
        $prior = [['category_id' => 1, 'amount' => 200000], ['category_id' => 2, 'amount' => 80000]];

        $merged = budgets_merge_gap_categories($current, $prior);

        $this->assertCount(2, $merged);
        $byCategory = [];
        foreach ($merged as $m) {
            $byCategory[$m['category_id']] = $m['amount'];
        }
        $this->assertSame(200000, $byCategory[1], 'Categoría faltante: debe tomarse del período anterior.');
        $this->assertSame(95000, $byCategory[2], 'Categoría ya presente: debe conservar el valor actual, no el anterior.');
    }

    public function testMergeHandlesTheGlobalNullCategoryBudget(): void
    {
        $current = [];
        $prior = [['category_id' => null, 'amount' => 1000000]];

        $merged = budgets_merge_gap_categories($current, $prior);

        $this->assertCount(1, $merged);
        $this->assertNull($merged[0]['category_id']);
    }
}
