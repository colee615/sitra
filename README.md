# SITRA

Sistema de seguimiento y operación postal con PostgreSQL e IPS5Db.

La pantalla de gestión está en `/operaciones`. Documentación de altas, eventos, entregas, configuración y limitaciones de activación: [Operaciones IPS](docs/IPS_OPERACIONES.md). Contrato de integración: [OpenAPI](docs/ips-openapi.json).

La conexión y las consultas IPS están verificadas. Las escrituras requieren configurar la identidad técnica y validar los procedimientos en IPS de pruebas antes de habilitar `IPS_WRITES_ENABLED`.

## Inicio

Requiere PHP 8.2 o superior, Composer, PostgreSQL y los controladores de SQL Server para PHP.

```sh
composer install
npm install
npm run build
php artisan key:generate
php artisan migrate
php artisan ips:diagnose --identities
```

Copie `.env.example` a `.env` solamente en instalaciones nuevas y configure las credenciales. No sobrescriba el entorno existente.

## Pruebas

```sh
php vendor/phpunit/phpunit/phpunit
```

Las pruebas de integración automatizadas utilizan SQLite aislado y dobles de los procedimientos IPS; no escriben en el servidor postal real. Consulte la guía de operaciones para la validación de IPS y la activación de escrituras.