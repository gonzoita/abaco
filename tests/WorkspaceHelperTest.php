<?php

use PHPUnit\Framework\TestCase;

/**
 * get_active_workspace() / get_workspace_sql_clause() son las funciones que
 * deciden si una consulta ve los datos "Personal" o "Mi Negocio" del
 * usuario. Un bug aquí no lanza un error visible: silenciosamente mezcla o
 * esconde datos de un espacio de trabajo en el otro (ya pasó una vez en
 * ai.php: $workspace se usaba antes de asignarse).
 */
final class WorkspaceHelperTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_GET['workspace'], $_POST['workspace'], $_SERVER['HTTP_X_WORKSPACE'], $_SERVER['REDIRECT_HTTP_X_WORKSPACE']);
    }

    public function testDefaultsToPersonalWhenNothingIsSet(): void
    {
        $this->assertSame('personal', get_active_workspace());
    }

    public function testReadsWorkspaceFromGetParameter(): void
    {
        $_GET['workspace'] = 'business';
        $this->assertSame('business', get_active_workspace());
    }

    public function testRejectsUnknownWorkspaceValues(): void
    {
        // Un valor inesperado (typo, manipulación) nunca debe filtrar sin
        // condición de workspace: siempre cae de vuelta a 'personal'.
        $_GET['workspace'] = 'algo-invalido';
        $this->assertSame('personal', get_active_workspace());
    }

    public function testSqlClauseForBusinessRequiresExactMatch(): void
    {
        $_GET['workspace'] = 'business';
        $clause = get_workspace_sql_clause('workspace');

        $this->assertStringContainsString("= 'business'", $clause);
        $this->assertStringNotContainsString('IS NULL', $clause, 'El espacio de negocio no debe heredar filas sin workspace asignado.');
    }

    public function testSqlClauseForPersonalIncludesNullAndEmptyRows(): void
    {
        // Sin esto, cuentas/transacciones creadas antes de que existiera el
        // concepto de workspace (workspace NULL) desaparecerían del espacio
        // Personal para siempre.
        $clause = get_workspace_sql_clause('workspace');

        $this->assertStringContainsString("= 'personal'", $clause);
        $this->assertStringContainsString('IS NULL', $clause);
    }
}
