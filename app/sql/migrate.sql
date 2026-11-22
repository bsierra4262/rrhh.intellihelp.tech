-- Portal de Recursos Humanos — Intellihelp
-- Migraciones idempotentes. Se aplican automáticamente con install.php.
ALTER TABLE users ADD COLUMN IF NOT EXISTS pending_activation TINYINT(1) NOT NULL DEFAULT 0 AFTER must_change_password;
ALTER TABLE users ADD COLUMN IF NOT EXISTS pending_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER pending_activation;
ALTER TABLE users ADD COLUMN IF NOT EXISTS company VARCHAR(120) NULL AFTER department;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS gcal_event_id VARCHAR(255) NULL AFTER reminded_at;
ALTER TABLE leave_types ADD COLUMN IF NOT EXISTS vacation_optional TINYINT(1) NOT NULL DEFAULT 0 AFTER uses_vacation_balance;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS deduct_vacation TINYINT(1) NOT NULL DEFAULT 0 AFTER days;
ALTER TABLE leave_types ADD COLUMN IF NOT EXISTS by_hours TINYINT(1) NOT NULL DEFAULT 0 AFTER counting;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS start_time TIME NULL AFTER end_date;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS end_time TIME NULL AFTER start_time;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS hours DECIMAL(5,2) NULL AFTER days;
-- Trámites por horario: no impactan en las vacaciones.
INSERT IGNORE INTO leave_types (code, name, description, color, requires_approval, requires_document, counting, by_hours, uses_vacation_balance, min_notice_days, sort) VALUES
 ('tramite', 'Trámite', 'Ausencia por horas dentro de la jornada para realizar un trámite. No impacta en las vacaciones.', '#7d6b91', 1, 0, 'habiles', 1, 0, 1, 5);
ALTER TABLE leave_types ADD COLUMN IF NOT EXISTS doc_due_days SMALLINT NULL AFTER document_label;
ALTER TABLE leave_types ADD COLUMN IF NOT EXISTS doc_due_from ENUM('created','end') NOT NULL DEFAULT 'end' AFTER doc_due_days;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS doc_due_date DATE NULL AFTER doc_status;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS doc_due_custom TINYINT(1) NOT NULL DEFAULT 0 AFTER doc_due_date;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS doc_reminded_at DATETIME NULL AFTER doc_due_custom;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS doc_overdue_at DATETIME NULL AFTER doc_reminded_at;
-- Plazos automáticos de entrega de documentación (solo si no fueron configurados).
UPDATE leave_types SET doc_due_days = 2, doc_due_from = 'created' WHERE code IN ('enfermedad', 'donacion') AND doc_due_days IS NULL;
UPDATE leave_types SET doc_due_days = 5, doc_due_from = 'created' WHERE code = 'maternidad' AND doc_due_days IS NULL;
UPDATE leave_types SET doc_due_days = 2, doc_due_from = 'end' WHERE code = 'estudio' AND doc_due_days IS NULL;
UPDATE leave_types SET doc_due_days = 5, doc_due_from = 'end' WHERE code IN ('mudanza', 'fallecimiento') AND doc_due_days IS NULL;
UPDATE leave_types SET doc_due_days = 10, doc_due_from = 'end' WHERE code IN ('matrimonio', 'nacimiento') AND doc_due_days IS NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS drive_id VARCHAR(128) NULL AFTER stored_name;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS drive_name VARCHAR(255) NULL AFTER drive_id;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS drive_link VARCHAR(255) NULL AFTER drive_name;
INSERT IGNORE INTO settings (k, v) VALUES ('drive_root_folder', '');
ALTER TABLE users ADD COLUMN IF NOT EXISTS vacation_override_year SMALLINT NULL AFTER vacation_days_override;
INSERT IGNORE INTO settings (k, v) VALUES ('report_enabled', '0'), ('report_recipients', ''), ('report_day', '1'), ('report_last_sent', '');
-- El día personal admite descuento opcional de vacaciones, a criterio de quien autoriza.
UPDATE leave_types SET vacation_optional = 1 WHERE code = 'personal' AND name = 'Día personal';
INSERT IGNORE INTO settings (k, v) VALUES ('support_email', 'soporte@intellihelp.tech'), ('ai_smtp_user', ''), ('ai_smtp_pass', ''), ('gcal_impersonate', '');
INSERT IGNORE INTO settings (k, v) VALUES ('event_email', 'off'), ('gcal_calendar_id', ''), ('gcal_sa_json', '');
INSERT IGNORE INTO settings (k, v) VALUES ('signup_enabled', '1'), ('signup_domain', 'intellihelp.tech'), ('default_approver_id', '0');
-- Regla del fin de semana: al tomar una semana completa se suma el fin de semana siguiente.
ALTER TABLE leave_types ADD COLUMN IF NOT EXISTS weekend_rule TINYINT(1) NULL AFTER counting;
UPDATE leave_types SET weekend_rule = IF(code = 'vacaciones', 1, 0) WHERE weekend_rule IS NULL;
-- Vacaciones fuera del período habitual (semanas incompletas) e ingreso con Google.
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS outside_period TINYINT(1) NOT NULL DEFAULT 0 AFTER deduct_vacation;
INSERT IGNORE INTO settings (k, v) VALUES ('vacation_contacts', 'nbaldelli@intellihelp.tech, cbaldelli@intellihelp.tech'), ('google_client_id', ''), ('google_client_secret', '');
-- Invitaciones de calendario por correo: secuencia por solicitud y registro de destinatarios.
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS cal_seq INT UNSIGNED NOT NULL DEFAULT 0 AFTER gcal_event_id;
CREATE TABLE IF NOT EXISTS calendar_invites (
  request_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (request_id, user_id),
  CONSTRAINT fk_inv_req FOREIGN KEY (request_id) REFERENCES leave_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Feriados importados del calendario oficial: tipo y origen (automático o cargado a mano).
ALTER TABLE holidays ADD COLUMN IF NOT EXISTS kind VARCHAR(20) NULL AFTER name;
ALTER TABLE holidays ADD COLUMN IF NOT EXISTS source ENUM('manual','auto') NOT NULL DEFAULT 'manual' AFTER kind;
-- Feriados que rigen solo en una jurisdicción (por ejemplo, «Provincia de Buenos Aires»).
ALTER TABLE holidays ADD COLUMN IF NOT EXISTS region VARCHAR(80) NULL AFTER kind;
INSERT IGNORE INTO settings (k, v) VALUES ('holidays_synced_at', ''), ('holidays_ignored', '');

-- Vacaciones según la LCT (arts. 151 a 164).
ALTER TABLE users ADD COLUMN IF NOT EXISTS cct VARCHAR(20) NULL AFTER position;
ALTER TABLE users ADD COLUMN IF NOT EXISTS exit_date DATE NULL AFTER hire_date;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_summer_season SMALLINT NULL AFTER exit_date;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS reschedule TINYINT(1) NOT NULL DEFAULT 0 AFTER outside_period;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS interrupted_days SMALLINT NULL AFTER reschedule;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS interrupted_by INT UNSIGNED NULL AFTER interrupted_days;
-- Año de vacaciones al que se imputa una reprogramación (puede ser el anterior, hasta el 30/4).
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS vacation_year SMALLINT NULL AFTER interrupted_by;
CREATE TABLE IF NOT EXISTS vacation_carryovers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  from_year SMALLINT NOT NULL,
  days SMALLINT NOT NULL,
  note VARCHAR(255) NULL,
  agreed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_carry (user_id, from_year),
  CONSTRAINT fk_carry_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ficha: categoría del convenio y legajo electrónico en Drive.
ALTER TABLE users ADD COLUMN IF NOT EXISTS cct_category VARCHAR(80) NULL AFTER cct;
ALTER TABLE users ADD COLUMN IF NOT EXISTS legajo_group VARCHAR(80) NULL AFTER cct_category;
ALTER TABLE users ADD COLUMN IF NOT EXISTS legajo_folder_id VARCHAR(80) NULL AFTER legajo_group;
ALTER TABLE users ADD COLUMN IF NOT EXISTS exit_processed_at DATETIME NULL AFTER exit_date;
INSERT IGNORE INTO settings (k, v) VALUES ('drive_rrhh_folder', ''), ('drive_legajos_folder', ''), ('drive_liquidaciones_folder', '');

-- Áreas y puestos editables desde Administración.
CREATE TABLE IF NOT EXISTS areas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS positions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  area_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_position (area_id, name),
  CONSTRAINT fk_position_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recibos de sueldo.
ALTER TABLE users ADD COLUMN IF NOT EXISTS cuil VARCHAR(13) NULL AFTER email;
ALTER TABLE users ADD COLUMN IF NOT EXISTS legajo_nro VARCHAR(20) NULL AFTER cuil;
CREATE TABLE IF NOT EXISTS payslips (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  period CHAR(7) NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'mensual',
  label VARCHAR(80) NULL,
  cuil VARCHAR(13) NULL,
  legajo VARCHAR(20) NULL,
  employee_name VARCHAR(120) NULL,
  pay_date DATE NULL,
  net DECIMAL(14,2) NULL,
  file_name VARCHAR(190) NOT NULL,
  drive_id VARCHAR(80) NOT NULL,
  sha256 CHAR(64) NOT NULL UNIQUE,
  uploaded_by INT UNSIGNED NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  signed_at DATETIME NULL,
  sign_status ENUM('conforme','disconforme') NULL,
  sign_comment VARCHAR(500) NULL,
  sign_ip VARCHAR(45) NULL,
  sign_agent VARCHAR(255) NULL,
  signed_drive_id VARCHAR(80) NULL,
  KEY idx_user (user_id, period),
  CONSTRAINT fk_payslip_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO settings (k, v) VALUES ('payslips_employees', '0');
