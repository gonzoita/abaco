# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Qué es

Ábaco: plataforma de finanzas personales y de negocio con asesor financiero
por IA. En producción en https://abaco.briela.app. Ingresos/gastos,
presupuestos, metas de ahorro, préstamos, reportes ejecutivos, y un Asesor
IA (Google Gemini, con la API key propia de cada usuario) que analiza la
situación financiera real del usuario. Soporta dos "espacios de trabajo"
(`personal` / `business`) dentro de la misma cuenta.

Stack: Vue 3 (Composition API) + Vite en el frontend, PHP puro (sin
framework) en el backend, MySQL, auth con Google Identity Services + JWT
propio.

## Comandos

```bash
# Setup inicial
mysql -u root < database/schema.sql
mysql -u root control_finanzas < database/loans_schema.sql
cp .env.example .env      # ajustar credenciales locales

# Frontend
cd frontend
npm install
npm run dev                # http://localhost:5173
npm run build               # compila a la raíz del repo, NO a frontend/dist
npm test                    # vitest run (todos los tests)
npx vitest run path/to/archivo.test.js   # un solo archivo
npx vitest run -t "nombre del test"      # un solo test por nombre

# Backend (desde la raíz del repo)
composer install
vendor/bin/phpunit                        # todos los tests
vendor/bin/phpunit tests/BudgetsLogicTest.php          # un archivo
vendor/bin/phpunit --filter nombreDelTest              # un test por nombre
php -l backend/api/algun_archivo.php      # chequeo de sintaxis (lo corre CI sobre todo el PHP)
```

El frontend detecta automáticamente si corre en `localhost:5173` (dev) o en
producción y ajusta la URL de la API (`frontend/src/config.js`).

## Arquitectura

**Backend — un archivo por recurso, sin router ni framework.**
`backend/api/*.php` (accounts, transactions, savings, budgets, ai, admin,
loans, reports...) son el punto de entrada HTTP directo: cada uno enruta a
mano según `$_SERVER['REQUEST_METHOD']`. Todos arrancan requiriendo
`cors.php` (headers CORS + `set_exception_handler` que loguea a
`backend/logs/error.log`, gitignored, visible desde el panel de Admin) y
`auth_helper.php` (valida el JWT, hace *sliding renewal* de la sesión, y
expone `get_active_workspace()` / `get_workspace_sql_clause()` para filtrar
todas las queries por el workspace activo — leído del header `X-Workspace`
o de `?workspace=`).

La lógica de negocio con riesgo real (cálculo de ahorros, presupuestos,
inserción de importaciones, prompts de IA, parseo de respuestas de Gemini)
está extraída a `backend/lib/*.php` como funciones puras, testeables sin
HTTP ni base de datos — esa es la carpeta a tocar con más cuidado y la que
tiene test coverage dedicado (`savings_logic.php`, `budgets_logic.php`,
`import_insert_row.php`, `gemini_response.php`, `ai_prompts.php`,
`session_refresh.php`).

`backend/config/` tiene la conexión a BD, JWT, y la tabla de control de
migraciones (`schema_migrations`, para que `migrate_workspaces.php` no
repita migraciones en cada deploy).

**Frontend — una vista por pantalla.** `frontend/src/views/` (Dashboard,
Cuentas, Presupuestos, Préstamos, Asesor IA, Admin...), componentes
compartidos en `src/components/`, rutas en `src/router/`, utilidades
testeadas junto a cada módulo (`src/utils/*.js` + `*.test.js` al lado).

**Build y deploy están acoplados de una forma no obvia:** Vite compila
*directo a la raíz del repo* (`frontend/vite.config.js`: `outDir: '../'`,
`emptyOutDir: false`), no a `frontend/dist`, porque `deploy.php` sincroniza
todo el repo tal cual al `public_html` del hosting con `rsync --delete`.
En cada push a `main`, GitHub Actions (`.github/workflows/ci.yml`) corre
`php -l` sobre todo el PHP, PHPUnit, Vitest, y `vite build`; si el build
cambió algo lo commitea de vuelta con `[skip ci]` (para no disparar el
workflow de nuevo). Un webhook de GitHub firmado con HMAC
(`GITHUB_WEBHOOK_SECRET`) dispara `deploy.php` en el servidor, que etiqueta
el commit actual como punto de rollback (`git tag pre-deploy-*`), corre
`php -l` de nuevo (si falla, aborta sin tocar nada), sincroniza con rsync y
corre `migrate_workspaces.php`. `exec()`/`shell_exec()` están deshabilitadas
en el hosting, así que `deploy.php` usa `proc_open()`.

## Convenciones

- Login solo con Google (Google Identity Services) en la UI; el backend
  soporta login por email/password pero está deshabilitado en el frontend
  y protegido contra fuerza bruta.
- Toda query de datos de usuario debe filtrar por workspace activo vía
  `get_workspace_sql_clause()` — el modo "Mi Negocio" depende de esto para
  no mezclar datos con "Personal".
- Cada usuario usa su propia API key de Gemini (no hay clave compartida ni
  se debe agregar una).
- Los mensajes de commit y comentarios del código están en español,
  consistente con el resto del repo.
