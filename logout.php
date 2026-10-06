<?php
require_once __DIR__ . '/includes/app.php';
exigirLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    throw new ApiException(405, 'El cierre de sesión requiere una petición POST.');
}
api('logout', 'POST', ['csrf' => valorTexto($_POST, 'csrf')]);
guardarCookie(null);
redirigir('login.php');
