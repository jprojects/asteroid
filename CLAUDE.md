# Quasar

CMS ligero de gestión (tablas de datos + formularios) definido por JSON. PHP 8.1+, MySQL/MariaDB y JS sin
dependencias. El README es la referencia de uso de cada función: léelo antes de cambiar algo y actualízalo
con cada función nueva.

## Convenciones

- **Sin dependencias**: ni Composer ni npm. PDF, XLSX, SMTP, select con buscador… están hechos a mano. No añadas librerías.
- Comentarios, mensajes de error para el desarrollador, README y CHANGELOG **en castellano**.
- Textos de la interfaz siempre en `lang/{ca,es,en}/*.json` (los tres idiomas; el catalán es el idioma por
  defecto). Nada de texto fijo en PHP ni en JS: los textos que necesita app.js se pasan en la config del componente.
- Iconos: siempre Lucide (`$this->icon()`, `Quasar.icon()`), nunca emojis. Si app.js pinta un icono, este tiene
  que estar en el sprite (`View::SCRIPT_ICONS` o `$this->useIcon()`).
- CSS: colores con variables del tema (`var(--primary)`, `--surface`…) para que funcione en claro y oscuro.
- Un error en el JSON de un módulo debe fallar **al cargar el módulo** con un mensaje claro
  (`RuntimeException("Módulo '…': …")`), no a mitad de una petición.
- Permisos y reglas `access`: aplícalos siempre en el servidor (Module, DataQuery, FormHandler, endpoints), no
  solo ocultándolos en la plantilla.
- Lo que el cliente envía (filtros, orden, campos) se valida contra el JSON del módulo; los identificadores SQL
  pasan por `Database::ident()`. `groups` es palabra reservada en MySQL 8.

## Base de datos

- Las tablas del núcleo están en `database/schema.sql` (`CREATE TABLE IF NOT EXISTS`) y las columnas añadidas
  después, en `Migrator::run()` (`ensureColumn`). Todo tiene que ser idempotente: lo ejecutan `bin/install.php` y
  la actualización remota (`?r=remote&a=migrate`).
- Datos de ejemplo en `database/demo.sql` (módulos `clients`, `countries`, `activities`).

## Versiones y publicación

- Semver en `VERSION`, con su entrada en `CHANGELOG.md` (una función nueva o un cambio de BD sube la versión MENOR).
- Los ficheros nuevos del núcleo se añaden a `core.json`; si no, la actualización remota (panel Cosmo) no los
  instala. Los módulos, idiomas y hooks propios de cada cliente no van ahí.
- Publicar: commit, `git tag -a vX.Y.Z` y push de la rama y de las etiquetas (Cosmo instala desde la etiqueta).

## Probar

- Comprobar la sintaxis: `php -l` en cada fichero PHP y `node --check templates/default/assets/app.js`.
- Entorno local: `config/config.local.php` (no versionado) con la BD de desarrollo; `php bin/install.php` y
  luego `php -S 127.0.0.1:8000 -t public`.
- Antes de dar algo por terminado, pruébalo en el navegador; en las funciones con permisos, también con un
  usuario que no sea administrador.
