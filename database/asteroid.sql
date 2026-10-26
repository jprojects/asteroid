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
