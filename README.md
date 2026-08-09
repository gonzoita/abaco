# Ábaco — Control de Finanzas e IA

Plataforma de finanzas personales y de negocio con asesor financiero por
inteligencia artificial. En producción en **[abaco.briela.app](https://abaco.briela.app)**.

Registro de ingresos/gastos, presupuestos, metas de ahorro, préstamos,
reportes ejecutivos, y un Asesor IA (Google Gemini) que analiza tu
situación real y da recomendaciones concretas — no solo tutoriales de la
app. Incluye modo "Personal" y "Mi Negocio" como espacios de trabajo
separados dentro de la misma cuenta.

## Stack técnico

| Capa | Tecnología |
|---|---|
| Frontend | Vue 3 (Composition API) + Vite, PWA instalable |
| Backend | PHP puro (sin framework), endpoints por acción en `backend/api/` |
| Base de datos | MySQL |
| Autenticación | Google Identity Services (OAuth) + JWT propio |
| IA | Google Gemini (cada usuario usa su propia API key, ver Ajustes) |
| Hosting | Hostinger (shared hosting), despliegue vía webhook de GitHub |
| CI | GitHub Actions — lint PHP, PHPUnit, Vitest, build del frontend |

## Estructura del proyecto

```
backend/
  api/            Un archivo por recurso (accounts.php, transactions.php,
                   savings.php, budgets.php, ai.php, admin.php...).
                   Cada uno enruta por $_SERVER['REQUEST_METHOD'].
  lib/             Lógica de negocio extraída de los endpoints de arriba
                   como funciones puras, testeables sin HTTP/auth
                   (savings_logic.php, budgets_logic.php,
                   import_insert_row.php).
  config/          Conexión a BD, JWT, tabla de control de migraciones.

frontend/
  src/views/       Una vista por pantalla (Dashboard, Cuentas,
                   Presupuestos, Préstamos, Asesor IA, Admin...).
  src/utils/       Funciones compartidas y testeadas (fetchSafe.js,
                   activity.js).
  src/router/      Rutas de Vue Router.

tests/             Pruebas de PHPUnit (backend).
frontend/src/**/*.test.js   Pruebas de Vitest (frontend), junto a cada módulo.

deploy.php         Script de despliegue (ver sección Deploy).
migrate_workspaces.php   Migraciones de esquema, con registro en
                         schema_migrations para no repetirse en cada deploy.
```

## Desarrollo local

Requiere PHP 8.3+, MySQL, Node 20+, [Laragon](https://laragon.org/) (o
equivalente) recomendado para Windows.

```bash
# Base de datos
mysql -u root < database/schema.sql
mysql -u root control_finanzas < database/loans_schema.sql

# Backend: copia .env.example y ajusta credenciales locales
cp .env.example .env

# Frontend
cd frontend
npm install
npm run dev             # http://localhost:5173
```

El frontend detecta automáticamente si corre en `localhost:5173` (dev) o
en producción y ajusta la URL de la API (`frontend/src/config.js`).

## Pruebas

```bash
# Backend (PHPUnit) — desde la raíz del repo
composer install
vendor/bin/phpunit

# Frontend (Vitest) — desde frontend/
npm test
```

Ambas corren automáticamente en cada push a `main` vía GitHub Actions
(`.github/workflows/ci.yml`). Cubren la lógica con más riesgo real:
autenticación (JWT, límite de intentos de login), filtrado por espacio de
trabajo, restauración de respaldos, y la lógica de dinero de ahorros y
presupuestos (`backend/lib/savings_logic.php`, `budgets_logic.php`).

## Deploy

El frontend compila **directo a la raíz del repo** (no a `frontend/dist`,
ver `frontend/vite.config.js`) porque `deploy.php` sincroniza todo el
repo tal cual a `public_html` del hosting.

Flujo en cada push a `main`:

1. **GitHub Actions** corre lint de PHP, PHPUnit, Vitest y `vite build`.
   Si el build cambió algo, lo commitea de vuelta con `[skip ci]`.
2. **Webhook de GitHub** (firmado con HMAC, ver `GITHUB_WEBHOOK_SECRET`)
   dispara `deploy.php` en el servidor.
3. `deploy.php`: etiqueta el commit actual como punto de rollback, corre
   `php -l` sobre todo el PHP (si falla, aborta sin tocar nada),
   sincroniza con `rsync --delete` (borra en el servidor lo que ya no
   está en el repo) y corre `migrate_workspaces.php`.

`exec()`/`shell_exec()` están deshabilitadas en el hosting; `deploy.php`
usa `proc_open()` en su lugar (ver comentarios en el archivo).

Rollback manual si un deploy rompe algo:
```bash
ssh -p 65002 usuario@servidor
cd .builds/source/repository
git tag -l 'pre-deploy-*'          # ver puntos de rollback disponibles
git reset --hard pre-deploy-<hash>-<fecha>
```

## Seguridad y privacidad

- Política de Tratamiento de Datos y Términos y Condiciones: `/legal`
  dentro de la app (`frontend/src/views/LegalView.vue`).
- Login solo con Google (sin formulario de correo/contraseña en la UI,
  aunque el backend lo soporta y está protegido contra fuerza bruta).
- Cada usuario usa su propia clave de Gemini — no hay clave compartida.
- Errores del servidor quedan en `backend/logs/error.log` (gitignored),
  visibles desde el panel de Admin sin necesitar SSH.
