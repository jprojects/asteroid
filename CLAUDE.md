# Asteroid

Gestor de incidencias de AFI. Es una **instalación de Quasar** (`~/Projects/quasar`): el núcleo viene de una versión
publicada de Quasar y lo propio de Asteroid va aparte. `ASTEROID.md` es la referencia de lo propio; `README.md` es el
de Quasar (referencia del núcleo): léelos antes de cambiar algo.

## El núcleo de Quasar no se toca aquí

Los ficheros que lista `core.json` (src/Core, src/Controllers, src/Fields, templates/default, lang/*/common.json,
config/config.php, README.md, VERSION…) son de Quasar: la actualización desde Cosmo los sustituye, así que **cualquier
cambio hecho aquí se pierde** y además bloquea la actualización. Para que Asteroid sea siempre actualizable:

- **Lo propio de Asteroid**: `modules/`, `src/Hooks/` (excepto los de Quasar), `src/Pages/` propias (`PissarraPage`,
  `SurveyPage`), `templates/default/pages/` propias (`pissarra.php`, `survey.php`), `lang/*/{módulo}.json` y
  `lang/*/app.json`, `database/asteroid.sql`, `tools/`, `ASTEROID.md`. Todo lo que se pueda hacer con un módulo, un
  hook, una página o un texto propio se hace así.
- **Si hace falta cambiar el núcleo** (una función nueva, un arreglo): se hace en `~/Projects/quasar`, de forma
  genérica (nada de Asteroid allí), con README y CHANGELOG, y se publica una versión (VERSION, `git tag -a vX.Y.Z`,
  push con etiquetas). Luego `php tools/update-core.php X.Y.Z` trae ese núcleo a Asteroid, se prueba Asteroid entero
  y se hace commit.
- Un hook de Claude Code (`.claude/hooks/guard-core.php`) bloquea editar ficheros del núcleo con Edit/Write. Tampoco
  se cambian por terminal (sed, scripts): antes de cada commit, `php tools/check-core.php` tiene que decir «Núcleo
  intacto».

## Convenciones (las de Quasar)

- **Sin dependencias**: ni Composer ni npm. No añadas librerías.
- Comentarios, mensajes de error para el desarrollador y documentación **en castellano**.
- Textos de la interfaz siempre en `lang/{ca,es,en}/*.json` (los tres idiomas; el catalán es el idioma por
  defecto). Nada de texto fijo en PHP ni en JS.
- Iconos: siempre Lucide (`$this->icon()`), nunca emojis. CSS con variables del tema (`var(--primary)`,
  `--surface`…) para claro y oscuro; los estilos de las páginas propias van en su plantilla, no en `style.css`.
- Un error en el JSON de un módulo debe fallar **al cargar el módulo** con un mensaje claro.
- Permisos, `scope` y reglas `access`: siempre en el servidor, no solo ocultándolos en la plantilla. Probar también
  con un usuario del equipo que no sea administrador y con un usuario de cliente.

## Base de datos

- Tablas propias en `database/asteroid.sql` (`CREATE TABLE IF NOT EXISTS`), las crea `tools/asteroid-migrate.php`
  (idempotente; también los grupos y sus permisos). Las del núcleo, `bin/install.php` / migrate de Quasar.
- Sin SSH en el servidor: dar también el SQL para phpMyAdmin cuando haya tablas o permisos nuevos.

## Publicar

- Commit y push de `main` en `github.com/jprojects/asteroid`. `VERSION` y `CHANGELOG.md` son los de Quasar: lo propio
  de Asteroid se documenta en `ASTEROID.md`.

## Probar

- Sintaxis: `php -l` en cada fichero PHP cambiado y `node --check templates/default/assets/app.js`.
- `php tools/check-core.php` antes de cada commit.
- Local: una copia con su propio `config.local.php` (el del proyecto puede tener la configuración del servidor) y la
  BD de desarrollo; datos reales con `tools/import-asteroid.php`. Antes de dar algo por terminado, pruébalo en el
  navegador.
