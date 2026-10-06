<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda Telefónica</title>
    <link rel="stylesheet" href="css/estilos.css">
</head>
<body>
<header>
    <h1><a href="index.php">📒 Agenda Telefónica</a></h1>
    <div class="estado-servidor">
        <span class="instancia">Atendido por: <?= e(nombreInstancia()) ?></span>
        <?php if (!empty($sesion['user'])): ?>
            <span>Usuario: <?= e($sesion['user']['username']) ?></span>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf" value="<?= e($sesion['csrf']) ?>">
                <button type="submit" class="btn pequeno secundario">Cerrar sesión</button>
            </form>
        <?php endif; ?>
    </div>
</header>
<main>
