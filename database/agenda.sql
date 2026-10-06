-- Instalación manual con el cliente mysql, desde la raíz del repositorio:
-- mysql --defaults-extra-file=/ruta/privada/mysql.cnf < database/agenda.sql
-- Las credenciales se aprovisionan fuera de Git. Los contactos se conservan.
CREATE DATABASE IF NOT EXISTS agenda CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agenda;
SOURCE database/schema.sql;
