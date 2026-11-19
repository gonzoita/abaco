<?php

use PHPUnit\Framework\TestCase;

/**
 * Cubre backend/lib/workspace_data.php y backend/lib/backup_logic.php: que
 * el espacio Personal y el espacio Negocio no se mezclen en categorías,
 * clientes de préstamos, respaldos ni al restablecer.
 */
final class WorkspaceDataTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, currency TEXT, reminder_days_before INTEGER)');
        $this->db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, name TEXT, icon TEXT, color TEXT, type TEXT, workspace TEXT DEFAULT \'personal\')');
        $this->db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, type TEXT, balance REAL DEFAULT 0, currency TEXT, tax_exempt INTEGER DEFAULT 0, workspace TEXT DEFAULT \'personal\')');
        $this->db->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, account_id INTEGER, category_id INTEGER, type TEXT, amount REAL, description TEXT, date TEXT, workspace TEXT DEFAULT \'personal\')');
        $this->db->exec('CREATE TABLE budgets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, category_id INTEGER, amount REAL, month INTEGER, year INTEGER, workspace TEXT DEFAULT \'personal\', items_json TEXT)');
        $this->db->exec('CREATE TABLE savings_goals (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, target_amount REAL, current_amount REAL, workspace TEXT DEFAULT \'personal\')');
        $this->db->exec('CREATE TABLE reminders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, due_date TEXT, status TEXT, workspace TEXT DEFAULT \'personal\')');
        $this->db->exec('CREATE TABLE loan_clients (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, document TEXT, phone TEXT, email TEXT, address TEXT, workspace TEXT DEFAULT \'personal\')');
        $this->db->exec('CREATE TABLE loans (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, client_id INTEGER, principal REAL, workspace TEXT DEFAULT \'personal\')');
        $this->db->exec('CREATE TABLE user_workspace_seeds (user_id INTEGER, workspace TEXT, PRIMARY KEY (user_id, workspace))');
        $this->db->exec('CREATE TABLE workspace_fix_log (id INTEGER PRIMARY KEY AUTOINCREMENT, table_name TEXT, row_id INTEGER, column_name TEXT, old_value TEXT, new_value TEXT)');

        $this->db->exec("INSERT INTO users (id, name, email, currency) VALUES (7, 'Diego', 'd@x.co', 'COP'), (8, 'Otra', 'o@x.co', 'COP')");
        // Categorías de sistema (compartidas, personales), como en schema.sql.
        $this->db->exec("INSERT INTO categories (id, user_id, name, icon, color, type, workspace) VALUES
            (1, NULL, 'Alimentación', 'coffee', '#EF4444', 'egreso', 'personal'),
            (2, NULL, 'Salario', 'briefcase', '#10B981', 'ingreso', 'personal'),
            (3, NULL, 'Gastos Bancarios', 'fa-building-columns', '#64748b', 'egreso', 'personal')");
    }

    private function rowCountOf(string $sql): int
    {
        return (int) $this->db->query($sql)->fetchColumn();
    }

    // ---- categorías base del negocio ----

    public function testElNegocioArrancaConSusPropiasCategorias(): void
    {
        $created = ws_seed_business_categories($this->db, 7);

        $this->assertSame(count(WS_BUSINESS_DEFAULT_CATEGORIES), $created);
        $names = $this->db->query("SELECT name FROM categories WHERE user_id = 7 AND workspace = 'business'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('Ventas', $names);
        $this->assertContains('Proveedores', $names);
        $this->assertContains('Nómina', $names);
        $this->assertSame(0, $this->rowCountOf("SELECT COUNT(*) FROM categories WHERE user_id = 7 AND workspace = 'personal'"), 'No toca el espacio personal.');
    }

    public function testLaSiembraSeHaceUnaSolaVezYRespetaLoQueElUsuarioBorra(): void
    {
        ws_seed_business_categories($this->db, 7);
        $this->db->exec("DELETE FROM categories WHERE user_id = 7 AND name = 'Publicidad'");

        $again = ws_seed_business_categories($this->db, 7);

        $this->assertSame(0, $again);
        $this->assertSame(0, $this->rowCountOf("SELECT COUNT(*) FROM categories WHERE name = 'Publicidad'"), 'Lo borrado no reaparece.');
    }

    public function testNoDuplicaUnaCategoriaQueElUsuarioYaTeniaEnElNegocio(): void
    {
        $this->db->exec("INSERT INTO categories (user_id, name, icon, color, type, workspace) VALUES (7, 'ventas', 'fa-tag', '#fff', 'ingreso', 'business')");

        ws_seed_business_categories($this->db, 7);

        $this->assertSame(1, $this->rowCountOf("SELECT COUNT(*) FROM categories WHERE user_id = 7 AND LOWER(name) = 'ventas'"));
    }

    public function testCadaUsuarioRecibeLasSuyasSinCompartirFilas(): void
    {
        ws_seed_business_categories($this->db, 7);
        ws_seed_business_categories($this->db, 8);

        $this->assertSame(count(WS_BUSINESS_DEFAULT_CATEGORIES), $this->rowCountOf("SELECT COUNT(*) FROM categories WHERE user_id = 8 AND workspace = 'business'"));
        $this->assertSame(0, $this->rowCountOf("SELECT COUNT(*) FROM categories WHERE user_id IS NULL AND workspace = 'business'"), 'Ninguna fila compartida: editar una no afecta a otros.');
    }

    public function testRestablecerPermiteSembrarDeNuevo(): void
    {
        ws_seed_business_categories($this->db, 7);
        backup_reset_workspace($this->db, 7, 'business');

        $this->assertSame(0, $this->rowCountOf("SELECT COUNT(*) FROM categories WHERE user_id = 7 AND workspace = 'business'"));
        $this->assertSame(count(WS_BUSINESS_DEFAULT_CATEGORIES), ws_seed_business_categories($this->db, 7));
    }

    public function testSinTablaDeSiembraIgualFuncionaSinDuplicar(): void
    {
        $this->db->exec('DROP TABLE user_workspace_seeds');

        $this->assertSame(count(WS_BUSINESS_DEFAULT_CATEGORIES), ws_seed_business_categories($this->db, 7));
        $this->assertSame(0, ws_seed_business_categories($this->db, 7), 'Por nombre: no duplica aunque no pueda marcar la siembra.');
    }

    // ---- categoría de Gastos Bancarios por espacio ----

    public function testElCobroDel4x1000UsaLaCategoriaDeSuPropioEspacio(): void
    {
        $personal = ws_bank_fees_category_id($this->db, 7, 'personal');
        $business = ws_bank_fees_category_id($this->db, 7, 'business');

        $this->assertSame(3, $personal);
        $this->assertNotNull($business);
        $this->assertNotSame($personal, $business);
        $this->assertSame('business', $this->db->query("SELECT workspace FROM categories WHERE id = {$business}")->fetchColumn());
    }

    // ---- gemelas de categoría y reparación de datos existentes ----

    public function testElNegocioQueUsabaUnaCategoriaPersonalPasaALaSuyaSinPerderNada(): void
    {
        // Caso real: un movimiento de negocio apuntando a "Alimentación" (personal, de sistema).
        $this->db->exec("INSERT INTO transactions (id, user_id, category_id, type, amount, date, workspace) VALUES
            (1, 7, 1, 'egreso', 50000, '2026-11-01', 'business'),
            (2, 7, 1, 'egreso', 20000, '2026-11-02', 'personal')");
        $this->db->exec("INSERT INTO budgets (id, user_id, category_id, amount, month, year, workspace) VALUES
            (1, 7, 1, 300000, 11, 2026, 'business'),
            (2, 7, 1, 600000, 11, 2026, 'personal')");

        $moved = ws_fix_cross_workspace_categories($this->db);

        $this->assertSame(2, $moved);
        $businessCat = (int) $this->db->query("SELECT category_id FROM transactions WHERE id = 1")->fetchColumn();
        $this->assertNotSame(1, $businessCat);
        $row = $this->db->query("SELECT name, type, workspace, user_id FROM categories WHERE id = {$businessCat}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['name' => 'Alimentación', 'type' => 'egreso', 'workspace' => 'business', 'user_id' => 7], $row);
        $this->assertSame(1, (int) $this->db->query("SELECT category_id FROM transactions WHERE id = 2")->fetchColumn(), 'Lo personal no se toca.');
        $this->assertEqualsWithDelta(300000, (float) $this->db->query("SELECT amount FROM budgets WHERE id = 1")->fetchColumn(), 0.001, 'El monto no cambia, solo la categoría.');
        $this->assertSame(1, (int) $this->db->query("SELECT category_id FROM budgets WHERE id = 2")->fetchColumn());
    }

    public function testLaReparacionEsIdempotenteYDejaBitacora(): void
    {
        $this->db->exec("INSERT INTO transactions (user_id, category_id, type, amount, date, workspace) VALUES (7, 1, 'egreso', 50000, '2026-11-01', 'business')");

        $first = ws_fix_cross_workspace_categories($this->db);
        $second = ws_fix_cross_workspace_categories($this->db);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $log = $this->db->query("SELECT table_name, column_name, old_value FROM workspace_fix_log")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([['table_name' => 'transactions', 'column_name' => 'category_id', 'old_value' => '1']], $log, 'Cada cambio queda registrado con el valor anterior para poder revertirlo.');
    }

    public function testReutilizaLaCategoriaDelNegocioQueYaExisteEnVezDeDuplicar(): void
    {
        ws_seed_business_categories($this->db, 7);
        $this->db->exec("INSERT INTO transactions (id, user_id, category_id, type, amount, date, workspace) VALUES (1, 7, 3, 'egreso', 1600, '2026-11-01', 'business')");
        $before = $this->rowCountOf("SELECT COUNT(*) FROM categories WHERE name = 'Gastos Bancarios'");

        ws_fix_cross_workspace_categories($this->db);

        $this->assertSame($before, $this->rowCountOf("SELECT COUNT(*) FROM categories WHERE name = 'Gastos Bancarios'"), 'Usa el Gastos Bancarios del negocio ya sembrado.');
        $now = (int) $this->db->query("SELECT category_id FROM transactions WHERE id = 1")->fetchColumn();
        $this->assertSame('business', $this->db->query("SELECT workspace FROM categories WHERE id = {$now}")->fetchColumn());
    }

    public function testElEspacioVacioOLoCasteadoCuentaComoPersonal(): void
    {
        $this->db->exec("INSERT INTO transactions (user_id, category_id, type, amount, date, workspace) VALUES (7, 1, 'egreso', 100, '2026-11-01', NULL), (7, 1, 'egreso', 100, '2026-11-01', '')");

        $this->assertSame(0, ws_fix_cross_workspace_categories($this->db), 'Sin espacio = personal, y la categoría también es personal: nada que reparar.');
    }

    // ---- clientes de préstamos ----

    public function testElClienteCreadoDesdeElNegocioPasaAlNegocio(): void
    {
        // Todos quedaron como 'personal' porque el insert no guardaba el espacio.
        $this->db->exec("INSERT INTO loan_clients (id, user_id, name, document, phone, workspace) VALUES (1, 7, 'Pedro', '123', '300', 'personal'), (2, 7, 'Ana', '456', '301', 'personal')");
        $this->db->exec("INSERT INTO loans (user_id, client_id, principal, workspace) VALUES (7, 1, 1000000, 'business'), (7, 2, 500000, 'personal')");

        $result = ws_assign_client_workspaces($this->db);

        $this->assertSame(['moved' => 1, 'split' => 0], $result);
        $this->assertSame('business', $this->db->query("SELECT workspace FROM loan_clients WHERE id = 1")->fetchColumn());
        $this->assertSame('personal', $this->db->query("SELECT workspace FROM loan_clients WHERE id = 2")->fetchColumn());
    }

    public function testUnClienteConPrestamosEnLosDosEspaciosSeDividePerdiendoNada(): void
    {
        $this->db->exec("INSERT INTO loan_clients (id, user_id, name, document, phone, email, workspace) VALUES (1, 7, 'Pedro', '123', '300', 'p@x.co', 'personal')");
        $this->db->exec("INSERT INTO loans (id, user_id, client_id, principal, workspace) VALUES (10, 7, 1, 1000000, 'business'), (11, 7, 1, 200000, 'personal'), (12, 7, 1, 300000, 'business')");

        $result = ws_assign_client_workspaces($this->db);

        $this->assertSame(['moved' => 0, 'split' => 1], $result);
        $this->assertSame(2, $this->rowCountOf('SELECT COUNT(*) FROM loan_clients'));
        $copy = $this->db->query("SELECT id, name, document, email, workspace FROM loan_clients WHERE id <> 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['name' => 'Pedro', 'document' => '123', 'email' => 'p@x.co', 'workspace' => 'business'], array_diff_key($copy, ['id' => 1]));
        $this->assertSame(2, $this->rowCountOf("SELECT COUNT(*) FROM loans WHERE client_id = {$copy['id']} AND workspace = 'business'"), 'Sus dos préstamos de negocio pasan a la copia.');
        $this->assertSame(1, $this->rowCountOf("SELECT COUNT(*) FROM loans WHERE client_id = 1 AND workspace = 'personal'"));
        $this->assertSame(3, $this->rowCountOf('SELECT COUNT(*) FROM loans'), 'Ningún préstamo se pierde.');
    }

    public function testClientesSinPrestamosNoSeTocanYLaAsignacionEsIdempotente(): void
    {
        $this->db->exec("INSERT INTO loan_clients (id, user_id, name, document, phone, workspace) VALUES (1, 7, 'Sin prestamos', '1', '1', 'personal'), (2, 7, 'Pedro', '2', '2', 'personal')");
        $this->db->exec("INSERT INTO loans (user_id, client_id, principal, workspace) VALUES (7, 2, 1000, 'business'), (7, 2, 1000, 'personal')");

        ws_assign_client_workspaces($this->db);
        $second = ws_assign_client_workspaces($this->db);

        $this->assertSame(['moved' => 0, 'split' => 0], $second);
        $this->assertSame('personal', $this->db->query("SELECT workspace FROM loan_clients WHERE id = 1")->fetchColumn());
        $this->assertSame(3, $this->rowCountOf('SELECT COUNT(*) FROM loan_clients'));
    }

    // ---- respaldo por espacio ----

    private function seedBothSpaces(): void
    {
        $this->db->exec("INSERT INTO accounts (id, user_id, name, type, balance, currency, workspace) VALUES
            (1, 7, 'Nequi', 'banco', 100, 'COP', 'personal'), (2, 7, 'Caja negocio', 'efectivo', 900, 'COP', 'business'), (3, 8, 'De otra', 'banco', 5, 'COP', 'personal')");
        $this->db->exec("INSERT INTO transactions (user_id, account_id, category_id, type, amount, date, workspace) VALUES
            (7, 1, 1, 'egreso', 10, '2026-11-01', 'personal'), (7, 2, NULL, 'ingreso', 90, '2026-11-02', 'business')");
        $this->db->exec("INSERT INTO categories (user_id, name, icon, color, type, workspace) VALUES
            (7, 'Mi categoria', 'fa-tag', '#fff', 'egreso', 'personal'), (7, 'Categoria negocio', 'fa-tag', '#fff', 'ingreso', 'business')");
        $this->db->exec("INSERT INTO reminders (user_id, title, due_date, status, workspace) VALUES (7, 'Pago personal', '2026-12-01', 'pendiente', 'personal'), (7, 'Pago negocio', '2026-12-01', 'pendiente', 'business')");
    }

    public function testExportarSoloTraeElEspacioActivo(): void
    {
        $this->seedBothSpaces();

        $business = backup_export($this->db, 7, 'business');

        $this->assertSame('business', $business['workspace']);
        $this->assertSame(2, $business['backup_version']);
        $this->assertSame(['Caja negocio'], array_column($business['accounts'], 'name'));
        $this->assertCount(1, $business['transactions']);
        $this->assertSame(['Categoria negocio'], array_column($business['categories'], 'name'), 'Sin las de sistema ni las del otro espacio.');
        $this->assertSame(['Pago negocio'], array_column($business['reminders'], 'title'));
    }

    public function testElRespaldoPersonalNoTraeNadaDeOtrosUsuarios(): void
    {
        $this->seedBothSpaces();

        $personal = backup_export($this->db, 7, 'personal');

        $this->assertSame(['Nequi'], array_column($personal['accounts'], 'name'));
    }

    public function testUnRespaldoDeOtroEspacioSeRechazaDiciendoACualCambiar(): void
    {
        $result = backup_prepare_import(['workspace' => 'business', 'accounts' => [['name' => 'X', 'workspace' => 'business']]], 'personal');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('espacio Negocio', $result['error']);
        $this->assertStringContainsString('estás en Personal', $result['error']);
    }

    public function testUnRespaldoAntiguoSoloCargaLoDelEspacioActivo(): void
    {
        $old = [
            'accounts' => [['name' => 'Nequi', 'workspace' => 'personal'], ['name' => 'Caja negocio', 'workspace' => 'business'], ['name' => 'Sin espacio']],
            'transactions' => [['amount' => 1, 'workspace' => 'business'], ['amount' => 2, 'workspace' => 'personal']],
        ];

        $result = backup_prepare_import($old, 'business');

        $this->assertSame(['Caja negocio'], array_column($result['backup']['accounts'], 'name'));
        $this->assertCount(1, $result['backup']['transactions']);
        $this->assertSame(3, $result['ignored'], 'Nequi, "Sin espacio" (cuenta como personal) y la transacción personal.');
        $this->assertSame('business', $result['backup']['accounts'][0]['workspace']);
    }

    public function testUnRespaldoDelMismoEspacioSeCargaCompleto(): void
    {
        $result = backup_prepare_import(['workspace' => 'business', 'accounts' => [['name' => 'A', 'workspace' => 'business']]], 'business');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(0, $result['ignored']);
    }

    public function testRestablecerUnEspacioNoTocaElOtro(): void
    {
        $this->seedBothSpaces();

        backup_reset_workspace($this->db, 7, 'business');

        $this->assertSame(0, $this->rowCountOf("SELECT COUNT(*) FROM transactions WHERE user_id = 7 AND workspace = 'business'"));
        $this->assertSame(1, $this->rowCountOf("SELECT COUNT(*) FROM transactions WHERE user_id = 7 AND workspace = 'personal'"), 'Lo personal sigue intacto.');
        $this->assertSame(1, $this->rowCountOf("SELECT COUNT(*) FROM categories WHERE user_id = 7 AND workspace = 'personal'"));
        $this->assertSame(0, $this->rowCountOf("SELECT COUNT(*) FROM categories WHERE user_id = 7 AND workspace = 'business'"));
        $this->assertSame(1, $this->rowCountOf("SELECT COUNT(*) FROM reminders WHERE user_id = 7 AND workspace = 'personal'"));
        $this->assertSame(3, $this->rowCountOf("SELECT COUNT(*) FROM categories WHERE user_id IS NULL"), 'Las categorías de sistema no se borran.');
        $this->assertSame(1, $this->rowCountOf('SELECT COUNT(*) FROM accounts WHERE user_id = 8'), 'Ni se toca a otro usuario.');
    }

    public function testRestablecerRecreaLasCuentasEnSuPropioEspacio(): void
    {
        $this->seedBothSpaces();

        backup_reset_workspace($this->db, 7, 'business');

        $names = $this->db->query("SELECT name FROM accounts WHERE user_id = 7 AND workspace = 'business' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['Caja Chica Negocio', 'Cuenta Banco Empresa'], $names);
        $this->assertSame(['Nequi'], $this->db->query("SELECT name FROM accounts WHERE user_id = 7 AND workspace = 'personal'")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testRestablecerPersonalRecreaSusCuentasComoPersonales(): void
    {
        $this->seedBothSpaces();

        backup_reset_workspace($this->db, 7, 'personal');

        $rows = $this->db->query("SELECT name, workspace FROM accounts WHERE user_id = 7 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertContains(['name' => 'Efectivo', 'workspace' => 'personal'], $rows);
        $this->assertContains(['name' => 'Mi Cuenta de Ahorros', 'workspace' => 'personal'], $rows);
        $this->assertContains(['name' => 'Caja negocio', 'workspace' => 'business'], $rows, 'Lo del negocio sigue ahí.');
    }
}
