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

`backend/lib/finance_analytics.php` es la fuente única de las cifras
financieras: la usan la IA (`ai.php`, como texto inyectado en
`{{RESUMEN_FINANCIERO}}`), `insights.php`, los puntos por ahorro y la
capacidad de inversión. Cualquier cálculo nuevo va ahí, no en el endpoint
ni en el prompt: a la IA se le entregan números ya calculados. Su SQL usa
rangos de fecha (nada de `MONTH()`/`YEAR()`) para correr igual en MySQL y
en el SQLite de las pruebas.

**Proveedores de IA.** `ai.php` habla con Gemini o Groq a través de
`callAI()`; el proveedor viaja en la cabecera `X-AI-Provider` (si falta, se
deduce del prefijo de la clave: `gsk_` es Groq). Por dentro los prompts y
payloads siguen en formato Gemini: `backend/lib/ai_providers.php` los traduce
al formato OpenAI para Groq y clasifica la respuesta con la misma forma que
`gemini_classify_response()`, así que los endpoints no saben con quién hablan.
Los modelos NO están escritos a mano: se le pregunta a cada proveedor cuáles
existen (caché de 6 h) y se elige por capacidad, con un piso de calidad para
Groq (sin modelos pequeños, que inventan cifras). Ante límites o sobrecarga se
salta al siguiente modelo. En el frontend, `src/utils/aiProvider.js` guarda la
clave en uso en `gemini_api_key` (nombre heredado que leen todas las vistas) y
el interceptor de `App.vue` agrega `X-AI-Provider` a cada petición.

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
GitHub Actions (`.github/workflows/ci.yml`) corre `php -l` sobre todo el
PHP, PHPUnit, Vitest y `vite build` en cada pull request hacia `main` y en
cada push a `main`. Es la compuerta: `main` exige que el chequeo `build`
pase, y los PR se mezclan solos con auto-merge cuando está en verde. Mezclar
a `main` publica a producción, así que nada llega ahí sin pasar las pruebas.

**El frontend compilado (`index.html` y `assets/`) se commitea en el MISMO
PR que cambia el código, nunca desde CI**: la regla de `main` rechaza el push
del robot (GH013) y el hosting no tiene Node. Antes de abrir un PR que toque
`frontend/`: `git rm -q 'assets/*.js' 'assets/*.css'`, `cd frontend && npm run
build`, `git add -A index.html assets`. CI repite ese build y falla si no
coincide con lo commiteado (el build es reproducible entre Node 20 y 22).
Un webhook de GitHub firmado con HMAC
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
- El presupuesto es "el vigente": un mes nuevo arranca con el del período
  anterior ya guardado (`budgets_ensure_period_filled()`), y lo que el
  usuario ajuste pasa a ser el vigente. No reintroducir herencia solo
  visual ni botones para "recuperarlo".
- Deudas del usuario = cuentas `tarjeta_credito` / `prestamo_pagar`, con
  saldo guardado en NEGATIVO. La tabla `loans` es plata que el usuario
  PRESTÓ (cartera por cobrar), no lo que debe.
- Un abono a una meta de ahorro se registra como `egreso` con descripción
  "Abono a meta de ahorro: …": es ahorro, no gasto.
- Cada usuario usa su propia API key gratuita de IA (Gemini o Groq; no hay
  clave compartida ni se debe agregar una). La clave solo vive en el
  navegador del usuario y viaja en una cabecera, nunca en la URL.
- Los mensajes de commit y comentarios del código están en español,
  consistente con el resto del repo.
