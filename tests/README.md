# Pruebas del login y el balanceador

Requieren Python 3.10 o posterior y usan solo su biblioteca estándar. Ejecutarlas desde la raíz del repositorio. Cada comando devuelve `0` cuando pasa y `1` cuando falla; muestra la distribución mediante `X-App-Instance` y un resultado JSON al terminar.

## Comprobar las tres webs sin credenciales

```sh
export AGENDA_BASE_URL='http://IP_PUBLICA_DEL_BALANCEADOR'
python3 tests/smoke_balancer.py
```

El script consulta `login.php` y `health.php` 18 veces por ruta. Exige tres instancias distintas en **cada** ruta, un formulario de login con CSRF y salud del backend y MySQL. También se puede exigir su identidad:

```sh
python3 tests/smoke_balancer.py --expect web-1 web-2 web-3
```

No modifica contactos. El login puede crear una sesión anónima temporal en el backend.

## Verificar login y sesión sin modificar contactos

Configurar las credenciales de un usuario existente fuera del repositorio. Para ingresar la contraseña sin mostrarla ni incluirla en el historial, usar `getpass`:

```sh
export AGENDATEST_USER='usuario_de_la_practica'
export AGENDATEST_PASSWORD="$(python3 -c 'import getpass; print(getpass.getpass("Contraseña de prueba: "))')"
python3 tests/test_login.py --read-only
unset AGENDATEST_PASSWORD
```

Comprueba la protección de la agenda, CSRF ausente e incorrecto en el login, un login incorrecto, un login correcto, la rotación y atributos de la cookie, y 24 lecturas con la misma sesión distribuidas entre las tres webs. Al cerrar sesión, vuelve a enviar la cookie anterior a cada web para comprobar que el backend la invalidó. Este modo modifica exclusivamente sesiones, mediante login y logout; no crea, edita ni elimina contactos.

## Verificar el CRUD compartido y CSRF

Con las mismas variables de entorno definidas:

```sh
python3 tests/test_login.py
```

Además de lo anterior, crea un contacto con nombre `Prueba` seguido de un UUID parcial, comprueba que se ve igual en las tres webs, lo edita y lo elimina. Rechaza CSRF ausente e incorrecto en crear, editar, borrar y cerrar sesión; también exige que GET no borre ni cierre sesión. Solo toca su propio contacto temporal.

Si hay un fallo después de crearlo, el script intenta eliminarlo en `finally`. Una pérdida de red o backend puede impedir esa limpieza: en ese caso imprime exclusivamente el nombre e ID del contacto que debe revisarse. Nunca imprime ni guarda credenciales, cookies o tokens CSRF.

## Probar que continúan dos webs al detener una

Detener únicamente el servicio web de una instancia; no detener el backend ni MySQL. Esperar a que Nginx detecte la caída y ejecutar:

```sh
python3 tests/smoke_balancer.py --expected-instances 2
python3 tests/test_login.py --read-only --expected-instances 2
```

Restaurar el servicio y repetir las pruebas con el valor predeterminado de tres instancias. El script no detiene servicios ni modifica la configuración del balanceador.

## Opciones

- `--base-url URL`: sustituye `AGENDA_BASE_URL`.
- `--samples N`: cantidad de muestras por comprobación; aumentar si hay tráfico concurrente y no aparecen todas las webs.
- `--timeout SEGUNDOS`: tiempo máximo de cada petición; predeterminado 15.
- `--expected-instances N`: exige exactamente ese número de webs; predeterminado 3.

Las pruebas usan las rutas públicas de PHP y siguen el flujo del navegador. No necesitan acceso SSH, la contraseña de MySQL ni la clave de la API interna. Si hay HTTPS, usan la verificación de certificados de Python y exigen la cookie `Secure`.
