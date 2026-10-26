<?php
/**
 * Configuración base. Para valores locales (credenciales, debug...) crea
 * config/config.local.php devolviendo solo las claves a sobrescribir.
 */
return [
    'app_name' => 'Quasar',
    'debug' => false,
    'timezone' => 'Europe/Madrid',

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'quasar',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    // Carpeta dentro de templates/
    'template' => 'default',

    // Imagen corporativa del cliente
    'branding' => [
        'logo' => null,       // ruta (relativa al proyecto o absoluta) a PNG, JPG, SVG o WEBP; p. ej. 'storage/branding/logo.png'
        'logo_pdf' => null,   // PNG/JPG para los PDF si 'logo' es SVG/WEBP (por defecto se usa 'logo')
        'color' => null,      // color principal, p. ej. '#c8102e' (botones, enlaces, cabeceras de PDF)
        'company' => null,    // nombre en exportaciones y pies de página (por defecto app_name)
    ],

    // Máximo de filas por exportación a Excel/PDF
    'export_max_rows' => 10000,

    // Idiomas disponibles (carpetas dentro de lang/)
    'locale' => 'ca',                // idioma por defecto (login y usuarios nuevos)
    'fallback_locale' => 'es',       // si falta un texto en un idioma, se toma de este
    'locales' => ['ca' => 'Català', 'es' => 'Español', 'en' => 'English'],

    'session_name' => 'QUASARSID',
    'session_lifetime' => 7200,      // segundos de inactividad antes de cerrar sesión (0 = sin límite)
    'login_max_attempts' => 5,       // intentos fallidos antes de bloquear la cuenta
    'login_lockout' => 300,          // segundos de bloqueo

    // Archivos subidos (campo media). Debe quedar FUERA de public/
    'uploads_dir' => dirname(__DIR__) . '/storage/uploads',

    // Verificación en dos pasos con código por email (requiere 'mail' configurado)
    'two_factor' => [
        'mode' => 'off',          // off | optional (cada usuario la activa en su perfil) | required (todos)
        'code_length' => 6,
        'code_ttl' => 600,        // segundos de validez del código
        'max_attempts' => 5,      // intentos por código (además cuentan para el bloqueo de cuenta)
        'resend_wait' => 60,      // segundos entre reenvíos
        'max_sends' => 3,         // códigos por inicio de sesión
        'remember_days' => 30,    // "confiar en este dispositivo" (0 = desactivado)
    ],

    // Log: storage/logs/{canal}-AAAA-MM-DD.log. En el código: Quasar\Core\Log::debug('…', [...])
    'log' => [
        'level' => null,      // debug | info | notice | warning | error | critical (null = 'debug' si debug=true, si no 'info')
        'dir' => dirname(__DIR__) . '/storage/logs',
        'max_files' => 30,    // días que se conservan por canal
    ],

    // Correo saliente
    'mail' => [
        'transport' => 'smtp',          // smtp | log (no envía: lo escribe en el log y guarda el .eml; útil en desarrollo)
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls',          // tls (STARTTLS, 587) | ssl (465) | none
        'username' => '',
        'password' => '',
        'timeout' => 15,
        'verify_peer' => true,          // false solo para servidores internos con certificado autofirmado
        'helo' => null,                 // nombre para EHLO (por defecto el del servidor)
        'from' => ['address' => 'no-reply@example.com', 'name' => 'Quasar'],
        'reply_to' => null,             // ['address' => '…', 'name' => '…']
    ],

    // API remota para el panel central de instalaciones (Cosmo): estado, copias, actualización, log.
    // Desactivada mientras no haya token (mínimo 32 caracteres). Ponlo en config.local.php:
    //   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    'remote' => [
        'token' => null,
        'allow_ips' => [],        // solo estas IPs (la del servidor de Cosmo); vacío = cualquiera con el token
        'backups_keep' => 5,      // copias que se conservan en storage/backups (las más antiguas se borran)
        'self_test' => true,      // tras actualizar, la instalación se llama a sí misma; si no arranca, se deshace
    ],

    // Filtrar listados con lenguaje natural (IA). Desactivado por defecto; cada módulo lo activa con "list.ai".
    // La clave va en config.local.php de cada instalación. Ver README, "Filtrar con lenguaje natural".
    'ai' => [
        'enabled' => false,
        'provider' => 'anthropic',
        'api_key' => null,
        'model' => 'claude-haiku-5-5',
        'base_url' => null,           // URL de la API si se pasa por un proxy o pasarela (por defecto, la de Anthropic)
        'effort' => 'low',            // low | medium | high: cuánto razona el modelo (más = más lento y caro)
        'timeout' => 20,              // segundos
        'monthly_limit_eur' => 0,     // gasto máximo al mes en euros (0 = sin límite)
        'warn_at' => 0.8,             // a partir de esta fracción del límite se avisa a los administradores
        'user_per_hour' => 60,        // consultas por usuario y hora
        'eur_per_usd' => 0.92,        // cambio para pasar a euros los precios en dólares; revísalo
        'cache_ttl' => 86400,         // segundos que se reutiliza la respuesta a una frase idéntica (0 = no)
        'log_text' => true,           // guardar en el log la frase del usuario y el resultado
        'max_options' => 50,          // opciones de un select con "source" que se envían al modelo (si hay más, solo el texto)
        // Dólares por millón de tokens (precios de Anthropic; compruébalos en su web al cambiar de modelo)
        'prices' => [
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_write' => 0.125, 'cache_read' => 0.01],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_write' => 5.00, 'cache_read' => 0.20],
        ],
    ],

    // Informes con lenguaje natural: la IA escribe una consulta SQL de solo lectura sobre las tablas de
    // 'tables' y el resultado se ve como una tabla con Excel y PDF. Usa la clave y el límite de 'ai'.
    // Ver README, "Informes con lenguaje natural".
    'reports' => [
        'enabled' => false,
        'tables' => [],               // tablas (o vistas) consultables: 'clients' => 'Clientes', 'activities' => 'Visitas y llamadas'
        'relations' => [],            // relaciones además de las claves foráneas: 'activities.client_id' => 'clients.id'
        'model' => 'claude-sonnet-5-5',
        'effort' => 'medium',
        'db' => null,                 // usuario de MySQL de solo lectura (recomendado): ['user' => 'informes', 'pass' => '…']
        'timeout' => 15,              // segundos máximos de cada consulta
        'max_rows' => 10000,          // filas máximas de un informe (pantalla y exportación)
    ],

    // URL pública de la instalación (p. ej. 'https://gestio.cliente.com'): enlaces de los correos de avisos y
    // vuelta de Google. Si no se indica se deduce de la petición; en los scripts de consola es necesaria.
    'base_url' => null,

    // Avisos de los usuarios (campana de la barra superior y correo)
    'notifications' => [
        'email' => 'instant',        // instant (al momento) | queue (bin/notifications.php en el cron, un correo agrupado) | off
        'throttle_minutes' => 10,    // no repetir el correo de un mismo registro mientras haya uno sin leer
        'poll_seconds' => 30,        // cada cuánto consulta la campana si hay avisos nuevos
    ],

    // Conversaciones de los registros (módulos con "chat")
    'chat' => [
        'poll_seconds' => 5,         // cada cuánto consulta una conversación abierta si hay mensajes nuevos
    ],

    // Google (videollamadas de Google Meet desde las conversaciones). Ver Administración → Integraciones.
    'google' => [
        'client_id' => null,
        'client_secret' => null,
        'meet_access' => 'OPEN',     // OPEN (cualquiera con el enlace) | TRUSTED | RESTRICTED | null (lo de la cuenta)
    ],

    // Tipos de campo propios: 'tipo' => Clase que extiende Quasar\Fields\Field
    'field_types' => [],
];
