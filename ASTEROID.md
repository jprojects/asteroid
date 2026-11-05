# Asteroid (sobre Quasar)

Gestor de incidencias de AFI, migrado de Franky CMS a Quasar (núcleo actual: 0.16.0, ver `VERSION`). El `README.md`
es el del núcleo (Quasar) y se sustituye con cada actualización; lo propio de Asteroid está aquí.

**Cambios en el núcleo**: se hacen en el repositorio de Quasar y se publica una versión; Asteroid se actualiza desde
ella con `php tools/update-core.php X.Y.Z` (en el servidor, desde Cosmo). Si se cambia un fichero del núcleo solo aquí,
la siguiente actualización lo pisa: `php tools/check-core.php` comprueba que el núcleo es idéntico al de Quasar.

## Qué es de Asteroid (no lo toca una actualización de Quasar)

| | |
|---|---|
| `modules/` | `issues`, `pissarra`, `agenda`, `surveys`, `survey`, `pending_billing`, `client_home`, `telegram`, `telegram_hook`, `webchat`, `webchat_settings`, `webchat_widget`, `web_audit`, `api_legacy`, `notes`, `clients`, `projects`, `client_users`, `folders`, `files`, `hours`, `contracts`, `monthly_hours`, `billing` |
| `src/Pages/`, `templates/default/pages/` | `PissarraPage`, `SurveyPage`, `PendingBillingPage`, `ClientHomePage`, `TelegramPage` y `TelegramHookPage` con sus plantillas (`pissarra.php`, `survey.php`, `pending_billing.php`, `client_home.php`, `telegram.php`) (con sus estilos) |
| `src/Webchat/` | chat web para las webs de los clientes: utilidades, `loader.js` (botón en su web) y `widget.js` (el chat) |
| `src/Telegram/` | cliente de la Bot API y avisos al grupo (sin librerías) |
| `src/Hooks/` | `Asteroid` (utilidades), `Survey` (encuestas), `IssuesHooks`, `HoursHooks`, `BillingHooks`, `FilesHooks`, `ClientUsersHooks` |
| `lang/{ca,es,en}/` | un fichero por módulo y `app.json` (grupos del menú: Suport, Clients, Equip) |
| `database/asteroid.sql` | tablas del proyecto |
| `tools/check-core.php`, `tools/update-core.php` | comprobar que el núcleo no se ha tocado y traer el de una versión de Quasar |
| `tools/asteroid-migrate.php` | crea o pone al día las tablas, `users.client_id` y los grupos con sus permisos |
| `tools/import-asteroid.php` | importa los datos del Asteroid antiguo |
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

**Avisos al grupo** (y tema): incidencias nuevas y mensajes de las conversaciones; opcionalmente cambios de estado
(`issue.status`) o solo las de pánico (`issue.panic`). Llevan el cliente, el proyecto, el técnico y el enlace.

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
       'events' => ['issue.new', 'issue.message'],      // + 'issue.status' | 'issue.panic'
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

## Claude Code (MCP): Claude lee y trabaja las incidencias

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

Las de escritura piden confirmación (Claude Code pregunta antes de usar una herramienta, y el servidor le indica que
enseñe el texto antes). Alta, una vez por usuario (queda en `~/.claude.json`, fuera de los repositorios):

```bash
claude mcp add asteroid --scope user \
  -e ASTEROID_URL=https://sat.aficat.com -e ASTEROID_TOKEN=qs_… \
  -- php /home/…/asteroid-quasar/tools/mcp.php
```

El token va en un fichero privado (no en la configuración ni en git):

```bash
mkdir -p ~/.config/asteroid-mcp && nano ~/.config/asteroid-mcp/token && chmod 600 ~/.config/asteroid-mcp/token
```

Uso: en VS Code, con el repositorio del cliente abierto, «*mira la incidència 4014 i proposa com arreglar-la*».

## API antigua (enlace con Factusol)

El programa del enlace con Factusol usaba la API del Asteroid antiguo (`admin/index.php?view=api&task=…`): lee las
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

Ese usuario necesita permiso en las horas (ver; editar para `setBilled`) y en las incidencias. Las tareas con
`apikey` de proyecto del Asteroid antiguo (`setIssue`, `getIssuesById`…) no están: para crear incidencias desde fuera
está la API del núcleo.

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
