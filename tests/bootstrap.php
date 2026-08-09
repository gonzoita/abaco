<?php
// tests/bootstrap.php
// Carga solo las piezas de lógica pura del backend (sin ejecutar los
// endpoints, que hacen authenticate()/exit() al incluirse). Ninguna de
// estas dependencias abre una conexión real a MySQL al incluirse.

require_once __DIR__ . '/../backend/config/jwt.php';
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/config/migrations.php';
require_once __DIR__ . '/../backend/api/auth_helper.php';
require_once __DIR__ . '/../backend/lib/import_insert_row.php';
require_once __DIR__ . '/../backend/lib/savings_logic.php';
require_once __DIR__ . '/../backend/lib/budgets_logic.php';
