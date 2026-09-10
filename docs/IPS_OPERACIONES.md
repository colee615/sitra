# Operación postal e integración IPS

## Estado de esta entrega

SITRA utiliza PostgreSQL como base principal y la conexión SQL Server `sqlsrv` para IPS5Db. Se eliminaron la conexión `sqlsrv2`, sus variables de entorno, servicio, controlador, ruta, vistas y enlaces de CDSDb. Las rutas anteriores de seguimiento siguen disponibles.

Se implementaron consulta de paquetes y pendientes, detalle e historial local, catálogos, altas con remitente y destinatario, recepción en oficina, salida a reparto, disponibilidad para retiro, intento fallido y entrega final. La pantalla está en **/operaciones**, accesible para administradores.

La conexión real, los catálogos y las firmas de procedimientos se verificaron el **10 de septiembre de 2026**. La migración de la bitácora se ejecutó exclusivamente en PostgreSQL. **No se ejecutaron escrituras de prueba en IPS real.** Los procedimientos internos son propietarios; sus definiciones no son visibles con esta conexión. La implementación comprueba su firma, transiciones y resultados, pero las pruebas aisladas no certifican sus efectos internos ni la emisión EDI.

Las escrituras permanecen deshabilitadas en `.env`. Falta asignar una identidad técnica y validar altas y entregas contra una instalación de prueba equivalente de IPS antes de habilitarlas. Esto no requiere volver a desarrollar las rutas.

## Eventos: qué listar y qué registrar

“Baja” significa entrega al destinatario; no significa borrar registros.

| Operación | Evento UPU | EVENT_TYPE_CD local verificado | Uso |
|---|---|---:|---|
| Admisión | EMA | 1 | Alta de salida desde Bolivia |
| Llegada a oficina de intercambio | EMD | 30 | Alta/recepción de importación hacia Bolivia |
| Llegada a oficina de entrega | EMG | 32 | Preparación para entrega |
| Salida a reparto | EDG | 74 | Entrega física en curso |
| Disponible para retiro | EDH | 75 | Retiro por el destinatario |
| Intento de entrega fallido | EMH | 36 | Registrar motivo y medida; no entregar |
| Entrega final | EMI | 37 | Nombre de quien recibe; estado interno 5 |

El catálogo también contiene el evento **39**, entrega al agente de reparto. Se considera candidato, pero no se equipara automáticamente con EDG.

Los significados internacionales se basan en la [documentación UPU sobre eventos e IMPC](https://www.upu.int/UPU/media/upu/documents/Standards/IMPC-usage-analysis.pdf) y el [flujo de eventos publicado por la UPU](https://www.upu.int/UPU/media/upu/files/postalSolutions/programmesAndServices/physicalServices/parcels/guideParcelsInternetBasedInquirySystemPt.pdf). Los números 1, 30, 32, 36, 37, 74 y 75 proceden del catálogo de **esta instalación**, no de un supuesto identificador SQL universal.

Política local de pendientes:

- Destino BO.
- Último evento local 32, 39, 74, 75 o 36.
- Estado actual 0 u 8.
- Sin evento local de entrega 37 ni terminación de importación 76.
- Para entregar, la oficina indicada debe ser la oficina actual del paquete.
- La transición debe estar permitida por `C_STATEINDS_EVTTYPES`.

Es una selección conservadora. No basta que un paquete haya tenido EMG alguna vez. Paquetes en aduana, traslado o con estado terminal no deben entregarse automáticamente. Eventos técnicos posteriores pueden sacar un paquete de la lista conservadora: revisar el historial y registrar el movimiento real correspondiente; no inventar una llegada o una entrega para hacerlo aparecer. No existe una secuencia previa única obligatoria para todas las operaciones postales.

## Bases de datos y configuración

`DB_*` configura PostgreSQL. `SQLSRV_*` configura únicamente IPS5Db. Elimine configuraciones antiguas cacheadas después de actualizar:

~~~sh
php artisan config:clear
php artisan view:clear
php artisan migrate --path=database/migrations/2026_09_10_120000_create_ips_operations_table.php
php artisan ips:diagnose --identities
~~~

La instalación de PHP necesita `pdo_pgsql` y `pdo_sqlsrv`, además del controlador ODBC de SQL Server. Las credenciales se leen del entorno y no están en documentación, respuestas ni archivos versionados nuevos.

~~~dotenv
DB_CONNECTION=pgsql
SQLSRV_DATABASE=IPS5Db
SQLSRV_PORT=1433
SQLSRV_ENCRYPT=yes
SQLSRV_TRUST_SERVER_CERTIFICATE=true

IPS_WRITES_ENABLED=false
IPS_USER_PID=
IPS_WORKSTATION_PID=
~~~

El host y las credenciales SQL proporcionados ya estaban configurados en el `.env` local y se mantuvieron. No configure una tercera conexión.

Identidades encontradas:

| Identidad | ID | Observación |
|---|---:|---|
| PSDUser | USER_PID 2 | Usuario de sistema existente; candidato técnico |
| WebClientVirtualWorkstation | WORKSTATION_PID 3 | Estación virtual existente |
| WebAdmin | USER_PID 1 | Cuenta administrativa; no usar como valor automático |

No se encontró una identidad llamada SITRA. Una cuenta existente no acredita por sí sola que esté asignada a esta integración. Configure una identidad técnica destinada a SITRA desde la administración de IPS o confirme la asignación de una existente; registre sus IDs en el entorno. No se crearon usuarios IPS ni se consultaron contraseñas.

Los indicadores de validez encontrados incluyen 1 y 3 como entradas habilitadas; las entradas 2 quedan excluidas. La identidad se valida contra el catálogo al escribir. El usuario SQL necesita SELECT sobre los catálogos y tablas consultados, EXECUTE sobre los procedimientos y permiso para adquirir un bloqueo de aplicación. Los permisos EXECUTE fueron comprobados en lectura. Las operaciones se atribuyen a la identidad configurada del servidor, nunca a IDs enviados por el consumidor.

La [guía oficial de la API IPS](https://www.api.post/Content/IPS_2021_API_Administrator_Guide.pdf) describe una vía HTTP soportada por UPU para importar objetos y eventos. Esta entrega implementa el acceso SQL directo solicitado mediante procedimientos existentes, no una llamada a aquella API. No se encontró un endpoint IPS HTTP ni token configurado. La confirmación local en SQL no demuestra recepción de un mensaje EDI por otro operador.

## API v1

Contrato importable en Postman/Swagger: [ips-openapi.json](ips-openapi.json).

Prefijo: `/api/v1/ips`. Todas las rutas requieren:

~~~http
Authorization: Bearer TOKEN_DE_SITRA
Accept: application/json
~~~

| Método | Ruta relativa | Permiso del token |
|---|---|---|
| GET | /catalogos | ips.read |
| GET | /paquetes | ips.read |
| GET | /paquetes/pendientes-entrega | ips.read |
| GET | /paquetes/{codigo} | ips.read |
| POST | /paquetes | ips.create |
| POST | /paquetes/{codigo}/eventos | ips.events |
| POST | /paquetes/{codigo}/entrega | ips.deliver |
| GET | /operaciones/{uuid} | ips.operations |

Se exige usuario administrador y token personal de Sanctum. Una sesión web no sustituye al Bearer token de integración. Hay un límite de 60 solicitudes por minuto. El endpoint general de eventos exige también `ips.deliver` cuando se solicita EMI; `ips.events` solo no permite entregar.

Las rutas antiguas `/api/tracking/eventos`, `/api/tracking/eventos-todos` y `/api/tracking/paquetes` conservan su permiso `sqlserver.read`.

Emitir un token para un administrador existente:

~~~sh
php artisan token:issue correo-del-administrador --name=otro-proyecto --ability=ips.read,ips.create,ips.events,ips.deliver,ips.operations --days=90
~~~

Entregar ese token al otro proyecto por su configuración de secretos. El comando muestra el token una sola vez. No se emitieron ni enviaron tokens automáticamente.

### Consulta y paginación

~~~http
GET /api/v1/ips/paquetes/pendientes-entrega?office_cd=1&per_page=25&page=1
~~~

Filtros: `q` (código exacto, admite ID local), `status=all|pending|delivered`, `office_cd`, `event_cd`, `from`, `to`, `page`, `per_page` (1–100). Fechas con zona horaria explícita. La ruta pendientes-entrega siempre fuerza `pending`.

~~~json
{
  "data": [{
    "id": "uuid-del-paquete",
    "codigo": "CODIGO_ASIGNADO",
    "state_cd": 0,
    "event_cd": 75,
    "event_at": "2026-09-09T15:00:00+00:00",
    "office_cd": 1
  }],
  "meta": {"page": 1, "per_page": 25, "has_more": false, "status": "pending"}
}
~~~

Use `has_more` para continuar. La lista se ordena por fecha descendente e ID. Como IPS está en movimiento, la paginación por página no representa una fotografía congelada; un consumidor debe deduplicar por ID y volver a consultar antes de escribir. Los catálogos devuelven los códigos reales para oficinas, clases postales, países, motivos y medidas.

### Entrega

1. Consulte el paquete y conserve `event_cd`, `event_at` y `office_cd`.
2. Registre la fecha real y el nombre de quien recibe.
3. Genere una clave de idempotencia por operación y **guárdela en el otro proyecto antes del envío**.
4. Envíe la misma clave y el mismo cuerpo ante una repetición de esa solicitud.

~~~http
POST /api/v1/ips/paquetes/CODIGO_ASIGNADO/entrega
Content-Type: application/json
Idempotency-Key: entrega-orden-12345
~~~

~~~json
{
  "occurred_at": "2026-09-10T10:30:00-04:00",
  "office_cd": 1,
  "expected_event_cd": 75,
  "expected_event_at": "2026-09-09T15:00:00+00:00",
  "signatory": "Nombre de quien recibe",
  "delivery_location": "Ventanilla"
}
~~~

Sustituya el código, fechas y oficina por valores reales. Las fechas futuras y las fechas sin zona se rechazan. La fecha del nuevo evento debe ser posterior al movimiento actual. `/entrega` fija EMI aunque el cliente envíe otro evento.

Respuesta confirmada:

~~~json
{
  "operation_id": "uuid-de-la-operacion",
  "status": "succeeded",
  "data": {
    "codigo": "CODIGO_ASIGNADO",
    "mailitm_pid": "uuid-del-paquete",
    "event": "EMI",
    "event_cd": 37,
    "event_at": "2026-09-10T14:30:00+00:00",
    "office_cd": 1
  }
}
~~~

### Alta de paquete

~~~http
POST /api/v1/ips/paquetes
Content-Type: application/json
Idempotency-Key: admision-orden-12345
~~~

~~~json
{
  "codigo": "CODIGOASIGNADO123",
  "event": "EMA",
  "occurred_at": "2026-09-10T09:00:00-04:00",
  "office_cd": 1,
  "mail_class": "C",
  "origin_country": "BO",
  "destination_country": "US",
  "weight_kg": 1.25,
  "sender": {
    "name": "Remitente",
    "address": "Dirección de origen",
    "city": "La Paz",
    "country": "BO"
  },
  "recipient": {
    "name": "Destinatario",
    "address": "Dirección de destino",
    "city": "Ciudad de destino",
    "country": "US"
  }
}
~~~

Se requiere un identificador ya asignado por el operador postal. SITRA no inventa rangos S10, no asigna etiquetas internacionales ni sustituye los controles del operador sobre esos rangos. Se admiten identificadores alfanuméricos y guiones de hasta 35 caracteres. Para recepción internacional use EMD, origen real y destino BO. El código no debe existir como identificador principal o local. La respuesta de alta confirmada es HTTP 201.

### Movimientos e intento fallido

`POST /paquetes/{codigo}/eventos` recibe el mismo contexto temporal y de estado que entrega, más `event`: EMD, EMG, EDG, EDH o EMH según la transición válida. EMA sobre un objeto existente queda sujeto a la compatibilidad IPS. Para EMH se requieren `non_delivery_reason` y `non_delivery_measure` del catálogo. No use valores de ejemplo como códigos reales. El receptor solo es obligatorio para EMI.

Este alcance no incluye borrar objetos, anulaciones, devoluciones automáticas, creación de despachos/sacas, liquidación aduanera ni emisión de rangos S10. Son operaciones distintas a la entrega y requieren sus propios contratos.

## Integridad y recuperación

- Bitácora local `ips_operations`: actor SITRA, clave y cuerpo resumidos mediante SHA-256, operación, código, evento y resultado. No guarda nombres, direcciones, credenciales ni el cuerpo completo.
- Unicidad por usuario SITRA y clave. Rotar el token del mismo usuario no permite duplicar la misma solicitud.
- Reserva persistente en PostgreSQL antes de ejecutar SQL Server.
- Bloqueo de aplicación por código y bloqueo de fila durante la transacción SQL.
- Comprobación de estado esperado y compatibilidad contra catálogos.
- Procedimientos con nombres y parámetros fijos; valores enlazados, sin SQL proporcionado por el cliente.
- Conservación de campos del paquete al registrar eventos.
- Verificación del evento, estado actual y receptor para EMI antes del commit SQL.
- Invalidación del seguimiento cacheado por identificador principal y local tras éxito. Las consultas operativas nuevas no usan resultados vencidos.
- No se realizan reintentos automáticos de procedimientos.

PostgreSQL y SQL Server son transacciones independientes; no se afirma una atomicidad distribuida. Si SQL confirma pero la conexión se pierde al confirmar o guardar la bitácora, queda `uncertain` o `processing`. Reenviar la misma clave no ejecuta otra escritura.

| HTTP / estado | Acción del consumidor |
|---|---|
| 200/201 y succeeded | Guardar operation_id y resultado confirmado |
| Idempotency-Replayed: true | Resultado repetido; no crear una segunda entrega |
| 401/403 | Revisar token, rol y permisos |
| 422 | Corregir datos; una validación de formulario no ejecutó IPS |
| 409 y rejected | Estado incompatible, paquete ya entregado o clave reutilizada |
| 409 y processing/uncertain | Consultar operación; no generar otra clave |
| 429 | Esperar y respetar Retry-After |
| 503 | Verificar disponibilidad/configuración y consultar operación si existe ID |

Ante `processing` persistente o `uncertain`, consulte `GET /operaciones/{id}`, el detalle del paquete y la bitácora con el operador IPS. Correlacione código, fecha real, evento, oficina y receptor. **No hay un botón de reintento automático ni un borrado de reservas**, porque no puede deducirse con certeza que IPS no haya confirmado. La reconciliación manual debe documentar el resultado antes de intentar una operación nueva. No purgar estas claves mientras los consumidores puedan reintentar solicitudes antiguas.

## Validación antes de producción

En una instalación de prueba de la misma versión:

1. Configure el usuario técnico y la estación destinados a la integración. Ejecute `ips:diagnose`.
2. Habilite escrituras allí y cree un envío de prueba con identificador de pruebas del operador.
3. Compruebe remitente/destinatario, evento inicial, oficina, peso y estados en la interfaz IPS.
4. Recorra EMG → EDH o EDG → EMI con fechas reales posteriores, o registre EMH con motivo y medida antes de reintentar.
5. Verifique que EMI produce estado 5 y el nombre del receptor en `L_MAILITM_DELIV_INFOS`.
6. Compruebe los disparadores y la exportación EDI en IPS; una escritura local no confirma envío internacional.
7. Reenvíe la misma solicitud y pruebe un estado desactualizado: no debe generarse otra entrega.
8. Tras validar, configure la identidad productiva y `IPS_WRITES_ENABLED=true`; ejecute `php artisan config:clear`.

Procedimientos detectados: `SP_SET_MAILITM` y `SP_SET_MAILITM_CUSTOMERS`. Se guardó su contrato de parámetros en `config/ips-procedures.php`; si cambia, el servicio rechaza la escritura. Propiedad de versión observada en el primero: `13.0.0.112701.2024-03-11T11:22:52`. Esto identifica ese objeto, no certifica la versión completa del servidor IPS.

Pruebas locales:

~~~sh
php vendor/phpunit/phpunit/phpunit
php artisan view:cache
php artisan ips:diagnose --identities
~~~

Las pruebas de API usan SQLite y dobles de los procedimientos; las pruebas del repositorio ejecutan consultas sobre fixtures aislados. No conectan con IPS de producción. Hay cobertura de autenticación, permisos, validación, idempotencia, errores ambiguos, propiedad de operaciones, filtros, paginación, estados obsoletos, entrega repetida, oficina incorrecta, conversión UTC, alta de clientes e intento fallido.
