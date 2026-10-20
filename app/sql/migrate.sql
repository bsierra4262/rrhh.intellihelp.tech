-- Portal de Recursos Humanos — Intellihelp
-- Migraciones idempotentes. Se aplican automáticamente con install.php.
ALTER TABLE users ADD COLUMN IF NOT EXISTS pending_activation TINYINT(1) NOT NULL DEFAULT 0 AFTER must_change_password;
ALTER TABLE users ADD COLUMN IF NOT EXISTS pending_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER pending_activation;
ALTER TABLE users ADD COLUMN IF NOT EXISTS company VARCHAR(120) NULL AFTER department;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS gcal_event_id VARCHAR(255) NULL AFTER reminded_at;
ALTER TABLE leave_types ADD COLUMN IF NOT EXISTS vacation_optional TINYINT(1) NOT NULL DEFAULT 0 AFTER uses_vacation_balance;
ALTER TABLE leave_requests ADD COLUMN IF NOT EXISTS deduct_vacation TINYINT(1) NOT NULL DEFAULT 0 AFTER days;
-- El día personal admite descuento opcional de vacaciones, a criterio de quien autoriza.
UPDATE leave_types SET vacation_optional = 1 WHERE code = 'personal' AND name = 'Día personal';
INSERT IGNORE INTO settings (k, v) VALUES ('support_email', 'soporte@intellihelp.tech'), ('ai_smtp_user', ''), ('ai_smtp_pass', ''), ('gcal_impersonate', '');
INSERT IGNORE INTO settings (k, v) VALUES ('event_email', 'off'), ('gcal_calendar_id', ''), ('gcal_sa_json', '');
INSERT IGNORE INTO settings (k, v) VALUES ('signup_enabled', '1'), ('signup_domain', 'intellihelp.tech'), ('default_approver_id', '0');
