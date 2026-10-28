# Asteroid (sobre Quasar)

Gestor de incidencias de AFI, migrado de Franky CMS a Quasar 0.7.0. El `README.md` es el del núcleo (Quasar) y se
sustituye con cada actualización; lo propio de Asteroid está aquí.

## Qué es de Asteroid (no lo toca una actualización de Quasar)

| | |
|---|---|
| `modules/` | `issues`, `pissarra`, `agenda`, `clients`, `projects`, `client_users`, `folders`, `files`, `hours`, `contracts`, `monthly_hours`, `billing` |
| `src/Pages/`, `templates/default/pages/` | `PissarraPage` y su plantilla `pissarra.php` (con sus estilos) |
| `src/Hooks/` | `Asteroid` (utilidades), `IssuesHooks`, `HoursHooks`, `BillingHooks`, `FilesHooks`, `ClientUsersHooks` |
| `lang/{ca,es,en}/` | un fichero por módulo y `app.json` (grupos del menú: Suport, Clients, Equip) |
| `database/asteroid.sql` | tablas del proyecto |
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

- Encuestas de satisfacción al resolver (`onSendSurvey`), tareas (`todo`), accesos (`accesos`).
