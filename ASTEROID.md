# Asteroid (sobre Quasar)

Gestor de incidencias de AFI, migrado de Franky CMS a Quasar (núcleo actual: 0.16.0, ver `VERSION`). El `README.md`
es el del núcleo (Quasar) y se sustituye con cada actualización; lo propio de Asteroid está aquí.

**Cambios en el núcleo**: se hacen en el repositorio de Quasar y se publica una versión; Asteroid se actualiza desde
ella con `php tools/update-core.php X.Y.Z` (en el servidor, desde Cosmo). Si se cambia un fichero del núcleo solo aquí,
la siguiente actualización lo pisa: `php tools/check-core.php` comprueba que el núcleo es idéntico al de Quasar.

## Qué es de Asteroid (no lo toca una actualización de Quasar)

| | |
|---|---|
| `modules/` | `issues`, `pissarra`, `agenda`, `surveys`, `survey`, `pending_billing`, `client_home`, `telegram`, `telegram_hook`, `webchat`, `webchat_settings`, `webchat_widget`, `web_audit`, `api_legacy`, `notes`, `wiki`, `wiki_pages`, `issue_from_mail`, `clients`, `projects`, `client_users`, `folders`, `files`, `hours`, `contracts`, `monthly_hours`, `billing` |
| `src/Pages/`, `templates/default/pages/` | `PissarraPage`, `SurveyPage`, `PendingBillingPage`, `ClientHomePage`, `TelegramPage` y `TelegramHookPage` con sus plantillas (`pissarra.php`, `survey.php`, `pending_billing.php`, `client_home.php`, `telegram.php`) (con sus estilos) |
| `src/Webchat/` | chat web para las webs de los clientes: utilidades, `loader.js` (botón en su web) y `widget.js` (el chat) |
| `src/Telegram/` | cliente de la Bot API y avisos al grupo (sin librerías) |
| `src/Hooks/` | `Asteroid` (utilidades), `Survey` (encuestas), `IssuesHooks`, `HoursHooks`, `BillingHooks`, `FilesHooks`, `ClientUsersHooks` |
| `lang/{ca,es,en}/` | un fichero por módulo y `app.json` (grupos del menú: Suport, Clients, Equip) |
| `database/asteroid.sql` | tablas del proyecto |
| `tools/check-core.php`, `tools/update-core.php` | comprobar que el núcleo no se ha tocado y traer el de una versión de Quasar |
| `tools/asteroid-migrate.php` | crea o pone al día las tablas, `users.client_id` y los grupos con sus permisos |
| `tools/import-asteroid.php` | importa los datos del Asteroid antiguo |
| `tools/import-factusol.php` | vuelca los datos fiscales de los clientes desde una exportación de FactuSOL |
| `config/config.local.php` | credenciales (no versionar) |

## Roles

- **`equip`** (Kim, Carles, Sergi): todos los módulos. Ven todas las incidencias, se las asignan, imputan horas y
  facturan. `kim` es además administrador (usuarios, grupos, permisos, integraciones).
- **`client`** (usuarios con `users.client_id`): solo **Incidències** y **Carpetes**. Ven las incidencias de su cliente
  marcadas como *Visible pel client*, las crean (el cliente y la visibilidad se fijan solos), escriben en la
  conversación y descargan los archivos de sus carpetas. No pueden cambiar estado, técnico ni planificación.

Los permisos de cada grupo están en *Administració → Grups → llave*; `tools/asteroid-migrate.php` crea los iniciales.
Los usuarios de los clientes se gestionan en *Clients → Usuaris de clients* (entran siempre en el grupo `client`).

## Pissarra

*Suport → Pissarra* (solo `equip`): una tarjeta por proyecto con incidencias pendientes o en curso, con cuántas hay
(pánico, urgentes, pendientes, en curso), el tiempo previsto y dedicado, la fecha límite más próxima (en rojo si ha
vencido) y las cuatro más urgentes. Primero los proyectos con pánico, después urgentes y por fecha límite. Por defecto
muestra las del técnico que entra; arriba se cambia a otro técnico, «Sense assignar» o «Tot l'equip». La tarjeta abre el
listado de incidencias con esos filtros. No salen las incidencias sin proyecto.

## El meu espai (inicio de los clientes)

Con `'home' => 'client_home'` en `config.local.php`, los usuarios de los clientes entran en **El meu espai**: sus
incidencias abiertas, los mensajes sin leer de sus conversaciones (con el último mensaje), los documentos nuevos de sus
carpetas (se descargan directamente) y las resueltas en los últimos 30 días, con el botón *Nova incidència*. Todo pasa
por el `scope`: cada cliente ve solo lo suyo. El equipo y los administradores siguen viendo el inicio de siempre. Los
mismos datos, en la API: `GET /api/v1/pages/client_home`.

## Pendent de facturar

*Equip → Pendent de facturar*: las horas pendientes (`hours.billing = 0`) por cliente y proyecto, con el importe de los
proyectos que tienen **preu/hora** (`projects.hourly_rate`; los que no, salen marcados «Sense preu» con enlace al
proyecto) y aparte las de proyectos con **pressupost tancat**. «Totes», «Fins al …» (final del mes pasado) o hasta
una fecha.

- **Ordre**: orden de facturación en borrador con las horas de ese proyecto, una línea por incidencia (y «Altres
  tasques» para las horas sin incidencia) al preu/hora del proyecto; las horas quedan facturadas y enlazadas a la
  orden. **Ordre de tot el client**: lo mismo con todos sus proyectos (el proyecto delante de cada línea).
- **No facturables** (icono): marca las horas pendientes de ese proyecto como no facturables (p. ej. presupuesto
  cerrado).
- Hace falta el permiso *Facturar* de Hores y *Crear* de Facturació. El importe de la página es el mismo que dará la
  orden (horas redondeadas a 2 decimales por línea).

## Archivos de los clientes

En *Clients → Arxius* se pueden soltar **varios archivos a la vez** (hasta 50): cada uno queda como un documento de la
carpeta, con su nombre (sin extensión) como título y las mismas observaciones (`FilesHooks`). El título es opcional.
**Desa i nou** (Ctrl+Mayús+S) abre otra alta con la misma carpeta (`form.keepOnNew`).

## Encuestas de satisfacción

En la ficha de una incidencia, al ponerla en **Resolta** aparece la casilla **Envia l'enquesta de satisfacció**
(desmarcada; solo la ve el equipo). También en *Imputar hores* cuando «Estat de la incidència» es «Resolta».

- Al guardar, cada destinatario recibe un aviso (campana y correo, en su idioma) con **su propio enlace**: vale una vez y
  caduca a los 30 días (`Survey::DAYS`). Destinatarios: quien abrió la incidencia si es del cliente; si la abrió el
  equipo, todos los usuarios activos del cliente con correo. Si no hay ninguno, queda anotado en la conversación.
- La encuesta (página pública `survey`, sin login): satisfacción general, tiempo de respuesta y calidad de la solución,
  de 1 a 5 estrellas, y un comentario opcional.
- Al responder: apunte en la conversación («… ha valorat l'atenció: ★★★★☆») y aviso al técnico (o al equipo si no hay).
- *Suport → Enquestes* (solo `equip`): enviadas, respondidas y caducadas, con filtros y exportación. La ficha de la
  incidencia muestra la valoración media («Valoració del client»).
- El correo sale como los avisos: hace falta `mail` configurado (no `'transport' => 'log'`) y los usuarios con los
  avisos por correo desactivados en su perfil solo la ven en la campana.

## Chat web para los clientes

Cada cliente puede poner un chat en **su** web y responder desde Asteroid.

- **AFI lo activa por cliente**: *Clients → ficha → Xat web* (solo el equipo).
- **El cliente lo configura** en *Suport → El meu xat web*: activo, nombre, color, posición del botón, mensaje de
  bienvenida y webs donde se puede usar (un dominio por línea; vacío = cualquiera). Ahí copia el código:
  `<script src="https://sat.aficat.com/index.php?r=public&m=webchat_widget&a=loader&k=CLAVE" async></script>`
- **En su web** sale un botón flotante con su color; el chat se abre en un iframe de Asteroid (solo se puede incrustar
  en sus dominios: `frame-ancestors`). El visitante deja su nombre y, si quiere, el correo; se identifica con un token
  aleatorio de su navegador (no hay cookies). Las respuestas le llegan en unos segundos.
- **Los usuarios del cliente responden** en *Suport → Xat web*: una conversación por visitante, con la conversación de
  siempre (avisos en la campana y por correo a todos los usuarios del cliente, no leídos en el listado, cerrar/reabrir).
  Cada cliente ve solo las suyas; el equipo de AFI no (los administradores sí).
- Contra abusos: 30 mensajes por visitante cada 10 minutos, 6 conversaciones nuevas por IP y hora, trampa para bots y
  2.000 caracteres por mensaje.

Código: `src/Webchat/`, `WebchatWidgetPage` (pública: `loader`, el iframe, `poll` y `send`), `WebchatSettingsPage`,
`WebchatHooks` y el núcleo `ChatService::postExternal()` (Quasar 0.20.0). Tablas `webchat_sites` y
`webchat_conversations`, columna `clients.webchat`; los mensajes van en `messages` (módulo `webchat`).

## Bot de Telegram

Sin librerías (la Bot API son peticiones HTTP con JSON): `src/Telegram/`, la página *Equip → Telegram* y el webhook
`index.php?r=public&m=telegram_hook` (página pública protegida con el `secret`).

**Avisos al grupo** (y tema): incidencias nuevas y mensajes de las conversaciones; opcionalmente las resueltas
(`issue.resolved`: quién, con las horas imputadas), todos los cambios de estado (`issue.status`) o solo las de pánico
(`issue.panic`). Llevan el cliente, el proyecto, el técnico y el enlace.

**Desde Telegram** (con la cuenta vinculada a un usuario del equipo; todo con sus permisos y los avisos de siempre):

- **Responder a un aviso** del bot escribe la respuesta en la conversación de esa incidencia (no vuelve a rebotar al
  grupo; el cliente recibe el aviso si la incidencia es visible para él).
- **Botones** en cada aviso (y en `/incidencia N`): *Imputa hores* (el bot pregunta el tiempo con botones de 0:15 a
  4:00 que solo puede usar quien lo ha pedido; las horas son de hoy, de ese usuario, de la incidencia y su proyecto),
  *Agafa-la* y *Resol*. Responder a la confirmación de unas horas les pone la descripción, o cambia el tiempo si la
  respuesta es un tiempo (`1:15`, `45m`, `2h`).
- `/nota TEXT`: una nota personal (*Equip → Notes*); respondiendo a un aviso, queda enlazada a esa incidencia y a su
  cliente. `/notes`: las pendientes (las suyas y las compartidas), con un botón **Feta** cada una.
- `/pissarra [nom|totes|sense]`, `/meves`, `/noves` (abiertas sin asignar), `/incidencia N` (resumen y últimos
  mensajes; también se puede responder), `/agafa N`, `/resol N`, `/hores N [temps] [descripció]` (sin tiempo, pregunta
  con botones), `/ajuda`, `/desvincular`.
- Con webhook, después de actualizar hay que volver a pulsar **Activa el webhook** para que Telegram envíe también los
  botones (`callback_query`); en modo consulta no hace falta.

**Clientes** (en privado; nunca ven nada del grupo ni del equipo): cada usuario de un cliente se vincula desde
*El meu espai → Avisos al mòbil* y recibe del bot, en su chat privado:

- los mensajes de los visitantes de **su chat web** (`Telegram::webchatMessage`, desde `WebchatHooks`);
- en **sus incidencias visibles**, vuestros mensajes y cuando se resuelven (a los mismos usuarios que la web,
  `Asteroid::clientRecipients`).

Contesta **respondiendo al mensaje**: se publica en esa conversación con sus permisos de siempre (el visitante lo ve en
la web; en una incidencia, el equipo recibe el aviso de siempre). Una respuesta a algo que no es suyo da «No tens
permís». Las órdenes no le funcionan: cualquier otra cosa recibe una ayuda breve (solo `/desvincular`).

**Menú de órdenes «/»**: lo gestiona Asteroid (no BotFather): la lista del equipo en el grupo y en el chat privado de
cada persona del equipo vinculada; ninguna en el resto (clientes incluidos). Se pone al vincular o desvincular y con
*Equip → Telegram → Actualitza les ordres del bot* (hay que pulsarlo una vez después de borrar las de BotFather).

**Puesta en marcha:**

1. @BotFather → `/newbot` → el token. Añade el bot al grupo del equipo (si el grupo tiene temas, el bot tiene que
   poder escribir en el tema).
2. `chat_id` del grupo (empieza por `-100`) y `thread_id` del tema: en Telegram Web, la URL del tema es
   `…/#-100XXXXXXXXXX_42` → `chat_id` = `-100XXXXXXXXXX`, `thread_id` = `42`.
3. En `config.local.php`:
   ```php
   'telegram' => [
       'token' => '123456:ABC…',
       'chat_id' => -1001234567890,
       'thread_id' => 42,
       'panic_thread_id' => 51,                         // opcional: tema para las de pánico (alta, mensajes, estado)
       'secret' => '…',                                 // 16-256 letras, números, _ o -; aleatorio
       'events' => ['issue.new', 'issue.message', 'issue.resolved'],   // + 'issue.status' | 'issue.panic'
       'locale' => 'ca',                                // idioma de los mensajes del bot
       'mode' => 'webhook',                             // o 'poll' (cron, ver abajo)
   ],
   ```
4. *Equip → Telegram* (administrador): **Activa el webhook** y **Envia una prova al grupo**.
5. Cada miembro del equipo: *Equip → Telegram → Vincula el meu compte* y abre el enlace al bot (o le envía
   `/vincular CODI`).

Tablas `telegram_users` (cuentas vinculadas) y `telegram_messages` (qué aviso es de qué incidencia).

**Modo consulta** (`'mode' => 'poll'`): si el hosting no deja entrar a Telegram (en Webempresa, el webhook recibe
**502** aunque desde fuera responda bien), el cron consulta los mensajes con `getUpdates` cada minuto, esperando hasta
~55 s, así que las respuestas llegan casi al momento. No necesita webhook (el script lo quita). Cron:

```
* * * * * php /home/…/sat.aficat.com/tools/telegram-poll.php > /dev/null 2>&1
```

Si el hosting no deja el cron cada minuto (wePanel, cada 5), hay que decirle que espere casi 5 minutos, o los botones
y respuestas que lleguen entre una ejecución y otra no se atienden hasta la siguiente:

```
*/5 * * * * php /home/…/sat.aficat.com/tools/telegram-poll.php 290 > /dev/null 2>&1
```

*Equip → Telegram* muestra la hora de la última consulta y, al abrirla, también recoge lo pendiente (así vincular una
cuenta funciona aunque el cron falle). El cron tiene que usar el **mismo PHP que la web** (en Webempresa,
`/opt/alt/php82/usr/bin/php` o el de la versión elegida en cPanel): el `php` a secas suele ser otro. Los avisos al
grupo no cambian (salen del servidor).

## Visibilidad para el cliente desde la tabla

En *Incidències*, la columna **Visible pel client** es un interruptor (`"toggle": true` en `modules/issues.json`,
Quasar 0.25.0): el equipo la cambia sin abrir la ficha. Los clientes no ven la columna (`access: ["equip"]`) y el
servidor rechaza el cambio si no pueden editar el campo.

## Incidencia desde un correo

Botón **Des d'un correu** en la barra de *Incidències* (solo el equipo; página `IssueFromMailPage`, fuera del menú).
Se suelta el `.eml` (Gmail: ⋮ → «Descargar mensaje»; Proton: «Exportar») o se pega el texto del correo, y se revisa:

- **Título**: el asunto sin «RE:», «Fwd:»…
- **Cliente y proyecto**: por el remitente (un usuario de cliente con ese correo, el correo de la ficha del cliente, o
  el dominio si no es genérico como gmail.com); sus proyectos salen primero. En un reenviado, el remitente original.
- **Descripción**: el HTML del correo (o el texto) sin el historial citado (Gmail, Yahoo, Outlook, Apple, Proton) ni la
  firma, con una línea «Correu de … · fecha».
- **Adjuntos**: pasan a la incidencia los tipos que admite Quasar (los vídeos, no: se avisa).
- Asignada a quien la crea y visible para el cliente si se ha encontrado (se puede cambiar).

El correo leído se guarda como borrador en `storage/cache/mail-drafts` (24 h, solo para quien lo subió): al revisar
solo viajan título, proyecto y prioridad, así el texto (con enlaces) no choca con el cortafuegos de Webempresa. El
tamaño máximo es el de PHP (`upload_max_filesize` y `post_max_size`). Código: `src/MailImport/EmlParser.php` (MIME sin
librerías).

## Agenda (Google Calendar del equipo)

*Agenda* (calendario del núcleo sobre las incidencias por fecha límite) muestra también el **Google Calendar** del
equipo, con sus Meet, en semana (por defecto), mes o día. Los eventos de Google son de solo lectura: al pulsarlos se
abren en Google Calendar; se crean y se cambian allí.

- **Cada persona conecta el suyo** desde su **perfil** («Connecta el meu Google Calendar», Quasar 0.24.0): se lee con
  su propio permiso, sin compartir calendarios. Quien no se ha conectado ve en la Agenda el aviso y el enlace.
- **«El meu calendari»** (por defecto) o **«Tot l'equip»** (los compañeros conectados; sus eventos privados, como
  «Ocupat»). Cada persona tiene su color en la leyenda; si no se puede leer, sale «(sense accés)».
- La cuenta de *Integracions* (la de los Meet) también sale, aunque no se conecte desde el perfil.

`src/Hooks/AgendaGoogle.php` (`"extra"` de `modules/agenda.json`). Caché de 5 minutos por calendario en
`storage/cache/agenda` (un error guardado deja de valer en cuanto ese calendario se lee bien).

1. Google Cloud Console: activar la **Google Calendar API** y añadir el ámbito `calendar.readonly` a la pantalla de
   consentimiento.
2. `config.local.php`: `'calendar' => ['equip']` dentro de `'google'` (solo el equipo y los administradores conectan el suyo).
3. *Integracions*: **Torna a connectar amb Google** (pide el permiso nuevo).
4. Cada persona del equipo: *Perfil → Google Calendar → Connecta el meu Google Calendar*.

Calendarios que no son de nadie (una sala…), leídos con la cuenta de *Integracions* y solo en «Tot l'equip»:
`'agenda' => ['calendars' => ['sala@aficat.com' => '#2563eb']]`.

## Wiki (documentación de los proyectos)

*Wiki* (página `WikiPage`) para leer la documentación de cada proyecto: a la izquierda, proyectos, secciones y páginas;
a la derecha, la página. Con buscador. Las páginas son el módulo `wiki_pages` (fuera del menú: *Totes les pàgines*,
*Nova pàgina*, *Edita*; y el botón **Wiki** de cada proyecto):

- **Per a: Client** → la ven los usuarios del cliente del proyecto (manuales de uso…). **Equip** → solo el equipo
  (accesos, despliegue, cómo está montado).
- Título, sección (agrupa en el menú), orden y contenido con formato, imágenes y *Ajuda a redactar*.
- Permisos en el servidor (`scope` de `wiki_pages`): el cliente solo ve (y solo lee) las de *Client* de su cliente;
  las internas, las de otros clientes o su ficha dan 404. El cliente de la página es siempre el del proyecto
  (`WikiPagesHooks`).

Tabla `wiki_pages`. Permisos: equip `wiki` (ver) y `wiki_pages` (todo); client `wiki` y `wiki_pages` (ver).

## Notes (recordatorios del equipo)

Botón **Nota** en la barra superior (al lado de *Imputar hores*): apunta un recordatorio en un momento, con fecha,
cliente o incidencia opcionales (dentro de una incidencia ya va enlazada a ella). *Equip → Notes*: la lista, con las
pendientes primero, para marcarlas como hechas, editarlas o eliminarlas.

Cada nota es de quien la crea (no se puede cambiar) y **solo la ve él**, también si es administrador: lo garantiza el
`where` del módulo con `{user.id}` (Quasar 0.22.0). Con **Compartida** la ve todo el equipo (temas para la reunión…).
Tabla `notes`; `NotesHooks`. Solo para el grupo `equip`.

## Salut de la web (informes SEO/GEO con Pulsar)

Las webs de los proyectos con **Informe SEO/GEO** marcado (Projectes → ficha → *Web*: el dominio y la casilla, que
solo ve el equipo) se analizan cada semana con la API de Pulsar (`~/Projects/pulsar`, `api.php`): SEO (cómo la ve
Google) y GEO (si las IA la pueden leer y citar). Cada análisis queda en `web_audits` (historial y tendencia).

- **Cliente**: tarjeta con las dos notas en *El meu espai* y la página *Salut de la web* (puntuación, categorías y los
  problemas explicados, sin las soluciones). Marca los que quiere y **«Vull que ho resolgueu»** crea una incidencia
  en ese proyecto con la lista (una a la vez mientras siga abierta). Si no tiene ninguna web con informe, la página le
  ofrece pedirlo (también crea una incidencia).
- **Equipo**: todos los proyectos con informe, las soluciones de cada punto, el informe completo en Pulsar (y PDF) y
  **Analitza ara**.

Configuración en `config.local.php` (y en el de Pulsar, el mismo token en `'api_tokens'`):

```php
'pulsar' => [
    'url' => 'https://pulsar.aficat.com/',   // donde está Pulsar
    'token' => '…',                          // mínimo 24 caracteres; aleatorio
    'days' => 7,                             // cada cuántos días se vuelve a analizar
],
```

Cron diario (de noche; analiza los que tocan): `30 3 * * * /opt/alt/php82/usr/bin/php …/tools/web-audit.php > /dev/null 2>&1`.
A mano: `php tools/web-audit.php --all` o `--project=N`. La web se envía a Pulsar sin `https://` (el tallafoc de
Webempresa bloquea URL en los parámetros). Los textos de los problemas vienen de Pulsar, en catalán.

## Claude Code (MCP): Claude lee y trabaja las incidencias y la wiki

`tools/mcp.php` es un servidor MCP local (stdio, sin dependencias; PHP con `curl`) que Claude Code arranca en el
ordenador de cada uno. Usa la API de Asteroid con el **token personal** (Perfil → API): ve y hace lo mismo que ese
usuario. Herramientas:

| Herramienta | Qué hace |
|---|---|
| `asteroid_incidencia` | la incidencia entera: datos, descripción, toda la conversación, horas imputadas y adjuntos (se descargan en `~/.cache/asteroid-mcp/incidencia-N/`; las imágenes también se le muestran a Claude) |
| `asteroid_les_meves` | las abiertas asignadas a uno |
| `asteroid_cerca` | buscar por texto |
| `asteroid_respon` | escribir en la conversación (lo recibe el cliente si la incidencia es visible para él) |
| `asteroid_imputa` | imputar horas (`1:30`, `45m`…) con comentario |
| `asteroid_estat` | pendent · en_curs · resolta · descartada |
| `asteroid_wiki` | la wiki: las páginas (de un proyecto, con un texto a buscar) por secciones, o una entera con su HTML |
| `asteroid_wiki_desa` | crear o actualizar una página de la wiki (si ya hay una con el mismo título en el proyecto, la actualiza): título, contenido en HTML, sección, orden y audiencia (`equip` por defecto, o `client`) |

Las de escritura piden confirmación (Claude Code pregunta antes de usar una herramienta, y el servidor le indica que
enseñe el texto antes). Alta, una vez por usuario (queda en `~/.claude.json`; `claude` también está dentro de la extensión de VS Code, en
`~/.vscode/extensions/anthropic.claude-code-*/resources/native-binary/claude`):

```bash
claude mcp add asteroid --scope user \
  -e ASTEROID_URL=https://sat.aficat.com \
  -- php /home/…/asteroid-quasar/tools/mcp.php
```

El token va en un fichero privado (no en la configuración ni en git):

```bash
mkdir -p ~/.config/asteroid-mcp && nano ~/.config/asteroid-mcp/token && chmod 600 ~/.config/asteroid-mcp/token
```

Uso: en VS Code, con el repositorio del cliente abierto, «*mira la incidència 4014 i proposa com arreglar-la*». Para la documentación: «*documenta a la wiki de NEUTRON com es publica una versió*» (en catalán, HTML sencillo, nunca
contraseñas ni tokens; lo dicen la herramienta y las instrucciones del servidor). Tras actualizar `tools/mcp.php`, Claude
Code carga las herramientas nuevas en una conversación nueva (o con `/mcp` → reconectar).

## API antigua (enlace con Factusol)

El programa del enlace con Factusol (un proyecto VBA) usaba la API del Asteroid antiguo (`index.php?task=api.getHours…&mode=raw`): lee las
horas con el código Factusol del cliente y las marca como facturadas. `LegacyApiPage` (módulo `api_legacy`, página
pública) la reproduce con las mismas tareas, parámetros y JSON; solo cambia la dirección:

```
https://sat.aficat.com/index.php?r=public&m=api_legacy&task=getHours&token=…&billed=2   (0 todas · 1 facturadas · 2 pendientes)
https://sat.aficat.com/index.php?r=public&m=api_legacy&task=setBilled&token=…&id=N&billed=1   (0 · 1 facturadas · 2 no facturables)
https://sat.aficat.com/index.php?r=public&m=api_legacy&task=getIssues&token=…
```

`getHours` acepta además `budget` (1 con presupuesto, 2 sin), `projectId` y `limit`; `task=api.getHours` también vale.
El `token` es un token personal (Perfil → API, `qs_…`) o el antiguo del programa, declarado en `config.local.php`:

```php
'legacy_api' => ['tokens' => ['EL_TOKEN_ANTIGUO' => 98]],   // token => id del usuario (del equipo) como el que actúa
```

En el VBA solo cambia el inicio de la dirección: `index.php?task=api.…` → `index.php?r=public&m=api_legacy&task=api.…`
(el resto de parámetros, incluido `mode=raw`, igual). El JSON es como el de antes: todo texto y los acentos escapados
(`\u00e0`). `remoteClientId` es el *Codi Factusol* de la ficha del cliente.

Ese usuario necesita permiso en las horas (ver; editar para `setBilled`) y en las incidencias. Las tareas con
`apikey` de proyecto del Asteroid antiguo (`setIssue`, `getIssuesById`…) no están: para crear incidencias desde fuera
está la API del núcleo.

## Datos fiscales de los clientes (FactuSOL)

La ficha del cliente tiene *Dades fiscals*: nombre fiscal (razón social), domicilio fiscal, código postal, población y
provincia (además del NIF, el teléfono y el correo de siempre). Sirven para los textos legales de las webs de los
clientes: `tools/legal.py` de Neutron los lee por la API (`GET /api/v1/clients?q=<NIF>`) con el token personal.

Se vuelcan desde FactuSOL exportando la tabla de clientes (F_CLI) a CSV con cabecera:

```
php tools/import-factusol.php clientes.csv > factusol.sql     # SQL para phpMyAdmin (sin SSH)
php tools/import-factusol.php clientes.csv --apply [--dry-run] # directamente, donde haya acceso a la BD
```

Columnas: `CODCLI` (código), `NIFCLI`, `NOFCLI` (nombre fiscal; si está vacío vale `NOCCLI`, el comercial), `DOMCLI`,
`CPOCLI`, `POBCLI`, `PROCLI`, `TELCLI`, `EMACLI`; separador `;` `,` o tabulador, UTF-8 o Windows-1252. Cada fila se busca
por el *Codi Factusol* y, si no, por el NIF (y entonces se apunta el código). Solo rellena lo vacío, salvo con
`--overwrite`; no crea clientes nuevos. Se puede repetir cuando haya clientes nuevos.

Las columnas las crea `tools/asteroid-migrate.php`; en phpMyAdmin, antes del volcado:

```sql
ALTER TABLE clients
  ADD COLUMN legal_name VARCHAR(150) NULL AFTER phone,
  ADD COLUMN address VARCHAR(255) NULL AFTER legal_name,
  ADD COLUMN postal_code VARCHAR(10) NULL AFTER address,
  ADD COLUMN city VARCHAR(100) NULL AFTER postal_code,
  ADD COLUMN province VARCHAR(100) NULL AFTER city;
```

## API (apps y bots)

La del núcleo (README de Quasar, «API para apps y bots»): `https://asteroid.afi.cat/api/v1/…` con un token personal.
Cada token actúa como su usuario, así que un cliente solo ve y crea sus incidencias visibles, igual que en la web.

- **Bot de Telegram**: crea un usuario para el bot (o usa el tuyo) y, en *El meu perfil → Aplicacions i API*, un
  token sin caducidad. Lo útil: `GET pages/pissarra` (las tarjetas de la Pissarra en JSON, `?worker=all|none|id`),
  `GET issues?f[status][]=1&f[status][]=2&f[worker_id][]={id}`, `GET notifications?unread=1`,
  `POST issues/{id}/messages`, `PATCH issues/{id}` (`{"status": 3}`) y `POST hours`.
- **App móvil**: `POST auth/token` con el email y la contraseña del usuario; `DELETE auth/token` al cerrar sesión.
- **Webs de clientes que creaban incidencias** (la API antigua, `setIssue` con `projects.api_key`): la nueva no es
  compatible. Hay que crear un usuario del cliente (*Clients → Usuaris de clients*), un token en su perfil y cambiar la
  web para que haga `POST /api/v1/issues` con `{"title", "project_id", "description"}` y los adjuntos con
  `POST /api/v1/issues/files/attachments`. Las claves `projects.api_key` importadas ya no se usan.

## Avisos

| Qué pasa | A quién |
|---|---|
| Un cliente crea una incidencia | al técnico asignado o, si no hay, a todo el equipo |
| Se asigna una incidencia | al técnico |
| Cambia el estado (también al imputar horas con «Estat de la incidència») | al técnico y al cliente que la abrió (o a todos los usuarios del cliente si la abrió el equipo) |
| Se hace visible para el cliente | a sus usuarios |
| Mensaje en la conversación | técnico, quien ya ha escrito, mencionados y el cliente si es visible |
| Videotrucada | a todos los participantes, por correo siempre |

## Instalación

```bash
# 1. Crea config/config.local.php (ver abajo)
php bin/install.php --no-demo --user=… --password=… --email=…
php tools/asteroid-migrate.php
php tools/import-asteroid.php --host=… --name=bd_antigua --user=… --pass=… \
    --files=/ruta/al/asteroid/admin/assets --admins=kim
```

`config/config.local.php` mínimo:

```php
<?php return [
    'app_name' => 'Asteroid',
    'login_field' => 'email',   // se entra con el correo, como en el Asteroid antiguo
    'api' => ['enabled' => true],   // API para apps y bots (ver «API» abajo)
    'home' => 'client_home',        // los usuarios de los clientes entran en «El meu espai»
    'base_url' => 'https://asteroid.afi.cat',
    'db' => ['host' => 'localhost', 'name' => '…', 'user' => '…', 'pass' => '…'],
    'mail' => ['host' => '…', 'username' => '…', 'password' => '…', 'from' => ['address' => 'suport@afi.cat', 'name' => 'Asteroid']],
    'google' => ['client_id' => '…', 'client_secret' => '…'],   // ver README de Quasar, "Videollamadas con Google Meet"
];
```

Cron: `php bin/media-clean.php` (diario) y, si `notifications.email = queue`, `php bin/notifications.php` cada 5 min.

## Videollamadas con Google Meet

Cada incidencia tiene el botón **Videotrucada** en la cabecera de la conversación: crea una sala de Google Meet, publica
el enlace en el chat (botón «Uneix-t'hi») y avisa a todos los participantes, también por correo. Meet no se puede
incrustar como Jitsi: se abre en otra pestaña. Está activado en `modules/issues.json` (`"chat": { …, "meet": true }`).

El botón no aparece hasta que la instalación está conectada a una cuenta de Google. Configuración (una sola vez):

1. **Google Cloud Console** (https://console.cloud.google.com) con la cuenta de AFI → crea un proyecto (p. ej. «Asteroid»).
2. *APIs y servicios → Biblioteca* → activa **Google Meet REST API**.
3. *APIs y servicios → Pantalla de consentimiento de OAuth*:
   - tipo **Interno** si la cuenta es de Google Workspace (solo pueden conectar usuarios de AFI; no caduca);
   - si es un Gmail personal, tipo **Externo** en modo *Prueba* y añade la cuenta como usuario de prueba (el token
     caduca a los 7 días: habrá que volver a conectar) o publica la aplicación;
   - ámbitos: `openid`, `email` y `https://www.googleapis.com/auth/meetings.space.created`.
4. *APIs y servicios → Credenciales → Crear credenciales → ID de cliente de OAuth* → tipo **Aplicación web**, con esta
   URI de redirección autorizada (tiene que coincidir exactamente con `base_url`):
   - servidor: `https://EL-DOMINIO/index.php?r=page&m=integrations&a=callback`
   - local: `http://127.0.0.1:8090/index.php?r=page&m=integrations&a=callback`
5. En `config/config.local.php`:
   ```php
   'base_url' => 'https://EL-DOMINIO',
   'google' => [
       'client_id' => '….apps.googleusercontent.com',
       'client_secret' => '…',
       'meet_access' => 'OPEN',   // los clientes (de fuera de AFI) entran con el enlace sin pedir permiso
   ],
   ```
6. Entra como administrador (kim) → *Administració → Integracions* → **Connecta amb Google** con la cuenta que
   organizará las reuniones (las salas se crean a su nombre).

Problemas habituales: `redirect_uri_mismatch` → la URI de Google no es idéntica a `base_url` + la ruta;
«No s'ha pogut crear la videotrucada» → mira `storage/logs/app-*.log` (`Google: POST …`): API sin activar o ámbito no
concedido. Si cambias `client_secret`, hay que volver a conectar (el token se guarda cifrado con él).

Código: `src/Google/GoogleClient.php` (OAuth y Meet API), `src/Pages/IntegrationsPage.php`, `ChatController::meet`.

## Importación

Repetible hasta el día del cambio (conserva ids, actualiza lo que existe y no duplica archivos). Las contraseñas bcrypt
se conservan. Resultado con la copia del 26/10/2026: 272 clientes, 238 proyectos, 18 usuarios, 4.100 incidencias
(con 111 imágenes incrustadas pasadas a archivos), 1.796 mensajes, 4.186 entradas de horas. No se importan:

- adjuntos de tipos que Quasar no admite (vídeos mp4, .sql, .php, .vb, .ai, .bmp, .ogg…) ni los que no coinciden con
  su extensión (un `.pdf` que no es PDF); el script los lista al final;
- comentarios de incidencias borradas (33) y la tabla de encuestas de satisfacción (vacía).

## Pendiente respecto al Asteroid antiguo

- Tareas (`todo`), accesos (`accesos`).

## Próximos pasos (decididos, sin empezar)

### Métricas de servicio y plazos (SLA)

Medir y comprometer cuánto se tarda en responder y en resolver. Por fases:

1. **Métricas sobre el historial** (panel del equipo; no cambia cómo se trabaja): primera respuesta (primer mensaje
   del equipo en la conversación o paso a *En curs*; se puede calcular con todo el historial de `messages`) y
   resolución (`resolved_at`), media y % dentro de plazo por mes; carga por técnico; por cliente (incidencias, horas,
   encuestas); incidencias paradas.
2. **Plazos**: horario laboral (L-V 8-18 y festivos configurables) y plazos por prioridad, mejorables por contrato
   (`contracts`). Ejemplo: pánico 1 h / 4 h, urgente 4 h / 2 días, normal 1 día / 5 días. Marca de color en la lista y
   la Pissarra (verde, naranja < 25 % del plazo, rojo vencida) y avisos (técnico; tema Urgent de Telegram si vence).
3. **Estado «Esperant el client»**: para el reloj; recordatorio al cliente y cierre automático si no contesta.
4. **Informe para el cliente**: tarjeta en *El meu espai* y correo mensual (incidencias, primera respuesta media,
   % en plazo, horas, valoración). Base para vender planes de soporte con niveles.

### Notas

Hecho: botón rápido, lista, hechas, compartidas y `/nota` · `/notes` en Telegram. Falta:

- **Convertir en incidencia**: botón en la nota que crea la incidencia (proyecto «AFI intern» para las internas) con
  el texto y la deja enlazada.
- **Recordatorio**: el día de `due_on`, aviso en Asteroid (y por Telegram, al usuario vinculado). Cron diario o el de
  `bin/notifications.php`.
