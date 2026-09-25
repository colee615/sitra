# Expediente postal: SITRA + IPS + CDS

Los accesos operativos están separados: búsqueda de paquetes IPS, consulta de declaraciones CDS, expediente conjunto y búsqueda de marbetes. La búsqueda de marbetes abre el receptáculo por su código, número de registro o seguro y recorre sus eventos, despacho asociado, envíos vinculados y manifiestos. Se puede imprimir la identificación interna y descargar la lista de envíos en CSV.

`/ips` consulta exclusivamente paquetes y movimientos postales, `/cds` consulta declaraciones aduaneras, `/conjunto` compara las dos fuentes, y `/marbetes` busca recept?culos por marbete, n?mero de registro o seguro. `/consultas` y `/aduana` se conservan como direcciones de compatibilidad.

## Conexiones

| Conexión Laravel | Base | Uso |
| --- | --- | --- |
| `pgsql` | SITRA | Usuarios, permisos y bitácora de operaciones existentes |
| `sqlsrv` | IPS5Db de operaciones | La configuraci?n `IPS_CONNECTION` dirige las escrituras de prueba |
| `ips_catalog` | IPS5Db de consultas | Cat?logo de solo lectura, en servidor compartido con CDS |
| `cds` | CDSDb | Consulta aduanera; este módulo no escribe en CDS |

IPS sigue usando `SQLSRV_*`. CDS tiene variables independientes `CDS_SQLSRV_HOST`, `CDS_SQLSRV_PORT`, `CDS_SQLSRV_DATABASE`, `CDS_SQLSRV_USERNAME`, `CDS_SQLSRV_PASSWORD`, `CDS_SQLSRV_ENCRYPT`, `CDS_SQLSRV_TRUST_SERVER_CERTIFICATE` y `CDS_ENABLED`. No repetir claves `SQLSRV_*` para configurar otra base: la última definición reemplazaría la primera.

Mant?n las credenciales en `.env`, fuera del repositorio. `.env.example` no incluye contrase?as. `POSTAL_IPS_READ_CONNECTION=ips_catalog` consulta el servidor SQL configurado para CDS, pero selecciona `IPS5Db`; `SQLSRV_*` y `IPS_CONNECTION` siguen controlando la conexi?n de escritura IPS. As? el cat?logo de lectura incluye el marbete operativo mientras las entregas de prueba siguen apuntando a su respaldo. En un despliegue donde las lecturas y escrituras deban compartir servidor, configura y valida ambas conexiones antes de habilitar movimientos.

Después de configurar el entorno:

```sh
php artisan migrate --path=database/migrations/2026_09_24_130000_add_cds_read_permission.php --force
php artisan config:clear
php artisan view:clear
```

La migración solo crea `cds.read`, sin asignarlo a ningún rol. En `/accesos`, un administrador puede asignar o retirar permisos del rol. Las rutas de gestión de usuarios, roles y permisos requieren administrador. Las asignaciones directas de un usuario no se eliminan al cambiar su rol.

## Identidad y permisos

- `postal.access`: administrador o permiso explícito `ips.read` / `cds.read`.
- El expediente consulta IPS solo para administrador o `ips.read`, y CDS solo para administrador o `cds.read`.
- Las operaciones IPS conservan sus permisos y comprobaciones anteriores. Consultar no autoriza entregar un paquete.
- Los documentos requieren autenticación y vuelven a consultar las fuentes autorizadas. El resumen aduanero requiere acceso CDS.
- La consulta y las copias envían `Cache-Control: private, no-store`.

La búsqueda normaliza espacios externos y mayúsculas; no cambia un código incorrecto por otro. Se valida el dígito de control S10 sin bloquear identificadores históricos o locales. El sufijo S10 identifica a la autoridad emisora; no se usa para inventar el origen, destino o ruta. Referencia: [UPU S10](https://www.upu.int/UPU/media/upu/files/postalSolutions/programmesAndServices/standards/S10-12.pdf).

## Correspondencia de datos

| Información | Fuente |
| --- | --- |
| Paquete, remitente y contactos | IPS `L_MAILITMS`, `L_MAILITM_CUSTOMERS` |
| Movimientos y operador | IPS eventos locales/EDI, `L_USERS` |
| Saca y despacho | IPS `L_RECPTCLS`, `L_DESPTCHS` |
| Formulario, oficina, autor | IPS `L_MANIFEST_LISTS.FORM_NM`, `USER_PID` → `L_USERS` |
| Relación código/identificador local | CDS `O_MAIL_OBJECTS` |
| Declaración y artículos | CDS `O_DECLARATIONS.DATA` (`DecData`, `ContentPieces/ContPc`) |
| Decisión aduanera | CDS `O_RESPONSES.DATA`, catálogo `M_CUSTOMS_DECISIONS` |
| Responsable y fecha de un cambio | CDS `O_DECLARATION_EVENTS`, `O_RESPONSE_EVENTS`, `A_USERS`, `M_OFFICES` |

No se consultan contraseñas de los operadores. Los usuarios que mantienen bloqueado un registro (`LOCK_USER_PID`) no se interpretan como sus autores. No se transforma una respuesta aduanera en entrega postal ni se eliminan movimientos por parecer contradictorios.

Los eventos CDS se identifican como UTC. IPS mantiene la fecha registrada por la consulta existente: los eventos locales usan GMT, mientras que algunos EDI contienen fecha local del evento; no se convierte toda la cronología asumiendo una única zona horaria.

El XML se interpreta por sus atributos, admite espacios de nombres y rechaza DTD, entidades externas, XML inválido o mayor de 2 MB. Los campos desconocidos se pueden consultar desplegando los datos de origen. No se completan campos ausentes con suposiciones. Los nombres de países y naturaleza se toman de catálogos CDS.

## Documentos y límites

- **Marbete interno:** código existente en Code128 para identificación; no crea un nuevo S10 ni acredita admisión, pago, despacho o entrega.
- **Resumen aduanero:** copia imprimible del contenido CDS; puede guardarse como PDF desde el navegador. No sustituye ni emite un CN22/CN23 oficial. [Referencia UPU/OMA](https://www.upu.int/UPU/media/upu/files/postalSolutions/programmesAndServices/postalSupplyChain/customs/guideWcoUPUCustomsEn.pdf).
- Los nombres CN se muestran cuando IPS registra el formulario. No se asigna un CN solo por el peso o valor del paquete.
- El usuario que imprime se identifica como generador de la copia, separado de quien registró los eventos.
- Si un identificador corresponde a varios códigos postales distintos, se solicita consultar el código exacto antes de imprimir.
- CDS limita documentos/paquetes a 100 y eventos a 500 por tipo e informa cuando el resultado está recortado. El expediente muestra registros actuales, no todas las versiones de XML de las tablas históricas.
- Una caída de una fuente no oculta los datos de la otra. «Sin registros» se distingue de «No disponible» y de «Sin permiso».

## Verificación

```sh
php artisan test
```

Las pruebas usan SQLite en memoria y repositorios simulados. Cubren checksum S10, XML/entidades, permisos por fuente, fallos independientes, renderizado y copias imprimibles. Las comprobaciones reales de integración fueron de lectura: declaración CDS, respuesta aduanera con eventos, paquete IPS con historial y un código presente en ambas bases. No prueban todos los documentos posibles ni generan eventos en producción.
