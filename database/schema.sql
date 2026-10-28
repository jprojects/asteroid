-- Quasar · tablas del núcleo (MySQL 5.7+ / MariaDB 10.3+)

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(50)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  name           VARCHAR(100) NOT NULL DEFAULT '',
  email          VARCHAR(150) NULL,
  locale         VARCHAR(5)   NOT NULL DEFAULT 'ca',
  is_admin       TINYINT(1)   NOT NULL DEFAULT 0,
  `groups`       JSON         NULL,                -- ids de user_groups: [1, 3]
  two_factor     TINYINT(1)   NOT NULL DEFAULT 0,
  notify_email   VARCHAR(10)  NOT NULL DEFAULT 'instant',   -- avisos por correo: instant | off
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  failed_logins  INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME     NULL,
  last_login     DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_permissions (
  user_id  INT UNSIGNED NOT NULL,
  module   VARCHAR(64)  NOT NULL,
  action   VARCHAR(32)  NOT NULL,
  PRIMARY KEY (user_id, module, action),
  CONSTRAINT fk_perm_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grupos de usuarios: dan permisos (group_permissions) y se usan en las reglas "access" de los módulos
CREATE TABLE IF NOT EXISTS user_groups (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(50)  NOT NULL,              -- el que se usa en los JSON: "access": ["direccion"]
  name         VARCHAR(100) NOT NULL,
  description  VARCHAR(255) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_groups_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_permissions (
  group_id  INT UNSIGNED NOT NULL,
  module    VARCHAR(64)  NOT NULL,
  action    VARCHAR(32)  NOT NULL,
  PRIMARY KEY (group_id, module, action),
  CONSTRAINT fk_gperm_group FOREIGN KEY (group_id) REFERENCES user_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Archivos subidos por los campos "media". Los ficheros están en config 'uploads_dir'.
CREATE TABLE IF NOT EXISTS media (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  module         VARCHAR(64)  NOT NULL,
  field          VARCHAR(128) NOT NULL,          -- ruta del campo: "photos" o "contacts.photo"
  original_name  VARCHAR(255) NOT NULL,
  path           VARCHAR(255) NOT NULL,          -- relativo a uploads_dir
  thumb_path     VARCHAR(255) NULL,
  mime           VARCHAR(127) NOT NULL,
  size           INT UNSIGNED NOT NULL,
  width          INT UNSIGNED NULL,
  height         INT UNSIGNED NULL,
  user_id        INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_media_owner (module, field),
  KEY ix_media_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Confiar en este dispositivo" de la verificación en dos pasos (solo se guarda el hash del token)
CREATE TABLE IF NOT EXISTS user_trusted_devices (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  user_agent  VARCHAR(255) NOT NULL DEFAULT '',
  expires_at  DATETIME     NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_trusted_token (token_hash),
  KEY ix_trusted_user (user_id, expires_at),
  CONSTRAINT fk_trusted_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Filtrar con lenguaje natural (IA): una fila por consulta, para el consumo y el límite mensual
CREATE TABLE IF NOT EXISTS ai_usage (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id        INT UNSIGNED  NULL,
  module         VARCHAR(64)   NOT NULL,
  provider       VARCHAR(32)   NOT NULL,
  model          VARCHAR(64)   NOT NULL,
  status         VARCHAR(20)   NOT NULL,         -- ok | not_understood | cached | error | limit
  prompt         VARCHAR(500)  NULL,             -- frase del usuario (si ai.log_text)
  result         TEXT          NULL,             -- filtros aplicados o error
  input_tokens   INT UNSIGNED  NOT NULL DEFAULT 0,
  output_tokens  INT UNSIGNED  NOT NULL DEFAULT 0,
  cache_write_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  cache_read_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
  cost_eur       DECIMAL(12,6) NOT NULL DEFAULT 0,
  duration_ms    INT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_ai_usage_created (created_at),
  KEY ix_ai_usage_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Respuestas a frases idénticas (mismo módulo, permisos, idioma y día): no se vuelven a pagar
CREATE TABLE IF NOT EXISTS ai_cache (
  hash        CHAR(64)  NOT NULL,
  response    TEXT      NOT NULL,
  created_at  DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (hash),
  KEY ix_ai_cache_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Informes con lenguaje natural: cada consulta generada (y las guardadas con nombre para reutilizarlas)
CREATE TABLE IF NOT EXISTS reports (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED  NULL,
  name         VARCHAR(150)  NULL,              -- con nombre = guardado y visible para quien tenga acceso
  prompt       VARCHAR(1000) NOT NULL,
  title        VARCHAR(200)  NOT NULL,
  explanation  TEXT          NULL,
  query        TEXT          NOT NULL,          -- SQL generado (solo SELECT; se vuelve a validar en cada ejecución)
  columns      TEXT          NOT NULL,          -- JSON: [{name, label, type}]
  sort         TEXT          NULL,              -- JSON: [{column, dir}]
  row_limit    INT UNSIGNED  NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  saved_at     DATETIME      NULL,
  PRIMARY KEY (id),
  KEY ix_reports_user (user_id, created_at),
  KEY ix_reports_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avisos de cada usuario: campana de la barra superior y, según su preferencia, correo
CREATE TABLE IF NOT EXISTS notifications (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  type          VARCHAR(40)  NOT NULL,              -- chat.message | chat.mention | chat.meet | o propios: issue.assigned…
  module        VARCHAR(64)  NULL,
  record_id     VARCHAR(64)  NULL,
  actor_id      INT UNSIGNED NULL,                  -- quién lo ha provocado
  title         VARCHAR(255) NOT NULL,
  body          TEXT         NULL,
  url           VARCHAR(500) NULL,                  -- ruta interna: index.php?r=…
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at       DATETIME     NULL,
  email_status  VARCHAR(10)  NOT NULL DEFAULT 'none',   -- none | pending | sent | skipped | failed
  emailed_at    DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_notifications_user (user_id, read_at, id),
  KEY ix_notifications_record (user_id, module, record_id),
  KEY ix_notifications_email (email_status, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversación de cada registro (módulos con "chat")
CREATE TABLE IF NOT EXISTS messages (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  module      VARCHAR(64)  NOT NULL,
  record_id   VARCHAR(64)  NOT NULL,
  user_id     INT UNSIGNED NULL,
  kind        VARCHAR(20)  NOT NULL DEFAULT 'text',  -- text | system (cambios del registro) | meet (videollamada)
  body        TEXT         NULL,
  media       JSON         NULL,                     -- ids de la tabla media: [12, 13]
  meta        JSON         NULL,                     -- datos del tipo: {"uri": "https://meet.google.com/…"}
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  edited_at   DATETIME     NULL,
  deleted_at  DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_messages_record (module, record_id, id),
  KEY ix_messages_user (user_id),
  CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hasta qué mensaje ha leído cada usuario cada conversación (y si la ha silenciado)
CREATE TABLE IF NOT EXISTS message_reads (
  user_id       INT UNSIGNED NOT NULL,
  module        VARCHAR(64)  NOT NULL,
  record_id     VARCHAR(64)  NOT NULL,
  last_read_id  INT UNSIGNED NOT NULL DEFAULT 0,
  seen_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- última vez que tenía la conversación abierta
  muted         TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, module, record_id),
  KEY ix_message_reads_record (module, record_id),
  CONSTRAINT fk_message_reads_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores que la aplicación guarda por sí misma (p. ej. la conexión con Google)
CREATE TABLE IF NOT EXISTS settings (
  name        VARCHAR(100) NOT NULL,
  value       TEXT         NULL,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API (apps y bots): tokens personales. Solo se guarda el hash; el token se enseña una vez al crearlo
CREATE TABLE IF NOT EXISTS api_tokens (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  name          VARCHAR(100) NOT NULL DEFAULT '',
  token_hash    CHAR(64)     NOT NULL,
  hint          VARCHAR(8)   NOT NULL DEFAULT '',   -- últimos caracteres, para reconocerlo
  expires_at    DATETIME     NULL,
  last_used_at  DATETIME     NULL,
  last_ip       VARCHAR(45)  NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_tokens_hash (token_hash),
  KEY ix_api_tokens_user (user_id),
  CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
