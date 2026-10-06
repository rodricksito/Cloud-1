<?php
declare(strict_types=1);
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/validar.php';

final class ErrorApi extends RuntimeException
{
    public function __construct(public readonly int $estado, string $mensaje, public readonly array $errores = [])
    {
        parent::__construct($mensaje);
    }
}

function responder(array $datos, int $estado = 200): never
{
    http_response_code($estado);
    echo json_encode(['ok' => true, 'data' => $datos], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

function leerJson(): array
{
    $tipo = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($tipo !== 'application/json') {
        throw new ErrorApi(415, 'Se requiere contenido JSON.');
    }
    $contenido = file_get_contents('php://input', false, null, 0, 16385);
    if ($contenido === false || strlen($contenido) > 16384) {
        throw new ErrorApi(413, 'La petición es demasiado grande.');
    }
    try {
        $datos = json_decode($contenido, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new ErrorApi(400, 'La petición JSON no es válida.');
    }
    if (!is_array($datos) || !preg_match('/^\s*\{/', $contenido)) {
        throw new ErrorApi(400, 'La petición debe contener un objeto JSON.');
    }
    return $datos;
}

function nuevaSesion(PDO $pdo, ?int $usuarioId = null): array
{
    $token = bin2hex(random_bytes(32));
    $sesion = [
        'token_hash' => hash('sha256', $token),
        'csrf_token' => bin2hex(random_bytes(32)),
        'usuario_id' => $usuarioId,
        'token' => $token,
    ];
    $stmt = $pdo->prepare('INSERT INTO sesiones (token_hash, csrf_token, usuario_id, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$sesion['token_hash'], $sesion['csrf_token'], $usuarioId, gmdate('Y-m-d H:i:s', time() + duracionSesion())]);
    return $sesion;
}

function leerSesion(PDO $pdo, bool $crear = false): array
{
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    $sesion = false;
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $stmt = $pdo->prepare('SELECT s.*, u.username, u.activo FROM sesiones s LEFT JOIN usuarios u ON u.id = s.usuario_id WHERE s.token_hash = ? AND s.expires_at > UTC_TIMESTAMP()');
        $stmt->execute([hash('sha256', $token)]);
        $sesion = $stmt->fetch();
        if ($sesion && $sesion['usuario_id'] !== null && !(bool) $sesion['activo']) {
            $pdo->prepare('DELETE FROM sesiones WHERE token_hash = ?')->execute([$sesion['token_hash']]);
            $sesion = false;
        }
    }
    // Limpieza acotada: conserva las sesiones activas y evita acumular expiradas.
    $pdo->exec('DELETE FROM sesiones WHERE expires_at <= UTC_TIMESTAMP() LIMIT 100');
    if (!$sesion) {
        if (!$crear) {
            throw new ErrorApi(401, 'La sesión terminó. Inicia sesión de nuevo.');
        }
        return nuevaSesion($pdo);
    }
    $pdo->prepare('UPDATE sesiones SET expires_at = ? WHERE token_hash = ?')
        ->execute([gmdate('Y-m-d H:i:s', time() + duracionSesion()), $sesion['token_hash']]);
    return $sesion;
}

function sesionPublica(array $sesion): array
{
    $datos = [
        'user' => $sesion['usuario_id'] === null ? null : [
            'id' => (int) $sesion['usuario_id'],
            'username' => (string) $sesion['username'],
        ],
        'csrf' => $sesion['csrf_token'],
        'expires_in' => duracionSesion(),
    ];
    if (isset($sesion['token'])) {
        $datos['token'] = $sesion['token'];
    }
    return $datos;
}

function exigirUsuario(array $sesion): void
{
    if ($sesion['usuario_id'] === null) {
        throw new ErrorApi(401, 'Inicia sesión para acceder a la agenda.');
    }
}

function exigirCsrf(array $sesion, array $datos): void
{
    $csrf = $datos['csrf'] ?? null;
    if (!is_string($csrf) || !hash_equals($sesion['csrf_token'], $csrf)) {
        throw new ErrorApi(403, 'El formulario venció o no es válido. Recarga la página e intenta de nuevo.');
    }
}

function iniciarSesion(PDO $pdo, array $sesion, array $datos): array
{
    exigirCsrf($sesion, $datos);
    $usuario = $datos['username'] ?? null;
    $clave = $datos['password'] ?? null;
    if (!is_string($usuario) || !is_string($clave)) {
        throw new ErrorApi(422, 'Escribe el usuario y la contraseña.');
    }
    $usuario = strtolower(trim($usuario));
    if (!preg_match('/^[a-z0-9_.-]{3,64}$/D', $usuario) || $clave === '' || strlen($clave) > 72 || str_contains($clave, "\0")) {
        throw new ErrorApi(422, 'El usuario o la contraseña no tienen un formato válido.');
    }
    $cuenta = hash('sha256', $usuario);
    $stmt = $pdo->prepare('SELECT fallos FROM intentos_login WHERE cuenta_hash = ? AND ventana_inicio > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)');
    $stmt->execute([$cuenta]);
    if ((int) $stmt->fetchColumn() >= 10) {
        throw new ErrorApi(429, 'Demasiados intentos. Espera 15 minutos antes de intentar de nuevo.');
    }
    $stmt = $pdo->prepare('SELECT id, username, password_hash, activo FROM usuarios WHERE username = ?');
    $stmt->execute([$usuario]);
    $fila = $stmt->fetch();
    // Hash de un valor aleatorio descartado; iguala el coste para usuarios inexistentes.
    $hashVacio = '$2y$12$7CKMf7eJSMuIEGJQ6WZId.2SXg4ao52GooVcFzf5s3u9T4XpBNfyy';
    $valida = password_verify($clave, $fila['password_hash'] ?? $hashVacio);
    if (!$fila || !$valida || !(bool) $fila['activo']) {
        $stmt = $pdo->prepare('INSERT INTO intentos_login (cuenta_hash, fallos, ventana_inicio) VALUES (?, 1, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE fallos = IF(ventana_inicio > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), fallos + 1, 1), ventana_inicio = IF(ventana_inicio > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), ventana_inicio, UTC_TIMESTAMP())');
        $stmt->execute([$cuenta]);
        throw new ErrorApi(401, 'Usuario o contraseña incorrectos.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM intentos_login WHERE cuenta_hash = ?')->execute([$cuenta]);
        $pdo->prepare('DELETE FROM sesiones WHERE token_hash = ?')->execute([$sesion['token_hash']]);
        if (password_needs_rehash($fila['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]), $fila['id']]);
        }
        $pdo->prepare('UPDATE usuarios SET ultimo_login = UTC_TIMESTAMP() WHERE id = ?')->execute([$fila['id']]);
        $nueva = nuevaSesion($pdo, (int) $fila['id']);
        $nueva['username'] = $fila['username'];
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    return sesionPublica($nueva);
}

function idContacto(): int
{
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ($id === false || $id === null) {
        throw new ErrorApi(400, 'El identificador del contacto no es válido.');
    }
    return $id;
}

function buscarContacto(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT id, nombre, apellidos, telefono, email, direccion FROM contactos WHERE id = ?');
    $stmt->execute([$id]);
    $contacto = $stmt->fetch();
    if (!$contacto) {
        throw new ErrorApi(404, 'El contacto no existe.');
    }
    return $contacto;
}
