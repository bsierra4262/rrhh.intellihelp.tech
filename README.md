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

## Notificaciones

| Canal | Estado | Para qué |
|---|---|---|
| Portal (campanita) | ✅ | Todos |
| Email (SMTP `helpdesk@intellihelp.tech`; soporte: `soporte@intellihelp.tech`) | ✅ | Solicitante, aprobadores y RRHH ("A todos") |
| Google Chat (webhook) | Configurar | Espacio de aprobadores: nuevas solicitudes, licencias, certificados, decisiones, recordatorios |
| Google Calendar (cuenta de servicio) | Configurar | Calendario compartido: amarillo pendiente, verde aprobada, violeta informada; se borra si se rechaza/cancela |
| WhatsApp | Desactivado | Drivers disponibles: Evolution API / WAHA (autoalojado), CallMeBot, Cloud API de Meta |

### Google Chat
1. Google Chat → Nuevo espacio "RRHH – Aprobaciones" → sumar a los aprobadores.
2. En el espacio: Apps e integraciones → Webhooks → Agregar → copiar la URL.
3. Portal: Administración → Configuración → Google Chat → pegar → Guardar → "Probar Google Chat".

### Google Calendar
1. https://console.cloud.google.com → crear proyecto "RRHH Intellihelp".
2. APIs y servicios → Biblioteca → **Google Calendar API** → Habilitar.
3. IAM → Cuentas de servicio → Crear ("rrhh-calendar") → Claves → Agregar clave → JSON (se descarga un archivo).
4. Google Calendar → Otros calendarios → Crear calendario "Licencias Intellihelp" → Configuración → Compartir con personas específicas:
   - el `client_email` de la cuenta de servicio → **Hacer cambios en eventos**
   - los aprobadores → Ver todos los detalles del evento
5. Copiar el **ID del calendario** (Integrar el calendario).
6. Portal: Configuración → Google Calendar → pegar ID y el contenido del JSON → Guardar → "Probar Google Calendar".

## Cron

Configurado en hPanel (Avanzado → Cron Jobs):
`/usr/bin/php /home/uXXXXXXXXX/domains/rrhh.intellihelp.tech/app/cron.php`
Envía recordatorios de solicitudes pendientes (cada N horas, Configuración) y reintenta la sincronización con Google Calendar.

## Deploy

Subir `app/` (sin `vendor/`, `storage/`, `config.php`) y `public_html/` a `~/domains/rrhh.intellihelp.tech/`, luego en el servidor:

```
cd ~/domains/rrhh.intellihelp.tech/app && composer install --no-dev
php install.php "Nombre Apellido" email@intellihelp.tech   # solo la primera vez (idempotente)
```
