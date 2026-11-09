# Oficinas de usuarios IPS

`GET /api/v1/ips/usuarios` y `GET /api/v1/ips/usuarios/{pid}` incluyen `offices`: la oficina principal y las oficinas activas asignadas al usuario en `dbo.L_USERS_OFFICES`, sin repetir oficinas. Cada entrada contiene `office_cd`, `office_fcd` y `office_name`.

Los campos individuales `office_cd`, `office_fcd` y `office_name` siguen identificando la oficina principal. Un usuario sin oficina principal puede tener oficinas asignadas en `offices`. Las consultas requieren un token con permiso `ips.read` y el rol administrador existente.

Bolipost utiliza esta lista para mostrar todas las oficinas. No debe sustituirse por el catalogo general de oficinas, porque este incluye oficinas ajenas al usuario.

## Publicar mediante Git

Desde el proyecto SITRA local, revisar y publicar solo estos archivos:

```powershell
git add app/Services/IpsRepository.php tests/Feature/IpsUserOfficesTest.php docs/IPS_USUARIOS_OFICINAS.md
git commit -m "Incluir todas las oficinas asignadas en usuarios IPS"
git push origin main
```

En el proyecto SITRA del servidor, actualizar la rama de despliegue:

```sh
git pull --ff-only origin main
```

No se necesitan migraciones ni cambios de credenciales. Si el servidor mantiene codigo PHP en OPcache sin comprobar modificaciones, recargar el proceso PHP mediante el procedimiento habitual del servidor.

Consultar `/api/v1/ips/usuarios/{pid}` con el token existente y comprobar que `data.offices` contiene todas las oficinas asignadas. En Bolipost, pulsar **Verificar** en el usuario para actualizar el vinculo guardado y recargar la vista. Esto tambien invalida la cache temporal de oficinas de ese usuario.

## Validacion

```sh
php vendor/bin/phpunit tests/Feature/IpsUserOfficesTest.php
```

Las pruebas usan bases de datos SQLite aisladas y no modifican IPS real.
