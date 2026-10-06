<?php
require_once __DIR__ . '/includes/app.php';
$sesion = sesionActual();
if (!empty($sesion['user'])) {
    redirigir('index.php');
}
$errorLogin = null;
$usuario = trim(valorTexto($_POST, 'username'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $resultado = api('login', 'POST', [
            'username' => $usuario,
            'password' => valorTexto($_POST, 'password'),
            'csrf' => valorTexto($_POST, 'csrf'),
        ]);
        guardarCookie($resultado['token']);
        redirigir('index.php');
    } catch (ApiException $error) {
        if (!in_array($error->estado, [401, 422, 429], true)) {
            throw $error;
        }
        http_response_code($error->estado);
        $errorLogin = $error->getMessage();
    }
}
require __DIR__ . '/includes/header.php';
?>
<h2>Iniciar sesión</h2>
<p class="vacio">Accede a la agenda compartida. Los usuarios se crean por el administrador.</p>
<?php if ($errorLogin): ?>
    <div class="alerta error" role="alert"><?= e($errorLogin) ?></div>
<?php endif; ?>
<form method="post" class="formulario">
    <input type="hidden" name="csrf" value="<?= e($sesion['csrf']) ?>">
    <label>Usuario
        <input type="text" name="username" minlength="3" maxlength="64" autocomplete="username" required value="<?= e($usuario) ?>">
    </label>
    <label>Contraseña
        <input type="password" name="password" maxlength="1024" autocomplete="current-password" required>
    </label>
    <button type="submit" class="btn">Entrar</button>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
