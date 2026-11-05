-- Asteroid · tablas propias del proyecto (gestor de incidencias de AFI). Las del núcleo están en schema.sql.
-- Lo ejecuta tools/asteroid-migrate.php (idempotente: CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS clients (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(150) NOT NULL,
  nif            VARCHAR(50)  NULL,
  factusol_code  VARCHAR(100) NULL COMMENT 'Código del cliente en FactuSOL',
  email          VARCHAR(150) NULL,
  phone          VARCHAR(50)  NULL,
  notes          TEXT         NULL,
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_clients_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  client_id    INT UNSIGNED  NULL,
  title        VARCHAR(150)  NOT NULL,
  code         VARCHAR(5)    NULL COMMENT 'Abreviatura',
  notes        TEXT          NULL,
  api_key      VARCHAR(255)  NULL COMMENT 'Clave de la API de incidencias de este proyecto',
  active       TINYINT(1)    NOT NULL DEFAULT 1,
  budget       DECIMAL(10,2) NULL COMMENT 'Presupuesto cerrado (sin presupuesto = por horas)',
  hourly_rate  DECIMAL(10,2) NULL COMMENT 'Precio por hora al facturar',
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_projects_client (client_id),
  CONSTRAINT fk_projects_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS issues (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id          INT UNSIGNED NULL COMMENT 'El del proyecto',
  project_id         INT UNSIGNED NULL,
  title              VARCHAR(150) NOT NULL,
  description        MEDIUMTEXT   NULL COMMENT 'HTML limpio (campo richtext)',
  priority           TINYINT      NOT NULL DEFAULT 0 COMMENT '0 normal, 1 urgente, 2 pánico',
  status             TINYINT      NOT NULL DEFAULT 1 COMMENT '1 pendiente, 2 en curso, 3 resuelta, 4 descartada',
  worker_id          INT UNSIGNED NULL COMMENT 'Técnico asignado',
  estimated_minutes  INT UNSIGNED NULL,
  starts_on          DATE         NULL,
  due_on             DATE         NULL,
  client_visible     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'El cliente la ve y participa en la conversación',
  created_by_client  TINYINT(1)   NOT NULL DEFAULT 0,
  created_by         INT UNSIGNED NULL,
  attachments        JSON         NULL,
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  resolved_at        DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_issues_client (client_id, client_visible),
  KEY ix_issues_project (project_id),
  KEY ix_issues_worker (worker_id, status),
  KEY ix_issues_status (status),
  CONSTRAINT fk_issues_project FOREIGN KEY (project_id) REFERENCES projects (id),
  CONSTRAINT fk_issues_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  billed_on       DATE          NOT NULL,
  client_id       INT UNSIGNED  NOT NULL,
  project_id      INT UNSIGNED  NULL,
  invoice_number  VARCHAR(30)   NULL COMMENT 'Número de factura en FactuSOL',
  status          TINYINT       NOT NULL DEFAULT 0 COMMENT '0 borrador, 1 facturada',
  `lines`         JSON          NULL COMMENT 'Líneas: [{description, quantity, price, amount}]',
  total           DECIMAL(10,2) NOT NULL DEFAULT 0,
  notes           TEXT          NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_billing_client (client_id),
  CONSTRAINT fk_billing_client FOREIGN KEY (client_id) REFERENCES clients (id),
  CONSTRAINT fk_billing_project FOREIGN KEY (project_id) REFERENCES projects (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hours (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  project_id  INT UNSIGNED NULL,
  issue_id    INT UNSIGNED NULL,
  worked_on   DATE         NOT NULL,
  minutes     INT UNSIGNED NOT NULL DEFAULT 0,
  comment     TEXT         NULL,
  billing     TINYINT      NOT NULL DEFAULT 0 COMMENT '0 pendiente de facturar, 1 facturada, 2 no facturable',
  billing_id  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_hours_user (user_id, worked_on),
  KEY ix_hours_project (project_id),
  KEY ix_hours_issue (issue_id),
  KEY ix_hours_billing (billing),
  CONSTRAINT fk_hours_project FOREIGN KEY (project_id) REFERENCES projects (id),
  CONSTRAINT fk_hours_issue FOREIGN KEY (issue_id) REFERENCES issues (id) ON DELETE SET NULL,
  CONSTRAINT fk_hours_billing FOREIGN KEY (billing_id) REFERENCES billing (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contracts (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED  NOT NULL,
  starts_on      DATE          NOT NULL,
  hours_per_day  DECIMAL(5,2)  NOT NULL DEFAULT 8,
  cost_per_hour  DECIMAL(10,2) NOT NULL DEFAULT 0,
  notes          TEXT          NULL,
  PRIMARY KEY (id),
  KEY ix_contracts_user (user_id, starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monthly_hours (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT UNSIGNED NOT NULL,
  year           SMALLINT     NOT NULL,
  month          TINYINT      NOT NULL,
  planned_hours  DECIMAL(6,2) NOT NULL DEFAULT 0,
  holiday_hours  DECIMAL(6,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_monthly_hours (user_id, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS folders (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id   INT UNSIGNED NOT NULL,
  name        VARCHAR(100) NOT NULL,
  notes       TEXT         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_folders_client (client_id),
  CONSTRAINT fk_folders_client FOREIGN KEY (client_id) REFERENCES clients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS files (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id   INT UNSIGNED NULL COMMENT 'El de la carpeta',
  folder_id   INT UNSIGNED NOT NULL,
  title       VARCHAR(150) NOT NULL,
  file        INT UNSIGNED NULL COMMENT 'id de la tabla media',
  notes       TEXT         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_files_folder (folder_id),
  KEY ix_files_client (client_id),
  CONSTRAINT fk_files_folder FOREIGN KEY (folder_id) REFERENCES folders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Encuestas de satisfacción: una por destinatario (usuario del cliente) cada vez que se envía al resolver
CREATE TABLE IF NOT EXISTS surveys (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_id           INT UNSIGNED NOT NULL,
  client_id          INT UNSIGNED NULL,
  user_id            INT UNSIGNED NULL     COMMENT 'Usuario del cliente que la recibe',
  sent_by            INT UNSIGNED NULL,
  token_hash         CHAR(64)     NOT NULL,
  sent_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at         DATETIME     NOT NULL,
  answered_at        DATETIME     NULL,
  rating             TINYINT      NULL     COMMENT '1-5: satisfacción general',
  speed_rating       TINYINT      NULL     COMMENT '1-5: tiempo de respuesta y resolución',
  resolution_rating  TINYINT      NULL     COMMENT '1-5: calidad de la solución',
  comment            TEXT         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_surveys_token (token_hash),
  KEY ix_surveys_issue (issue_id),
  KEY ix_surveys_client (client_id, answered_at),
  CONSTRAINT fk_surveys_issue FOREIGN KEY (issue_id) REFERENCES issues (id) ON DELETE CASCADE,
  CONSTRAINT fk_surveys_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bot de Telegram: cuentas vinculadas (Equip → Telegram) y avisos enviados al grupo (para responderles)
CREATE TABLE IF NOT EXISTS telegram_users (
  user_id        INT UNSIGNED    NOT NULL,
  telegram_id    BIGINT          NOT NULL,
  telegram_name  VARCHAR(100)    NOT NULL DEFAULT '',
  linked_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_telegram_users_tg (telegram_id),
  CONSTRAINT fk_telegram_users_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS telegram_messages (
  chat_id     VARCHAR(32)   NOT NULL,
  message_id  INT UNSIGNED  NOT NULL,
  module      VARCHAR(64)   NOT NULL,
  record_id   VARCHAR(64)   NOT NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (chat_id, message_id),
  KEY ix_telegram_messages_record (module, record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat web para las webs de los clientes: la configuración de cada cliente (una por cliente) y sus conversaciones.
-- Los mensajes van en la tabla "messages" del núcleo (módulo "webchat"); los de los visitantes, sin usuario.
CREATE TABLE IF NOT EXISTS webchat_sites (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  client_id   INT UNSIGNED  NOT NULL,
  public_key  CHAR(24)      NOT NULL,
  active      TINYINT(1)    NOT NULL DEFAULT 0,
  name        VARCHAR(80)   NOT NULL DEFAULT '',
  color       CHAR(7)       NOT NULL DEFAULT '#2563eb',
  greeting    VARCHAR(500)  NOT NULL DEFAULT '',
  domains     TEXT          NULL COMMENT 'Un dominio por línea; vacío = cualquiera',
  position    VARCHAR(5)    NOT NULL DEFAULT 'right',
  updated_at  DATETIME      NULL ON UPDATE CURRENT_TIMESTAMP,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webchat_sites_client (client_id),
  UNIQUE KEY uq_webchat_sites_key (public_key),
  CONSTRAINT fk_webchat_sites_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webchat_conversations (
  id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  client_id        INT UNSIGNED  NOT NULL,
  visitor_hash     CHAR(64)      NOT NULL COMMENT 'SHA-256 del identificador del navegador del visitante',
  visitor_name     VARCHAR(80)   NOT NULL DEFAULT '',
  visitor_email    VARCHAR(150)  NULL,
  status           TINYINT       NOT NULL DEFAULT 1 COMMENT '1 abierta, 2 cerrada',
  page_url         VARCHAR(500)  NULL,
  ip_hash          CHAR(64)      NULL,
  user_agent       VARCHAR(255)  NULL,
  last_message_at  DATETIME      NULL,
  created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_webchat_conv_client (client_id, status, last_message_at),
  KEY ix_webchat_conv_visitor (visitor_hash),
  KEY ix_webchat_conv_ip (ip_hash, created_at),
  CONSTRAINT fk_webchat_conv_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Informes SEO/GEO de las webs de los proyectos (Pulsar, tools/web-audit.php): uno por análisis, para ver la evolución.
-- categories e issues son el JSON que devuelve la API de Pulsar.
CREATE TABLE IF NOT EXISTS web_audits (
  id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED      NOT NULL,
  kind        VARCHAR(8)        NOT NULL COMMENT 'seo | geo',
  url         VARCHAR(255)      NOT NULL,
  score       TINYINT UNSIGNED  NULL COMMENT '0-100; NULL si el análisis ha fallado',
  categories  TEXT              NULL,
  issues      MEDIUMTEXT        NULL,
  passed      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  total       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  report_id   VARCHAR(32)       NULL COMMENT 'Informe en Pulsar',
  error       VARCHAR(255)      NULL,
  created_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_web_audits_project (project_id, kind, created_at),
  CONSTRAINT fk_web_audits_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notas personales del equipo (modules/notes.json): recordatorios rápidos. Cada uno ve las suyas y las compartidas.
CREATE TABLE IF NOT EXISTS notes (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED  NOT NULL,
  body        TEXT          NOT NULL,
  due_on      DATE          NULL,
  issue_id    INT UNSIGNED  NULL,
  client_id   INT UNSIGNED  NULL,
  shared      TINYINT(1)    NOT NULL DEFAULT 0,
  done        TINYINT(1)    NOT NULL DEFAULT 0,
  done_at     DATETIME      NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_notes_user (user_id, done, due_on),
  KEY ix_notes_shared (shared, done),
  CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
