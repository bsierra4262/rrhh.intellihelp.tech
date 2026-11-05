<?php

/**
 * Portal de Recursos Humanos — Intellihelp
 *
 * Ingreso con Google (OpenID Connect) para cuentas del dominio de la empresa.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */

declare(strict_types=1);

/**
 * Indica si el ingreso con Google está configurado.
 */
function google_login_enabled(): bool
{
    return setting('google_client_id') !== '' && setting('google_client_secret') !== '';
}

/**
 * Dirección a la que Google devuelve al usuario; debe estar autorizada en el cliente OAuth.
 */
function google_redirect_uri(): string
{
    return url('/auth/google/callback');
}

/**
 * Inicia el ingreso con Google.
 */
function google_start(): void
{
    if (!google_login_enabled()) {
        redirect('/login');
    }
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['google_oauth'] = ['state' => $state, 'nonce' => $nonce, 'at' => time()];
    redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => setting('google_client_id'),
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'nonce' => $nonce,
        'prompt' => 'select_account',
        // Sugiere a Google mostrar solo las cuentas de la empresa.
        'hd' => trusted_domains()[0] ?? '',
    ]));
}

/**
 * Vuelta desde Google: valida la identidad y abre la sesión.
 *
 * Solo se aceptan cuentas verificadas de los dominios de confianza. Si la persona todavía no
 * tiene cuenta en el portal se crea activa, igual que en el autoregistro con correo corporativo.
 */
function google_callback(): void
{
    $flow = $_SESSION['google_oauth'] ?? null;
    unset($_SESSION['google_oauth']);
    $fail = function (string $msg): never {
        flash('error', $msg);
        redirect('/login');
    };
    if (isset($_GET['error'])) {
        $fail('Se canceló el ingreso con Google.');
    }
    if (!$flow || !hash_equals($flow['state'], (string) ($_GET['state'] ?? '')) || time() - $flow['at'] > 600) {
        $fail('El ingreso con Google venció. Intentá de nuevo.');
    }

    [$code, $body] = http_request('https://oauth2.googleapis.com/token', http_build_query([
        'code' => (string) ($_GET['code'] ?? ''),
        'client_id' => setting('google_client_id'),
        'client_secret' => setting('google_client_secret'),
        'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]), ['Content-Type: application/x-www-form-urlencoded']);
    $token = json_decode((string) $body, true);
    if ($code !== 200 || empty($token['id_token'])) {
        log_line('auth.log', "Google: error al canjear el código (HTTP $code) " . mb_substr((string) $body, 0, 300));
        $fail('No pudimos validar tu cuenta de Google. Intentá de nuevo.');
    }

    // El token llega directamente de Google por TLS; se validan emisor, audiencia, vencimiento y nonce.
    $part = explode('.', $token['id_token'])[1] ?? '';
    $claims = json_decode((string) base64_decode(strtr($part, '-_', '+/')), true) ?: [];
    $valid = in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
        && ($claims['aud'] ?? '') === setting('google_client_id')
        && (int) ($claims['exp'] ?? 0) >= time()
        && hash_equals($flow['nonce'], (string) ($claims['nonce'] ?? ''));
    if (!$valid) {
        log_line('auth.log', 'Google: token inválido para ' . ($claims['email'] ?? '?'));
        $fail('No pudimos validar tu cuenta de Google. Intentá de nuevo.');
    }

    $email = mb_strtolower((string) ($claims['email'] ?? ''));
    $domain = mb_strtolower((string) ($claims['hd'] ?? ''));
    if (empty($claims['email_verified']) || !is_trusted_email($email) || !in_array($domain, trusted_domains(), true)) {
        $fail('El ingreso con Google es solo para cuentas @' . implode(', @', trusted_domains()) . '. Si sos personal externo, ingresá con tu correo y contraseña.');
    }

    $u = row('SELECT * FROM users WHERE email = ?', [$email]);
    if ($u && !$u['active'] && !$u['pending_activation']) {
        $fail('Tu cuenta del portal está desactivada. Comunicate con RRHH.');
    }
    $approver = (int) setting('default_approver_id', '0') ?: null;
    if (!$u) {
        $name = mb_substr(trim((string) ($claims['name'] ?? '')) ?: explode('@', $email)[0], 0, 120);
        $id = insert('users', [
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'role' => 'employee',
            'company' => setting('company_name', 'Intellihelp') ?: 'Intellihelp',
            'manager_id' => $approver,
            'active' => 1,
            'calendar_token' => bin2hex(random_bytes(20)),
        ]);
        defer(fn () => notify_hr_new_user($id, false));
    } elseif ($u['pending_activation']) {
        // Una cuenta registrada que no había confirmado su correo queda confirmada por Google.
        $id = (int) $u['id'];
        q('UPDATE users SET active = 1, pending_activation = 0, pending_approval = 0, manager_id = COALESCE(manager_id, ?) WHERE id = ?', [$approver, $id]);
        q('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$id]);
        defer(fn () => notify_hr_new_user($id, false));
    } else {
        $id = (int) $u['id'];
    }

    login_user(row('SELECT * FROM users WHERE id = ?', [$id]));
    log_line('auth.log', "Google: ingreso de $email");
    $to = $_SESSION['intended'] ?? '/';
    unset($_SESSION['intended']);
    redirect(str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/');
}
