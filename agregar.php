<?php
require_once __DIR__ . '/includes/app.php';
$sesion = exigirLogin();
$contacto = array_fill_keys(['nombre', 'apellidos', 'telefono', 'email', 'direccion'], '');
$errores = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($contacto as $campo => $_) {
        $contacto[$campo] = trim(valorTexto($_POST, $campo));
    }
    try {
        api('contacts', 'POST', $contacto + ['csrf' => valorTexto($_POST, 'csrf')]);
        redirigir('index.php?msg=agregado');
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
<h2>Nuevo contacto</h2>
<?php require __DIR__ . '/includes/formulario.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
