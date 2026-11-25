# Changelog

Cambios notables del Portal de RRHH (Capital Humano). El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/).

## 2026-11-25 · Ingreso mixto con ITHub (en producción)

- **Personal @intellihelp.tech: solo ITHub.** «Ingresar con ITHub (personal de Intellihelp)» entra
  con la sesión única (OpenID Connect, código + PKCE; `id_token` validado entero: RS256 con la
  clave de ITHub, emisor, destinatario, vencimiento y nonce). Para esos correos, el formulario de
  contraseña, «Creá tu cuenta», el recupero, la activación por correo, Google directo, el cambio de
  contraseña del perfil y «Restablecer contraseña» de la ficha se rechazan del lado del servidor.
- **Personas de otras empresas: como siempre.** Correo y contraseña, alta de cuenta y recupero, con
  el rol que les asigna RRHH.
- **El rol del personal interno lo decide ITHub** (`admin` | `hr` | `manager` | `employee`) y se
  aplica en cada ingreso. Responsable, área, puesto, legajo y estado siguen en el portal; una
  persona desactivada o desafectada no entra aunque tenga permiso en ITHub.
- **Una sola salida**: «Cerrar sesión» de una sesión abierta con ITHub cierra la sesión única.
  ITHub avisa los cierres por detrás a `/ithub-logout` y la sesión del portal se corta en el pedido
  siguiente (tabla `ithub_closed_sessions`). El cierre por inactividad no cierra ITHub.
- La pantalla de ingreso muestra primero ITHub y debajo el acceso para otras empresas, con el mismo
  panel violeta. `/ithub-login` (tarjeta de ITHub) entra directo.
- Sin la sección `ithub` en la configuración, todo sigue como antes.
- 38 pruebas en `tests/ithub.php` (firma, emisor, destinatario, vencimiento, nonce, PKCE, aviso de
  cierre, salida, dominio interno: correo interno rechazado en el formulario, externo aceptado).
