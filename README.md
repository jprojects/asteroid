# Quasar

CMS ligero para aplicaciones de gestión tipo escritorio basadas en dos vistas:
**tablas de datos** y **formularios**. PHP 8.1+, MySQL/MariaDB y JavaScript sin dependencias.

Cada pantalla se define en un JSON; no hace falta escribir PHP para añadir un mantenimiento nuevo.

## Instalación

```bash
# 1. Credenciales de BD (solo las claves que cambian)
cat > config/config.local.php <<'PHP'
<?php return ['db' => ['user' => 'quasar', 'pass' => 'secreto'], 'debug' => true];
PHP

# 2. Crea la BD, las tablas, los datos de ejemplo y el administrador
php bin/install.php                 # --no-demo para omitir clientes/países de ejemplo
                                    # también actualiza una BD de una versión anterior (añade columnas y tablas)
                                    # (necesita permiso de escritura en storage/uploads)

# 3. Arranca (en producción apunta el DocumentRoot a public/)
php -S localhost:8000 -t public
```

Requiere las extensiones `pdo_mysql`, `mbstring` y `json`.

## Estructura

```
config/            config.php (+ config.local.php opcional, no versionar)
modules/           un JSON por módulo  →  modules/clients.json = módulo "clients"
lang/{es,en}/      common.json + un JSON por módulo/vista
templates/default/ plantilla (PHP + assets). Cambiable con 'template' en config
src/Core/          núcleo: App, Auth, User, Permissions, Lang, View, Module, DataQuery, FormHandler
src/Fields/        un fichero por tipo de campo
src/Hooks/         lógica propia de módulos (opcional)
src/Export/        generadores XLSX y PDF (sin dependencias)
src/Pages/         controladores de páginas personalizadas (calendario incluido)
src/Mail/          correo: mensaje MIME, cliente SMTP, fachada Mail
src/Remote/        API remota para Cosmo: copias, actualizaciones, log, mantenimiento
src/Ai/            filtrar con lenguaje natural: proveedor de IA, consumo y límites
src/Reports/       informes con lenguaje natural: esquema, validación y ejecución de solo lectura
src/Google/        conexión con Google (OAuth) y salas de Google Meet
storage/logs/      ficheros de log
storage/backups/   copias de seguridad (API remota)
storage/remote/    estado de la API remota: manifiesto, mantenimiento, historial
public/index.php   único punto de entrada
VERSION            versión del núcleo (ver "Versiones")
core.json          qué ficheros son del núcleo (los que sustituye una actualización remota)
database/          schema.sql (núcleo) y demo.sql
```

## Definición de un módulo

```jsonc
// modules/products.json
{
  "table": "products",
  "primaryKey": "id",                 // por defecto "id"
  "title": "title",                   // clave de idioma; por defecto "title"
  "adminOnly": false,                 // true = solo administradores
  "actions": ["view", "create", "edit", "delete"],  // acciones sujetas a permiso
  "hooks": "Quasar\\Hooks\\ProductsHooks",          // opcional
  "menu": { "group": "main", "order": 30, "icon": "package", "visible": true },   // icono: ver "Iconos"

  "fields": {
    "id":    { "type": "number", "readonly": true, "grouping": false },
    "name":  { "type": "text", "required": true, "maxlength": 120, "width": "half" },
    "price": { "type": "number", "decimals": 2, "min": 0, "width": "quarter" },
    "family_id": { "type": "select", "source": { "table": "families", "value": "id", "label": "name" } }
  },

  "list": {
    "columns": [{ "field": "id", "width": "70px" }, "name", { "field": "price", "align": "right" },
                { "field": "notes", "overflow": "wrap", "lines": 3 }],     // ver "Textos largos en las tablas"
    "expand": ["notes", "contacts"],                      // filas desplegables (ver "Filas desplegables")
    "defaultSort": { "field": "name", "dir": "asc" },
    "pageSize": 25,
    "pageSizes": [10, 25, 50, 100],
    "search": ["name"],                                   // buscador global (siempre visible)
    "filtersOpen": false,                                 // panel de filtros desplegado al entrar (por defecto plegado)
    "filters": [
      { "field": "family_id" },                           // operador por defecto según tipo
      { "field": "price", "operator": "between" }         // like | = | between
    ],
    "rowActions": [{ "label": "actions.prices", "url": "index.php?r=...&id={id}", "icon": "receipt-euro" }],
    "toolbar": [{ "label": "actions.recalc", "icon": "refresh-cw", "action": "recalc" }]  // ver "Botones y acciones propias"
  },

  "form": {
    "sections": [
      { "title": "sections.general", "fields": ["name", "family_id", "price"] }
    ]
    // o simplemente: "fields": ["name", "family_id", "price"]
  }
}
```

Crea la tabla en MySQL, añade `lang/es/products.json` con `title` y `fields.*`, y el módulo aparece en el
menú, en la matriz de permisos y con su tabla y formulario funcionando.

### Tipos de campo

| tipo       | opciones específicas                                       | filtro por defecto |
|------------|------------------------------------------------------------|--------------------|
| `text`     | `maxlength`, `minlength`, `pattern` (sin anclas), `patternMessage` | `like`     |
| `email`    | igual que text + validación de email                        | `like`             |
| `password` | `minlength` (8). Se guarda con `password_hash`; vacío al editar = no cambiar | —   |
| `number`   | `decimals`, `min`, `max`, `step`, `grouping`                | `=`                |
| `textarea` | `rows`, `maxlength`, `listLength` (recorte en tabla)        | `like`             |
| `select`   | `options` `{valor: clave_idioma}` o `source` `{table, value, label, order}`, `placeholder`, `search`, `multiple`, `maxItems` | `=` (con `multiple`: contiene el valor) |
| `tags`     | `maxTags`, `maxLength`, `lowercase`, `suggestions` (lista o `"existing"`), `colors` | `like` dentro de las etiquetas |
| `checkbox` | guarda 0/1                                                  | `=` (Todos/Sí/No)  |
| `date`     | `min`, `max`                                                | `between`          |
| `datetime` |                                                             | `between`          |
| `hidden`   |                                                             | —                  |
| `repeater` | `fields` (subcampos), `layout` (`table`\|`block`), `min`, `max`, `addLabel`, `summary` | `like` sobre el JSON |
| `media`    | `multiple`, `maxFiles`, `maxSize` (`"5M"`), `accept` (`["image/*", ".pdf"]`) | —            |
| `duration` | `min`, `max` (minutos), `bare` (`hours`\|`minutes`), `decimal`. Se escribe `1:30`, `1h30`, `45m` o `1,5`; se guarda en minutos (INT) | `between` |
| `richtext` | `rows`, `images`, `maxSize`, `listLength`. Texto con formato (HTML limpio) con editor propio | `like` (sobre el HTML) |

Opciones comunes: `label`, `help`, `column` (si la columna de BD tiene otro nombre), `required`,
`requiredOnCreate`, `readonly`, `unique`, `default`, `placeholder`, `autocomplete`,
`width` (`full`, `two-thirds`, `half`, `third`, `quarter`), `virtual` (no existe en la tabla),
`access` y `editAccess` (ver "Acceso por grupos").

### Repeater

Filas dinámicas con sus propios subcampos (cualquier tipo salvo `password`, incluidos `media` y otros
repeaters). Se guarda como JSON en **una columna** (`JSON` o `TEXT`) de la misma tabla.

```jsonc
"contacts": {
  "type": "repeater",
  "layout": "table",          // table: una línea por fila · block: rejilla con anchos, como el formulario
  "min": 0, "max": 10,
  "summary": "name",          // qué subcampo se muestra en la tabla (si no, "N elementos")
  "addLabel": "repeater.add_contact",
  "fields": {
    "name":  { "type": "text", "label": "contact.name", "required": true },
    "email": { "type": "email", "label": "contact.email" },
    "main":  { "type": "checkbox", "label": "contact.main" }
  }
}
```

Las filas se añaden, quitan y reordenan (arrastrando por ⠿ o con ↑↓) en el navegador; los errores de
validación se muestran en la fila y el subcampo exactos.

### Media (dropzone)

```jsonc
"logo":      { "type": "media", "accept": ["image/*"], "maxSize": "2M" },              // columna INT NULL
"documents": { "type": "media", "multiple": true, "maxFiles": 10, "maxSize": "10M" }   // columna JSON/TEXT
```

- Los archivos se suben al momento por AJAX (con barra de progreso) a `index.php?r=upload` y se
  guardan en `uploads_dir` (por defecto `storage/uploads/`, **fuera de `public/`**) con nombre aleatorio.
  El registro solo guarda los ids de la tabla `media`.
- Tipos permitidos: imágenes (jpg, png, gif, webp), pdf, txt, csv, office/odf y zip. Se valida el tipo
  **real** del contenido con `fileinfo`, no la extensión; SVG y HTML están prohibidos.
- Se sirven por `index.php?r=media&id=N` solo a quien puede ver el módulo propietario.
- Si PHP tiene GD se generan miniaturas; si no, se muestra la imagen original reducida.
- Revisa `upload_max_filesize` y `post_max_size` de PHP: deben ser ≥ que el `maxSize` mayor.
- Los archivos que se suben y nunca se guardan, o que pertenecen a registros borrados, se eliminan con
  `php bin/media-clean.php` (programarlo en cron; `--dry-run` para ver qué borraría).
- Para importar archivos que ya están en el disco (migraciones de otra aplicación):
  `$app->media()->importFile($ruta, $nombre, $modulo, $campo, $userId, $fecha)` hace las mismas comprobaciones del
  tipo real y crea la miniatura (copia el original).

### Texto con formato (richtext)

```jsonc
"description": { "type": "richtext", "rows": 10, "images": true, "maxSize": "10M" }   // columna TEXT/MEDIUMTEXT
```

- Editor propio sin librerías: negrita, cursiva, subrayado, listas, cita, enlaces e imágenes (con `images`: pegar,
  arrastrar o elegir; se suben como archivos de Quasar y se sirven por `index.php?r=media`). Pegar texto de otra
  aplicación pega solo el texto.
- El servidor guarda **HTML limpio** (`Quasar\Core\HtmlSanitizer`): una lista cerrada de etiquetas, enlaces
  http/https/mailto/internos e imágenes solo de `index.php?r=media`. Se reconstruye nodo a nodo: `script`, `style`,
  atributos `on…`, `javascript:` o imágenes externas nunca llegan a la BD.
- En la tabla, el detalle desplegable y las exportaciones se usa el texto sin formato.

### Duración (duration)

```jsonc
"minutes": { "type": "duration", "required": true, "min": 1 }      // columna INT (minutos)
```

Se escribe como `1:30`, `1h30`, `2h`, `45m` o `1,5` (horas con decimales; con `"bare": "minutes"`, un número solo son
minutos) y se muestra como `1:30` (o `1,50 h` con `"decimal": true`). Exporta a Excel como duración real (`[h]:mm`).

### Select con buscador

Los selects con más de 10 opciones muestran un buscador (como select2, sin librerías): se escribe para filtrar
(sin distinguir mayúsculas ni acentos), ↑ ↓ Enter para elegir, Esc para cerrar y un botón para vaciar. También en
los filtros de las tablas. El `<select>` original sigue debajo, así que validación, `showon` y envío no cambian.

```jsonc
"country_id": { "type": "select", "source": { … }, "search": true },       // forzar el buscador
"status":     { "type": "select", "options": { … }, "search": false },     // forzar select normal
"client_id":  { "type": "select", "source": { "table": "clients", "value": "id", "label": "name" },
                "search": "remote" }                                       // tablas grandes: busca en el servidor
```

Con `"remote"` la página no carga todas las opciones: solo la elegida, y el resto se busca mientras se escribe
(`index.php?r=options`, máximo 30 resultados).

### Select múltiple

```jsonc
"sectors": { "type": "select", "multiple": true, "maxItems": 3,
             "options": { "industry": "sector.industry", "services": "sector.services" } },
"groups":  { "type": "select", "multiple": true, "source": { "table": "user_groups", "value": "id", "label": "name" } }
```

- Se guarda como lista JSON de valores (`["industry","services"]`) en una columna `JSON`/`TEXT`. Al leer
  también acepta texto separado por comas (`1,4,7`), útil al migrar datos de aplicaciones antiguas.
- En el formulario las opciones elegidas son etiquetas: se escribe para filtrar, ↑ ↓ Enter para marcar o
  desmarcar (la lista sigue abierta para elegir varias), retroceso quita la última y Esc cierra. Admite
  `"search": "remote"` y `required` (al menos una).
- En la tabla se ven las etiquetas separadas por comas, o una etiqueta de color por valor si el campo tiene
  `colors`. No se puede ordenar por él. El filtro (un valor) encuentra los registros que lo contienen.
- `showon` funciona igual: `"sectors:industry"` se cumple si `industry` está entre los elegidos.

### Opciones de otra tabla: filtro y registros visibles

```jsonc
"worker_id":  { "type": "select", "source": { "table": "users", "value": "id", "label": "name", "where": "users.active = 1" } },
"project_id": { "type": "select", "source": { "module": "projects", "value": "id", "label": "title" }, "search": "remote" }
```

- `source.where`: condición SQL fija (del JSON) sobre la tabla de origen.
- `source.module`: la tabla de ese módulo y **solo los registros que el usuario ve en él** (su `scope` y `where`, ver
  "Registros visibles por usuario"). Se aplica en las opciones, en la búsqueda remota y en la validación: un usuario no
  puede guardar un valor que no podría elegir.
- `source.order` + `source.dir` (`asc`|`desc`): orden de las opciones (p. ej. las incidencias más recientes primero).

**Selects dependientes** (las incidencias del proyecto elegido, los proyectos del cliente elegido):

```jsonc
"issue_id":   { "type": "select", "source": { "table": "issues", "value": "id", "label": "title" },
                "dependsOn": { "field": "project_id", "column": "project_id" } },
"project_id": { "type": "select", "source": { "module": "projects", "value": "id", "label": "title" },
                "dependsOn": { "field": "client_id", "column": "client_id", "form": false } }   // solo en los filtros
```

- Las opciones son las filas de la tabla de origen cuya `column` coincide con lo elegido en `field` (en la ficha o en
  el panel de filtros); sin nada elegido, todas. Siempre buscan en el servidor.
- Al cambiar `field`, si el valor elegido ya no corresponde, se vacía. En la ficha, además, se valida al guardar.
- `"form": false` / `"filters": false` lo limita a los filtros o a la ficha (p. ej. si en la ficha `field` es de solo
  lectura).

### Campo de etiquetas (tags)

```jsonc
"tags": { "type": "tags", "suggestions": "existing", "maxTags": 8, "maxLength": 30,
          "colors": { "urgent": "red", "*": "blue" } }
```

- Se escribe y se pulsa Enter, coma o Tab; retroceso borra la última; pegar `a, b, c` crea tres. No admite
  duplicadas (sin distinguir mayúsculas). Enter nunca envía el formulario desde este campo.
- `suggestions`: lista fija `["vip", "export"]` o `"existing"` (las ya usadas en otros registros, las más frecuentes
  primero). Una sugerencia se elige con las flechas; Enter sin marcarla añade lo escrito.
- Se guarda como JSON en una columna `JSON`/`TEXT`. En el listado se ve una etiqueta de color por valor; el filtro
  y la búsqueda encuentran registros por sus etiquetas.

### Etiquetas de color en las tablas

Cualquier campo puede mostrarse en el listado como una etiqueta de color según su valor:

```jsonc
"status": { "type": "select", "options": { … },
            "colors": { "lead": "blue", "active": "green", "inactive": "gray" } },
"vip":    { "type": "checkbox", "colors": { "1": "amber" } },          // "No" queda como texto normal
"risk":   { "type": "select", "compute": "riskLevel", "options": { … },
            "colors": { "high": "red", "*": "gray" } }                  // "*" = resto de valores
```

- Colores: `gray`, `blue`, `green`, `amber`, `orange`, `red`, `purple`, `pink`, `cyan`, `teal` (adaptados al tema
  claro y oscuro) o un hexadecimal `"#c8102e"`. Un nombre desconocido da error al cargar el módulo.
- Funciona con cualquier tipo de campo, también los calculados (`sql` / `compute`).
- El calendario usa los mismos colores si su campo `color` los define.
- Desde JavaScript propio: `Quasar.tag('Texto', 'green')` devuelve la etiqueta.
- En las exportaciones a Excel y PDF el valor sale como texto.

### Textos largos en las tablas

Por defecto una celda ocupa una línea y el texto que no cabe se corta con «…» (el texto entero sale al
pasar el ratón). Por columna:

```jsonc
"columns": [
  { "field": "description", "overflow": "ellipsis", "maxWidth": "240px" },  // una línea con «…» (por defecto)
  { "field": "notes",       "overflow": "wrap" },                           // texto entero, en varias líneas si no cabe
  { "field": "notes",       "overflow": "wrap", "lines": 3 },               // como máximo 3 líneas y «…»
  { "field": "address",     "overflow": "wrap", "minWidth": "180px" }       // ancho mínimo para que no quede estrecha
]
// "list": { "overflow": "wrap" }   cambia el valor por defecto de todas las columnas del módulo
```

- Con `wrap` se respetan los saltos de línea del texto y un `textarea` se muestra entero (sin `listLength`).
- `minWidth` / `maxWidth` / `width` admiten cualquier medida CSS (`"200px"`, `"20em"`).
- Las exportaciones a Excel incluyen siempre el texto entero; en PDF va en una línea.

### Filas desplegables

Una flecha al principio de la fila despliega debajo los campos indicados, con su etiqueta y el texto entero
(saltos de línea incluidos). Útil para notas, observaciones o contactos que no caben en una columna.

```jsonc
"list": {
  "expand": ["notes", "contacts", "sectors"]
  // o con etiqueta propia: "expand": { "fields": ["notes", { "field": "contacts", "label": "expand.people" }] }
}
```

- Solo las filas con contenido en alguno de esos campos tienen flecha. La flecha de la cabecera despliega o
  pliega todas las de la página.
- Teclado: con la fila seleccionada, → despliega y ← pliega.
- Las filas desplegadas siguen así al cambiar de página, ordenar o filtrar (mientras no se recargue la página).
- Admite cualquier tipo de campo (también calculados, repeaters y etiquetas) salvo contraseñas, y no hace
  falta que el campo esté en `columns`.

### Totales al pie de la tabla

```jsonc
"list": { "totals": ["minutes", "cost"] }
```

Una fila de pie con la suma de esas columnas para **todos los registros filtrados** (no solo la página), formateada
como el campo. Solo campos `number` o `duration` que existan en SQL (también calculados con `sql`).

### Campos condicionales (showon)

Un campo puede mostrarse solo según el valor de otro campo del formulario:

```jsonc
"credit_limit": { "type": "number", "showon": "status!:lead" },
"since":        { "type": "date",   "showon": "status:active,inactive" },
"vat":          { "type": "text",   "required": true, "showon": "kind:company" }
```

| Condición | Se muestra si… |
|---|---|
| `vip:1` | `vip` vale 1 (un checkbox vale `1` o `0`) |
| `status:active,inactive` | `status` es alguno de esos valores |
| `status!:lead` | `status` **no** es `lead` |
| `email:` / `email!:` | `email` está vacío / no está vacío |
| `vip:1[AND]status:active` | se cumplen todas (`[AND]` tiene prioridad sobre `[OR]`) |
| `vip:1[OR]credit_limit!:` | se cumple alguna |

- Funciona en el navegador al momento y también en el servidor: un campo oculto **no se valida** (un obligatorio
  oculto no impide guardar) y **no se guarda** (conserva el valor que tenía).
- Las dependencias en cadena se resuelven solas: si C depende de B y B de A, al ocultarse A se ocultan B y C.
- Una sección cuyos campos quedan todos ocultos se oculta también.
- Dentro de un repeater la condición se refiere a otro subcampo **de la misma fila**.
- Si la condición menciona un campo que no existe, el módulo da un error claro al cargar.

### Campos calculados

Cualquier campo puede mostrar un valor calculado en lugar de una columna. Se formatea según su `type`
(número con decimales, fecha, select con etiquetas…), es de solo lectura y nunca se guarda.

```jsonc
// 1) "sql": expresión SQL. Se puede ordenar, filtrar, buscar y exportar como cualquier columna.
//    Para referirte a la tabla del módulo usa su nombre (clients.id).
"contacts_count": { "type": "number", "sql": "COALESCE(JSON_LENGTH(clients.contacts), 0)" },
"country_code":   { "type": "text",   "sql": "SELECT c.code FROM countries c WHERE c.id = clients.country_id" },
"pending":        { "type": "number", "decimals": 2,
                    "sql": "SELECT SUM(i.total) FROM invoices i WHERE i.client_id = clients.id AND i.paid = 0" },

// 2) "compute": función PHP para lógica que no cabe en SQL. Método de la clase hooks del módulo
//    (o "Clase::metodo" estático) que recibe la fila completa y devuelve el valor.
"credit_band": { "type": "select", "compute": "creditBand",
                 "options": { "high": "credit_band.high", "medium": "credit_band.medium", "low": "credit_band.low" } }
```

```php
// src/Hooks/ClientsHooks.php
public function creditBand(array $row): ?string
{
    return (float) $row['credit_limit'] >= 50000 ? 'high' : 'low';
}
```

- Se usan como cualquier campo: en `list.columns`, en `export.columns` y en el formulario (solo lectura).
- `compute` se ejecuta en PHP **una vez por fila**: no se puede ordenar ni filtrar por él (si se intenta, el
  módulo da un error claro al cargar). Para totales o recuentos sobre otras tablas usa `sql`, que es una
  sola consulta.
- La expresión `sql` se inserta tal cual en la consulta: es configuración del desarrollador; nunca debe
  construirse con datos del usuario.

### Crear un tipo de campo propio

```php
// src/Fields/ColorField.php
namespace Quasar\Fields;

final class ColorField extends TextField
{
    protected string $inputType = 'color';

    protected function validateValue(mixed $value): array
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $value) ? [] : [$this->ctx->t('validation.pattern')];
    }
}
```

Y regístralo en `config.php`: `'field_types' => ['color' => Quasar\Fields\ColorField::class]`.
Un campo puede redefinir `render`, `renderFilter`, `fromRequest`, `validateValue`, `toDb`, `fromDb`,
`format` y `filterClause`. Si su HTML es complejo, puede pintarlo con un parcial de la plantilla mediante
`$this->ctx->render('fields/mi_tipo', [...])` (así lo hacen `repeater` y `media`).

### Hooks

Para lógica que no cabe en el JSON, extiende `Quasar\Core\ModuleHooks` (`beforeSave`, `afterSave`,
`beforeDelete`, `afterDelete`). Los `before*` devuelven una clave de idioma para cancelar la operación.
Ejemplo: [src/Hooks/UsersHooks.php](src/Hooks/UsersHooks.php) impide que un admin se desactive o se borre a sí mismo.
La misma clase contiene las funciones de los campos `compute` y las acciones de los botones propios.
En `beforeSave`/`afterSave`, `$this->values` tiene los valores validados del formulario, incluidos los campos
`"virtual": true` (que no se guardan): útil para un campo que solo sirve para disparar algo al guardar.
`chatRecipients()` añade destinatarios a los avisos de la conversación (ver "Conversación en la ficha").

## Personalizar un cliente sin tocar el núcleo

Cada cliente suele querer alguna particularidad. Todo esto se hace con ficheros **del proyecto**, que una
actualización (`core.json`) nunca sobrescribe:

| Necesidad | Cómo | Dónde |
|---|---|---|
| Un mantenimiento nuevo | Módulo JSON | `modules/*.json`, `lang/*/` |
| Lógica al guardar o borrar | Hooks `beforeSave`, `afterSave`… | `src/Hooks/` |
| Un botón con una tarea (barra, fila o ficha) | `toolbar`, `rowActions`, `form.buttons` con `action` | JSON + `src/Hooks/` |
| Un botón que abre otra pantalla o URL | El mismo botón con `url` | JSON |
| Filtrar un listado escribiendo en lenguaje natural | `"list": { "ai": … }` + `ai` en la config | JSON + `config.local.php` |
| Informes a medida pidiéndolos en lenguaje natural | `reports` en la config (tablas permitidas) + permisos | `config.local.php` |
| Un informe fijo (siempre la misma consulta) | Una vista de MySQL + un módulo con `"actions": ["view", "export"]` | BD + `modules/*.json` |
| Ocultar el botón Excel o PDF | `"list": { "export": { "formats": ["pdf"] } }` o `"export": false` | JSON |
| Ocultar Nuevo, Eliminar o Exportar a algunos usuarios | Matriz de permisos (por usuario o grupo) | Administración |
| Un campo o botón solo para algunos grupos | `access` / `editAccess` | JSON |
| Que cada usuario vea solo sus registros (sus clientes, su delegación…) | `scope` (y `where` para todos) | JSON |
| Un módulo en el menú solo para algunos grupos | `"menu": { "access": [...] }` | JSON |
| Una conversación con avisos en cada registro | `"chat": true` | JSON (+ hooks) |
| Avisar a usuarios de algo (campana y correo) | `Notify::send()` | `src/Hooks/` |
| Textos propios compartidos (grupos del menú…) | `lang/{idioma}/app.json` | `lang/*/` |
| Un valor calculado | Campo con `sql` o `compute` | JSON (+ hooks) |
| Una pantalla propia (panel, asistente, informe) | Página personalizada | `modules/*.json`, `src/Pages/`, `templates/default/pages/` |
| Un tipo de campo nuevo | Clase que extiende `Field` + `field_types` en la config | `src/Fields/` (fichero nuevo), `config.local.php` |
| Cambiar el HTML de un listado o ficha concretos | `templates/default/modules/{módulo}/list.php` o `form.php` | plantilla (último recurso: es una copia y no recibe las mejoras del núcleo) |

## Botones y acciones propias

Se pueden añadir botones en la **barra de la tabla** (`list.toolbar`), en **cada fila** (`list.rowActions`) y en la
**ficha** de un registro ya guardado (`form.buttons`). Cada botón es un enlace (`url`) o una **acción**
(`action`): un método PHP de la clase hooks del módulo que se ejecuta por POST.

```jsonc
// modules/clients.json
"actions": ["view", "create", "edit", "delete", "export", "duplicate"],   // "duplicate": permiso propio
"list": {
  "toolbar": [
    { "label": "actions.summary", "icon": "chart-pie", "action": "summary" }
  ],
  "rowActions": [
    { "label": "actions.vcard", "icon": "contact", "action": "vcard", "download": true, "showon": "email!:" },
    { "label": "actions.duplicate", "icon": "copy", "action": "duplicate", "showon": "status!:inactive",
      "confirm": "confirm.duplicate", "access": { "permission": "duplicate" } },
    { "label": "actions.prices", "icon": "receipt-euro", "url": "index.php?r=list&m=prices&f[client_id]={id}" }
  ]
},
"form": {
  "buttons": [ { "label": "actions.duplicate", "icon": "copy", "action": "duplicate", "variant": "primary" } ]
}
```

```php
// src/Hooks/ClientsHooks.php
use Quasar\Core\Action;
use Quasar\Core\ActionResult;

public function summary(Action $a): string
{
    $n = $this->db->fetchValue("SELECT COUNT(*) FROM clients WHERE status = 'active'");
    return "$n clientes activos";                               // aviso; la tabla se recarga
}

public function duplicate(Action $a): ActionResult
{
    $row = $this->db->fetch('SELECT * FROM clients WHERE id = ?', [$a->id]);
    unset($row['id'], $row['created_at']);
    $newId = $this->db->insert('clients', ['email' => null] + $row);
    return ActionResult::redirect($a->editUrl($newId), $a->t('flash.duplicated'));
}

public function vcard(Action $a): ActionResult
{
    if (!$a->row['email']) {
        return ActionResult::error('Este cliente no tiene email');
    }
    return ActionResult::downloadData("BEGIN:VCARD\r\n…", 'contacto.vcf', 'text/vcard');
}
```

Opciones del botón:

| Opción | |
|---|---|
| `label`, `icon` | Clave de idioma e icono Lucide (en las filas solo se ve el icono; el texto sale al pasar el ratón) |
| `action` / `url` | Uno de los dos. En `url`, `{id}` se sustituye por el registro (filas y ficha) |
| `confirm` | Clave de idioma: pide confirmación antes de ejecutar |
| `variant` | `default`, `primary` o `danger` (la confirmación de `danger` es roja) |
| `download` | La acción devuelve un fichero (`ActionResult::download…`) |
| `access` | Grupos o permiso que pueden usarlo (ver "Acceso por grupos") |
| `showon` | Solo filas y ficha: el botón solo aparece en los registros que cumplen la condición (misma sintaxis que en los campos) |

El método recibe un `Quasar\Core\Action`: `$a->id` y `$a->row` (el registro con los mismos valores que el
formulario; `null` en la barra), `$a->user`, `$a->db`, `$a->module`, `$a->t()`, `$a->url()`, `$a->editUrl()`,
`$a->listUrl()`. Puede devolver:

| Devuelve | Resultado |
|---|---|
| un texto, o `ActionResult::success('…')` | Aviso; en la tabla se recargan los datos y en la ficha se recarga la página |
| `null` | Igual, sin aviso |
| `ActionResult::error('…')` | Ventana de error; no se recarga nada |
| `ActionResult::redirect($url, '…')` | Va a esa página y muestra el aviso al llegar |
| `ActionResult::download($ruta, $nombre)` o `downloadData($contenido, $nombre, $mime)` | Descarga (necesita `"download": true`) |

- Se ejecuta por POST con el token CSRF. Antes de llamar al método, el núcleo comprueba la sesión, el permiso
  *Ver* del módulo, el `access` del botón y, en filas y ficha, que el registro existe y cumple su `showon`. Si el
  botón no se le muestra a un usuario, ese usuario tampoco puede ejecutar la acción llamando a la URL.
- Para limitar una tarea delicada, declara una acción propia en `actions` (`"duplicate"`): aparece como columna
  en la matriz de permisos (su etiqueta es `perm.duplicate` del idioma del módulo) y se usa con
  `"access": { "permission": "duplicate" }`.
- Un nombre de acción que no exista en la clase hooks, o que esté a la vez en la barra y en las filas, da un
  error al cargar el módulo. Cada ejecución queda en el log.
- Desde JavaScript propio: los botones son `<button data-qs-action='{…}'>` y la ruta es
  `POST index.php?r=action&m={módulo}&a={acción}` con `_id` y `_csrf`.

### Tareas por URL (sin botón de acción)

Una tarea también puede estar en una **página personalizada** con un `actionX()` y llamarse por GET desde un botón
con `url` (`index.php?r=page&m=tareas&a=duplicarCliente&id={id}`). Ver "Páginas personalizadas". Al terminar,
redirige con `$this->app->redirect()` para que recargar la página no la repita.

Ojo: una URL GET se ejecuta con solo abrirla, sin confirmación, también si alguien envía el enlace por correo a
un usuario con la sesión abierta. Úsalo solo para tareas que consultan o generan algo (un informe, un PDF). Para
las que cambian datos o envían correos, usa una acción (POST, con `confirm`) o pide `$this->requirePost()` en la página.

## Páginas personalizadas

Para lo que no encaja en listado + formulario (un calendario, un panel de indicadores, un asistente…) se
crea un módulo de tipo **página**. Hereda menú, permisos, idiomas, cabecera y plantilla.

```jsonc
// modules/dashboard.json
{
  "type": "page",
  "controller": "Quasar\\Pages\\DashboardPage",
  "menu": { "group": "main", "order": 5, "icon": "layout-dashboard" },
  "actions": ["view", "refresh"]        // permisos (por defecto solo "view")
}
```

```php
// src/Pages/DashboardPage.php
namespace Quasar\Pages;

use Quasar\Controllers\Page;

final class DashboardPage extends Page
{
    // index.php?r=page&m=dashboard
    public function index(): void
    {
        $total = $this->app->db->fetchValue('SELECT COUNT(*) FROM clients');
        $this->view(['total' => $total]);                       // pinta templates/default/pages/dashboard.php
    }

    // index.php?r=page&m=dashboard&a=stats  → JSON para llamadas AJAX
    public function actionStats(): void
    {
        if (!$this->allowed('refresh')) { $this->json(['error' => 'forbidden'], 403); return; }
        $this->json(['ok' => true, 'time' => date('c')]);
    }
}
```

- La plantilla `templates/default/pages/{módulo}.php` usa los helpers de siempre (`$this->t()`, `icon()`,
  `url()`…); los textos van en `lang/{idioma}/{módulo}.json`.
- El acceso exige el permiso `view` de la página (aparece en la matriz de permisos); para otros permisos,
  `$this->allowed('accion')` o `$this->allowed('view', $this->otherModule('clients'))`.
- Para las acciones que modifican datos (POST), llama a `$this->requirePost()` (comprueba el token CSRF).
- Helpers de `Page`: `view()`, `json()`, `pageUrl()`, `param()`, `allowed()`, `otherModule()`, `text()`, `flash()`.
- `api()`: datos de la página para la API (`GET /api/v1/pages/{módulo}`; ver "API para apps y bots").

### Calendario (incluido)

`Quasar\Pages\CalendarPage` muestra en una vista mensual los registros de cualquier módulo con un campo de
fecha. Se configura en el JSON de la página:

```jsonc
"calendar": { "source": "activities", "date": "starts_at", "title": "title", "color": "type" }
```

Cada registro enlaza a su ficha; el `+` de cada día abre un alta con esa fecha ya puesta. Solo muestra datos
si el usuario puede ver el módulo de origen. El ejemplo usa el módulo **Activitats** (`modules/activities.json`).
Solo muestra los registros que el usuario ve en el módulo de origen (`scope`). Se puede crear otro calendario con
otro nombre de módulo y la misma clase (p. ej. `modules/agenda.json` sobre incidencias por fecha límite).

Un módulo con `"requires": ["activities"]` solo existe si existen esos módulos (el calendario de ejemplo desaparece
del menú en una instalación sin `activities`).

### Alta rápida desde la barra superior (quickCreate)

```jsonc
"quickCreate": { "label": "quick.new", "icon": "clock-plus", "context": { "issues": "issue_id", "projects": "project_id" } }
```

Un botón en la barra superior (para quien puede crear registros del módulo) abre el alta en una ventana sin salir de la
página; al guardar se cierra, avisa y recarga las tablas de la página. Con `context`, en la ficha de un registro de ese
módulo el alta se rellena con él (en una incidencia, «Imputar horas» ya lleva la incidencia). Cualquier alta se puede
abrir así con `index.php?r=edit&m=…&modal=1`.

En las fechas, `"default": "today"` (y `"now"` en `datetime`) pone la del día en las altas.

### Valores iniciales en las altas

Cualquier formulario de alta acepta valores por URL: `index.php?r=edit&m=activities&v[starts_at]=2026-10-21T09:00&v[type]=visit`.
Cada valor pasa por su tipo de campo y solo se aplica si es válido.

## Filtrar con lenguaje natural (IA)

Opcional por instalación. El usuario escribe lo que quiere ver («clientes activos de España con más de 10.000 de
crédito, los de más crédito primero») y la IA lo convierte en los **filtros normales del panel**: se ven como
chips, se pueden corregir a mano y la exportación, la paginación y el orden funcionan igual.

```php
// config.local.php de la instalación
'ai' => [
    'enabled' => true,
    'api_key' => 'sk-ant-…',              // clave de Anthropic de este cliente
    'model' => 'claude-haiku-5-5',         // por defecto; 'claude-sonnet-5-5' o 'claude-opus-5-5' si el cliente lo paga
    'monthly_limit_eur' => 20,             // 0 = sin límite
],
```

```jsonc
// en cada módulo que lo use
"list": {
  "ai": true,
  // o con ayudas para el modelo:
  "ai": {
    "synonyms": { "status:lead": "potencial, prospecto", "vip": "preferente" },
    "examples": ["clientes activos de España", "VIP con más de 20.000 de crédito"],
    "instructions": "«Cartera» significa clientes activos."
  }
}
```

**Cómo funciona y por qué es seguro**

- La IA **nunca escribe SQL**. Recibe la descripción de los filtros del módulo (`list.filters`), el buscador y las
  columnas ordenables, y devuelve un JSON con un esquema fijo (salida estructurada). El servidor valida cada condición
  contra esos filtros y descarta lo que no encaja (campos inexistentes, valores que no son opciones, fechas
  imposibles…). El resultado son los mismos parámetros que el panel de filtros, aplicados con consultas preparadas.
- Solo puede usar lo que el usuario puede ver: los campos con `access` que no le corresponden no se le describen
  a la IA ni se aceptan en la respuesta.
- Al proveedor se envía la descripción de los filtros (con las opciones de los select: estados, países… hasta
  `ai.max_options`; si hay más, solo se pide el texto y el servidor lo busca), la fecha, la zona horaria y la frase.
  **Nunca filas de datos.** La frase va marcada como texto del usuario, no como instrucciones.
- Fechas relativas («este mes», «el año pasado») según la fecha y zona horaria del servidor. La explicación de lo
  que no se ha podido aplicar sale en el idioma en que se ha escrito la frase.
- Los nombres se resuelven solos: «Alemania» → el país con ese nombre, también en selects con `search: "remote"`.

**Costes y límites**

- Cada consulta queda en `ai_usage` (usuario, módulo, modelo, frase y filtros aplicados si `ai.log_text`, tokens de
  entrada, salida y caché, coste en euros y tiempo). Los administradores lo ven en *Administración → Consumo de IA*
  (solo aparece con la IA activada), con un botón con el gasto del mes. Cosmo lo recibe en `?r=status` (`ai`).
- El coste se calcula con `ai.prices` (dólares por millón de tokens de cada modelo) × `ai.eur_per_usd`. **Revisa
  ambos** al cambiar de modelo o si cambian los precios de Anthropic.
- `monthly_limit_eur`: a partir de `warn_at` (80 %) los administradores ven un aviso en la barra; al 100 % la barra
  se desactiva para todos y quedan los filtros normales. Además, `user_per_hour` consultas por usuario y hora.
- Una frase idéntica (mismo módulo, permisos, idioma y día) se sirve de `ai_cache` sin coste durante `cache_ttl`.
  Las instrucciones del módulo van marcadas para la caché de Anthropic: las consultas seguidas del mismo listado
  pagan menos por ellas (si superan el mínimo cacheable del modelo).
- Orientativo con Haiku: unos 1.000–2.000 tokens de entrada y 200–400 de salida, una fracción de céntimo por consulta.

**Proveedores.** `Quasar\Ai\LlmProvider` es la interfaz; se incluye Anthropic (`AnthropicProvider`, HTTP con cURL,
sin dependencias). `ai.base_url` permite pasar por un proxy o pasarela. Otro proveedor (p. ej. uno compatible con
OpenAI, Ollama) es una clase que implemente la interfaz y su nombre en `LlmFactory`.

**Si falla** (sin conexión, clave no válida, servicio caído) el usuario ve un aviso, la tabla no cambia y el error queda
en el log y en `ai_usage`. Una petición que no se puede convertir en filtros («¿qué tiempo hace?») se explica en un
aviso.

## Informes con lenguaje natural (IA)

Sección **Informes** para usuarios de confianza: describen los datos que necesitan («nombre y teléfono de los
clientes con alguna actividad este año, primero los VIP», «número de clientes y crédito total por país»,
«los 5 clientes con más actividades») y la IA escribe la consulta SQL, con `JOIN`, `GROUP BY`, subconsultas…
El resultado se ve como una tabla de Quasar (paginación, orden por columnas, **Excel** y **PDF**), con una
explicación de lo que se ha entendido y la consulta SQL desplegable. Los informes útiles se **guardan con un nombre**
y se vuelven a abrir sin llamar a la IA (sin coste); las fechas relativas («este año») se recalculan cada vez.

```php
// config.local.php (además de 'ai' con la clave)
'reports' => [
    'enabled' => true,
    'tables' => [                                   // lo único que la IA puede consultar
        'clients' => 'Clientes de la empresa',
        'countries' => 'Países',
        'activities' => 'Visitas, llamadas y reuniones con clientes',
    ],
    'relations' => ['v_ventas.client_id' => 'clients.id'],   // opcional: para vistas o tablas sin clave foránea
    'model' => 'claude-sonnet-5-5',                 // por defecto; escribir SQL pide más que filtrar
    'db' => ['user' => 'quasar_informes', 'pass' => '…'],    // recomendado: usuario de MySQL de solo lectura
],
```

**Funciona igual en cualquier instalación.** No hay que subir el esquema: se lee de la propia BD (columnas, tipos,
comentarios y claves foráneas de las tablas de `tables`) y se completa con lo que saben los módulos de Quasar (la
etiqueta de cada columna, las opciones de los select, sí/no, contenido de las columnas JSON). Poner un
`COMMENT` en las columnas de la BD ayuda a la IA. Para limitar las **columnas** visibles de una tabla, crea una vista
con solo esas columnas y pon la vista en `tables`.

**Permisos** (matriz de permisos, por usuario o grupo): *Ver* (abrir los informes guardados), *Pedir a la IA*,
*Guardar* (guardar con nombre y borrar los propios) y *Exportar*. Los informes sin nombre solo los ve quien los pidió
(y los administradores); los guardados, todos los que tienen acceso a la sección.

**Seguridad.** Un informe lee lo que permita `tables`, **sin las reglas `access` de los módulos**: da el permiso solo
a quien pueda ver todos esos datos. La consulta de la IA nunca se ejecuta sin pasar por:

- **Validación** (`Quasar\Reports\SqlGuard`): una sola sentencia `SELECT`/`WITH`; solo tablas de `tables`; sin
  comentarios, variables, `INTO`, `SLEEP`, `LOAD_FILE`, otras bases de datos ni esquemas del sistema. `users`,
  `user_trusted_devices` y `ai_cache` nunca se pueden consultar.
- **Ejecución de solo lectura** (`ReportRunner`): transacción `READ ONLY`, tiempo máximo (`timeout`) y filas máximas
  (`max_rows`). La paginación y el orden los pone Quasar envolviendo la consulta.
- **Usuario de MySQL de solo lectura** (`reports.db`, recomendado): aunque todo lo anterior fallara, la BD no
  dejaría escribir. En cPanel: crea un usuario, asígnalo a la base de datos solo con el privilegio *SELECT* y ponlo en
  `reports.db` (se combina con `db`: basta `user` y `pass`).
- El navegador **nunca envía SQL**: solo el número del informe, la página y la columna por la que ordenar.
- Cada petición queda en *Consumo de IA* (frase, SQL generado, tokens, coste) y cuenta para el límite mensual.
- Si se quita una tabla de `tables`, los informes guardados que la usan dejan de ejecutarse.

Si la consulta generada no pasa la validación o da error al ejecutarse, se le devuelve a la IA con el error para que
la corrija (una vez). Orientativo con Sonnet 5.5: 3–6 segundos y menos de un céntimo por informe nuevo.

## Conversación en la ficha (chat)

```jsonc
"chat": true
"chat": { "notify": ["worker_id"], "access": ["equip"], "attachments": true, "maxSize": "20M", "meet": true }
```

Cada registro guardado tiene, junto a la ficha (debajo en pantallas estrechas), una conversación:

- Mensajes con enlaces, saltos de línea, **@menciones** (al escribir `@` se proponen las personas que pueden ver el
  registro), **adjuntos e imágenes** (botón, pegar o arrastrar) y borrado de los propios (los administradores, de
  cualquiera). Retorno envía; Mayús+Retorno, salto de línea.
- **Leído por usuario**: separador «Mensajes nuevos», «Visto por …» bajo el último mensaje propio y, en el listado, un
  contador de mensajes por fila (en rojo si hay sin leer) que abre la ficha en la conversación (`#chat`).
- Se actualiza sola cada `chat.poll_seconds` (5 s; menos a menudo si la pestaña no está a la vista), sin websockets:
  funciona en cualquier hosting.
- **Apuntes del sistema** desde los hooks: `Chat::system('issues', $id, ['issues:log.status', ['to' => ['issues:status.resolved']]], $user->id())`
  (texto o clave de idioma con variables; se traduce al idioma de quien lo lee).
- Silenciar: el botón de la campana de la conversación deja de avisar de ella (salvo menciones).
- Acceso: quien ve el módulo y el registro (`scope`), y, si se indica, `chat.access`. Los adjuntos solo se sirven a
  quien puede ver el mensaje. Tablas `messages` y `message_reads`.

**A quién se avisa de un mensaje:** los usuarios de los campos de `chat.notify`, quienes ya han escrito en la
conversación, los mencionados y los que devuelva el hook `chatRecipients($id, $row, $author)`. Nunca al autor, a quien
no puede ver el registro, a quien la tiene silenciada (salvo mención) ni a quien la está viendo en ese momento.

## Avisos (campana y correo)

La campana de la barra superior muestra los avisos del usuario (sin leer resaltados, «marcar todo como leído»), los
nuevos aparecen como aviso flotante y, si el usuario lo activa, como aviso del escritorio del navegador; el título de la
pestaña lleva el número de sin leer. Abrir un aviso (o la conversación de su registro) lo marca como leído.

```php
use Quasar\Core\Notify;

Notify::send([$workerId], [
    'type'   => 'issue.assigned',
    'title'  => ['issues:notify.assigned', ['record' => '#12 · Login']],   // texto o [clave, variables]: idioma de cada destinatario
    'body'   => 'Asignada por Kim',
    'url'    => 'index.php?r=edit&m=issues&id=12',                         // solo rutas internas
    'module' => 'issues', 'record' => '12',                                // agrupa los avisos del registro
    'actor'  => $user->id(),                                               // a él no se le avisa
    'urgent' => true,                                                      // correo aunque haya otro reciente
]);
```

```php
// config.local.php
'base_url' => 'https://gestion.cliente.com',   // enlaces completos en los correos (obligatorio para el cron)
'notifications' => [
    'email' => 'instant',          // instant | queue (bin/notifications.php en el cron: un correo por usuario) | off
    'throttle_minutes' => 10,      // no repetir el correo de un registro mientras haya uno sin leer
    'poll_seconds' => 30,
],
```

- Cada usuario elige en su perfil si recibe los avisos por correo (`users.notify_email`: `instant` | `off`).
- Tabla `notifications`; en modo `queue`, `php bin/notifications.php` envía los pendientes.

## Videollamadas con Google Meet

Con `"chat": { "meet": true }`, la conversación tiene un botón **Videollamada** que crea una sala de Google Meet con la
Meet REST API, publica el enlace en la conversación (con el botón «Unirse») y avisa a los participantes (también por
correo). Google Meet no se puede incrustar en la página: se abre en otra pestaña.

1. En Google Cloud Console crea un proyecto, activa la **Google Meet REST API** y configura la pantalla de
   consentimiento OAuth (tipo *interno* con Google Workspace) con el ámbito `meetings.space.created`.
2. Crea unas credenciales *ID de cliente de OAuth* de tipo aplicación web con la URI de redirección
   `{base_url}/index.php?r=page&m=integrations&a=callback` (la muestra la página de integraciones).
3. En `config.local.php`:
   ```php
   'google' => ['client_id' => '….apps.googleusercontent.com', 'client_secret' => '…', 'meet_access' => 'OPEN'],
   ```
4. *Administración → Integraciones → Conectar con Google* con la cuenta que organizará las reuniones.

- `meet_access`: `OPEN` (entra cualquiera con el enlace, también los clientes de fuera de la organización),
  `TRUSTED`, `RESTRICTED` o `null`. Si la cuenta no permite cambiarlo (Gmail personal), se crea con el valor por defecto.
- El token de refresco se guarda **cifrado** en la tabla `settings` con una clave derivada de `client_secret`.
  Desconectar lo revoca en Google.
- El botón **Videollamada** solo aparece con una cuenta conectada, y *Integraciones* solo existe si `google` tiene
  `client_id` y `client_secret` (y solo la ven los administradores).
- Google solo acepta `http://` en la URI de redirección para `localhost`/`127.0.0.1`; en un dominio, `https://`. La URI
  debe coincidir **exactamente** (también `base_url`): si no, Google responde `redirect_uri_mismatch`.
- Con la pantalla de consentimiento en modo *Prueba* (cuentas que no son de Workspace), añade la cuenta que conectará
  como usuario de prueba; Google caduca los tokens de las apps en prueba a los 7 días (habrá que volver a conectar).
- Si falla al crear la reunión, el usuario ve un aviso y el detalle queda en el log (`Google: POST … → 403: …`): suele
  ser la API sin activar en el proyecto o el ámbito sin conceder.

## Exportación a Excel y PDF

Cada tabla muestra los botones **Excel** y **PDF**, que descargan los datos con **los mismos filtros,
búsqueda y orden** que hay en pantalla (sin paginar, hasta `export_max_rows`, 10.000 por defecto).

- **Excel (.xlsx)**: números y fechas como valores reales de Excel (se pueden sumar y filtrar), fila de
  cabecera fija con autofiltro, anchos automáticos y, arriba, título, fecha, usuario y filtros aplicados.
- **PDF**: A4 con orientación automática (apaisado si hay muchas columnas), logo y color del cliente,
  cabecera de tabla repetida en cada página, filas alternas y «Página X de Y». Usa las fuentes base de PDF
  (WinAnsi): cubre español y el resto de lenguas de Europa occidental; otros caracteres latinos se
  transliteran (Ł → L) y los alfabetos no latinos no son representables (en Excel sí).
- Ambos se generan sin librerías externas (solo las extensiones `zip` y `zlib`).
- Requiere el permiso **Exportar** del módulo (columna nueva en la matriz de permisos).

Configuración por módulo, en `list`:

```jsonc
"export": {
  "formats": ["xlsx", "pdf"],          // por defecto ambos
  "columns": ["id", "name", "notes"],  // por defecto las del listado; admite campos que no estén en él
  "orientation": "landscape"           // portrait | landscape | auto (por defecto)
}
// "export": false  desactiva la exportación del módulo
```

## Imagen corporativa del cliente

En `config.php` (o mejor en `config.local.php` de cada instalación):

```php
'branding' => [
    'logo' => 'storage/branding/logo.png',  // PNG, JPG, SVG o WEBP (ruta relativa al proyecto o absoluta)
    'logo_pdf' => null,                     // PNG/JPG para los PDF si el logo es SVG/WEBP
    'color' => '#c8102e',                   // color principal: botones, enlaces, menú activo y cabeceras de PDF
    'company' => 'ACME Corp, S.L.',         // nombre en exportaciones (por defecto app_name)
],
```

El logo aparece en la barra lateral, en el login, como favicon y en la cabecera de los PDF (y el nombre de la
empresa en el Excel). Se sirve por `index.php?r=logo`, sin necesidad de sesión.

## Log

Desde **cualquier punto del código** (controladores, hooks, campos, scripts):

```php
use Quasar\Core\Log;

Log::debug('Precio calculado {total}', ['total' => $total, 'lineas' => $lineas]);
Log::info('Pedido {id} confirmado', ['id' => $id]);
Log::error('Fallo al sincronizar con el ERP', ['exception' => $e]);   // incluye la traza y la causa
Log::channel('facturacion')->notice('Factura {num} emitida', ['num' => $num]); // fichero propio
```

- Se escribe en `storage/logs/{canal}-AAAA-MM-DD.log` (canal por defecto `app`; el correo usa `mail`).
  Un fichero por día y se conservan `log.max_files` días.
- Niveles: `debug` < `info` < `notice` < `warning` < `error` < `critical`. Solo se escribe lo que alcanza
  `log.level`; si no se indica, es `debug` con `'debug' => true` e `info` en producción. Para depurar en
  producción basta con poner `'log' => ['level' => 'debug']` en `config.local.php`.
- Cada línea lleva un id de petición (para agrupar las líneas de una misma petición), el usuario y la IP:
  `[2026-10-19 11:30:12.123] ERROR [req:3fa9c1 ip:10.0.0.4 user:3 r:save] Mensaje {"contexto":…}`
- `{clave}` en el mensaje se sustituye por el valor del contexto. Las claves que parecen secretas
  (`password`, `token`, `api_key`, `secret`…) se escriben como `***`, también si van anidadas.
- Se registran automáticamente: excepciones no controladas, warnings/notices/fatales de PHP (respetando `@`),
  logins correctos y fallidos, logout, altas, modificaciones y bajas de registros, subidas de archivos,
  exportaciones, cambios de permisos y envíos de correo.
- Consultarlo: `tail -f storage/logs/app-$(date +%F).log`

## Correo (SMTP)

Configuración en `mail` (credenciales en `config.local.php`):

```php
'mail' => [
    'transport' => 'smtp',            // 'log' en desarrollo: no envía, guarda el .eml en storage/logs/mail/
    'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls',   // tls=STARTTLS · ssl=465 · none
    'username' => 'avisos@cliente.com', 'password' => '…',
    'from' => ['address' => 'avisos@cliente.com', 'name' => 'ACME Gestión'],
],
```

Comprobar la configuración (muestra la conversación SMTP si falla): `php bin/mail-test.php tu@correo.com`

Uso en el código:

```php
use Quasar\Mail\Mail;

// Mensaje simple (el remitente se toma de la configuración)
Mail::send(Mail::message()
    ->to('ana@cliente.es', 'Ana Pérez')->cc('jefe@cliente.es')
    ->subject('Pedido 125 confirmado')
    ->html('<p>Hola Ana, …</p>')        // la versión de texto se genera sola
    ->attach('/ruta/factura.pdf'));

// Con plantilla: templates/default/emails/pedido.php dentro de emails/layout.php
// (logo del cliente incrustado, color corporativo y pie)
$msg = Mail::compose('emails/pedido', ['pedido' => $pedido])->to($email)->subject('Tu pedido');
if (!Mail::trySend($msg)) { /* el error ya está en storage/logs/mail-… */ }
```

- Cliente SMTP propio, sin dependencias: SSL/STARTTLS (TLS 1.2+), AUTH PLAIN/LOGIN, varios destinatarios,
  CC/BCC, Reply-To, HTML + texto, adjuntos (de disco o generados en memoria con `attachData`) e imágenes
  incrustadas (`embed`). Cabeceras UTF-8 bien codificadas y protegidas contra inyección.
- `Mail::send()` lanza `MailException` si falla; `Mail::trySend()` devuelve `false`. Ambos lo dejan en el log.
- El envío es síncrono: para envíos masivos conviene una cola (no incluida).

## Verificación en dos pasos (2FA por email)

```php
'two_factor' => [
    'mode' => 'optional',   // off | optional (cada usuario la activa en su perfil) | required (todos)
    'code_ttl' => 600,      // validez del código en segundos
    'remember_days' => 30,  // "confiar en este dispositivo" (0 = pedir siempre)
],
```

- Tras la contraseña correcta se envía un código de 6 dígitos al email del usuario (en su idioma, con el
  logo del cliente). Requiere el correo configurado (`mail`).
- Protecciones: el código caduca y solo vale una vez; cada código erróneo cuenta para el bloqueo de cuenta
  (`login_max_attempts`); reenvíos limitados (`resend_wait`, `max_sends`); el código y el token de
  "dispositivo de confianza" solo se guardan como hash.
- Cambiar la contraseña o el email revoca los dispositivos de confianza; el usuario también puede
  revocarlos desde su perfil.
- En modo `required`, un usuario **sin email no puede entrar**: antes de activarlo, asegúrate de que todos
  (y el administrador: `php bin/install.php --email=…`) tienen email.
- Si alguien pierde el acceso a su email, un administrador puede desactivarle la verificación (en modo
  `optional`) o cambiarle el email desde *Usuarios*.

## Perfil de usuario

Haciendo clic en su nombre (arriba a la derecha) cada usuario puede cambiar su nombre, email, idioma y
contraseña, y activar o desactivar la verificación en dos pasos (modo `optional`). Cambiar el email o la
contraseña y desactivar la verificación exigen la contraseña actual.

## Usuarios y permisos

- Login obligatorio para todas las rutas, con verificación en dos pasos opcional. Bloqueo temporal tras
  `login_max_attempts` fallos, caducidad por inactividad (`session_lifetime`), regeneración de sesión al
  entrar y CSRF en todos los POST.
- Con qué se entra: `'login_field' => 'username'` (por defecto), `'email'` o `'both'` (usuario o email). Con el
  email, un correo que tengan varios usuarios no sirve para entrar (queda en el log); los campos email de
  `users.json` y del perfil ya son únicos.
- `is_admin = 1` concede todo. El resto de usuarios tiene permisos por **módulo × acción**
  (ver, crear, editar, eliminar, exportar y las acciones propias del módulo; tabla `user_permissions`), que se
  gestionan en *Administración → Usuarios → icono de llave*.
- Conceder cualquier acción concede también `view`. Sin `edit`, el formulario se abre en solo lectura.
- En código: `$user->can('clients', 'delete')`.

### Grupos de usuarios

En *Administración → Grupos* se crean grupos (Dirección, Comercial…) con un **código** (`direccion`) que es el
que se usa en los JSON. Cada usuario puede estar en varios grupos (campo *Grupos* de su ficha).

- Cada grupo tiene su propia matriz de permisos (*Grupos → icono de llave*; tabla `group_permissions`). Un
  usuario tiene sus permisos **más** los de todos sus grupos. En la matriz de un usuario, un icono marca los
  permisos que ya tiene por sus grupos.
- Los grupos de un usuario se guardan como lista JSON de ids en `users.groups`. Al borrar un grupo se quita de
  los usuarios y se borran sus permisos.
- En código: `$user->inGroup('direccion')` (o una lista: alguno de ellos), `$user->groups()` (códigos). En las
  plantillas, `$this->user()->inGroup(…)`. Los administradores pertenecen a todos a efectos de `inGroup`.
- Ojo: `groups` es palabra reservada en MySQL 8; en SQL propio escríbela entre comillas: `` users.`groups` ``.

### Acceso por grupos en campos, secciones y botones

Con `access` cualquier campo, sección del formulario o acción de fila se limita a unos grupos:

```jsonc
"fields": {
  "credit_band":  { "type": "select", …, "access": ["direccion"] },          // solo lo ve Dirección
  "credit_limit": { "type": "number", …, "editAccess": ["direccion"] },      // todos lo ven, solo Dirección lo cambia
  "margin":       { "type": "number", …, "access": { "groups": ["direccion"], "permission": "costs" } }
},
"form": { "sections": [ { "title": "sections.risk", "fields": [ … ], "access": "direccion" } ] },
"list": { "rowActions": [ { "label": "actions.approve", "url": "…", "icon": "check", "access": ["direccion", "jefes"] } ] }
```

- Valor: un código de grupo, una lista (basta con estar en uno) o `{ "groups": […], "permission": "acción" }`,
  que también deja pasar a quien tenga ese permiso del módulo en la matriz (la acción debe estar en `actions`;
  así se puede conceder desde la pantalla de permisos, por usuario o por grupo, sin tocar el JSON).
- Los administradores lo ven todo.
- Un campo con `access` desaparece **para ese usuario** del formulario, la tabla, la fila desplegable, los
  filtros, el buscador, el orden, la exportación y los archivos que contenga: no basta con ocultarlo, el
  servidor tampoco lo envía, ni lo valida ni lo guarda (conserva su valor).
- Con `editAccess` el campo se muestra en solo lectura y el servidor ignora lo que llegue.
- Un error en la regla (grupo con mayúsculas, permiso que no existe, clave desconocida) da un error claro al
  cargar el módulo. No se admite dentro de los subcampos de un repeater (se pone en el repeater).
- Si un campo visible tiene `showon` que depende de un campo oculto por `access`, en el navegador ese campo
  cuenta como vacío: evita esa combinación.
- Un campo obligatorio con `access` no se rellena en las altas que hacen otros usuarios: dale un valor por
  defecto en la tabla de la BD.

### Registros visibles por usuario (scope)

Para que cada usuario vea **solo algunos registros** de un módulo (los de su cliente, su delegación…):

```jsonc
// modules/issues.json
"scope": {
  "equip":  true,                                                     // el grupo "equip" ve todos
  "client": {
    "where": "issues.client_id = {user.client_id} AND issues.client_visible = 1",
    "set":   { "client_id": "{user.client_id}", "client_visible": 1 }
  }
  // "*": "…"   regla para los usuarios que no están en ningún grupo de la lista
},
"where": "issues.deleted = 0"                                         // condición fija para todos (también admins)
```

- La clave es el código de un grupo (o `"*"`) y el valor `true` (todo), `false` (nada), una condición SQL o
  `{"where", "set"}`. `{user.columna}` es el valor de esa columna de `users` del usuario (como parámetro, nunca
  concatenado); se pueden añadir columnas propias a `users` (p. ej. `client_id`).
- Un usuario de varios grupos ve lo de cualquiera de ellos. **Si no encaja en ninguna regla y no hay `"*"`, no ve
  nada.** Los administradores lo ven todo (salvo `where`, que es para todos).
- Se aplica en el servidor en el listado, la búsqueda, los filtros (también los de IA), los totales, la exportación,
  la ficha, el guardado, el borrado, las acciones, los archivos (`r=media`), el calendario, las sugerencias de
  etiquetas, la conversación y los avisos. Un registro fuera de su alcance responde 404 aunque se escriba la URL.
- `set`: columnas que se fijan al crear o modificar un registro (después de los hooks), para que nadie guarde un
  registro fuera de lo que ve.
- Los selects de otros módulos respetan el `scope` con `"source": { "module": … }`.

### Menú por grupos

`"menu": { "access": ["equip"] }` muestra el módulo en el menú (y en la portada) solo a esos grupos. No es un permiso:
quien tenga permiso de *Ver* puede abrirlo desde un enlace (p. ej. los archivos de una carpeta).

## Idiomas

Incluye **catalán (por defecto)**, castellano e inglés (`lang/ca`, `lang/es`, `lang/en`). El idioma de cada
usuario se guarda en su perfil; `locale` en la configuración fija el del login y el de los usuarios nuevos.
Para añadir otro idioma, copia una carpeta de `lang/`, tradúcela y añádelo a `locales`.

`lang/{locale}/common.json` contiene textos compartidos (botones, validaciones, formatos de fecha y número);
`lang/{locale}/{módulo}.json` los de cada vista. Las claves se anidan y se acceden con puntos (`fields.name`);
se busca primero en el dominio del módulo y luego en `common`. Entre los dos se consulta
`lang/{locale}/app.json`, que es **de la instalación** (no del núcleo: una actualización no lo sobrescribe): textos
compartidos propios, como los nombres de grupos del menú nuevos (`"menu": { "clientes": "Clientes" }`). El idioma sale del perfil del usuario
(o del selector del login) y lo que falte se toma de `fallback_locale`.

## Plantillas

Todo el HTML está en `templates/{nombre}/`: `layout.php`, `blank.php`, `login.php`, `home.php`,
`list.php`, `form.php`, `permissions.php`, `error.php`, `fields/repeater.php`, `fields/media.php`,
`emails/layout.php` (+ una plantilla por tipo de correo) y `assets/`. Para una plantilla nueva, copia
`default` y cambia `'template'` en la config. Para personalizar solo un módulo crea
`templates/default/modules/{módulo}/list.php` o `form.php`.

Las plantillas usan los helpers de `View` vía `$this`: `e()`, `t()`, `url()`, `asset()`, `csrfField()`,
`user()`, `menu()`…

## Menú lateral y filtros plegables

- El botón de menú de la barra superior (o **Ctrl+B**) reduce el menú lateral a una franja de iconos; se recuerda
  en una cookie (`qs_sidebar`), así que la página ya se pinta plegada.
- En los listados, el buscador está siempre visible y los filtros quedan tras el botón **Filtros**, con una
  insignia que indica cuántos hay activos aunque el panel esté plegado, y un botón para quitarlos. Si el panel
  está abierto o cerrado se recuerda por módulo en el navegador. Para que un módulo lo muestre abierto al
  entrar: `"list": { "filtersOpen": true }`.
- Los filtros de lista (select) admiten **varias opciones** («España o Portugal»): se muestran los registros que
  tienen cualquiera de ellas. En un select múltiple, los que contienen alguna.
- Encima de la tabla, cada filtro activo (y la búsqueda) aparece como un **chip** «País: España, Portugal»: clic
  para abrir ese filtro en el panel, × para quitarlo.

## Cabecera de página

Cada página muestra arriba la ruta de navegación (Inici › Gestió › Clients › Acme Ibèrica S.L.) y una
cabecera con icono, título y subtítulo. En las fichas el título es el propio registro: el campo indicado en
`"recordTitle"` del JSON del módulo o, si no se indica, `name`, `title`, `username` o el primer campo de texto.
Opcionalmente, cada módulo puede tener una clave `description` en su fichero de idioma, que se muestra como
subtítulo del listado. Desde un controlador propio se pasan `heading`, `subtitle`, `icon` y `breadcrumbs`
al renderizar.

## Iconos

Se usa el set **[Lucide](https://lucide.dev/icons)** (2.159 iconos de línea, licencia ISC; aviso en
`templates/default/icons/LICENSE-lucide.txt`), incluido en la plantilla (`icons/lucide.php`): no depende de
ningún CDN. Los iconos heredan el color del texto y se ven nítidos a cualquier tamaño.

- **En los JSON** se indica el nombre tal como aparece en lucide.dev: `"menu": { "icon": "truck" }`,
  `"rowActions": [{ "icon": "file-text", … }]`. Si un módulo no indica icono se usa `table-2`.
- **En las plantillas**: `<?= $this->icon('calendar') ?>` (opcional: clase CSS y etiqueta accesible).
- **En JavaScript propio**: `Quasar.icon('check')` devuelve un `<svg>` (solo para iconos incluidos en el sprite
  de la página; en una plantilla, `$this->useIcon('nombre')` lo añade).
- **Iconos propios**: un SVG de 24×24 de trazo en `templates/default/icons/custom/{nombre}.svg` se usa con el mismo
  nombre (y tiene prioridad sobre Lucide).

Para actualizar Lucide, regenera `icons/lucide.php` a partir del paquete `lucide-static`.

## Diálogos y avisos

La aplicación no usa los `confirm()`/`alert()` del navegador sino ventanas propias con el estilo del
tema (`Quasar.dialog`) y avisos flotantes que desaparecen solos (`Quasar.toast`).

En las plantillas basta con un atributo en cualquier botón de envío:

```html
<button type="submit" class="qs-btn qs-btn-danger" data-confirm="¿Anular la factura?"
        data-confirm-title="Anular factura" data-confirm-ok="Anular">Anular</button>
```

(`data-confirm-variant`: `danger` | `warning` | `info`; los `.qs-btn-danger` son `danger` por defecto.)

Desde JavaScript propio:

```js
if (await Quasar.dialog.confirm('¿Enviar el pedido?', { okText: 'Enviar' })) { … }
await Quasar.dialog.alert('No hay stock suficiente', { variant: 'warning' });
Quasar.toast.show('Pedido enviado', 'success');   // success | info | warning | danger
```

Los mensajes de éxito del servidor (`Session::flash('success', …)`) se muestran también como toasts.
Al salir de un formulario con cambios sin guardar aparece un aviso propio (al cerrar la pestaña el
navegador solo permite su aviso nativo).

## Atajos de teclado

| Dónde     | Atajo                 | Acción                    |
|-----------|-----------------------|---------------------------|
| Tabla     | Alt+N / Insert        | Nuevo registro            |
| Tabla     | ↑ ↓, Enter, doble clic | Moverse / abrir registro |
| Tabla     | Supr                  | Eliminar                  |
| Tabla     | RePág / AvPág         | Página anterior/siguiente |
| Formulario | Ctrl+S / Esc         | Guardar / volver          |
| Tabla     | /                     | Ir al buscador            |
| Todas     | Ctrl+B                | Plegar / desplegar el menú lateral |

## API para apps y bots

API REST en JSON en `/api/v1/…` (o `index.php?r=api&p=v1/…` si el servidor no reescribe URL). Cada petición actúa
**como un usuario**: mismos permisos, `scope`, campos con `access`, hooks, avisos y conversación que en la web. No usa
sesión ni cookies.

```php
'api' => [
    'enabled' => true,
    'token_days' => 365,   // caducidad de los tokens creados al iniciar sesión desde una app (0 = no caducan)
    'cors' => [],          // orígenes web permitidos (una web en otro dominio); las apps y bots no lo necesitan
],
```

**Tokens.** Van en la cabecera `Authorization: Bearer qs_…` (o `X-Api-Key: qs_…`) y se obtienen de dos maneras:

- desde el **perfil** (*Aplicacions i API*): nombre y caducidad (o ninguna). Es lo indicado para un bot o una
  integración. Se ve una sola vez; en la lista se ve el último uso y se puede revocar;
- `POST /api/v1/auth/token` `{"login": "…", "password": "…", "name": "iPhone de Kim"}` → `{token, expires_at, user}`:
  el inicio de sesión de una app. `login` es usuario o email según `login_field`; cuenta para el bloqueo por intentos
  fallidos. Los usuarios con verificación en dos pasos tienen que crear el token desde el perfil (`two_factor_required`).
  `DELETE /api/v1/auth/token` revoca el token con el que se llama (cerrar sesión en la app).

En BD solo se guarda el SHA-256 del token (`api_tokens`). Un usuario desactivado deja de poder usarlos.

| Petición | |
|---|---|
| `GET /` | nombre, versión de la aplicación y de la API (sin token) |
| `GET me` | usuario, avisos sin leer y módulos a los que tiene acceso, con sus acciones permitidas |
| `GET modules` · `GET modules/{m}` | módulos · esquema de uno: campos (tipo, etiqueta, obligatorio, editable, opciones), columnas, orden, búsqueda, filtros y acciones propias |
| `GET {m}` | listado. `q` (buscador), `f[campo]=…` (filtros del módulo, como en la web: `f[status][]=1&f[status][]=2`, `f[created_at][from]=…`), `sort`, `dir`, `page`, `per_page` (máx. 200), `fields=a,b` o `fields=all` (por defecto, las columnas del listado) → `{data, meta: {total, page, pages, per_page, sort, dir}}` |
| `GET {m}/{id}` | ficha con todos los campos que ve el usuario, `_files` (archivos de los campos media) y `_chat` (mensajes y sin leer) |
| `POST {m}` · `PATCH {m}/{id}` · `DELETE {m}/{id}` | alta · modificación **parcial** (solo los campos enviados) · borrado. Alta y modificación devuelven la ficha |
| `GET {m}/options/{campo}?q=&parent=` | opciones de un select con buscador (o dependiente: `parent` = valor del campo del que depende) |
| `POST {m}/files/{campo}` | sube un archivo (multipart, `file`) → `{id, …}`; el id se pone después en el campo (`"attachments": [12, 15]`) |
| `GET files/{id}[?thumb=1][&download=1]` | descarga un archivo (con los mismos permisos que en la web) |
| `GET {m}/{id}/messages[?after=&before=&read=1]` | conversación; `read=1` la marca como leída |
| `POST {m}/{id}/messages` | `{"body": "…", "media": [ids], "mentions": [ids de usuario]}`; adjuntos con `POST {m}/{id}/messages/files` |
| `POST {m}/{id}/actions/{acción}` · `POST {m}/actions/{acción}` | acciones propias del módulo (botones de fila/ficha o de la barra), salvo las que descargan un fichero |
| `GET notifications[?unread=1&after=&limit=]` · `POST notifications/read {id?}` | avisos · marcarlos como leídos (uno o todos) |
| `GET pages/{m}` | datos de una página personalizada que implemente `api()` |

**Valores.** Los de BD, en JSON: números como número, checkbox como `true`/`false`, selects múltiples, etiquetas y
archivos como listas, fechas `AAAA-MM-DD` y fechas con hora en ISO 8601 (`2026-10-28T09:22:13+01:00`). Las duraciones
son minutos. `_display` lleva el texto que se ve en la web cuando es distinto (la etiqueta de un select, `"1:30"`,
`"28/10/2026"`, el texto sin HTML de un richtext). Al escribir se aceptan los mismos valores (o texto como en el
formulario); un campo que no existe o que el usuario no ve da error, uno de solo lectura se ignora.

**Errores.** `{"error": "mensaje en el idioma del usuario", "code": "…"}` con su estado HTTP: `unauthorized` (401),
`forbidden` (403), `not_found` (404), `method_not_allowed` (405), `conflict` (409, registro en uso), `validation` (422,
con `"fields": {"campo": "mensaje"}`), `invalid_credentials`, `locked` (429), `maintenance` (503). `?lang=es` cambia el
idioma de una petición.

```bash
T=qs_…   # token del perfil
curl -H "Authorization: Bearer $T" https://app.example.com/api/v1/me
curl -H "Authorization: Bearer $T" "https://app.example.com/api/v1/clients?q=acme&f[status][]=active&per_page=20"
curl -H "Authorization: Bearer $T" -H 'Content-Type: application/json' \
     -d '{"name": "Acme", "status": "lead"}' https://app.example.com/api/v1/clients
curl -X PATCH -H "Authorization: Bearer $T" -H 'Content-Type: application/json' \
     -d '{"status": "active"}' https://app.example.com/api/v1/clients/12
```

Una **página personalizada** ofrece sus datos a la API implementando `api()` (con los mismos permisos que la página;
`$this->param()` lee los parámetros de la URL):

```php
public function api(): ?array
{
    return ['pendientes' => $this->contarPendientes()];
}
```

En Apache, `public/.htaccess` ya reescribe `/api/` y pasa la cabecera `Authorization` a PHP. En nginx:
`location /api/ { try_files $uri /index.php?$query_string; }`.

## Rutas

`index.php?r=` `login` · `logout` · `home` · `list&m=` · `data&m=` (JSON) · `edit&m=&id=` · `save&m=` ·
`delete&m=` · `action&m=&a=` (POST) · `ask&m=` (POST, IA) · `page&m=[&a=]` · `verify` · `profile` · `notifications&a=` (poll · read · open) · `chat&a=&m=&id=` (poll · post · upload · delete · mute · people · meet) · `permissions&id=` (usuario) · `permissions&group=` · `upload&m=&field=` · `media&id=` · `export&m=&format=` · `api&p=v1/…` (= `/api/v1/…`, JSON, con token) · `status` y `remote&a=` (JSON, con token) · `logo` · `asset&f=`

## Versiones

La versión del núcleo está en el fichero `VERSION` de la raíz (versionado semántico `MAYOR.MENOR.PARCHE`)
y se muestra al pie del menú lateral. En código: `Quasar\Core\Version::current()`; en plantillas: `$this->version()`.

- **PARCHE** (0.1.0 → 0.1.1): correcciones sin cambios de BD ni de configuración.
- **MENOR** (0.1.1 → 0.2.0): funciones nuevas compatibles (columnas o tablas nuevas, claves de config opcionales).
- **MAYOR** (0.x → 1.0.0): cambios que obligan a tocar módulos, plantillas o configuración de los proyectos.

Para publicar una versión: actualiza `VERSION` y `CHANGELOG.md`, haz commit y etiqueta con el mismo número:

```bash
git commit -am "Versión 0.2.0"
git tag -a v0.2.0 -m "Versión 0.2.0"
git push && git push --tags
```

### API remota (panel Cosmo)

Para gestionar las instalaciones desde el panel central Cosmo: estado, copias de seguridad,
actualizaciones, log y modo mantenimiento. Desactivada mientras no haya token; actívala en `config.local.php`
con un token propio de cada instalación (mínimo 32 caracteres):

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

```php
return ['remote' => [
    'token' => 'el-token-generado',
    'allow_ips' => ['203.0.113.10'],   // opcional: solo la IP del servidor de Cosmo
    'backups_keep' => 5,               // copias que se conservan en storage/backups
]];
```

Todas las llamadas llevan la cabecera `X-Quasar-Token` (o `Authorization: Bearer …`). Sin token o con uno
incorrecto responden `404` (no revelan que existe la API) y dejan un aviso en el log. No abren sesión ni
envían cookies. Úsala solo por HTTPS.

| Acción `index.php?r=remote&a=` | Método | Qué hace |
|---|---|---|
| `status` (también `?r=status`) | GET | Versión, PHP, BD, disco, extensiones, configuración sensible, usuarios, errores del log en 24 h, última copia, integridad, mantenimiento |
| `logs&level=warning&limit=100&days=7` | GET | Últimas entradas de `storage/logs` |
| `integrity` | GET | Ficheros del núcleo cambiados a mano e historial de actualizaciones |
| `backups` | GET | Copias de `storage/backups` |
| `download&file=` | GET | Descarga una copia (cabecera `X-Backup-Sha256`) |
| `backup` (`type=full\|db\|files`) | POST | Crea una copia: ZIP con `database.sql`, `files/…` y `backup.json` |
| `backup-delete` (`file=`) | POST | Borra una copia |
| `update` (`package`, `sha256`, `version`, `backup`, `force`) | POST | Instala un paquete de versión (ver abajo) |
| `migrate` | POST | Pone al día la BD (lo mismo que `bin/install.php`, sin tocar usuarios) y quita el mantenimiento |
| `maintenance` (`on=1\|0`) | POST | Modo mantenimiento: la aplicación responde 503 salvo la API remota |

```bash
curl -H "X-Quasar-Token: el-token-generado" "https://cliente.example.com/index.php?r=remote&a=status"
curl -H "X-Quasar-Token: el-token-generado" -d type=db "https://cliente.example.com/index.php?r=remote&a=backup"
```

Las copias usan un volcado SQL propio (no hace falta `mysqldump`) y la extensión `zip`. Copias,
actualizaciones y migraciones no se solapan: si ya hay una en marcha, la siguiente responde `409`.

#### Cómo se actualiza una instalación

Cosmo descarga de GitHub el ZIP de la etiqueta `vX.Y.Z` y lo sube a la instalación (que no necesita salir a
internet ni conocer el token de GitHub). La instalación:

1. Hace una copia completa (si `backup=1`) y se pone en mantenimiento.
2. Comprueba el sha256, las rutas del ZIP y su `VERSION`.
3. Si algún fichero del núcleo se cambió a mano desde la última actualización, se detiene (salvo `force=1`).
4. Copia **solo los ficheros del núcleo** que lista `core.json` del paquete nuevo y borra los que la versión
   anterior instaló y la nueva ya no trae. Módulos, idiomas, hooks y plantillas propios del proyecto,
   `config.local.php` y `storage/` no se tocan nunca.
5. Se llama a sí misma (`a=status`) con el código nuevo: si no arranca, deshace todos los cambios.
6. Cosmo pide `migrate`, que ya corre con el código nuevo, y la instalación sale del mantenimiento.

Guarda en `storage/remote/manifest.json` el sha1 de cada fichero instalado (para la integridad y los
borrados). La **primera** vez hay que actualizar a mano a la 0.2.0, que es la que trae esta API.

**`core.json`**: si añades al núcleo una carpeta o fichero nuevo, añádelo aquí antes de etiquetar la versión;
lo que no esté en la lista no llegará a las instalaciones al actualizar.
