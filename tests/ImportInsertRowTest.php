<?php

use PHPUnit\Framework\TestCase;

/**
 * import_insert_row() es el motor de "Cargar Respaldo" / "Restaurar desde
 * Drive" en Ajustes: inserta cada fila del JSON exportado como un registro
 * nuevo. Un bug aquí significa dinero mal restaurado (montos, cuentas o
 * categorías apuntando a donde no debían) sin que nadie lo note hasta que
 * el usuario revisa sus saldos.
 */
final class ImportInsertRowTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, name TEXT, balance REAL)');
    }

    public function testInsertsRowAndReturnsNewAutoIncrementId(): void
    {
        $newId = import_insert_row($this->db, 'accounts', [
            'id' => 999, // el id original del respaldo: debe ignorarse
            'user_id' => 7,
            'name' => 'Nequi',
            'balance' => 150000.50,
        ]);

        $this->assertNotSame(999, (int) $newId, 'El ID original del respaldo nunca debe reutilizarse.');

        $row = $this->db->query('SELECT * FROM accounts WHERE id = ' . (int) $newId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(7, (int) $row['user_id']);
        $this->assertSame('Nequi', $row['name']);
        $this->assertEqualsWithDelta(150000.50, (float) $row['balance'], 0.001);
    }

    public function testOverwritingUserIdBeforeInsertReassignsOwnership(): void
    {
        // Así es como settings.php lo usa: fuerza user_id al usuario
        // autenticado antes de insertar, sin importar de quién era
        // originalmente el respaldo (restaurar el propio backup de otra
        // persona no debe poder inyectar datos bajo su cuenta).
        $row = ['id' => 1, 'user_id' => 999, 'name' => 'Efectivo', 'balance' => 0];
        $row['user_id'] = 42;

        $newId = import_insert_row($this->db, 'accounts', $row);

        $stored = $this->db->query('SELECT user_id FROM accounts WHERE id = ' . (int) $newId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(42, (int) $stored['user_id']);
    }

    public function testReturnsNullAndInsertsNothingWhenRowIsEmptyAfterRemovingId(): void
    {
        $result = import_insert_row($this->db, 'accounts', ['id' => 1]);

        $this->assertNull($result);
        $count = (int) $this->db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
        $this->assertSame(0, $count);
    }
}
