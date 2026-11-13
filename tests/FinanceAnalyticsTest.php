<?php

use PHPUnit\Framework\TestCase;

/**
 * Cubre backend/lib/finance_analytics.php: las cifras que se le entregan a
 * la IA, los puntos por ahorro y la decisión de si el usuario puede invertir.
 */
final class FinanceAnalyticsTest extends TestCase
{
    private PDO $db;

    private const TODAY = '2026-11-12';

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, currency TEXT)');
        $this->db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, color TEXT, icon TEXT)');
        $this->db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, type TEXT, balance REAL, currency TEXT, credit_limit REAL DEFAULT 0, interest_rate TEXT, term_months INTEGER, workspace TEXT)');
        $this->db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, account_id INTEGER, category_id INTEGER, type TEXT, amount REAL, description TEXT, date TEXT, workspace TEXT)');
        $this->db->exec('CREATE TABLE budgets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, category_id INTEGER, amount REAL, month INTEGER, year INTEGER, workspace TEXT, items_json TEXT)');
        $this->db->exec('CREATE TABLE savings_goals (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, target_amount REAL, current_amount REAL, target_date TEXT, workspace TEXT)');
        $this->db->exec('CREATE TABLE loan_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT)');
        $this->db->exec('CREATE TABLE loans (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, client_id INTEGER, status TEXT, workspace TEXT)');
        $this->db->exec('CREATE TABLE loan_installments (id INTEGER PRIMARY KEY AUTOINCREMENT, loan_id INTEGER, date TEXT, installment REAL, paid_amount REAL DEFAULT 0, status TEXT)');

        $this->db->exec("INSERT INTO users (id, currency) VALUES (7, 'COP')");
        $this->db->exec("INSERT INTO categories (id, name) VALUES (1, 'Alimentación'), (2, 'Transporte')");
    }

    private function tx(string $type, float $amount, string $date, ?int $categoryId = null, string $description = 'x', string $workspace = 'personal'): void
    {
        $stmt = $this->db->prepare('INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, workspace) VALUES (7, 1, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$categoryId, $type, $amount, $description, $date, $workspace]);
    }

    // ---- fa_shift_month ----

    public function testShiftsMonthsAcrossYearBoundaries(): void
    {
        $this->assertSame([2025, 12], fa_shift_month(2026, 1, -1));
        $this->assertSame([2027, 1], fa_shift_month(2026, 12, 1));
        $this->assertSame([2025, 11], fa_shift_month(2026, 11, -12));
    }

    // ---- fa_month_activity ----

    public function testGoalContributionsAreSavingsNotExpenses(): void
    {
        $this->tx('ingreso', 3000000, '2026-10-01');
        $this->tx('egreso', 600000, '2026-10-05', 1);
        $this->tx('egreso', 400000, '2026-10-15', null, 'Abono a meta de ahorro: Viaje');

        $a = fa_month_activity($this->db, 7, 'personal', 2026, 10);

        $this->assertEqualsWithDelta(600000, $a['expense'], 0.001, 'Abonar a una meta no es gastar.');
        $this->assertEqualsWithDelta(400000, $a['goal_contributions'], 0.001);
        $this->assertEqualsWithDelta(0.8, fa_savings_rate($a['income'], $a['expense']), 0.0001);
    }

    public function testNeverMixesPersonalAndBusinessMoney(): void
    {
        $this->tx('ingreso', 3000000, '2026-10-01');
        $this->tx('ingreso', 9000000, '2026-10-01', null, 'Ventas', 'business');
        $this->tx('egreso', 5000000, '2026-10-02', 1, 'Proveedor', 'business');

        $personal = fa_month_activity($this->db, 7, 'personal', 2026, 10);
        $business = fa_month_activity($this->db, 7, 'business', 2026, 10);

        $this->assertEqualsWithDelta(3000000, $personal['income'], 0.001);
        $this->assertEqualsWithDelta(0, $personal['expense'], 0.001);
        $this->assertEqualsWithDelta(9000000, $business['income'], 0.001);
        $this->assertEqualsWithDelta(5000000, $business['expense'], 0.001);
    }

    public function testIgnoresTransfersAndOtherMonths(): void
    {
        $this->tx('transferencia', 500000, '2026-10-10');
        $this->tx('egreso', 100000, '2026-09-30', 1);
        $this->tx('egreso', 200000, '2026-10-31', 1);
        $this->tx('egreso', 300000, '2026-11-01', 1);

        $a = fa_month_activity($this->db, 7, 'personal', 2026, 10);

        $this->assertEqualsWithDelta(200000, $a['expense'], 0.001);
    }

    // ---- fa_project_month_expense ----

    public function testProjectionDoesNotMultiplyTheRentPaidOnDayOneByThirty(): void
    {
        // Día 1: se pagó el arriendo (500.000). Un mes normal son 3.000.000.
        $projected = fa_project_month_expense(500000, 1, 30, 3000000);

        $this->assertEqualsWithDelta(3400000, $projected, 0.001, 'La proyección lineal habría dado 15.000.000.');
    }

    public function testProjectionFollowsTheRealPaceWhenSpendingIsLow(): void
    {
        $this->assertEqualsWithDelta(1250000, fa_project_month_expense(500000, 12, 30, 3000000), 0.001);
    }

    public function testProjectionWithoutHistoryIsLinear(): void
    {
        $this->assertEqualsWithDelta(900000, fa_project_month_expense(300000, 10, 30, 0), 0.001);
    }

    public function testProjectionIsNeverBelowWhatWasAlreadySpent(): void
    {
        $this->assertEqualsWithDelta(4000000, fa_project_month_expense(4000000, 30, 30, 3000000), 0.001);
    }

    // ---- fa_budget_execution ----

    public function testBudgetExecutionComparesEachCategoryAgainstItsLimit(): void
    {
        $activity = ['expense' => 900000, 'by_category' => [1 => ['name' => 'Alimentación', 'amount' => 700000], 2 => ['name' => 'Transporte', 'amount' => 50000], 0 => ['name' => 'Sin categoría', 'amount' => 150000]]];
        $rows = [
            ['category_id' => 1, 'amount' => 600000, 'category_name' => 'Alimentación'],
            ['category_id' => 2, 'amount' => 110000, 'category_name' => 'Transporte'],
        ];

        $b = fa_budget_execution($rows, $activity);

        $this->assertTrue($b['has_budget']);
        $this->assertEqualsWithDelta(710000, $b['limit'], 0.001);
        $this->assertEqualsWithDelta(750000, $b['spent'], 0.001, 'Solo cuenta el gasto de las categorías presupuestadas.');
        $this->assertSame(1, $b['categories_over']);
    }

    public function testBudgetExecutionWithoutBudgetRows(): void
    {
        $b = fa_budget_execution([], ['expense' => 100, 'by_category' => []]);
        $this->assertFalse($b['has_budget']);
    }

    // ---- fa_points_for_month ----

    public function testPointsRewardSavingsRateBudgetGoalsAndStreak(): void
    {
        $points = fa_points_for_month([
            'income' => 4000000,
            'expense' => 3200000, // ahorró 20%
            'goal_contributions' => 100000,
            'budget' => ['has_budget' => true, 'limit' => 3500000, 'spent' => 3200000, 'categories_over' => 0, 'categories' => [['x']]],
            'streak' => 3,
        ]);

        // 200 (20%) + 100 (presupuesto) + 50 (ninguna categoría excedida) + 50 (metas) + 75 (racha de 3)
        $this->assertSame(475, $points['total']);
        $this->assertCount(5, $points['breakdown']);
    }

    public function testNoPointsForAMonthThatSpentMoreThanItEarned(): void
    {
        $points = fa_points_for_month([
            'income' => 2000000,
            'expense' => 2500000,
            'goal_contributions' => 0,
            'budget' => ['has_budget' => true, 'limit' => 2000000, 'spent' => 2500000, 'categories_over' => 2, 'categories' => [['x']]],
            'streak' => 0,
        ]);

        $this->assertSame(0, $points['total']);
    }

    public function testSavingsPointsAreCappedSoOneFreakMonthDoesNotDecideTheLevel(): void
    {
        $points = fa_points_for_month(['income' => 1000000, 'expense' => 0, 'goal_contributions' => 0, 'streak' => 1]);
        $this->assertSame(500, $points['total']);
    }

    public function testNoPointsWithoutIncome(): void
    {
        $points = fa_points_for_month(['income' => 0, 'expense' => 0, 'goal_contributions' => 0, 'streak' => 0]);
        $this->assertSame(0, $points['total']);
    }

    // ---- fa_level_for_points ----

    public function testLevels(): void
    {
        $this->assertSame('Bronce', fa_level_for_points(0)['name']);
        $this->assertSame('Plata', fa_level_for_points(500)['name']);
        $this->assertSame('Oro', fa_level_for_points(2999)['name']);
        $this->assertSame(1, fa_level_for_points(2999)['points_to_next']);

        $top = fa_level_for_points(9000);
        $this->assertSame('Diamante', $top['name']);
        $this->assertNull($top['next_name']);
        $this->assertSame(0, $top['points_to_next']);
    }

    // ---- fa_points_summary ----

    public function testCurrentMonthIsShownButDoesNotCountUntilItCloses(): void
    {
        // Septiembre y octubre: ahorró 10% cada mes. Noviembre (en curso): 50%.
        foreach (['2026-09', '2026-10'] as $ym) {
            $this->tx('ingreso', 2000000, "{$ym}-01");
            $this->tx('egreso', 1800000, "{$ym}-10", 1);
        }
        $this->tx('ingreso', 2000000, '2026-11-01');
        $this->tx('egreso', 1000000, '2026-11-05', 1);

        $summary = fa_points_summary($this->db, 7, 'personal', self::TODAY);

        // sep: 100 | oct: 100 + racha de 2 (50) = 150
        $this->assertSame(250, $summary['total']);
        $this->assertSame(2, $summary['streak']);
        $this->assertCount(2, $summary['months']);
        $this->assertSame('octubre 2026', $summary['months'][0]['label'], 'El mes más reciente va primero.');
        $this->assertTrue($summary['current_month']['in_progress']);
        $this->assertSame(500 + 75, $summary['current_month']['points'], 'El mes en curso muestra lo que lleva (50% + racha de 3), sin sumarlo.');
    }

    public function testAMonthWithoutSavingsBreaksTheStreak(): void
    {
        $this->tx('ingreso', 2000000, '2026-08-01');
        $this->tx('egreso', 1800000, '2026-08-10', 1);
        $this->tx('ingreso', 2000000, '2026-09-01');
        $this->tx('egreso', 2400000, '2026-09-10', 1); // se pasó
        $this->tx('ingreso', 2000000, '2026-10-01');
        $this->tx('egreso', 1800000, '2026-10-10', 1);

        $summary = fa_points_summary($this->db, 7, 'personal', self::TODAY);

        $this->assertSame(1, $summary['streak']);
        $this->assertSame(200, $summary['total'], 'Agosto 100 + septiembre 0 + octubre 100 (sin bono de racha).');
    }

    // ---- fa_investment_readiness ----

    public function testCardDebtComesBeforeAnyInvestment(): void
    {
        $r = fa_investment_readiness([
            'liquid' => 50000000,
            'avg_monthly_expense' => 3000000,
            'avg_monthly_surplus' => 1000000,
            'debts' => [['name' => 'Lulo', 'type' => 'tarjeta_credito', 'balance' => 800000]],
        ]);

        $this->assertSame('pagar_deuda_cara', $r['stage']);
        $this->assertEqualsWithDelta(0, $r['investable_monthly'], 0.001, 'Con deuda de tarjeta no se propone invertir nada.');
    }

    public function testEmergencyFundComesBeforeInvesting(): void
    {
        $r = fa_investment_readiness([
            'liquid' => 4000000,
            'avg_monthly_expense' => 3000000,
            'avg_monthly_surplus' => 500000,
            'debts' => [['name' => 'Crédito', 'type' => 'prestamo_pagar', 'balance' => 2000000]],
        ]);

        $this->assertSame('fondo_emergencia', $r['stage']);
        $this->assertEqualsWithDelta(5000000, $r['emergency_gap'], 0.001, 'Faltan 9M (3 meses) - 4M.');
    }

    public function testReadyToInvestOnlyTheSurplusAboveTheFullReserve(): void
    {
        $r = fa_investment_readiness([
            'liquid' => 25000000,
            'avg_monthly_expense' => 3000000,
            'avg_monthly_surplus' => 700000,
            'debts' => [],
        ]);

        $this->assertSame('listo', $r['stage']);
        $this->assertEqualsWithDelta(700000, $r['investable_monthly'], 0.001);
        $this->assertEqualsWithDelta(7000000, $r['investable_lump'], 0.001, '25M - 6 meses de gastos (18M).');
    }

    public function testEnoughReserveButNoSurplus(): void
    {
        $r = fa_investment_readiness(['liquid' => 10000000, 'avg_monthly_expense' => 3000000, 'avg_monthly_surplus' => -200000, 'debts' => []]);
        $this->assertSame('sin_excedente', $r['stage']);
    }

    public function testNoDataMeansNoRecommendation(): void
    {
        $r = fa_investment_readiness(['liquid' => 0, 'avg_monthly_expense' => 0, 'avg_monthly_surplus' => 0, 'debts' => []]);
        $this->assertSame('sin_datos', $r['stage']);
        $this->assertNull($r['emergency_months']);
    }

    // ---- fa_accounts ----

    public function testDebtIsAlwaysAPositiveAmountOwedWhateverSignItWasStoredWith(): void
    {
        $this->db->exec("INSERT INTO accounts (user_id, name, type, balance, currency, credit_limit, workspace) VALUES
            (7, 'Nequi', 'banco', 1500000, 'COP', 0, 'personal'),
            (7, 'Tarjeta Lulo', 'tarjeta_credito', -800000, 'COP', 2000000, 'personal'),
            (7, 'Crediflores', 'prestamo_pagar', -1500000, 'COP', 0, 'personal'),
            (7, 'Tarjeta al día', 'tarjeta_credito', 0, 'COP', 1000000, 'personal'),
            (7, 'Caja negocio', 'efectivo', 9000000, 'COP', 0, 'business')");

        $acc = fa_accounts($this->db, 7, 'personal');

        $this->assertEqualsWithDelta(1500000, $acc['liquid_total'], 0.001, 'La caja del negocio no es liquidez personal.');
        $this->assertEqualsWithDelta(2300000, $acc['debt_total'], 0.001);
        $this->assertCount(2, $acc['debts'], 'Una tarjeta en cero no es una deuda.');
    }

    // ---- fa_build_context / fa_context_to_text ----

    public function testContextGivesTheModelPrecomputedFigures(): void
    {
        $this->db->exec("INSERT INTO accounts (user_id, name, type, balance, currency, credit_limit, workspace) VALUES
            (7, 'Nequi', 'banco', 2000000, 'COP', 0, 'personal'),
            (7, 'Tarjeta Lulo', 'tarjeta_credito', -500000, 'COP', 1000000, 'personal')");
        foreach (['2026-08', '2026-09', '2026-10'] as $ym) {
            $this->tx('ingreso', 4000000, "{$ym}-01");
            $this->tx('egreso', 600000, "{$ym}-05", 1, 'Mercado');
            $this->tx('egreso', 100000, "{$ym}-06", 2, 'Gasolina');
        }
        $this->tx('ingreso', 4000000, '2026-11-01');
        $this->tx('egreso', 900000, '2026-11-08', 1, 'Mercado grande');
        $this->db->exec("INSERT INTO budgets (user_id, category_id, amount, month, year, workspace) VALUES (7, 1, 600000, 10, 2026, 'personal'), (7, 2, 110000, 10, 2026, 'personal')");
        $this->db->exec("INSERT INTO savings_goals (user_id, name, target_amount, current_amount, target_date, workspace) VALUES (7, 'Viaje', 3000000, 1000000, '2027-03-15', 'personal')");
        $this->db->exec("INSERT INTO loan_clients (id, user_id, name) VALUES (1, 7, 'Pedro')");
        $this->db->exec("INSERT INTO loans (id, user_id, client_id, status, workspace) VALUES (1, 7, 1, 'activo', 'personal')");
        $this->db->exec("INSERT INTO loan_installments (loan_id, date, installment, paid_amount, status) VALUES (1, '2026-10-15', 100000, 0, 'pendiente'), (1, '2026-12-15', 100000, 0, 'pendiente')");

        $ctx = fa_build_context($this->db, 7, 'personal', self::TODAY);

        $this->assertEqualsWithDelta(4000000, $ctx['avg_income'], 0.001);
        $this->assertEqualsWithDelta(700000, $ctx['avg_expense'], 0.001);
        $this->assertSame('pagar_deuda_cara', $ctx['readiness']['stage']);
        $this->assertEqualsWithDelta(500000, $ctx['goals'][0]['monthly_needed'], 0.001, 'Faltan 2M y quedan 4 meses.');
        $this->assertEqualsWithDelta(200000, $ctx['receivables']['total'], 0.001);
        $this->assertSame(1, $ctx['receivables']['items'][0]['overdue_installments']);

        // El presupuesto de octubre sigue vigente en noviembre sin que nadie lo copie a mano.
        $this->assertTrue($ctx['budget']['has_budget']);
        $this->assertEqualsWithDelta(710000, $ctx['budget']['limit'], 0.001);
        $novemberRows = (int) $this->db->query('SELECT COUNT(*) FROM budgets WHERE month = 11 AND year = 2026')->fetchColumn();
        $this->assertSame(2, $novemberRows);

        $text = fa_context_to_text($ctx);
        $this->assertStringContainsString('día 12 de 30 de noviembre 2026', $text);
        $this->assertStringContainsString('Tarjeta Lulo (tarjeta de crédito): debe 500.000', $text);
        $this->assertStringContainsString('uso 50%', $text);
        $this->assertStringContainsString('EXCEDIDO por 300.000', $text);
        $this->assertStringContainsString('50% por encima de lo habitual', $text);
        $this->assertStringContainsString('Pedro: 200.000 — 1 cuota(s) VENCIDA(S)', $text);
        $this->assertStringContainsString('etapa «pagar_deuda_cara»', $text);
        $this->assertStringContainsString('necesita 500.000/mes', $text);
    }

    public function testContextForABrandNewUserDoesNotBlowUp(): void
    {
        $ctx = fa_build_context($this->db, 7, 'personal', self::TODAY);
        $text = fa_context_to_text($ctx);

        $this->assertSame('sin_datos', $ctx['readiness']['stage']);
        $this->assertStringContainsString('el usuario no tiene presupuesto definido', $text);
        $this->assertStringContainsString('DEUDAS: ninguna registrada', $text);
    }

    public function testContextSurvivesAnInstallWithoutTheLoansTables(): void
    {
        $this->db->exec('DROP TABLE loan_installments');
        $ctx = fa_build_context($this->db, 7, 'personal', self::TODAY);
        $this->assertSame([], $ctx['receivables']['items']);
    }
}
