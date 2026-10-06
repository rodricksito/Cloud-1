<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/conexion.php';
$usuario = strtolower(trim($argv[1] ?? ''));
$actualizar = ($argv[2] ?? '') === '--update-password';
if ($argc < 2 || $argc > 3 || ($argc === 3 && !$actualizar) || !preg_match('/^[a-z0-9_.-]{3,64}$/D', $usuario)) {
    fwrite(STDERR, "Uso: php scripts/crear-usuario.php USUARIO [--update-password]\nUsuario: de 3 a 64 letras, números, puntos, guiones o guiones bajos.\nContraseña: variable USER_PASSWORD o entrada estándar, de 12 a 72 bytes.\n");
    exit(1);
}
$clave = getenv('USER_PASSWORD');
if ($clave === false) {
    if (function_exists('stream_isatty') && stream_isatty(STDIN)) {
        fwrite(STDERR, "Suministra USER_PASSWORD o una entrada estándar sin eco del terminal.\n");
        exit(1);
    }
    $clave = rtrim((string) stream_get_contents(STDIN, 1025), "\r\n");
}
if (strlen($clave) < 12 || strlen($clave) > 72 || str_contains($clave, "\0")) {
    fwrite(STDERR, "La contraseña debe tener entre 12 y 72 bytes y no contener bytes nulos.\n");
    exit(1);
}
try {
    $pdo = conectar();
    $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE username = ?');
    $stmt->execute([$usuario]);
    $id = $stmt->fetchColumn();
    if ($id !== false && !$actualizar) {
        fwrite(STDERR, "El usuario ya existe. Para cambiar su contraseña usa --update-password.\n");
        exit(1);
    }
    $hash = password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]);
    unset($clave);
    if ($id !== false) {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE usuarios SET password_hash = ?, activo = 1 WHERE id = ?')->execute([$hash, $id]);
        $pdo->prepare('DELETE FROM sesiones WHERE usuario_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM intentos_login WHERE cuenta_hash = ?')->execute([hash('sha256', $usuario)]);
        $pdo->commit();
        fwrite(STDOUT, "Contraseña actualizada y sesiones anteriores revocadas.\n");
    } else {
        $pdo->prepare('INSERT INTO usuarios (username, password_hash) VALUES (?, ?)')->execute([$usuario, $hash]);
        fwrite(STDOUT, "Usuario creado.\n");
    }
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "No se pudo guardar el usuario. Revisa la conexión, la migración y los permisos de la base.\n");
    exit(1);
}
