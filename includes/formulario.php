<?php
// Formulario compartido por agregar.php y editar.php
// Requiere las variables $contacto (array) y $errores (array)
?>
<?php if ($errores): ?>
    <div class="alerta error">
        <ul>
            <?php foreach ($errores as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="formulario">
    <input type="hidden" name="csrf" value="<?= e($sesion['csrf']) ?>">
    <label>Nombre *
        <input type="text" name="nombre" maxlength="50" required value="<?= e($contacto['nombre']) ?>">
    </label>
    <label>Apellidos *
        <input type="text" name="apellidos" maxlength="80" required value="<?= e($contacto['apellidos']) ?>">
    </label>
    <label>Teléfono *
        <input type="tel" name="telefono" maxlength="20" required value="<?= e($contacto['telefono']) ?>">
    </label>
    <label>Correo electrónico
        <input type="email" name="email" maxlength="100" value="<?= e($contacto['email']) ?>">
    </label>
    <label>Dirección
        <input type="text" name="direccion" maxlength="150" value="<?= e($contacto['direccion']) ?>">
    </label>
    <div class="acciones">
        <button type="submit" class="btn">Guardar</button>
        <a href="index.php" class="btn secundario">Cancelar</a>
    </div>
</form>
