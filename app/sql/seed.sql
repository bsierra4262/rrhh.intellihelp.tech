-- Tipos de licencia (basados en la LCT argentina; editables desde Administración > Tipos de licencia)
INSERT IGNORE INTO leave_types
 (code, name, description, color, requires_approval, requires_document, document_label, max_days_per_year, max_days_per_request, counting, uses_vacation_balance, min_notice_days, sort) VALUES
 ('vacaciones',  'Vacaciones',               'Vacaciones anuales según antigüedad (LCT art. 150).',              '#663399', 1, 0, NULL,                                  NULL, NULL, 'corridos', 1, 15, 1),
 ('estudio',     'Día de estudio / examen',  'Hasta 2 días corridos por examen, máximo 10 por año (LCT art. 158).', '#2E86AB', 1, 0, 'Constancia de examen',             10,   2,    'corridos', 0, 3, 2),
 ('enfermedad',  'Enfermedad',               'No requiere autorización. El certificado médico es obligatorio.',  '#D1495B', 0, 1, 'Certificado médico',                 NULL, NULL, 'corridos', 0, 0, 3),
 ('personal',    'Trámite / día personal',   'Ausencia por trámites o motivos personales.',                      '#E0A100', 1, 0, NULL,                                  3,    1,    'habiles',  0, 2, 4),
 ('mudanza',     'Mudanza',                  '1 día por mudanza.',                                               '#8C6A4F', 1, 0, 'Constancia de domicilio',            1,    1,    'habiles',  0, 5, 5),
 ('matrimonio',  'Matrimonio',               '10 días corridos (LCT art. 158).',                                  '#C2185B', 1, 0, 'Acta de matrimonio',                 NULL, 10,   'corridos', 0, 15, 6),
 ('nacimiento',  'Nacimiento de hijo/a',     'Licencia por nacimiento (LCT art. 158).',                           '#00897B', 0, 0, 'Partida de nacimiento',              NULL, 2,    'corridos', 0, 0, 7),
 ('fallecimiento','Fallecimiento de familiar','3 días cónyuge, hijos o padres; 1 día hermano/a (LCT art. 158).',  '#455A64', 0, 0, 'Acta de defunción',                  NULL, 3,    'corridos', 0, 0, 8),
 ('maternidad',  'Maternidad',               '90 días (LCT art. 177). Requiere certificado médico.',              '#9CC930', 0, 1, 'Certificado médico con fecha probable de parto', NULL, 90, 'corridos', 0, 0, 9),
 ('donacion',    'Donación de sangre',       '1 día. Requiere constancia del centro de salud.',                   '#B71C1C', 0, 1, 'Constancia de donación',             NULL, 1,    'corridos', 0, 0, 10);

-- Feriados nacionales Argentina 2026 (verificar y ajustar desde Administración > Feriados)
INSERT IGNORE INTO holidays (date, name) VALUES
 ('2026-01-01','Año Nuevo'),
 ('2026-02-16','Carnaval'),
 ('2026-02-17','Carnaval'),
 ('2026-03-23','Feriado puente turístico'),
 ('2026-03-24','Día Nacional de la Memoria por la Verdad y la Justicia'),
 ('2026-04-02','Día del Veterano y de los Caídos en Malvinas'),
 ('2026-04-03','Viernes Santo'),
 ('2026-05-01','Día del Trabajador'),
 ('2026-05-25','Revolución de Mayo'),
 ('2026-06-15','Paso a la Inmortalidad del Gral. Güemes'),
 ('2026-06-20','Paso a la Inmortalidad del Gral. Belgrano'),
 ('2026-07-09','Día de la Independencia'),
 ('2026-07-10','Feriado puente turístico'),
 ('2026-08-17','Paso a la Inmortalidad del Gral. San Martín'),
 ('2026-10-12','Día del Respeto a la Diversidad Cultural'),
 ('2026-11-23','Día de la Soberanía Nacional'),
 ('2026-12-07','Feriado puente turístico'),
 ('2026-12-08','Inmaculada Concepción de María'),
 ('2026-12-25','Navidad');

INSERT IGNORE INTO settings (k, v) VALUES
 ('company_name', 'Intellihelp'),
 ('mail_driver', 'mail'),
 ('mail_from', 'rrhh@intellihelp.tech'),
 ('mail_from_name', 'RRHH Intellihelp'),
 ('smtp_host', 'smtp.gmail.com'),
 ('smtp_port', '587'),
 ('smtp_secure', 'tls'),
 ('smtp_user', ''),
 ('smtp_pass', ''),
 ('hr_email', ''),
 ('wa_driver', 'off'),
 ('wa_cloud_token', ''),
 ('wa_cloud_phone_id', ''),
 ('wa_cloud_template', ''),
 ('reminder_hours', '24');
