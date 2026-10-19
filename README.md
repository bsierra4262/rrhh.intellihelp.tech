# Portal RRHH — Intellihelp

Portal para pedir y autorizar vacaciones, días de estudio y licencias. En producción en https://rrhh.intellihelp.tech (Hostinger).

Stack: PHP 8.3 sin framework + MariaDB + PHPMailer. Identidad: Saira, #663399, #161922, #9CC930.

## Estructura

```
app/                 → fuera del docroot (~/domains/rrhh.intellihelp.tech/app)
  config.php         → credenciales DB + app_key (NO versionar)
  controllers/ lib/ views/ sql/
  storage/           → uploads (certificados), logs, sesiones
  install.php        → crea tablas + datos iniciales + admin
  cron.php           → recordatorios a aprobadores
public_html/         → docroot: index.php (front controller), .htaccess, assets/
```

## Flujo

- **Requiere autorización** (vacaciones, estudio, trámite, mudanza, matrimonio): queda *Pendiente* → se avisa al responsable directo (o a RRHH si no tiene) por aviso en el portal, email con link "Agregar a Google Calendar" y WhatsApp → entra al portal y autoriza o rechaza → se avisa a la persona con invitación .ics (Gmail la agrega sola a Google Calendar).
- **Solo se informa** (enfermedad, nacimiento, fallecimiento, maternidad, donación): queda *Informada*. En enfermedad el certificado médico es **obligatorio** para poder enviarla; RRHH y el responsable reciben el aviso "ya subió el certificado". RRHH puede marcar la documentación como verificada u observada.
- Saldo de vacaciones automático según antigüedad (LCT art. 150) o manual por persona. Topes por tipo editables en Administración → Tipos de licencia. Días hábiles descuentan feriados (Administración → Feriados).
- Roles: Colaborador/a, Responsable de equipo (autoriza a su equipo), RRHH (ve y autoriza todo), Administrador/a.
- Cada persona tiene un feed iCal personal (Calendario / Mi perfil) para suscribirse desde Google Calendar.

## Configuración pendiente

1. **Email** — ✅ configurado: `smtp.gmail.com`, 587, STARTTLS, cuenta `helpdesk@intellihelp.tech` con contraseña de aplicación (Administración → Configuración).
2. **WhatsApp** — CallMeBot (gratis, cada persona carga su apikey en Mi perfil) o WhatsApp Cloud API de Meta (token, phone number id y plantilla aprobada con un parámetro `{{1}}`).
3. **Cron** (hPanel → Avanzado → Cron Jobs, cada hora):
   `/usr/bin/php /home/uXXXXXXXXX/domains/rrhh.intellihelp.tech/app/cron.php`
4. Revisar los feriados 2026 cargados y agregar los de 2027.

## Deploy

Subir `app/` (sin `vendor/`, `storage/`, `config.php`) y `public_html/` a `~/domains/rrhh.intellihelp.tech/`, luego en el servidor:

```
cd ~/domains/rrhh.intellihelp.tech/app && composer install --no-dev
php install.php "Nombre Apellido" email@intellihelp.tech   # solo la primera vez (idempotente)
```
