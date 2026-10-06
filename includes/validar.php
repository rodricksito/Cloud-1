<?php
declare(strict_types=1);

function validarContacto(array $datos): array
{
    $contacto = [];
    $errores = [];
    foreach (['nombre' => 50, 'apellidos' => 80, 'telefono' => 20, 'email' => 100, 'direccion' => 150] as $campo => $maximo) {
        $valor = $datos[$campo] ?? '';
        if (!is_string($valor) || strlen($valor) > 4096 || !preg_match('//u', $valor)) {
            $errores[] = 'El campo ' . $campo . ' no es válido.';
            $valor = '';
        }
        $valor = trim($valor);
        $contacto[$campo] = $valor;
        $longitud = preg_match_all('/./us', $valor);
        if ($longitud > $maximo) {
            $errores[] = 'El campo ' . $campo . ' admite hasta ' . $maximo . ' caracteres.';
        }
        if (str_contains($valor, "\0")) {
            $errores[] = 'El campo ' . $campo . ' no es válido.';
        }
    }
    if ($contacto['nombre'] === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if ($contacto['apellidos'] === '') {
        $errores[] = 'Los apellidos son obligatorios.';
    }
    if ($contacto['telefono'] === '') {
        $errores[] = 'El teléfono es obligatorio.';
    } elseif (!preg_match('/^[0-9 +()\-]{7,20}$/D', $contacto['telefono'])) {
        $errores[] = 'El teléfono solo puede contener números, espacios y los signos + ( ) -.';
    }
    if ($contacto['email'] !== '' && !filter_var($contacto['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo electrónico no es válido.';
    }
    return [$contacto, array_values(array_unique($errores))];
}
