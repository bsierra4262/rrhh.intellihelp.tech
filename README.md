<p align="center">
  <img src="docs/banner.png" alt="Portal de Recursos Humanos — Intellihelp" width="100%">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white" alt="PHP 8.3">
  <img src="https://img.shields.io/badge/MariaDB-11-003545?logo=mariadb&logoColor=white" alt="MariaDB">
  <img src="https://img.shields.io/badge/Google%20Calendar-API-4285F4?logo=googlecalendar&logoColor=white" alt="Google Calendar API">
  <img src="https://img.shields.io/badge/Google%20Chat-Webhooks-00AC47?logo=googlechat&logoColor=white" alt="Google Chat">
  <img src="https://img.shields.io/badge/Hosting-Hostinger-673DE6?logo=hostinger&logoColor=white" alt="Hostinger">
  <img src="https://img.shields.io/badge/uso-interno-663399" alt="Uso interno">
</p>

<p align="center">
  <b><a href="https://rrhh.intellihelp.tech">rrhh.intellihelp.tech</a></b>
</p>

---

## Descripción

**Portal de Recursos Humanos de Intellihelp**: plataforma interna para solicitar, aprobar y registrar vacaciones, días de estudio, licencias por enfermedad y demás ausencias del personal, incluido el personal tercerizado.

Reemplaza los pedidos por mail o mensajería por un circuito único y trazable:

- el colaborador **pide** desde el portal (también desde el celular);
- quien autoriza **recibe el aviso** por Google Chat, email y Google Calendar;
- **autoriza o rechaza** con un clic y el colaborador recibe la respuesta;
- todo queda **registrado** con historial, saldos y documentación respaldatoria.

Las licencias que no requieren autorización (por ejemplo, enfermedad) se **informan**, y el certificado médico es **obligatorio** para poder enviarlas.

## Índice

- [Capturas](#capturas)
- [Funcionalidades](#funcionalidades)
- [Circuito de una solicitud](#circuito-de-una-solicitud)
- [Notificaciones](#notificaciones)
- [Arquitectura](#arquitectura)
- [Seguridad](#seguridad)
- [Instalación y despliegue](#instalación-y-despliegue)
- [Configuración](#configuración)
- [Autor](#autor)

## Capturas

> Las capturas usan datos ficticios de demostración.

### Ingreso

<p align="center"><img src="docs/screenshots/login.png" alt="Pantalla de ingreso" width="100%"></p>

### Inicio — vista de Recursos Humanos

Solicitudes por autorizar, documentación para verificar, ausentes del día y saldos.

<p align="center"><img src="docs/screenshots/dashboard-rrhh.png" alt="Inicio de RRHH" width="100%"></p>

### Nueva solicitud

Selección del tipo de licencia, cálculo automático de días (corridos o hábiles, descontando feriados), fecha de regreso, validación de saldos y adjuntos.

<p align="center"><img src="docs/screenshots/nueva-solicitud.png" alt="Nueva solicitud" width="100%"></p>

### Decisión del responsable

Detalle de la solicitud, saldos del colaborador, alerta de superposición con el equipo e historial.

<p align="center"><img src="docs/screenshots/decision-responsable.png" alt="Autorizar o rechazar" width="100%"></p>

<table>
  <tr>
    <td width="50%"><b>Por autorizar</b><br><img src="docs/screenshots/aprobaciones.png" alt="Bandeja de aprobaciones"></td>
    <td width="50%"><b>Licencia por enfermedad con certificado</b><br><img src="docs/screenshots/licencia-enfermedad.png" alt="Licencia por enfermedad"></td>
  </tr>
  <tr>
    <td width="50%"><b>Calendario del equipo</b><br><img src="docs/screenshots/calendario.png" alt="Calendario"></td>
    <td width="50%"><b>Todas las licencias (filtros y exportación CSV)</b><br><img src="docs/screenshots/todas-las-licencias.png" alt="Listado de licencias"></td>
  </tr>
  <tr>
    <td width="50%"><b>Personas</b><br><img src="docs/screenshots/personas.png" alt="Administración de personas"></td>
    <td width="50%"><b>Tipos de licencia configurables</b><br><img src="docs/screenshots/tipos-de-licencia.png" alt="Tipos de licencia"></td>
  </tr>
</table>

### Celular

<p align="center">
  <img src="docs/screenshots/mobile-login.png" alt="Ingreso en celular" width="30%">
  &nbsp;
  <img src="docs/screenshots/mobile-inicio.png" alt="Inicio en celular" width="30%">
  &nbsp;
  <img src="docs/screenshots/mobile-enfermedad.png" alt="Informar enfermedad desde el celular" width="30%">
</p>

### Email de notificación

<p align="center"><img src="docs/screenshots/email-aprobacion.png" alt="Email de solicitud para autorizar" width="60%"></p>

## Funcionalidades

### Colaboradores
- Solicitud de **vacaciones, días de estudio/examen, trámites, mudanza, matrimonio, nacimiento, fallecimiento, maternidad, donación de sangre** y **enfermedad**.
- Cálculo automático de días **corridos o hábiles**, descontando fines de semana y **feriados nacionales**.
- **Saldos** por tipo de licencia; vacaciones calculadas según antigüedad conforme a la **LCT (art. 150)**: 14, 21, 28 o 35 días.
- Validaciones: superposición de fechas, topes anuales y por solicitud, anticipación sugerida.
- Carga de **certificados y constancias** (PDF o foto, también desde la cámara del celular).
- Historial completo, cancelación de solicitudes y notificaciones dentro del portal.
- Calendario personal suscribible (iCal).

### Responsables y Recursos Humanos
- Bandeja **Por autorizar** con aprobación en un clic; el rechazo exige un motivo.
- Alerta de **superposición** con otras ausencias del equipo o del área.
- Verificación u **observación de documentación** médica.
- **Calendario** mensual de ausencias y listado general con **filtros y exportación CSV**.
- **Recordatorios automáticos** de solicitudes pendientes.
- Carga de licencias en nombre de un colaborador (con aprobación directa opcional).

### Administración
- Alta de **personas** con rol, responsable directo, área, empresa, fecha de ingreso y saldo manual opcional.
- **Autoregistro** de colaboradores: los correos del dominio corporativo se activan solos; los de **personal tercerizado** quedan pendientes de aprobación por RRHH.
- **Tipos de licencia** configurables: autorización, documentación obligatoria, topes, forma de conteo, color.
- **Feriados** editables por año.
- Configuración de **canales de notificación** con pruebas integradas (email, Google Chat, Google Calendar).

### Roles

| Rol | Alcance |
|---|---|
| Colaborador/a | Pide y consulta sus propias licencias |
| Responsable de equipo | Autoriza a su equipo y ve su calendario |
| Recursos Humanos | Ve y autoriza todo, verifica documentación, administra personas y tipos |
| Administrador/a | Todo lo anterior más la configuración del sistema |

## Circuito de una solicitud

```mermaid
flowchart LR
    A[Colaborador<br>carga la solicitud] --> B{¿Requiere<br>autorización?}
    B -- Sí --> C[Pendiente]
    C -->|Aviso: Chat · Email · Calendar| D[Responsable / RRHH]
    D -->|Autoriza| E[Aprobada]
    D -->|Rechaza con motivo| F[Rechazada]
    B -- No --> G[Informada<br>con certificado]
    G -->|Aviso a RRHH y responsable| H[RRHH verifica<br>documentación]
    E --> I[(Google Calendar<br>en verde)]
    C --> J[(Google Calendar<br>en amarillo)]
    F --> K[Se borra<br>del calendario]
```

| Estado | Significado | En Google Calendar |
|---|---|---|
| Pendiente | Espera la decisión del responsable | 🟡 `⏳ [Pendiente] Tipo — Persona` |
| Aprobada | Autorizada | 🟢 `Tipo — Persona` |
| Informada | Licencia sin autorización (ej. enfermedad) | 🟣 `Tipo — Persona` |
| Rechazada / Cancelada | Sin efecto | Se elimina |

## Notificaciones

| Canal | Destinatarios | Contenido |
|---|---|---|
| **Portal** | Todos | Avisos con acceso directo a la solicitud |
| **Email** | Colaborador, aprobadores y casilla de RRHH | Detalle de la solicitud, botón de acción e invitación `.ics` |
| **Google Chat** | Espacio de aprobadores | Nuevas solicitudes, licencias informadas, certificados, decisiones y recordatorios |
| **Google Calendar** | Calendario compartido de licencias | Evento por solicitud, actualizado según el estado |

La integración con Google Calendar usa una **cuenta de servicio** con **delegación de todo el dominio**, por lo que no requiere compartir el calendario con cuentas externas a la organización.

## Arquitectura

Aplicación **PHP 8.3 sin framework**, pensada para hosting compartido: sin procesos residentes ni dependencias de compilación.

```
├── app/                    # Fuera del docroot (no accesible desde la web)
│   ├── bootstrap.php       # Carga de configuración y librerías
│   ├── routes.php          # Rutas y despacho
│   ├── controllers/        # Autenticación, registro, solicitudes, calendario, perfil, administración
│   ├── lib/
│   │   ├── leave.php       # Reglas de negocio: conteo de días, saldos LCT, validaciones
│   │   ├── notify.php      # Orquestación de notificaciones por evento
│   │   ├── gcal.php        # Cliente de Google Calendar (JWT de cuenta de servicio)
│   │   ├── mailer.php      # Envío SMTP y plantilla de email
│   │   ├── ics.php         # Invitaciones y feeds iCalendar
│   │   └── whatsapp.php    # Google Chat y conectores opcionales de WhatsApp
│   ├── views/              # Plantillas PHP
│   ├── sql/                # Esquema, datos iniciales y migraciones idempotentes
│   ├── cron.php            # Recordatorios y resincronización
│   └── install.php         # Instalador por línea de comandos
├── public_html/            # Docroot: front controller, .htaccess y assets
└── docs/                   # Banner y capturas
```

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.3 (PDO, cURL, OpenSSL, fileinfo) |
| Base de datos | MariaDB 11 / MySQL 8 |
| Email | PHPMailer (SMTP de Google Workspace) |
| Integraciones | Google Calendar API, Google Chat Webhooks, iCalendar |
| Frontend | HTML + CSS propio + JavaScript sin dependencias |
| Identidad | Tipografía Saira · `#663399` · `#161922` · `#9CC930` |

## Seguridad

- Contraseñas con `password_hash` (bcrypt/argon) y política de complejidad.
- Protección **CSRF** en todos los formularios y cookies de sesión `HttpOnly`, `Secure` y `SameSite`.
- Consultas **parametrizadas** (PDO) y escape de salida en todas las vistas.
- **Bloqueo por intentos fallidos** de ingreso y de recuperación de contraseña.
- Certificados almacenados **fuera del docroot**, servidos solo a usuarios autorizados, con validación de tipo MIME y tamaño.
- **Secretos cifrados** en base de datos (AES-256-GCM): contraseña SMTP, clave de la cuenta de servicio, webhooks.
- Validación de correo por enlace en el autoregistro y aprobación manual para dominios externos.
- HTTPS forzado, HSTS y cabeceras `X-Frame-Options`, `X-Content-Type-Options` y `Referrer-Policy`.

## Instalación y despliegue

### Requisitos
- PHP 8.2+ con `pdo_mysql`, `curl`, `openssl`, `mbstring`, `fileinfo`
- MariaDB 10.6+ o MySQL 8
- Composer

### Pasos

```bash
# 1. Subir app/ y public_html/ al servidor
#    (public_html/ como docroot; app/ al mismo nivel, fuera de la web)

# 2. Configuración
cp app/config.sample.php app/config.php
#    completar credenciales de base de datos y app_key (64 caracteres hex):
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

# 3. Dependencias
cd app && composer install --no-dev

# 4. Base de datos y usuario administrador (idempotente: también aplica migraciones)
php install.php "Nombre Apellido" admin@intellihelp.tech
```

### Tarea programada

Ejecutar cada hora (en hPanel: **Avanzado → Cron Jobs**):

```
/usr/bin/php /home/<usuario>/domains/rrhh.intellihelp.tech/app/cron.php
```

Envía recordatorios de solicitudes pendientes y reintenta la sincronización con Google Calendar.

## Configuración

Todo se configura desde **Administración → Configuración**, sin tocar código:

| Sección | Parámetros |
|---|---|
| General | Nombre de la empresa, correo de soporte, casilla de RRHH, aprobador por defecto, frecuencia de recordatorios |
| Registro | Habilitar autoregistro, dominios de confianza |
| Email | Servidor SMTP, remitente, alcance de los avisos |
| Google Calendar | ID del calendario, clave JSON de la cuenta de servicio, usuario a representar |
| Google Chat | Webhook del espacio de aprobadores |

<details>
<summary><b>Configurar Google Calendar</b></summary>

1. En Google Cloud: crear un proyecto, habilitar **Google Calendar API** y crear una **cuenta de servicio** con clave JSON.
2. En la Consola de administración de Google Workspace: **Seguridad → Controles de API → Delegación de todo el dominio**, autorizar el *Client ID* de la cuenta de servicio con el permiso `https://www.googleapis.com/auth/calendar`.
3. Crear el calendario compartido de licencias y compartirlo con quienes autorizan.
4. En el portal: cargar el ID del calendario, la clave JSON y el usuario dueño del calendario en «Actuar en nombre de».
5. **Probar Google Calendar** crea un evento de prueba; después de verlo en el calendario, **Finalizar test** lo elimina.

</details>

<details>
<summary><b>Configurar Google Chat</b></summary>

1. Crear un espacio (por ejemplo, «RRHH – Aprobaciones») y sumar a quienes autorizan.
2. En el espacio: **Apps e integraciones → Webhooks → Agregar webhook** y copiar la URL.
3. Pegarla en el portal y usar **Probar Google Chat**.

</details>

## Autor

**Brian Gabriel Sierra**
Jefe de Sistemas e Infraestructura IT — Intellihelp

[![LinkedIn](https://img.shields.io/badge/LinkedIn-brian--gabriel--sierra-0A66C2?logo=linkedin&logoColor=white)](https://www.linkedin.com/in/brian-gabriel-sierra)

---

<p align="center">
  <sub>© 2026 Intellihelp · Software de uso interno. Todos los derechos reservados.</sub>
</p>
