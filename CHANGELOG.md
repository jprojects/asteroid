# Cambios

Formato: una sección por versión, la más reciente arriba. Cada versión se etiqueta en git como `vX.Y.Z`.

## 0.7.0 — 2026-10-26

Registros visibles por usuario, conversación en la ficha, avisos y Google Meet. `bin/install.php` (o `migrate`) crea
las tablas `notifications`, `messages`, `message_reads` y `settings`, y la columna `users.notify_email`.

- **`scope`** en los módulos: qué registros ve cada grupo (`{user.columna}` como parámetro), con `set` para fijar
  columnas al guardar, y **`where`** fijo para todos. Se aplica en el servidor en listado, filtros (también los de IA),
  totales, exportación, ficha, guardado, borrado, acciones, archivos, calendario y sugerencias de etiquetas; fuera de
  alcance responde 404. Sin regla para el usuario, no ve nada.
- Selects con **`source.module`** (solo los registros que el usuario ve en ese módulo, también al validar) y
  **`source.where`**.
- **Conversación en la ficha** (`"chat"`): mensajes con enlaces, @menciones con autocompletado, adjuntos e imágenes
  (pegar y arrastrar), borrado, leído por usuario («Mensajes nuevos», «Visto por…»), silenciar, apuntes del sistema
  traducidos (`Chat::system`) y contador de mensajes sin leer en cada fila del listado. Se actualiza sola.
- **Avisos**: campana en la barra superior (sin leer, marcar como leídos, aviso flotante y del escritorio, contador en el
  título), API `Notify::send()` con textos en el idioma de cada destinatario, correo al momento o agrupado por el cron
  (`bin/notifications.php`), sin repetir el correo de un registro con avisos sin leer (`throttle_minutes`) y
  preferencia de correo en el perfil.
- **Google Meet**: botón de videollamada en la conversación (`"chat": { "meet": true }`) que crea la sala con la Meet
  REST API, la publica y avisa. Conexión OAuth desde *Administración → Integraciones* (`config google`); token de
  refresco cifrado en `settings`.
- Campos nuevos: **`richtext`** (editor propio y HTML limpio con `HtmlSanitizer`, imágenes como archivos) y
  **`duration`** (`1:30`, `1h30`, `45m`, `1,5`; minutos en BD; Excel `[h]:mm`).
- **`list.totals`**: fila de pie con las sumas de todos los registros filtrados.
- **Selects dependientes** (`dependsOn`): las opciones según lo elegido en otro campo, en la ficha (validado al
  guardar) y en los filtros. `source.dir` para ordenar las opciones de forma descendente.
- **Alta rápida** (`quickCreate`): botón en la barra superior que abre el alta de un módulo en una ventana, rellenada
  con el registro que se está viendo (`context`); `?modal=1` en cualquier alta. Fechas con `"default": "today"`.
- **`login_field`**: entrar con el nombre de usuario (por defecto), con el email o con cualquiera de los dos.
- **`menu.access`**: un módulo en el menú solo para algunos grupos. **`requires`**: un módulo solo existe si existen otros
  (el calendario de ejemplo ya no da 404 sin `activities`).
- **`lang/{idioma}/app.json`**: textos propios de la instalación compartidos por todos los módulos, que una
  actualización no sobrescribe (p. ej. grupos del menú).
- Hooks: `$this->values` con los valores del formulario (también los campos `virtual`) y `chatRecipients()`.
  `Auth::userById()`, `Settings`, `App::absoluteUrl()` (config `base_url`) y `MediaStorage::importFile()` para
  importar archivos del disco.
- `bin/media-clean.php` conserva las imágenes de los campos `richtext` y los adjuntos de las conversaciones.
- Corregido: un calendario en un módulo con otro nombre que `calendar` buscaba la plantilla `pages/{módulo}`.

## 0.6.0 — 2026-10-23

Informes con lenguaje natural. `bin/install.php` (o `migrate`) crea la tabla `reports`.

- Sección **Informes** (`modules/reports.json`, config `reports`, desactivada por defecto): la IA escribe una consulta
  SELECT sobre las tablas permitidas y el resultado se ve como una tabla con paginación, orden, Excel y PDF.
  Informes guardados con nombre, consultas recientes, explicación de lo entendido y SQL visible.
- Esquema leído de la BD de cada instalación (columnas, tipos, comentarios, claves foráneas) y completado con los
  módulos de Quasar; relaciones adicionales en `reports.relations`.
- Seguridad: validación de la consulta (`SqlGuard`), transacción de solo lectura con tiempo y filas máximas,
  usuario de MySQL de solo lectura opcional (`reports.db`), el navegador nunca envía SQL, revalidación al abrir.
- Permisos de la sección: ver, pedir a la IA, guardar y exportar. Modelo por defecto `claude-sonnet-5-5`.
- La tabla de `app.js` admite tablas de solo lectura sin ficha (sin `editUrl`).
- El aviso del límite de la IA muestra con cuatro decimales los importes de menos de 10 céntimos (antes, «0,00 €»).

## 0.5.0 — 2026-10-23

Filtrar con lenguaje natural (IA), opcional por instalación. `bin/install.php` (o `migrate`) crea `ai_usage` y `ai_cache`.

- Config `ai` (desactivada por defecto) y `"list": { "ai": … }` por módulo: barra «Filtrar con lenguaje natural» que
  rellena los filtros del panel. Proveedor Anthropic por HTTP (`Quasar\Ai`), modelo por defecto `claude-haiku-5-5`.
- La IA no genera SQL: salida estructurada con esquema fijo, validada contra los filtros que el usuario puede ver.
- Consumo en `ai_usage` (tokens, coste en euros, frase), módulo *Consumo de IA* para administradores, límite mensual
  en euros con aviso y corte, límite por usuario y hora, caché de frases idénticas; consumo en `?r=status` para Cosmo.
- Filtros de lista con varias opciones a la vez y chips de filtros activos encima de la tabla.
- Los módulos pueden depender de una función de la instalación (`"feature": "ai"`).
- `Action::config()` en las acciones de módulo.
- Corregido: un filtro de fecha con una fecha imposible (2026-13-45) se aceptaba desbordando al mes siguiente.

## 0.4.0 — 2026-10-23

Botones y acciones propias por módulo, sin tocar el núcleo. No cambia la BD.

- `list.toolbar` (barra de la tabla), `list.rowActions` (cada fila) y `form.buttons` (ficha): enlaces (`url`) o
  acciones (`action`), métodos de la clase hooks que reciben `Quasar\Core\Action` y devuelven `ActionResult`
  (aviso, error, redirección o descarga).
- Opciones `confirm`, `variant`, `download`, `access` y, en filas y ficha, `showon` por registro.
- Nueva ruta `POST ?r=action&m=&a=` con CSRF, que comprueba permiso, `access`, el registro y `showon` antes de
  ejecutar; cada ejecución queda en el log.
- Las acciones de fila con `url` siguen igual; una acción de fila sin `url` ni `action` ahora da error al cargar.
- README: sección "Personalizar un cliente sin tocar el núcleo".

## 0.3.0 — 2026-10-23

Tablas más flexibles, select múltiple y grupos de usuarios. `bin/install.php` (o `migrate` desde Cosmo) crea las
tablas `user_groups` y `group_permissions` y la columna `users.groups`.

- Columnas con textos largos: `"overflow": "ellipsis"` (por defecto) o `"wrap"` (texto entero en varias líneas),
  `"lines"` (máximo de líneas), `"minWidth"`, `"maxWidth"`; `list.overflow` para todo el módulo.
- Filas desplegables: `list.expand` muestra bajo la fila los campos indicados con el texto entero. Flecha por
  fila y en la cabecera (todas), → y ← con el teclado.
- Select múltiple: `"multiple": true` y `"maxItems"`. Se guarda como lista JSON; etiquetas con buscador en el
  formulario, filtro "contiene" en la tabla, compatible con `showon` y `search: "remote"`.
- Grupos de usuarios (*Administración → Grupos*) con su propia matriz de permisos, que se suma a la del usuario.
- Reglas `access` / `editAccess` por grupo o por permiso en campos, secciones del formulario y acciones de fila,
  aplicadas también en el servidor (datos, filtros, búsqueda, exportación, archivos y guardado).
- Las exportaciones a Excel incluyen el texto entero de los `textarea` (antes se cortaba a `listLength`).
- `$user->inGroup()`, `$user->groups()`, `FieldContext::user()`.
- Acciones propias de un módulo en la matriz de permisos: su etiqueta se busca también en el idioma del módulo
  (`perm.{acción}`).

## 0.2.0 — 2026-10-20

API remota para el panel central de instalaciones (Cosmo). Requiere la extensión `zip` para copias y actualizaciones.

- `?r=remote&a=…` con token: estado ampliado, log, integridad, copias (crear, listar, descargar, borrar),
  actualización con paquete de versión, migración y modo mantenimiento. `?r=status` sigue funcionando.
- Copias de seguridad en `storage/backups`: volcado SQL propio (sin `mysqldump`) y ficheros del proyecto.
- Actualización segura: copia previa, solo ficheros del núcleo (`core.json`), detección de cambios a mano,
  autoprueba tras instalar y vuelta atrás automática si la versión nueva no arranca.
- Modo mantenimiento (503) durante las actualizaciones.
- `Quasar\Core\Migrator`: la puesta al día de la BD de `bin/install.php`, reutilizable.
- Configuración nueva (opcional): `remote.allow_ips`, `remote.backups_keep`, `remote.self_test`.

## 0.1.0 — 2026-10-20

Primera versión versionada.

- Módulos definidos en JSON: tablas de datos, formularios, permisos por acción, hooks.
- Campos: texto, número, fecha, fecha y hora, email, contraseña, select, checkbox, etiquetas, media, repetidor.
- Exportación a Excel y PDF, imagen corporativa por cliente, plantillas intercambiables.
- Idiomas (ca, es, en), log por canales, correo SMTP, verificación en dos pasos por email.
- Fichero `VERSION`, versión visible en el menú lateral y consulta remota de estado (`?r=status`) con token.
