<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/conexion.php';
try {
    $sql = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('No se encontró la migración.');
    }
    $pdo = conectar();
    // PDO sin emulación: ejecutar cada sentencia CREATE TABLE por separado.
    foreach (explode(';', $sql) as $sentencia) {
        if (trim($sentencia) !== '') {
            $pdo->exec($sentencia);
        }
    }
    fwrite(STDOUT, "Migración aplicada. Los contactos existentes se conservaron.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "No se pudo aplicar la migración. Revisa DB_*, la existencia de la base y los permisos CREATE.\n");
    exit(1);
}
