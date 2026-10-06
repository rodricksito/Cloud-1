<?php
// Copiar a config.local.php y completar fuera de Git.
return [
    'APP_INSTANCE' => 'web-1',
    'API_URL' => 'http://IP_PRIVADA_VM2:8081/api/index.php',
    'API_KEY' => '', // Mismo secreto aleatorio, de al menos 32 caracteres, en las tres VM.
    'SESSION_TTL' => 28800,
    'SESSION_COOKIE' => 'agenda_session',
    'COOKIE_SECURE' => false, // true cuando el balanceador publique HTTPS.

    // Estas claves se configuran exclusivamente en VM2, que ejecuta el backend.
    // 'DB_HOST' => '10.0.0.12',
    // 'DB_PORT' => 3306,
    // 'DB_NAME' => 'agenda',
    // 'DB_USER' => 'agenda_user',
    // 'DB_PASS' => '',
    // 'DB_SSL_CA' => '/etc/cloud1/mysql-ca.pem', // CA MySQL legible; exige TLS y verifica la identidad del servidor.
];
