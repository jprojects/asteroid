# Cambios

Formato: una sección por versión, la más reciente arriba. Cada versión se etiqueta en git como `vX.Y.Z`.

## 0.24.1 — 2026-11-09

- `google.calendar` también admite una lista de grupos (`['equip']`): solo esos usuarios (y los administradores) ven en
  el perfil la conexión personal con Google Calendar (`GoogleClient::calendarFor()`).

## 0.24.0 — 2026-11-09

- **Google Calendar de cada usuario**: con `google.calendar`, cada usuario conecta el suyo desde su **perfil** (solo
  lectura, token personal cifrado) y `GoogleClient::calendarEvents(…, $userId)` lo lee sin compartir calendarios
  (`connectUser`, `userConnection`, `disconnectUser`; los eventos llevan `private`). Misma dirección de retorno de
  Google que la conexión de la instalación.
- **`Page::USER_ACTIONS`**: acciones de una página que puede usar cualquier usuario con sesión (la acción comprueba lo
  suyo). Integraciones las usa para la conexión personal (`mine`, `mineoff`, `callback`).
- **Calendario**: la fuente `extra` puede mostrar un aviso con enlaces encima del calendario (`notice()`, opcional).

## 0.23.0 — 2026-11-06

- **Google Calendar (lectura)**: `'calendar' => true` en `google` pide al conectar el permiso `calendar.readonly`;
  `GoogleClient::calendarEvents()` lee los eventos de un calendario (con su enlace de Meet). *Integraciones* indica si
  falta el permiso y permite volver a conectar. Documentado en README, «Google Calendar (lectura)».
- **Eventos de fuera en el calendario**: opción `extra` de `calendar` con una clase `Quasar\Core\CalendarSource`;
  sus eventos son de solo lectura (no se arrastran) y se abren en otra pestaña, y salen en la leyenda. Con `extra`,
  semana y día también con una fecha `date`. Documentado en README, «Calendario».

## 0.22.2 — 2026-11-05

- Guardado parcial (API `PATCH`, `FormHandler::process(…, true)`): un campo virtual que no venía en los datos y que
  un `showon` hacía visible (p. ej. «enviar encuesta» al resolver) daba un aviso de PHP («Undefined array key») y,
  con `debug`, una respuesta que no era JSON. Ahora toma su valor por defecto.

## 0.22.1 — 2026-11-05

- API: los archivos de los campos media de una ficha (`_files` en `GET {m}/{id}`) salían sin nombre ni extensión (y
  con avisos de PHP en el log) porque sus datos se preparaban dos veces.

## 0.22.0 — 2026-11-05

- **`where` con `{user.columna}`**: la condición fija de un módulo admite los mismos marcadores que el `scope` y se
  aplica a todos, también a los administradores (que se saltan el `scope`). Sirve para registros personales, como
  notas privadas de cada usuario. Sin usuario (consola) no deja ver ninguno. Documentado en README, «Registros
  visibles por usuario».

## 0.21.1 — 2026-11-05

- Plantilla `aurora`: modo oscuro propio (matiz índigo y barra lateral del tono de las tarjetas); antes casi no se
  distinguía de `default` en oscuro.

## 0.21.0 — 2026-11-05

- **Módulos fuera del menú**: `"menu": false` (atajo de `"menu": { "visible": false }`) quita el módulo del menú y de
  la portada; sigue accesible por enlaces y botones y con sus permisos. Un `"menu"` mal escrito falla al cargar el
  módulo. Documentado en README, «Módulos fuera del menú».
- **Plantillas que heredan de `default`**: otra plantilla (`'template'` en la config) solo contiene lo que cambia;
  vistas, correos, assets e iconos que no tenga se toman de `default`, así que recibe las mejoras del núcleo. Un
  `assets/theme.css` de la plantilla se carga después de `style.css`. Una plantilla que no existe cae a `default`.
- Plantilla nueva **`aurora`** (clara, barra lateral blanca, más espaciosa): solo un `theme.css`.
- CSS: variable `--sidebar-text-strong` (antes `#fff` fijo) para poder tener la barra lateral clara.

## 0.20.0 — 2026-11-04

- **Mensajes de alguien de fuera en la conversación**: `ChatService::postExternal()` publica un mensaje sin usuario,
  con el nombre de quien lo escribe (`meta.author`); el panel lo muestra con ese nombre y se avisa como de cualquier
  mensaje. Hook nuevo `externalRecipients()` para elegir a quién. Es la base de un chat web para visitantes.

## 0.19.0 — 2026-11-04

- **Correo sin SMTP**: transporte `mail` (la función `mail()` de PHP, el sendmail del servidor) y `auto`, el nuevo
  valor por defecto de `mail.transport`: SMTP si hay `host` y, si no, `mail()`. Una instalación sin datos SMTP ya envía
  los avisos, las invitaciones y los códigos con solo `mail.from`. `bin/mail-test.php` y el estado para Cosmo muestran
  el transporte que se usa.

## 0.18.0 — 2026-11-03

- **Alta rápida con varios campos del registro abierto**: `quickCreate.context` admite, por módulo, un objeto
  *campo del alta → campo del registro* (p. ej. desde una incidencia, la incidencia y su proyecto). La forma de antes
  (`"issues": "issue_id"`) sigue igual.
- `"default": "{user.id}"` (o `{user.columna}`): el usuario conectado como valor por defecto de un campo en las altas.

## 0.17.0 — 2026-11-03

- **Desa i nou** en los formularios (Ctrl+Mayús+S), para quien puede crear registros: guarda y abre un alta vacía.
  `"form": { "keepOnNew": [...] }` conserva esos campos en el alta nueva (p. ej. la carpeta al subir documentos
  seguidos). No sale en las altas rápidas en ventana.

## 0.16.0 — 2026-11-03

`bin/install.php` (o `migrate`) crea la tabla `api_tokens`.

- **API REST para apps y bots** (`/api/v1/…`, config `api`, desactivada por defecto): tokens personales (desde el
  perfil, *Aplicacions i API*, o con `POST auth/token`; en BD solo el hash), listado con los filtros, buscador y orden
  de la web, ficha, alta, modificación parcial (`PATCH`), borrado, esquema de cada módulo, opciones de selects,
  archivos, conversación, acciones propias, avisos y datos de páginas (`Page::api()`). Cada petición actúa como su
  usuario: mismos permisos, `scope`, `access` y hooks que la web. `public/.htaccess` reescribe `/api/` y pasa la
  cabecera `Authorization`. Ver README, «API para apps y bots».
- **Páginas públicas** (`"public": true`): se abren sin iniciar sesión en `index.php?r=public&m=…`, sin menú y sin
  permisos (no salen en la matriz); la página valida el acceso, p. ej. con un token en la URL.
- `FormHandler::process(…, $partial)`: guarda solo los campos recibidos. `DataQuery::page()`: filas sin formatear y
  paginadas.
- Seguridad: un select de solo lectura ya no incluye en la página todas sus opciones (solo la elegida), y
  `r=options` solo responde para campos editables o filtros (antes, p. ej., la lista de todos los usuarios).

## 0.15.0 — 2026-11-02

- **Grupos del menú plegables**: el título de cada grupo del menú lateral pliega y despliega sus módulos; se recuerda
  en la cookie `qs_nav` y el grupo del módulo abierto siempre se ve desplegado.
- Las fichas del inicio (`qs-tile`) con un texto largo ya no estrechan el icono.

## 0.14.0 — 2026-11-02

- **Página de inicio configurable**: `'home' => 'módulo'` en la configuración lleva *Inicio* (y la entrada después del
  login) a ese módulo para quien puede verlo; `index.php?r=home&menu=1` muestra el inicio de siempre.

## 0.13.0 — 2026-11-02

- **Calendario con vistas de semana y día** (`CalendarPage`): rejilla de horas para fechas `datetime`, con
  `views`, `hours`, `slot`, `days`, `duration` y un campo de fin opcional (`end`) en `calendar`. Los eventos se
  **arrastran** (otra hora u otro día) y **se estiran** (otra duración); mover pasa por el permiso Editar, `scope`,
  `editAccess`, validación y hooks como guardar la ficha. Pulsar una franja vacía abre el alta con esa hora; los que
  se solapan se reparten la columna. Sin `views`, el calendario sigue siendo solo mensual, como antes.
- `branding.logo_plate = false`: el logo sin la placa blanca de la barra lateral y del login (logos transparentes).
- Calendario: el color puede salir de un campo que guarda `#rrggbb` (además de un select); el número del día lleva a
  la vista de día; textos genéricos en `cal.*` de `common.json`, que cada calendario puede redefinir.

## 0.12.0 — 2026-10-29

`bin/install.php` (o `migrate`) crea la tabla `password_resets`.

- **Recuperar la contraseña** desde el login: enlace de un solo uso por correo (1 hora, máximo 3 por usuario y hora),
  misma respuesta exista o no el usuario, y al cambiarla se desbloquea la cuenta, se revocan los dispositivos de
  confianza y se avisa por correo. Configuración en `password_reset`. Ver README, «Recuperar la contraseña».
- **Invitar usuarios**: al crear un usuario, *Envia una invitació* (por defecto) le envía un enlace para elegir su
  contraseña; en la ficha, *Envia l'enllaç de contrasenya* le envía uno para cambiarla (o la invitación otra vez).
  Caducan en `password_reset.invite_ttl` (7 días).
- **Doble clic**: un formulario que ya se está enviando ignora los siguientes envíos y su botón muestra que está
  trabajando; también los botones de acción y los de Excel/PDF. `data-no-lock` lo desactiva en un formulario.
- **Login**: el mensaje de cuenta bloqueada dice cuántos minutos faltan y enlaza a recuperar la contraseña; aviso de
  Bloq Mayús activado en los campos de contraseña.
- Campos: opción `createOnly` (solo en el formulario de alta).
- **Ver la contraseña**: botón con un ojo en todos los campos de contraseña (login, contraseña nueva, perfil y campos
  `password` de los formularios); al enviar el formulario se vuelve a ocultar.
- Los Informes IA nunca pueden consultar `password_resets`.

## 0.11.0 — 2026-10-29

- **Informes PDF** (módulos `"type": "report"`): cada instalación define en un JSON sus documentos PDF con filtros
  propios (`params`, los mismos tipos de campo que un formulario), una consulta con parámetros `:nombre` (consulta
  preparada; `:user_id` es el usuario conectado), consultas de un valor (`values`) y la maquetación: bloque de datos,
  tabla con anchos relativos, formatos, agrupación con subtotales, totales (`sum`, `avg`, `min`, `max`, `count`) y
  resumen final. Se ofrecen en un desplegable *Informes* de la barra de los listados (`"list": { "reports": [...] }`)
  y en el menú; permiso *Ver* de cada informe en la matriz. Se ejecutan en solo lectura con los límites de `reports`.
  Ejemplo en la demo: *Activitats per client*. Ver README, «Informes PDF».
- Fechas: `"default"` admite `first_of_month`, `last_of_month`, `first_of_last_month`, `last_of_last_month`,
  `first_of_year` y `last_of_year`.

## 0.10.0 — 2026-10-28

- **Instalación desde Cosmo** (0.3.0+): `bin/cosmo-setup.php` recibe del instalador de Cosmo la base de datos, la URL,
  el token y la plantilla de configuración; escribe `config/config.local.php`, crea las tablas y el administrador y,
  sin datos de ejemplo, quita los módulos de ejemplo.
- `Quasar\Core\Installer`: la lógica de `bin/install.php` (base de datos, tablas, administrador). Si la base de
  datos ya existe no intenta crearla (en hostings compartidos el usuario no tiene permiso para `CREATE DATABASE`).

## 0.9.0 — 2026-10-28

- **Constructor de módulos** (*Administración → Constructor*, solo administradores): a partir de una descripción, de un
  Excel/CSV o de ambos, la IA propone la tabla, el listado y el formulario; el administrador la revisa, pide cambios o
  edita el JSON y, al confirmar, Quasar genera el `CREATE TABLE`, `modules/{nombre}.json`, los textos en los tres
  idiomas e importa las filas del fichero. Lector de `.xlsx` y CSV propio.
- `ai.max_tokens` configurable (por defecto 4096).
- PHP 8.5: fuera `curl_close()` y `$http_response_header`, que solo llenaban el log de avisos.
- Avisos de tipo `warning` con estilo propio.

## 0.8.0 — 2026-10-28

IA en los informes y en los textos. `bin/install.php` (o `migrate`) crea las columnas `reports.comment` y
`reports.comment_at`.

- **Comentario de la IA en los informes**: resumen y observaciones del resultado (totales de las columnas numéricas y
  primeras 200 filas), que se guarda con el informe y lo ven todos los que lo ven. `'comment' => false` en `reports`
  para desactivarlo (envía datos a la IA).
- **Ayuda a redactar** (`"assist": true` en textarea y richtext): mejorar, más formal, más breve, corregir y traducir,
  con vista previa antes de sustituir el texto.
- `Dialog.open()` admite `content` (un nodo bajo el mensaje) y `wide`.

## 0.7.0 — 2026-10-26

Registros visibles por usuario, conversación en la ficha, avisos y Google Meet. `bin/install.php` (o `migrate`) crea
las tablas `notifications`, `messages`, `message_reads` y `settings`, y las columnas `users.notify_email` y
`reports.chart`.

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
- **Informes: pestaña *Gráfico*** (columnas, barras, líneas o circular, en SVG propio). La IA propone el gráfico al
  crear el informe; el usuario cambia tipo, categorías y valores, lo guarda con el informe y lo descarga en PNG.
- `demo.sql`: más datos de ejemplo (5 países, 100 clientes y 600 actividades de los últimos 18 meses) para probar
  listados, filtros e informes.
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
