-- Quasar · datos de ejemplo para los módulos "countries" y "clients"

CREATE TABLE IF NOT EXISTS countries (
  id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code  CHAR(2)      NOT NULL,
  name  VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_countries_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name          VARCHAR(150)  NOT NULL,
  status        VARCHAR(20)   NOT NULL DEFAULT 'lead',
  email         VARCHAR(150)  NULL,
  phone         VARCHAR(30)   NULL,
  country_id    INT UNSIGNED  NULL,
  since         DATE          NULL,
  vip           TINYINT(1)    NOT NULL DEFAULT 0,
  credit_limit  DECIMAL(12,2) NULL,
  notes         TEXT          NULL,
  contacts      JSON          NULL,              -- campo repeater
  logo          INT UNSIGNED  NULL,              -- campo media (un archivo): id de media
  documents     JSON          NULL,              -- campo media (múltiple): lista de ids
  tags          JSON          NULL,              -- campo tags: lista de textos
  sectors       JSON          NULL,              -- campo select múltiple: lista de valores
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_clients_country (country_id),
  KEY ix_clients_status (status),
  CONSTRAINT fk_clients_country FOREIGN KEY (country_id) REFERENCES countries (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO countries (id, code, name) VALUES
  (1, 'ES', 'España'),
  (2, 'PT', 'Portugal'),
  (3, 'FR', 'Francia'),
  (4, 'DE', 'Alemania'),
  (5, 'IT', 'Italia'),
  (6, 'GB', 'Reino Unido'),
  (7, 'MX', 'México'),
  (8, 'AR', 'Argentina'),
  (9, 'CO', 'Colombia'),
  (10, 'US', 'Estados Unidos');

INSERT IGNORE INTO clients (id, name, status, email, phone, country_id, since, vip, credit_limit, notes) VALUES
  (1,  'Acme Ibérica S.L.',            'active',   'compras@acme-iberica.es',   '+34 911 000 101', 1,  '2019-03-14', 1, 50000.00, 'Cliente estratégico. Revisión anual en marzo.'),
  (2,  'Baleares Turismo S.A.',        'active',   'info@baleturismo.es',       '+34 971 000 202', 1,  '2020-06-01', 0, 12000.00, NULL),
  (3,  'Construcciones Duero',         'inactive', 'admin@duero.es',            '+34 983 000 303', 1,  '2017-11-20', 0, 0.00,     'Sin actividad desde 2023.'),
  (4,  'Lisboa Digital Lda.',          'active',   'geral@lisboadigital.pt',    '+351 21 000 404', 2,  '2021-02-10', 1, 30000.00, NULL),
  (5,  'Porto Vinhos',                 'lead',     'contacto@portovinhos.pt',   '+351 22 000 505', 2,  NULL,         0, NULL,     'Interesados en la versión de escritorio.'),
  (6,  'Lumière Conseil SARL',         'active',   'contact@lumiere.fr',        '+33 1 00 00 06 06', 3, '2018-09-05', 0, 20000.00, NULL),
  (7,  'Provence Bio',                 'lead',     'bonjour@provencebio.fr',    '+33 4 00 00 07 07', 3, NULL,         0, NULL,     NULL),
  (8,  'Berliner Maschinenbau GmbH',   'active',   'einkauf@bmb.de',            '+49 30 000 0808',   4, '2016-01-18', 1, 80000.00, 'Pago a 60 días.'),
  (9,  'Hamburg Logistik',             'inactive', 'info@hh-logistik.de',       '+49 40 000 0909',   4, '2019-07-22', 0, 5000.00,  NULL),
  (10, 'Milano Design S.r.l.',         'active',   'ordini@milanodesign.it',    '+39 02 000 1010',   5, '2022-04-03', 0, 15000.00, NULL),
  (11, 'Napoli Alimentari',            'lead',     'info@napolialimentari.it',  '+39 081 000 1111',  5, NULL,         0, NULL,     NULL),
  (12, 'Thames Software Ltd',          'active',   'accounts@thames.co.uk',     '+44 20 0000 1212',  6, '2020-10-12', 1, 45000.00, NULL),
  (13, 'Grupo Azteca Distribución',    'active',   'compras@azteca.mx',         '+52 55 0000 1313',  7, '2021-08-30', 0, 25000.00, NULL),
  (14, 'Monterrey Aceros',             'lead',     'ventas@mtyaceros.mx',       '+52 81 0000 1414',  7, NULL,         0, NULL,     'Demo programada.'),
  (15, 'Pampa Agro S.A.',              'active',   'administracion@pampa.ar',   '+54 11 0000 1515',  8, '2018-05-16', 0, 18000.00, NULL),
  (16, 'Café de Colombia Export',      'active',   'export@cafecol.co',         '+57 1 000 1616',    9, '2023-01-09', 1, 22000.00, NULL),
  (17, 'Andes Textil',                 'inactive', 'info@andestextil.co',       '+57 4 000 1717',    9, '2017-03-27', 0, 0.00,     NULL),
  (18, 'Silicon Desk Inc.',            'active',   'billing@silicondesk.com',   '+1 415 000 1818',  10, '2022-11-11', 1, 100000.00, 'Integración vía API.'),
  (19, 'Texas Supplies LLC',           'lead',     'hello@texassupplies.com',   '+1 512 000 1919',  10, NULL,         0, NULL,     NULL),
  (20, 'Valencia Cerámicas',           'active',   'pedidos@valceramica.es',    '+34 963 000 2020',  1, '2015-02-02', 0, 35000.00, NULL),
  (21, 'Galicia Mar Conservas',        'active',   'comercial@galiciamar.es',   '+34 986 000 2121',  1, '2021-12-01', 0, 9000.00,  NULL),
  (22, 'Sevilla Eventos',              'lead',     'hola@sevillaeventos.es',    '+34 954 000 2222',  1, NULL,         0, NULL,     NULL),
  (23, 'Bilbao Industrial',            'active',   'compras@bilbaoind.es',      '+34 944 000 2323',  1, '2019-09-19', 1, 60000.00, NULL),
  (24, 'Canarias Solar',               'active',   'info@canariassolar.es',     '+34 928 000 2424',  1, '2024-03-08', 0, 11000.00, NULL);

UPDATE clients SET tags = '["export","vip"]' WHERE id IN (1, 12) AND tags IS NULL;
UPDATE clients SET tags = '["proveïdor"]' WHERE id IN (8, 20) AND tags IS NULL;
UPDATE clients SET tags = '["urgent","export"]' WHERE id = 18 AND tags IS NULL;

UPDATE clients SET contacts = '[{"name":"Laura Gómez","position":"Compras","email":"laura@acme-iberica.es","phone":"+34 600 000 001","main":1},{"name":"Pedro Ruiz","position":"Administración","email":"pedro@acme-iberica.es","phone":"","main":0}]'
WHERE id = 1 AND contacts IS NULL;

-- Actividades de ejemplo (módulo "activities" y página "calendar"), con fechas relativas al día de la instalación
CREATE TABLE IF NOT EXISTS activities (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title      VARCHAR(150) NOT NULL,
  type       VARCHAR(20)  NOT NULL DEFAULT 'meeting',
  starts_at  DATETIME     NOT NULL,
  client_id  INT UNSIGNED NULL,
  done       TINYINT(1)   NOT NULL DEFAULT 0,
  notes      TEXT         NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_activities_starts (starts_at),
  KEY ix_activities_client (client_id),
  CONSTRAINT fk_activities_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO activities (id, title, type, starts_at, client_id, done) VALUES
  (1,  'Revisió anual del contracte',  'meeting', TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 9 DAY), '10:00:00'), 1,  1),
  (2,  'Trucada de seguiment',          'call',    TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 6 DAY), '09:30:00'), 2,  1),
  (3,  'Visita a la fàbrica',           'visit',   TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 3 DAY), '11:00:00'), 23, 1),
  (4,  'Preparar oferta',               'task',    TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY), '16:00:00'), 18, 0),
  (5,  'Demo del producte',             'meeting', TIMESTAMP(CURDATE(), '12:00:00'), 14, 0),
  (6,  'Trucar per la factura',         'call',    TIMESTAMP(CURDATE(), '17:30:00'), 3,  0),
  (7,  'Visita comercial',              'visit',   TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 2 DAY), '10:30:00'), 20, 0),
  (8,  'Reunió de planificació',        'meeting', TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 4 DAY), '09:00:00'), NULL, 0),
  (9,  'Enviar documentació',           'task',    TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 4 DAY), '13:00:00'), 4,  0),
  (10, 'Trucada a Lisboa Digital',      'call',    TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 7 DAY), '15:00:00'), 4,  0),
  (11, 'Visita a Valencia Cerámicas',   'visit',   TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 11 DAY), '10:00:00'), 20, 0),
  (12, 'Tancament de trimestre',        'task',    TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 16 DAY), '18:00:00'), NULL, 0);

-- Grupos de ejemplo (el módulo "clients" usa "direccion" en sus reglas "access")
INSERT IGNORE INTO user_groups (id, code, name, description) VALUES
  (1, 'direccion', 'Dirección', 'Ve y modifica los datos de riesgo y crédito de los clientes'),
  (2, 'comercial', 'Comercial', 'Gestión de clientes y actividades');

UPDATE clients SET sectors = '["industry"]' WHERE id IN (1, 8) AND sectors IS NULL;
UPDATE clients SET sectors = '["services", "tech"]' WHERE id IN (4, 12) AND sectors IS NULL;
UPDATE clients SET sectors = '["retail", "food"]' WHERE id IN (11, 13) AND sectors IS NULL;
UPDATE clients SET notes = 'Cliente estratégico. Revisión anual en marzo.\nContrato marco firmado en 2019 con renovación tácita; cualquier cambio de condiciones debe pasar por dirección.\nContacto principal: departamento de compras.' WHERE id = 1;
