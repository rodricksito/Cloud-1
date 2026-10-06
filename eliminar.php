<?php
require_once __DIR__ . '/includes/app.php';
$sesion = exigirLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    throw new ApiException(405, 'La eliminación requiere una petición POST.');
}
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    throw new ApiException(400, 'El identificador del contacto no es válido.');
}
api('contact', 'DELETE', ['csrf' => valorTexto($_POST, 'csrf')], ['id' => $id]);
redirigir('index.php?msg=eliminado');
