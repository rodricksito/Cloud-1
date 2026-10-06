<?php
require_once __DIR__ . '/includes/app.php';
$sesion = exigirLogin();
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    throw new ApiException(400, 'El identificador del contacto no es válido.');
}
$contacto = api('contact', 'GET', [], ['id' => $id])['contact'];
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['nombre', 'apellidos', 'telefono', 'email', 'direccion'] as $campo) {
        $contacto[$campo] = trim(valorTexto($_POST, $campo));
    }
    try {
        api('contact', 'PUT', $contacto + ['csrf' => valorTexto($_POST, 'csrf')], ['id' => $id]);
        redirigir('index.php?msg=editado');
    } catch (ApiException $error) {
        if ($error->estado !== 422) {
            throw $error;
        }
        http_response_code(422);
        $errores = $error->errores ?: [$error->getMessage()];
    }
}
require __DIR__ . '/includes/header.php';
?>
<h2>Editar contacto</h2>
<?php require __DIR__ . '/includes/formulario.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
