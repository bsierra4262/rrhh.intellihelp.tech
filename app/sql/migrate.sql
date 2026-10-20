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
INSERT IGNORE INTO settings (k, v) VALUES ('report_enabled', '0'), ('report_recipients', ''), ('report_day', '1'), ('report_last_sent', '');
-- El día personal admite descuento opcional de vacaciones, a criterio de quien autoriza.
UPDATE leave_types SET vacation_optional = 1 WHERE code = 'personal' AND name = 'Día personal';
INSERT IGNORE INTO settings (k, v) VALUES ('support_email', 'soporte@intellihelp.tech'), ('ai_smtp_user', ''), ('ai_smtp_pass', ''), ('gcal_impersonate', '');
INSERT IGNORE INTO settings (k, v) VALUES ('event_email', 'off'), ('gcal_calendar_id', ''), ('gcal_sa_json', '');
INSERT IGNORE INTO settings (k, v) VALUES ('signup_enabled', '1'), ('signup_domain', 'intellihelp.tech'), ('default_approver_id', '0');
