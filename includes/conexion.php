<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

// Solo el backend y los comandos CLI cargan este archivo.
function conectar(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $host = (string) config('DB_HOST', '127.0.0.1');
    $nombre = (string) config('DB_NAME', 'agenda');
    $puerto = (int) config('DB_PORT', 3306);
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $nombre) || preg_match('/[;\r\n]/', $host)) {
        throw new RuntimeException('La configuración de la base de datos no es válida.');
    }
    $usuario = (string) config('DB_USER', '');
    $clave = (string) config('DB_PASS', '');
    if ($usuario === '' || $clave === '') {
        throw new RuntimeException('Faltan las credenciales de la base de datos.');
    }
    $opciones = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $ca = (string) config('DB_SSL_CA', '');
    if ($ca !== '') {
        $rutaCa = realpath($ca);
        if ($rutaCa === false || !is_file($rutaCa) || !is_readable($rutaCa)) {
            throw new RuntimeException('No se encontró una CA MySQL legible.');
        }
        // Pdo\Mysql evita constantes deprecadas en PHP 8.5; PDO cubre PHP 8.1–8.3.
        $atributoCa = defined('Pdo\\Mysql::ATTR_SSL_CA') ? constant('Pdo\\Mysql::ATTR_SSL_CA') : PDO::MYSQL_ATTR_SSL_CA;
        $atributoVerificar = defined('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') ? constant('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
        $opciones[$atributoCa] = $rutaCa;
        $opciones[$atributoVerificar] = true;
    }
    $pdo = new PDO(
        "mysql:host={$host};port={$puerto};dbname={$nombre};charset=utf8mb4",
        $usuario,
        $clave,
        $opciones
    );
    if ($ca !== '') {
        $cifrado = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch();
        if (!$cifrado || empty($cifrado['Value'])) {
            $pdo = null;
            throw new RuntimeException('La conexión MySQL requiere TLS verificado.');
        }
    }
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
