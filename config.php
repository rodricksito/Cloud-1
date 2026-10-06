<?php
declare(strict_types=1);

// Las variables de entorno tienen prioridad. Este archivo nunca contiene secretos.
function config(string $nombre, mixed $predeterminado = null): mixed
{
    static $local = null;
    if ($local === null) {
        $archivo = __DIR__ . '/config.local.php';
        $local = is_file($archivo) ? require $archivo : [];
        if (!is_array($local)) {
            throw new RuntimeException('La configuración local no es válida.');
        }
    }
    $valor = getenv($nombre);
    return $valor !== false ? $valor : ($local[$nombre] ?? $predeterminado);
}

function apiKey(): string
{
    $clave = (string) config('API_KEY', '');
    if (strlen($clave) < 32 || preg_match('/[\r\n]/', $clave)) {
        throw new RuntimeException('Configure API_KEY con al menos 32 caracteres.');
    }
    return $clave;
}

function duracionSesion(): int
{
    return max(300, min(86400, (int) config('SESSION_TTL', 28800)));
}

function nombreInstancia(): string
{
    $nombre = (string) config('APP_INSTANCE', 'web-local');
    return preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $nombre) ? $nombre : 'web-local';
}
