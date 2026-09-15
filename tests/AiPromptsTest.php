<?php

use PHPUnit\Framework\TestCase;

/**
 * Cubre ai_prompt_get(), ai_prompt_fill() y ai_prompt_list() -- el
 * mecanismo detrás del editor de prompts del panel de admin (ver
 * backend/api/admin.php: list_prompts/update_prompt/reset_prompt).
 */
final class AiPromptsTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec('CREATE TABLE ai_prompts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            prompt_key TEXT NOT NULL UNIQUE,
            prompt_text TEXT NOT NULL,
            updated_by INTEGER,
            updated_at TEXT
        )');
    }

    // ---- ai_prompt_get ----

    public function testReturnsDefaultWhenNoOverrideExists(): void
    {
        $this->assertSame(
            AI_PROMPT_DEFAULTS['scan_receipt'],
            ai_prompt_get($this->db, 'scan_receipt')
        );
    }

    public function testReturnsOverrideWhenOneExists(): void
    {
        $stmt = $this->db->prepare('INSERT INTO ai_prompts (prompt_key, prompt_text, updated_by) VALUES (?, ?, ?)');
        $stmt->execute(['scan_receipt', 'Prompt personalizado de prueba.', 5]);

        $this->assertSame('Prompt personalizado de prueba.', ai_prompt_get($this->db, 'scan_receipt'));
    }

    public function testThrowsForUnknownPromptKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ai_prompt_get($this->db, 'no_existe');
    }

    // ---- ai_prompt_fill ----

    public function testFillsSingleToken(): void
    {
        $result = ai_prompt_fill('Hola {{NOMBRE}}, bienvenido.', ['{{NOMBRE}}' => 'Ana']);
        $this->assertSame('Hola Ana, bienvenido.', $result);
    }

    public function testFillsMultipleTokens(): void
    {
        $template = 'Cuentas: {{CUENTAS_JSON}} | Defecto: {{CUENTA_DEFECTO_ID}}';
        $result = ai_prompt_fill($template, [
            '{{CUENTAS_JSON}}' => '[{"id":1}]',
            '{{CUENTA_DEFECTO_ID}}' => '1',
        ]);
        $this->assertSame('Cuentas: [{"id":1}] | Defecto: 1', $result);
    }

    public function testLeavesUnknownTokensUntouched(): void
    {
        $result = ai_prompt_fill('Texto sin marcadores conocidos {{OTRO}}', ['{{NOMBRE}}' => 'Ana']);
        $this->assertSame('Texto sin marcadores conocidos {{OTRO}}', $result);
    }

    // ---- ai_prompt_list ----

    public function testListsAllDefaultsAsNotCustomizedWhenTableIsEmpty(): void
    {
        $list = ai_prompt_list($this->db);

        $this->assertCount(count(AI_PROMPT_DEFAULTS), $list);
        foreach ($list as $row) {
            $this->assertFalse($row['is_customized']);
            $this->assertSame(AI_PROMPT_DEFAULTS[$row['prompt_key']], $row['prompt_text']);
            $this->assertNull($row['updated_at']);
        }
    }

    public function testMarksOverriddenPromptAsCustomized(): void
    {
        $stmt = $this->db->prepare('INSERT INTO ai_prompts (prompt_key, prompt_text, updated_by, updated_at) VALUES (?, ?, ?, ?)');
        $stmt->execute(['optimize_budget', 'Nuevo texto.', 3, '2026-09-15 10:00:00']);

        $list = ai_prompt_list($this->db);
        $byKey = [];
        foreach ($list as $row) {
            $byKey[$row['prompt_key']] = $row;
        }

        $this->assertTrue($byKey['optimize_budget']['is_customized']);
        $this->assertSame('Nuevo texto.', $byKey['optimize_budget']['prompt_text']);
        $this->assertFalse($byKey['scan_receipt']['is_customized']);
    }
}
