<?php
require_once __DIR__ . '/includes/app.php';
$sesion = exigirLogin();
$buscar = trim(valorTexto($_GET, 'buscar'));
$contactos = api('contacts', 'GET', [], ['buscar' => $buscar])['contacts'];
$mensajes = [
    'agregado' => 'Contacto agregado correctamente.',
    'editado' => 'Contacto actualizado correctamente.',
    'eliminado' => 'Contacto eliminado correctamente.',
];
$msg = $mensajes[valorTexto($_GET, 'msg')] ?? null;
require __DIR__ . '/includes/header.php';
?>
<?php if ($msg): ?>
    <div class="alerta exito"><?= e($msg) ?></div>
<?php endif; ?>
<div class="barra">
    <form method="get" class="buscador">
        <input type="text" name="buscar" maxlength="150" placeholder="Buscar por nombre o teléfono..." value="<?= e($buscar) ?>">
        <button type="submit" class="btn">Buscar</button>
        <?php if ($buscar !== ''): ?>
            <a href="index.php" class="btn secundario">Limpiar</a>
        <?php endif; ?>
    </form>
    <a href="agregar.php" class="btn">+ Nuevo contacto</a>
</div>
<?php if (!$contactos): ?>
    <p class="vacio">No se encontraron contactos.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Nombre</th><th>Teléfono</th><th>Correo</th><th>Dirección</th><th>Acciones</th></tr></thead>
        <tbody>
        <?php foreach ($contactos as $c): ?>
            <tr>
                <td><?= e($c['nombre'] . ' ' . $c['apellidos']) ?></td>
                <td><?= e($c['telefono']) ?></td>
                <td><?= e($c['email']) ?></td>
                <td><?= e($c['direccion']) ?></td>
                <td class="acciones">
                    <a href="editar.php?id=<?= (int) $c['id'] ?>" class="btn pequeno">Editar</a>
                    <form method="post" action="eliminar.php" onsubmit="return confirm('¿Desea eliminar este contacto?');">
                        <input type="hidden" name="csrf" value="<?= e($sesion['csrf']) ?>">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="btn pequeno peligro">Eliminar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="total">Total: <?= count($contactos) ?> contacto(s)</p>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
