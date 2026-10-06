<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/backend.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-App-Instance: ' . nombreInstancia());

try {
    $clave = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!is_string($clave) || !hash_equals(apiKey(), $clave)) {
        throw new ErrorApi(403, 'Acceso no autorizado al backend.');
    }
    $accion = $_GET['action'] ?? '';
    $metodo = $_SERVER['REQUEST_METHOD'];
    $permitidos = [
        'health' => ['GET'],
        'session' => ['GET'],
        'login' => ['POST'],
        'logout' => ['POST'],
        'contacts' => ['GET', 'POST'],
        'contact' => ['GET', 'PUT', 'DELETE'],
    ];
    if (!is_string($accion) || !isset($permitidos[$accion])) {
        throw new ErrorApi(404, 'La operación no existe.');
    }
    if (!in_array($metodo, $permitidos[$accion], true)) {
        header('Allow: ' . implode(', ', $permitidos[$accion]));
        throw new ErrorApi(405, 'El método no está permitido.');
    }
    $pdo = conectar();
    if ($accion === 'health') {
        $pdo->query('SELECT 1')->fetchColumn();
        responder(['status' => 'ok', 'database' => 'ok']);
    }
    $sesion = leerSesion($pdo, $accion === 'session');
    if ($accion === 'session') {
        responder(sesionPublica($sesion));
    }
    $datos = $metodo !== 'GET' ? leerJson() : [];
    if ($accion === 'login') {
        responder(iniciarSesion($pdo, $sesion, $datos));
    }
    exigirUsuario($sesion);
    if ($metodo !== 'GET') {
        exigirCsrf($sesion, $datos);
    }
    if ($accion === 'logout') {
        $pdo->prepare('DELETE FROM sesiones WHERE token_hash = ?')->execute([$sesion['token_hash']]);
        responder(['logged_out' => true]);
    }
    if ($accion === 'contacts' && $metodo === 'GET') {
        $buscar = $_GET['buscar'] ?? '';
        if (!is_string($buscar) || strlen($buscar) > 600) {
            throw new ErrorApi(400, 'La búsqueda no es válida.');
        }
        $buscar = trim($buscar);
        if ($buscar !== '') {
            $stmt = $pdo->prepare('SELECT id, nombre, apellidos, telefono, email, direccion FROM contactos WHERE nombre LIKE ? OR apellidos LIKE ? OR telefono LIKE ? ORDER BY nombre, apellidos');
            $patron = '%' . $buscar . '%';
            $stmt->execute([$patron, $patron, $patron]);
        } else {
            $stmt = $pdo->query('SELECT id, nombre, apellidos, telefono, email, direccion FROM contactos ORDER BY nombre, apellidos');
        }
        responder(['contacts' => $stmt->fetchAll()]);
    }
    if ($accion === 'contact') {
        $id = idContacto();
        $existente = buscarContacto($pdo, $id);
        if ($metodo === 'GET') {
            responder(['contact' => $existente]);
        }
        if ($metodo === 'DELETE') {
            $pdo->prepare('DELETE FROM contactos WHERE id = ?')->execute([$id]);
            responder(['deleted' => true]);
        }
    }
    [$contacto, $errores] = validarContacto($datos);
    if ($errores) {
        throw new ErrorApi(422, 'Revisa los datos del contacto.', $errores);
    }
    $valores = [$contacto['nombre'], $contacto['apellidos'], $contacto['telefono'], $contacto['email'] ?: null, $contacto['direccion'] ?: null];
    if ($accion === 'contacts') {
        $pdo->prepare('INSERT INTO contactos (nombre, apellidos, telefono, email, direccion) VALUES (?, ?, ?, ?, ?)')->execute($valores);
        $id = (int) $pdo->lastInsertId();
        responder(['contact' => buscarContacto($pdo, $id)], 201);
    }
    $valores[] = $id;
    $pdo->prepare('UPDATE contactos SET nombre = ?, apellidos = ?, telefono = ?, email = ?, direccion = ? WHERE id = ?')->execute($valores);
    responder(['contact' => buscarContacto($pdo, $id)]);
} catch (ErrorApi $error) {
    http_response_code($error->estado);
    echo json_encode(['ok' => false, 'error' => $error->getMessage(), 'errors' => $error->errores], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Cloud-1 backend: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'El backend no está disponible. Intenta de nuevo en unos momentos.']);
}
