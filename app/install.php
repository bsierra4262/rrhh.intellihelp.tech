<?php
// Instalación por SSH:  php install.php "Nombre Apellido" email@dominio
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

foreach (['uploads', 'logs', 'sessions'] as $d) {
    if (!is_dir(STORAGE . "/$d")) mkdir(STORAGE . "/$d", 0750, true);
}

foreach (['schema.sql', 'seed.sql', 'migrate.sql'] as $f) {
    $sql = file_get_contents(__DIR__ . "/sql/$f");
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
        if ($stmt !== '' && !preg_match('/^(--[^\n]*\n?)+$/', $stmt)) db()->exec($stmt);
    }
    echo "✓ $f\n";
}

[$name, $email] = [$argv[1] ?? null, $argv[2] ?? null];
if ($name && $email) {
    $email = mb_strtolower($email);
    if (val('SELECT id FROM users WHERE email = ?', [$email])) {
        echo "• El usuario $email ya existe\n";
    } else {
        $pass = random_password();
        insert('users', [
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
            'role' => 'admin',
            'must_change_password' => 1,
            'calendar_token' => bin2hex(random_bytes(20)),
            'department' => 'Recursos Humanos',
        ]);
        echo "✓ Admin creado: $email / $pass\n";
    }
}
