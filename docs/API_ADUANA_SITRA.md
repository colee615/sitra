# SITRA | Integración de consulta para Aduana

## Especificación técnica de la API

Documento de transferencia para el Área de Sistemas de Aduana. Describe el contrato HTTP de consulta expuesto por SITRA, los campos de respuesta y las condiciones para iniciar una integración.

| Control del documento | Valor |
|---|---|
| Sistema proveedor | SITRA — Gestión postal y aduanera |
| Sistema consumidor | Aduana — nombre del sistema consumidor pendiente de confirmar |
| Responsable técnico proveedor | Equipo de Sistemas SITRA — contacto operativo pendiente de completar |
| Versión del documento | 2.5 |
| Fecha | 28/10/2026 |
| Estado | Contrato funcional definido para validación conjunta; conectividad desde Aduana pendiente de validar |
| Interfaz | API REST, JSON, solo lectura |
| Clasificación | Documento técnico de integración |

> Antes de circular externamente, completar los nombres de contacto, el host del ambiente de integración, el procedimiento de emisión de credenciales y la ventana de soporte acordada.

## Resumen ejecutivo

SITRA expone siete rutas `GET` autenticadas. El flujo de carga entrante usa dos operaciones: consultar el índice de prealertas PREDES dirigidas a Bolivia que aún tienen sacas sin recepción registrada en IPS y recuperar el expediente de cada despacho S8. El detalle reúne el árbol S8 → S9 → S10, las declaraciones CDS encontradas y, por separado, la recepción física que IPS haya registrado.

Las rutas de despacho, saca, paquete y declaraciones sirven para consultas puntuales. La ruta general por código es una facilidad auxiliar; no es el flujo recomendado para recibir prealertas. Todas las respuestas de éxito se normalizan en JSON y los campos de contrato usan nombres estables en inglés.

La API no registra actuaciones ni modifica información. Para limitar el acceso del sistema consumidor, SITRA emite `customs.prealerts.read` para las dos operaciones PREDES. `customs.read` habilita las siete rutas; las credenciales históricas con `customs.process` también conservan acceso a las siete por compatibilidad. El detalle general S10 y el detalle de prealerta pueden incluir datos personales: el primero expone contactos IPS y el segundo puede incluirlos dentro de los datos de declaraciones CDS. Aduana debe limitar el acceso, la retención y los logs a lo necesario para su función.

### Qué información recibe Aduana y para qué sirve

La entrega principal es un **expediente postal de prealerta por despacho S8**. El índice excluye los despachos cuyas sacas PREDES tienen todas un evento de recepción IPS 130 registrado. Si al menos una saca no tiene ese evento, el despacho permanece como pendiente de recepción registrada; esto no prueba por sí solo que la carga siga físicamente en tránsito. El detalle reúne, bajo el mismo S8, las sacas S9, los paquetes S10 identificados y las declaraciones/respuestas CDS que se puedan enlazar. Cuando IPS registra la recepción física de una saca, el expediente también informa ese dato en un bloque separado.

| Bloque | Fuente | Información entregada | Uso operativo recomendado y límite |
|---|---|---|---|
| Despacho `S8` | PREDES en IPS | Identificador, origen/destino, fecha de cierre, totales del encabezado y tramos de transporte anunciados | Identificar la llegada prevista y organizar la revisión. Las fechas y el transporte son anunciados; no prueban que el despacho haya llegado. |
| Saca `S9` | PREDES en IPS | Código de saca, peso anunciado, cantidad declarada, paquetes identificados y resultado de comparación | Conciliar lo declarado para cada receptáculo. Las diferencias se reportan; SITRA no las corrige. |
| Paquete `S10` | PREDES en IPS | Código postal, peso y atributos postales disponibles, dentro de la saca a la que fue anunciado | Saber qué paquetes forman parte del despacho antes de su recepción operativa. La cobertura depende de que el mensaje PREDES incluya los identificadores. |
| Declaración y respuesta | CDS, enlazado por código postal/local del paquete | Número, estado, contenido estructurado y respuesta CDS, si existe coincidencia | Facilitar la revisión de la información declarada. Una falta de coincidencia no demuestra que no exista una declaración bajo otro identificador. |
| Recepción física | Evento de recepción IPS `130` y ficha local de ingreso | Estado, fecha y oficina de recepción y datos de transporte disponibles | Contrastar lo anunciado con lo registrado al ingreso. `not_recorded_in_ips` significa que IPS no muestra ese evento al consultar; no confirma por sí solo que la carga no haya arribado. |
| Cobertura y calidad | IPS/CDS | Estado de fuente, integridad de filas PREDES y señalización de truncamiento CDS | Decidir si el expediente puede procesarse como completo o si requiere reconsulta/validación. |

PREDES es un mensaje EDI postal de preaviso de despacho: transporta información del despacho, transporte previsto, receptáculos e ítems identificados. **No es por sí mismo una declaración aduanera ni una decisión de levante.** Los datos de declaración se consultan en CDS y se incluyen solo cuando se pueden asociar al S10; SITRA no inventa declaraciones faltantes ni reemplaza los controles o decisiones de Aduana. Por eso el contrato separa el anuncio PREDES, la cobertura CDS y la recepción física de IPS en lugar de presentarlos como un único estado.

Las dos operaciones principales responden a dos necesidades distintas: el índice permite detectar qué S8 entrantes debe procesar Aduana; el detalle entrega en una sola respuesta la jerarquía S8 → S9 → S10 y sus datos CDS asociados. De esta manera el consumidor no necesita descubrir cada saca y paquete mediante llamadas sucesivas ni confundir una prealerta con una recepción.

### Flujo recomendado para Aduana

1. Sondear `GET {{baseUrl}}/v1/postal/prealertas` usando un periodo acotado; el índice muestra despachos con al menos una saca sin evento de recepción IPS 130.
2. Recorrer todas las páginas siguiendo el enlace `rel="next"` del encabezado HTTP `Link`; la URL conserva las fechas y el límite de la consulta.
3. Registrar cada `dispatches[].identifier` de forma idempotente en el sistema consumidor y solicitar su `detail_path`.
4. Procesar juntos el despacho, sus sacas, paquetes y datos CDS. Mantener separados el preaviso PREDES y la recepción física IPS.
5. Volver a consultar los detalles de despachos aún en tránsito según la frecuencia acordada. La API no envía notificaciones ni incluye un cursor de cambios; la fecha del índice es la fecha de cierre PREDES.

Las rutas específicas permiten volver a consultar una sola entidad cuando el consumidor la necesita. Revisar siempre `source_status`, `completeness` y los indicadores `truncated` antes de dar por completo el expediente. El peso anunciado en PREDES, el peso registrado por IPS y el peso codificado en un S9 son valores de fuentes distintas.

## Índice

1. [Propósito y alcance](#1-propósito-y-alcance)
2. [Ambientes, autenticación y seguridad](#2-ambientes-autenticación-y-seguridad)
3. [Catálogo de operaciones](#3-catálogo-de-operaciones)
4. [Contrato de la consulta general auxiliar](#4-contrato-de-la-consulta-general-auxiliar)
5. [Contratos de consultas específicas](#5-contratos-de-consultas-específicas)
6. [Convenciones y calidad de datos](#6-convenciones-y-calidad-de-datos)
7. [Errores y recuperación](#7-errores-y-recuperación)
8. [Ejemplos de consumo](#8-ejemplos-de-consumo)
9. [Pruebas y pasos para habilitar la integración](#9-pruebas-y-pasos-para-habilitar-la-integración)

## 1. Propósito y alcance

La API permite a un sistema autorizado consultar información postal y aduanera registrada en IPS y CDS. Reconoce identificadores UPU S8 (despacho), S9 (saca), S10 (paquete) y códigos locales que puedan resolverse en las fuentes habilitadas.

Las respuestas están normalizadas por SITRA: no exponen directamente filas crudas ni nombres internos de tablas. La ruta general entrega el detalle asociado al tipo detectado; las rutas específicas devuelven una vista acotada para integraciones que no necesitan la ficha completa.

Este contrato es **solo de consulta**. No abre expedientes, no registra escaneos o eventos, no cambia estados y no crea paquetes ni entregas. Las operaciones de escritura quedan fuera del alcance y requieren un contrato y autorización separados.

## 2. Ambientes, autenticación y seguridad

### 2.1 Direcciones

En este documento, `{{baseUrl}}` significa **la URL base de la API, incluido el prefijo `/api` y sin barra final**. Cada consumidor configura ese único valor por ambiente. No se agrega `/api` de nuevo en las rutas de ejemplo.

Ejemplo local:

```text
{{baseUrl}} = http://127.0.0.1:8000/api
```

Esta dirección solo es accesible desde el mismo equipo. Para pruebas compartidas y producción, Infraestructura SITRA debe proporcionar el nombre DNS HTTPS, certificados y reglas de red. No usar `127.0.0.1` desde el servidor o equipo de Aduana.

Definir la dirección por ambiente en la configuración del consumidor, por ejemplo:

| Variable | Ejemplo | Uso |
|---|---|---|
| `SITRA_API_BASE_URL` / `{{baseUrl}}` | `https://<host-de-integracion>/api` | Base común de todos los endpoints, incluido el prefijo `/api` |
| `SITRA_API_TOKEN` | Secreto administrado | Token Bearer; no guardar en código fuente |

Si cambia el host, el puerto o el prefijo común, actualizar solo esta variable en el ambiente de consumo; los paths `/v1/postal/...` permanecen iguales. El host compartido es un dato pendiente de coordinación y no se debe inferir a partir del ejemplo local.

El segmento `/v1` identifica la versión principal del contrato. SITRA y Aduana deben acordar y comunicar cambios antes del despliegue; un cambio incompatible de ruta, nombre, tipo o significado de campo requiere una transición coordinada a una nueva versión principal.

### 2.2 Autenticación y permisos

Enviar en cada solicitud:

```http
Authorization: Bearer <TOKEN>
Accept: application/json
```

| Elemento | Requisito | Notas |
|---|---|---|
| Método | `GET` | Todas las operaciones son de consulta |
| URL | `{{baseUrl}}/v1/postal/...` | `{{baseUrl}}` ya incluye `/api` y no termina en `/` |
| `Authorization` | `Bearer <TOKEN>` | Token personal SITRA vigente |
| `Accept` | `application/json` | Solicita respuestas JSON, incluidos errores de autenticación |
| `Body` | Vacío | No enviar JSON ni formulario |

En una respuesta satisfactoria se espera `Content-Type: application/json` y el envoltorio `{ "data": ... }`. Las respuestas de Aduana incluyen `Cache-Control: no-store`.

Para el sondeo de carga entrante, emitir un token de solo lectura con la capacidad `customs.prealerts.read`. Esta capacidad autoriza únicamente el índice y el detalle PREDES. `customs.read` autoriza las siete rutas de Aduana y debe reservarse para integraciones que necesiten también consultas operativas IPS/CDS. Las credenciales históricas con `customs.process` conservan el mismo acceso amplio hasta que SITRA las rote a una capacidad más limitada.

La cuenta propietaria debe ser administradora o tener autorización de consulta aduanera (`customs.read`/`customs.process`); para PREDES también se admite el acceso postal CDS existente. Además, el token debe tener una capacidad válida: `customs.prealerts.read` para las dos rutas PREDES, o `customs.read`/`customs.process` para el catálogo completo. Son dos controles separados. La pantalla de credenciales API está restringida a administradores. Las credenciales pueden rotarse o revocarse desde SITRA.

### 2.3 Límites y protección de datos

- Límite configurado: 30 solicitudes por minuto por credencial, compartido entre las siete rutas de Aduana. Si se recibe `429`, respetar `Retry-After`, aplicar espera progresiva y reducir la concurrencia.
- Todas las respuestas de estas rutas incluyen `Cache-Control: no-store`. El cliente también debe evitar almacenar respuestas sensibles en caché.
- Mantener el token en un gestor de secretos o almacén seguro del ambiente. No incorporarlo a repositorios, capturas, tickets ni registros de aplicación.
- La consulta S10 completa puede devolver nombre, domicilio, teléfono y correo de contactos postales. Aplicar mínimo privilegio, limitar retención y acceso, y evitar registrar esos campos en logs.
- El detalle PREDES también incluye datos decodificados de CDS (`fields`, `pieces` y `documents`); según la declaración, esos datos pueden contener información personal y comercial. Proteger `/prealertas/{S8}` con los mismos controles de acceso, retención y logging.
- Usar HTTPS en todo ambiente compartido y validar el certificado del servidor. No desactivar la comprobación TLS para resolver problemas de conectividad.

## 3. Catálogo de operaciones

Todas las operaciones requieren Bearer Token, usan `Accept: application/json`, son `GET` y no reciben contenido en `Body`. Use `customs.prealerts.read` para el flujo de prealertas y `customs.read` únicamente cuando el consumidor necesite el catálogo completo. Las credenciales históricas `customs.process` también conservan acceso a las siete rutas.

Las dos primeras rutas son el flujo principal para carga entrante. Las siguientes son consultas puntuales por entidad; `/consulta/{codigo}` queda como acceso auxiliar por identificador y no se necesita para completar el flujo PREDES.

| Rol | Operación | URL completa | Identificador de entrada | Capacidad recomendada | Información devuelta |
|---|---|---|---|---|
| Principal · sondeo | Índice de prealertas pendientes | `GET {{baseUrl}}/v1/postal/prealertas` | Fechas y límite opcionales; continuación por encabezado `Link` | `customs.prealerts.read` | Lista S8 PREDES con destino Bolivia que tienen al menos una saca sin recepción IPS 130 registrada. Excluye los despachos con todas las sacas recibidas; conserva los parcialmente recibidos. |
| Principal · expediente | Detalle de prealerta | `GET {{baseUrl}}/v1/postal/prealertas/{codigo}` | Identificador S8 entrante a BO | `customs.prealerts.read` | Estructura PREDES S8 → S9 → S10, datos CDS que se puedan enlazar y registro de recepción física IPS por saca si existe. La respuesta indica si CDS está incompleto o no disponible. |
| Puntual | Despacho operativo | `GET {{baseUrl}}/v1/postal/despachos/{codigo}` | Código S8 | `customs.read` | Registro operativo IPS: origen, destino, salida, peso y cantidades de sacas declaradas/vinculadas. No consulta el preaviso PREDES. |
| Puntual | Resumen de saca | `GET {{baseUrl}}/v1/postal/sacas/{codigo}` | S9, número de registro o seguro que resuelva a una sola saca | `customs.read` | Identificación y peso, conteos declarados/vinculados, despacho asociado, códigos S10, cantidad de movimientos y resumen CDS. No incluye las fichas completas de los paquetes. |
| Puntual | Ficha de paquete | `GET {{baseUrl}}/v1/postal/paquetes/{codigo}` | Código S10 o código local | `customs.read` | Datos postales, último movimiento IPS, conteos y códigos relacionados de sacas, despachos y declaraciones CDS. No incluye el historial completo ni el contenido de las declaraciones. |
| Puntual | Declaraciones CDS | `GET {{baseUrl}}/v1/postal/declaraciones/{codigo}` | Código postal o local que resuelva a un paquete | `customs.read` | Datos del paquete, declaraciones y respuestas disponibles, campos, artículos, documentos y eventos CDS asociados. |
| Auxiliar | Consulta por código | `GET {{baseUrl}}/v1/postal/consulta/{codigo}` | S8, S9, S10 o código local | `customs.read` | Arma una ficha según el tipo: S8 busca el despacho operativo IPS; S9, la saca y paquetes; S10/local, el paquete y CDS. No consulta la bandeja PREDES. |

**Para la primera integración de carga entrante, Aduana debe consumir las dos operaciones principales y recibir una credencial `customs.prealerts.read`.** Las cinco rutas restantes son consultas puntuales de registros operativos IPS/CDS. Una entidad anunciada en PREDES puede no tener todavía registro operativo; por eso una respuesta `404` en esas consultas no debe borrar ni reemplazar el dato PREDES. `/consulta/{codigo}` es auxiliar y devuelve una ficha variable según el tipo de identificador. `customs.read` conserva acceso a las siete rutas por compatibilidad y para integraciones que justifiquen ese alcance.

`{codigo}` admite de 1 a 35 caracteres: letras, números y guion. La búsqueda normaliza a mayúsculas. Enviar el identificador como un único segmento de la ruta; no enviar listas separadas por coma en estas operaciones.

La ruta de declaraciones busca el paquete en CDS usando su identificador postal o local. El número de declaración CN aparece dentro del resultado cuando existe; no es el parámetro de esta ruta. Si Aduana necesita consultar una declaración concreta por su CN, ese caso requiere una operación de búsqueda por número de declaración que actualmente no forma parte de este contrato.

La ruta `/despachos/{codigo}` resuelve el registro operativo de despacho en IPS. La ruta `/prealertas/{codigo}` resuelve el mensaje PREDES que anuncia una entrada a Bolivia. La primera no sustituye a la segunda y ninguna debe interpretarse como confirmación de llegada sin revisar la recepción física asociada. `/sacas/{codigo}` admite un S9 o un número de registro/seguro que identifique exactamente una saca local; si un valor identifica varias, responde `422`.

La misma distinción aplica a S9 y S10: `/sacas/{codigo}`, `/paquetes/{codigo}` y `/declaraciones/{codigo}` son búsquedas puntuales en el espacio operativo IPS/CDS. Un código que ya está en PREDES puede todavía no tener un registro operativo de saca o un objeto completo de paquete; por eso esas rutas pueden devolver `404`, campos del paquete `null` o colecciones CDS vacías mientras `/prealertas/{S8}` sí devuelve el S9/S10 anunciado. Para carga entrante, la ausencia en una consulta puntual no debe borrar ni reemplazar el dato del expediente PREDES.

## 4. Contrato de la consulta general auxiliar

Esta ruta es útil para una búsqueda puntual cuando el consumidor solo conoce un código y necesita que SITRA determine si corresponde a S8, S9, S10 o un identificador local. No forma parte del flujo de sondeo PREDES y no debe usarse para recorrer una llegada: para eso se usa el índice y el detalle de prealertas.

### 4.1 Forma común

```json
{
  "data": {
    "type": "S9",
    "identifier": "BOLPBBCAYTOAAEN60031001100007",
    "identifier_analysis": {},
    "details": {}
  }
}
```

| Campo | Tipo | Descripción |
|---|---|---|
| `data.type` | string | `S8`, `S9`, `S10` o `Código local`, según el código consultado |
| `data.identifier` | string | Código normalizado utilizado para la consulta, incluido el identificador local |
| `data.identifier_analysis` | object o `null` | Desglose UPU para S8/S9; para S10 o local informa clasificación, validez y mensaje. S10 incluye además `issuer` |
| `data.details` | object | Campos específicos de la entidad detectada |

### 4.2 Análisis del identificador UPU

La API devuelve el análisis como un objeto de campos; no reemplaza el identificador registrado en IPS/CDS.

| Campo | Tipo JSON | Cuándo aparece | Significado |
|---|---|---|---|
| `type` | string | Todos | Dentro de `identifier_analysis`: `s8`, `s9`, `S10` o `Código local`. El campo externo `data.type` usa `S8`, `S9`, `S10` o `Código local`. |
| `code` | string | S8/S9 | Identificador UPU normalizado que se analizó |
| `origin_ctci` | string | S8/S9 | CTCI de origen, caracteres 1–6 |
| `destination_ctci` | string | S8/S9 | CTCI de destino, caracteres 7–12 |
| `mail_category` | string | S8/S9 | Categoría postal, carácter 13 |
| `mail_class` | string | S8/S9 | Clase postal, carácter 14 |
| `mail_subclass` | string | S8/S9 | Subclase postal, carácter 15 |
| `year_digit` | string | S8/S9 | Último dígito del año, carácter 16 |
| `dispatch_sequence` | string | S8/S9 | Número de despacho, caracteres 17–20 |
| `dispatch_code` | string | S9 | Prefijo S8, caracteres 1–20 |
| `receptacle_sequence` | string | S9 | Número de saca, caracteres 21–23 |
| `highest_receptacle_indicator` | string | S9 | Indicador UPU de saca con número más alto, carácter 24 |
| `highest_receptacle_label` | string | S9 | Etiqueta descriptiva del indicador anterior |
| `certified_indicator` | string | S9 | Indicador UPU de envíos certificados/valor declarado, carácter 25 |
| `certified_label` | string | S9 | Etiqueta descriptiva del indicador anterior |
| `weight_code` | string | S9 | Peso bruto codificado, caracteres 26–29 |
| `encoded_gross_weight_kg` | number o `null` | S9 | Peso del código UPU en kg; `null` si el código indica un peso superior al representable |
| `encoded_gross_weight_label` | string | S9 | Etiqueta legible del peso UPU |
| `valid` | boolean o `null` | S10/local | Resultado del dígito de control S10; `null` para código local |
| `message` | string | S10/local | Resultado legible del análisis S10 o instrucción de búsqueda local |
| `issuer` | string | S10 | Código de emisor postal, últimos dos caracteres |

Un S8 tiene 20 caracteres y un S9 tiene 29; S10 usa dos letras, nueve dígitos y dos letras. Para S10, el análisis contiene `type`, `valid`, `message` e `issuer`. Para un código local contiene `type`, `valid` y `message`; `valid` puede ser `null` porque el formato local no tiene una regla UPU de validación. Un código S10 con dígito de control inválido se identifica como tal, pero la búsqueda mantiene el texto recibido normalizado y no lo corrige automáticamente.

### 4.3 `details` para despacho S8

`details` tiene los siguientes campos:

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `dispatch_number` | string o `null` | Secuencia de despacho codificada en S8 |
| `dispatches[]` | array de objetos | Despacho(s) resuelto(s) en IPS; normalmente uno |
| `truncated` | boolean | `true` cuando IPS alcanzó uno de los límites de consulta descritos en la sección 6 |

| Campo de `dispatches[]` | Tipo | Descripción |
|---|---|---|
| `identifier` | string o `null` | Identificador S8 registrado en IPS |
| `origin`, `destination` | string o `null` | Códigos de oficina de origen y destino |
| `departure_at` | string o `null` | Fecha/hora de salida de IPS |
| `weight_kg` | number o `null` | Peso del despacho, en kilogramos |
| `transport` | string o `null` | Código del medio de transporte en IPS |
| `sacks_declared` | integer o `null` | Cantidad de sacas declarada en el despacho |
| `sacks_linked` | integer o `null` | Cantidad de sacas vinculadas en IPS |
| `sacks[]` | array | Sacas enlazadas al despacho y sus datos resumidos |

Cada elemento de `sacks[]` tiene esta estructura:

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `identifier` | string o `null` | Identificador S9 registrado en IPS |
| `registration_number`, `insurance_number`, `seal_number` | string o `null` | Número postal, seguro y sello, cuando están registrados |
| `weight_kg` | number o `null` | Peso de la saca en IPS |
| `packages_declared`, `packages_linked` | integer o `null` | Cantidad anotada en la saca y cantidad enlazada en IPS |
| `subclass`, `characteristic`, `content_format` | string o `null` | Clasificación y características de la saca |
| `documentation_enclosed`, `comments` | string, boolean o `null` | Indicador/documentación y observaciones de IPS |
| `status_code`, `postal_status_code` | integer o `null` | Códigos de estado de IPS |
| `last_event` | object | Último evento resumido: `code` y `occurred_at`, ambos potencialmente nulos |

Los valores no disponibles se devuelven como `null`.

`dispatch_number` es la secuencia de cuatro posiciones codificada en el identificador UPU S8. `sacks_declared` y `sacks_linked` provienen de fuentes distintas; comparar ambos para detectar discrepancias.

La búsqueda S8 de esta ruta general consulta el despacho operativo disponible en IPS. No consulta la cola PREDES de prealertas. Para localizar carga entrante antes de su recepción operativa, usar `/prealertas` y `/prealertas/{codigo}`.

### 4.4 `details` para saca S9

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `sack` | object | Datos registrados de la saca; campos detallados en la tabla siguiente |
| `dispatch` | object o `null` | Despacho enlazado con `identifier`, `origin`, `destination`, `departure_at`, `sacks_declared`, `weight_kg`, `transport` |
| `summary` | object | Conteos y conciliación entre paquetes declarados y enlazados |
| `events[]` | array de objetos | Historial normalizado de eventos de la saca |
| `packages[]` | array de objetos | Paquetes vinculados, último movimiento, estado de escaneo, acciones informativas y CDS |
| `manifests[]` | array de objetos | Manifiestos asociados a la operación postal |
| `cds` | object | Estado y cobertura de la consulta CDS para los paquetes de la saca |
| `office` | `null` | Estas rutas públicas no reciben contexto de oficina |
| `truncated` | boolean | Truncamiento detectado en IPS; en la consulta S9 truncada la API responde `409` |

Campos de `sack`:

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `identifier` | string o `null` | Identificador S9 canónico registrado en IPS |
| `number` | string o `null` | Secuencia S9 codificada en el identificador |
| `weight_kg` | number o `null` | Peso registrado en IPS |
| `registration_number`, `insurance_number`, `seal_number` | string o `null` | Registro, seguro y sello de IPS |
| `declared_package_count` | integer o `null` | Cantidad anotada en la saca |
| `subclass`, `characteristic`, `content_format` | string o `null` | Clasificación/características de IPS |
| `documentation_enclosed`, `comments` | string, boolean o `null` | Indicador documental y observación |
| `status_code`, `postal_status_code` | integer o `null` | Estados reportados por IPS |

`summary` tiene `package_count` (cantidad de paquetes encontrados), `declared_package_count` (cantidad anotada en IPS), `count_matches` (boolean o `null`) y `reconciliation`. Este último objeto contiene:

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `declared_count` | integer o `null` | Cantidad anotada en la saca |
| `linked_count` | integer | Paquetes enlazados encontrados en IPS |
| `count_matches` | boolean o `null` | Comparación de ambos conteos; `null` si no puede concluirse |
| `item_weight_total_kg` | number | Suma de pesos disponibles para los paquetes |
| `items_without_weight` | integer | Paquetes vinculados que no tienen peso conocido |
| `bag_weight_kg` | number o `null` | Peso de saca registrado en IPS |
| `can_compare` | boolean | Indica si IPS entregó una lista utilizable para comparar |

El peso de la saca (`sack.weight_kg`/`bag_weight_kg`) viene de IPS; `identifier_analysis.encoded_gross_weight_kg` viene del código S9 UPU. Son valores diferentes y no deben sobrescribirse entre sí.

Cada evento incluye `occurred_at`, `local_time_offset`, `code`, `name`, `office_code`, `office`, `office_name`, `next_office`, `user`, `user_name`, `detail` y `source`. Las propiedades pueden ser `null` si IPS no las registra para ese evento.

Cada paquete incluye `identifier`, `local_identifier`, `weight_kg`, objetos `class`, `origin`, `destination` y `postal_status` (`code`, `name`), `last_movement` (`code`, `name`, `occurred_at`, `office_code`), `scan_status`, `scanned_at`, `allowed_actions` y `cds`. `allowed_actions` describe el flujo interno de SITRA; no autoriza ni permite ejecutar acciones mediante esta API de consulta. El bloque CDS puede incluir identificadores, metadatos de paquete y arreglos `declarations[]` y `responses[]`. Si se requiere interpretar el esquema interno de los subobjetos CDS, usar las tablas de declaraciones y respuestas de la sección 5.4.

Cada manifiesto contiene `id`, `name`, `type`, `created_at`, `office`, `office_name`, `user`, `user_name` y `observation`. El objeto `cds` de la saca informa `status`, `declaration_count`, `missing_codes`, `without_declaration_codes`, `deleted_declaration_codes`, `unconfirmed_codes`, `complete` y `truncated`.

### 4.5 `details` para paquete S10 o código local

La ficha de paquete tiene estos bloques:

| Campo | Tipo JSON | Contenido |
|---|---|---|
| `sources` | object | Estados de fuente, normalmente las propiedades `IPS` y `CDS` |
| `summary` | object | Conteos enteros: `movement_count`, `delivery_count`, `sack_count`, `manifest_count`, `content_item_count`, `declaration_count` |
| `package` | object | Datos postales y aduaneros del paquete |
| `events[]` | array de objetos | Cero o un evento IPS: el más reciente del paquete, si existe |
| `customers[]` | array de objetos | Contactos postales; puede contener datos personales |
| `delivery_records[]` | array de objetos | Registros relacionados con entrega/no entrega |
| `logistics` | object | Sacas, manifiestos y eventos internacionales relacionados |
| `customs` | object | Registros aduaneros, contenido declarado y relación con CDS |
| `truncated` | boolean | `true` si una fuente informó un límite alcanzado |

`package` contiene:

| Campo | Tipo JSON | Origen/interpretación |
|---|---|---|
| `code`, `local_code` | string o `null` | Identificador postal S10 y código local de IPS |
| `weight_kg`, `value`, `duties_amount` | number/string o `null` | Peso, valor y derechos registrados en IPS |
| `customs_number` | string o `null` | Número aduanero disponible en IPS |
| `class`, `content`, `product_type`, `postal_status`, `origin`, `destination`, `currency` | object | Cada objeto incluye `code` y `name`, ambos posiblemente `null` |

| Subobjeto de paquete | Significado |
|---|---|
| `class` | Clase postal |
| `content` | Código y descripción del contenido postal |
| `product_type` | Tipo de producto |
| `postal_status` | Estado postal actual |
| `origin`, `destination` | País de origen y destino |
| `currency` | Moneda asociada al valor |

Los tipos SQL de origen pueden llegar como texto o número según el driver; los pesos normalizados `_kg` son numéricos. Usar `null` como ausencia y no como cero.

Un evento postal contiene:

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `occurred_at` | string o `null` | Fecha/hora del evento |
| `local_time_offset` | string/número o `null` | Desfase local informado por IPS |
| `code` | string/número o `null` | Código del evento |
| `name` | string o `null` | Nombre del evento, preferentemente en español si IPS lo proporciona |
| `office_code`, `office`, `office_name`, `next_office` | string/número o `null` | Oficina actual/siguiente y sus códigos |
| `user`, `user_name` | string o `null` | Usuario asociado si la fuente lo proporciona |
| `detail` | string o `null` | Información adicional |
| `source` | string o `null` | Fuente del movimiento, por ejemplo IPS o EDI |

Cada contacto de `customers[]` contiene `role`, `name`, `address`, `city`, `postal_code`, `country` (`code`, `name`), `phone` y `email`. Los valores de contacto son datos personales y pueden estar vacíos.

`delivery_records[]` contiene `occurred_at`, `event_code`, `event`, `local_time_offset`, `non_delivery_reason_code`, `non_delivery_measure_code`, `signatory`, `location` y `postal_code`; cada valor puede ser `null` cuando IPS no lo registró.

`logistics.sacks[]` contiene `identifier`, `weight_kg`, `packages_declared` y `dispatch` (`identifier`, `origin`, `destination`, `departure_at`). `logistics.manifests[]` usa el formato de manifiesto descrito para S9. `logistics.international_events[]` contiene `event_code`, `event`, `occurred_at`, `captured_at`, `location`, `sender_id` y `dispatch_number`.

`customs.records[]` contiene `declared_gross_weight`, `sender_reference` y `recipient_reference`. `customs.declared_content[]` contiene `identifier`, `quantity`, `description`, `declared_value`, `currency`, `net_weight_kg`, `origin` y `tariff_heading`. `customs.cds` contiene `status`, `packages[]`, `truncated` y `declarations_truncated`.

### 4.6 Ejemplo representativo de respuesta S9

El siguiente esquema ampliado ilustra la forma de la respuesta; sus valores son demostrativos y no representan una consulta vigente de integración. El resultado concreto depende de IPS/CDS al momento de cada llamada.

```json
{
  "data": {
    "type": "S9",
    "identifier": "BOLPBBCAYTOAAEN60031001100007",
    "identifier_analysis": {
      "type": "s9",
      "code": "BOLPBBCAYTOAAEN60031001100007",
      "origin_ctci": "BOLPBB",
      "destination_ctci": "CAYTOA",
      "mail_category": "A",
      "mail_class": "E",
      "mail_subclass": "N",
      "year_digit": "6",
      "dispatch_sequence": "0031",
      "dispatch_code": "BOLPBBCAYTOAAEN60031",
      "receptacle_sequence": "001",
      "highest_receptacle_indicator": "1",
      "highest_receptacle_label": "Es la saca con el número más alto del despacho",
      "certified_indicator": "0",
      "certified_label": "No contiene envíos certificados o con valor declarado",
      "weight_code": "0007",
      "encoded_gross_weight_kg": 0.7,
      "encoded_gross_weight_label": "0,7 kg"
    },
    "details": {
      "sack": {
        "identifier": "BOLPBBCAYTOAAEN60031001100007",
        "number": "001",
        "weight_kg": 0.7,
        "registration_number": null,
        "insurance_number": null,
        "seal_number": null,
        "declared_package_count": 4,
        "subclass": "N",
        "characteristic": null,
        "content_format": null,
        "documentation_enclosed": null,
        "comments": null,
        "status_code": null,
        "postal_status_code": null
      },
      "dispatch": {
        "identifier": "BOLPBBCAYTOAAEN60031",
        "origin": "BOLPBB",
        "destination": "CAYTOA",
        "departure_at": "2026-10-13 15:31:30",
        "sacks_declared": 1,
        "weight_kg": 0.7,
        "transport": "P"
      },
      "summary": {
        "package_count": 4,
        "declared_package_count": 4,
        "count_matches": true,
        "reconciliation": {
          "declared_count": 4,
          "linked_count": 4,
          "count_matches": true,
          "item_weight_total_kg": 0.7,
          "items_without_weight": 0,
          "bag_weight_kg": 0.7,
          "can_compare": true
        }
      },
      "events": [],
      "packages": [],
      "manifests": [],
      "cds": {
        "status": "available",
        "declaration_count": 4,
        "missing_codes": [],
        "without_declaration_codes": [],
        "deleted_declaration_codes": [],
        "unconfirmed_codes": [],
        "complete": true,
        "truncated": false
      },
      "office": null,
      "truncated": false
    }
  }
}
```

Este ejemplo se enfoca en la estructura y en los campos principales; los arreglos aparecen vacíos para no presentar registros de paquetes/personas como datos vigentes. La respuesta real de S9 puede incluir eventos, paquetes, manifiestos y declaraciones CDS.

## 5. Contratos de consultas específicas

### 5.1 Resumen del despacho S8

```http
GET {{baseUrl}}/v1/postal/despachos/BOLPBCITMXPAACN60004
```

Respuesta:

```json
{
  "data": {
    "identifier": "BOLPBCITMXPAACN60004",
    "dispatch_number": "0004",
    "dispatches": [
      {
        "identifier": "BOLPBCITMXPAACN60004",
        "origin": "BOLPBC",
        "destination": "ITMXPA",
        "departure_at": "2026-10-16 14:40:00",
        "weight_kg": 9.1,
        "transport": "P",
        "sacks_declared": 1,
        "sacks_linked": 1
      }
    ],
    "truncated": false
  }
}
```

La ruta específica devuelve el resumen y los conteos; no expande `sacks[]`. Para obtener las sacas vinculadas y sus detalles, consultar `/consulta/{codigo}` con el S8.

Contrato de la respuesta compacta:

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `data.identifier` | string | S8 consultado, normalizado |
| `data.dispatch_number` | string o `null` | Secuencia S8 derivada del identificador |
| `data.dispatches[]` | array | Despachos resueltos; cada objeto contiene `identifier`, `origin`, `destination`, `departure_at`, `weight_kg`, `transport`, `sacks_declared`, `sacks_linked` |
| `data.truncated` | boolean | `true` si se alcanzó un límite de búsqueda IPS |

Los campos de fecha, peso, transporte y conteos pueden ser `null` si la fuente no tiene el dato. `dispatches[]` puede estar vacío únicamente cuando no se encontró un despacho; ese caso se devuelve como `404`, no como `200` vacío.

### 5.2 Resumen de saca S9

```http
GET {{baseUrl}}/v1/postal/sacas/BOLPBBCAYTOAAEN60031001100007
```

Respuesta representativa:

```json
{
  "data": {
    "identifier": "BOLPBBCAYTOAAEN60031001100007",
    "sack": {
      "identifier": "BOLPBBCAYTOAAEN60031001100007",
      "number": "001",
      "weight_kg": 0.7,
      "packages_declared": 4,
      "packages_linked": 4,
      "counts_match": true
    },
    "related_identifiers": {
      "dispatch": "BOLPBBCAYTOAAEN60031",
      "packages": [
        "EE000738405BO",
        "EE000738428BO",
        "EE000738476BO",
        "EE000748014BO"
      ]
    },
    "summary": {
      "events_count": 4,
      "declarations_count": 4,
      "declarations_status": "available"
    },
    "truncated": false
  }
}
```

Esta ruta es deliberadamente resumida. `sack.weight_kg` es el peso de IPS. Para consultar eventos, reconciliación de peso, datos de cada paquete y respuestas CDS, usar la ruta general con el mismo S9.

Contrato de la respuesta compacta:

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `data.identifier` | string | Código recibido en la consulta, normalizado |
| `data.sack.identifier` | string o `null` | Identificador S9 canónico registrado en IPS |
| `data.sack.number` | string o `null` | Número de saca codificado en S9; es `null` si se consultó por registro o seguro |
| `data.sack.weight_kg` | number o `null` | Peso registrado en IPS |
| `data.sack.packages_declared` | integer o `null` | Cantidad anotada en IPS |
| `data.sack.packages_linked` | integer | Paquetes distintos enlazados en IPS |
| `data.sack.counts_match` | boolean o `null` | Resultado de la comparación; `null` si no puede calcularse |
| `data.related_identifiers.dispatch` | string o `null` | Código del S8 relacionado |
| `data.related_identifiers.packages[]` | array de strings | Códigos postales de paquetes enlazados |
| `data.summary.events_count` | integer | Eventos IPS localizados para la saca |
| `data.summary.declarations_count` | integer | Declaraciones CDS contadas para paquetes asociados |
| `data.summary.declarations_status` | string | Estado de la consulta CDS: `available`, `unavailable` o `disabled`; `available` significa que CDS respondió, aunque el conteo de declaraciones sea cero |
| `data.truncated` | boolean | Indicador de truncamiento IPS; si IPS truncó la consulta, esta ruta devuelve `409` |

### 5.3 Resumen de paquete S10

```http
GET {{baseUrl}}/v1/postal/paquetes/EE000738405BO
```

`data` incluye `identifier`, `sources`, `summary`, `package`, `movements[]`, `related_identifiers` y `truncated`.

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `identifier` | string | Código de paquete recibido en la ruta, normalizado |
| `sources` | object | Estado de IPS y CDS (`ok`, `empty`, `unavailable` o `disabled`) |
| `summary.movement_count` | integer | Total de movimientos IPS encontrados para el paquete |
| `summary.delivery_count` | integer | Registros de entrega encontrados |
| `summary.sack_count` | integer | Sacas relacionadas |
| `summary.declaration_count` | integer | Declaraciones CDS asociadas |
| `summary.content_item_count` | integer | Líneas de contenido aduanero |
| `package` | object | Resumen postal con `local_code`, `weight_kg`, `value`, `duties_amount`, `customs_number`, `class`, `content`, `product_type`, `postal_status`, `origin`, `destination` y `currency` |
| `movements[]` | array de objetos | Cero o un elemento: el movimiento IPS más reciente, con `occurred_at`, `code`, `name` y `office` |
| `related_identifiers` | object | Listas `sacks[]`, `dispatches[]` y `declarations[]` |
| `truncated` | boolean | `true` si alguna fuente consultada informó truncamiento. El historial anterior al último movimiento se omite por diseño y no activa este indicador |

En este resumen `package` no repite el campo `code`: el código consultado se entrega en `data.identifier`. Para el detalle ampliado del paquete, contactos, entregas y datos aduaneros, usar `/consulta/{codigo}`. Esa ruta también devuelve solo el último evento postal IPS; no expone el historial postal completo.

Ejemplo ilustrativo de la forma de la respuesta:

```json
{
  "data": {
    "identifier": "EE000738405BO",
    "sources": {"IPS": "ok", "CDS": "ok"},
    "summary": {
      "movement_count": 4,
      "delivery_count": 0,
      "sack_count": 1,
      "declaration_count": 1,
      "content_item_count": 1
    },
    "package": {
      "local_code": "LOCAL-001",
      "weight_kg": 0.7,
      "value": null,
      "duties_amount": null,
      "customs_number": null,
      "class": {"code": "E", "name": "EMS"},
      "content": {"code": null, "name": null},
      "product_type": {"code": null, "name": null},
      "postal_status": {"code": null, "name": null},
      "origin": {"code": "BO", "name": "Bolivia"},
      "destination": {"code": "CA", "name": "Canadá"},
      "currency": {"code": null, "name": null}
    },
    "movements": [
      {"occurred_at": "2026-10-13 15:31:30", "code": 30, "name": "Insertar envío en saca (salida)", "office": null}
    ],
    "related_identifiers": {
      "sacks": ["BOLPBBCAYTOAAEN60031001100007"],
      "dispatches": ["BOLPBBCAYTOAAEN60031"],
      "declarations": ["CN23-0001"]
    },
    "truncated": false
  }
}
```

Los valores de este ejemplo son demostrativos. Consultar la fuente en el ambiente para confirmar códigos, traducciones, fechas y asociaciones vigentes.

### 5.4 Declaraciones y respuestas CDS

```http
GET {{baseUrl}}/v1/postal/declaraciones/EE000738405BO
```

La respuesta raíz es `data` con:

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `data.identifier` | string | Código de paquete consultado, normalizado |
| `data.source_status` | string | Estado CDS para la consulta (`ok`, `empty`, `unavailable` o `disabled`) |
| `data.packages[]` | array | Paquetes CDS consolidados por identificador postal; no una fila por cada registro técnico |
| `data.truncated` | boolean | Indicador general de truncamiento CDS |
| `data.packages_truncated` | boolean | CDS limitó la lista de paquetes |
| `data.declarations_truncated` | boolean | CDS limitó declaraciones o respuestas asociadas |

Cada objeto de `packages[]` tiene estos campos:

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `code` | string o `null` | Identificador postal principal CDS |
| `local_codes[]` | array de strings | Identificadores locales alternativos enlazados al mismo objeto |
| `type`, `class`, `category`, `flow` | string o `null` | Tipo de objeto, clase/categoría y dirección de flujo CDS |
| `origin`, `destination` | string o `null` | Códigos de organización postal CDS |
| `posted_at` | string o `null` | Fecha de admisión/registro en CDS |
| `state` | string o `null` | Nombre del estado CDS |
| `declarations[]` | array de objetos | Declaraciones CN asociadas a este paquete |
| `responses[]` | array de objetos | Respuestas/decisiones CDS asociadas a este paquete |

Cada elemento de `declarations[]` contiene:

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `number` | string o `null` | Número de declaración CN informado por CDS |
| `state`, `workflow_stage` | string o `null` | Estado CDS y etapa interpretada por SITRA |
| `content_summary` | string o `null` | Resumen textual del contenido de la declaración |
| `data_status` | string | Resultado de parsear el XML de declaración: `ok`, `empty` o `invalid` |
| `data.fields` | object/mapa dinámico | Atributos decodificados del elemento principal; claves dependen del formulario. Si no hay atributos, la serialización actual puede mostrar `[]` |
| `data.pieces[]` | array de objetos | Renglones de contenido declarados, con atributos del XML CDS |
| `data.documents[]` | array de objetos | Documentos declarados, cada uno con `type` y `fields` |
| `countries` | object/array | Códigos y nombres de países asociados, indexados por código; vacío se representa como `[]` |
| `nature` | string o `null` | Descripción de naturaleza del contenido |
| `events[]` | array de objetos | Historial de eventos de esta declaración |

Cada elemento de `responses[]` contiene `state`, `decision`, `decision_code`, `data_status`, `data` (`fields`, `pieces`, `documents`) y `events[]`. `decision` es la etiqueta de decisión resuelta por CDS; `decision_code` es el código de decisión. Las estructuras internas dentro de `fields`, `pieces`, `documents`, `countries` y `nature` reflejan los datos CDS y pueden variar según el formulario.

Los eventos CDS contienen:

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `occurred_at` | string o `null` | Fecha/hora del evento CDS |
| `code`, `name` | string/número o `null` | Código y nombre del tipo de evento |
| `user_code`, `user_name` | string o `null` | Usuario CDS asociado |
| `office` | string o `null` | Nombre de oficina |
| `source` | string | Origen del evento; por defecto `CDS` |
| `kind` | string o `null` | `declarations` o `responses` |

Ejemplo ilustrativo del envoltorio y de los campos expuestos por una declaración/respuesta:

```json
{
  "data": {
    "identifier": "EE000738405BO",
    "source_status": "ok",
    "packages": [
      {
        "code": "EE000738405BO",
        "local_codes": ["LOCAL-001"],
        "type": "S10",
        "class": "E",
        "category": "A",
        "flow": "OUTBOUND",
        "origin": "BO",
        "destination": "CA",
        "posted_at": "2026-10-13 15:31:30",
        "state": "Registrado",
        "declarations": [
          {
            "number": "CN23-0001",
            "state": "Registrada",
            "workflow_stage": "submitted",
            "content_summary": "Documentos",
            "data_status": "ok",
            "data": {"fields": [], "pieces": [], "documents": []},
            "countries": [],
            "nature": null,
            "events": []
          }
        ],
        "responses": [
          {
            "state": "Procesada",
            "decision": "Liberada",
            "decision_code": "1",
            "data_status": "ok",
            "data": {"fields": [], "pieces": [], "documents": []},
            "events": []
          }
        ]
      }
    ],
    "truncated": false,
    "packages_truncated": false,
    "declarations_truncated": false
  }
}
```

Los valores y la nomenclatura textual de estados de este bloque son de muestra; los arreglos y campos internos de CDS dependen del formulario y los registros asociados.

Un paquete encontrado en IPS puede no tener declaración. En ese caso la consulta de declaraciones puede responder `200` con `packages: []` o con grupos sin declaraciones, según lo que CDS tenga registrado; revisar `source_status` y los arreglos. `404` significa que no se encontró el paquete/código en las fuentes de la búsqueda, no simplemente que no haya declaración.

### 5.5 Índice de prealertas PREDES entrantes

```http
GET {{baseUrl}}/v1/postal/prealertas?desde=2026-10-22&hasta=2026-10-28&limite=10
```

Esta consulta lista despachos del repositorio EDI de IPS cuyo CTCI de destino (posiciones 7–12 del S8) comienza con `BO`, dentro del periodo de cierre PREDES indicado, y que tienen al menos una saca S9 sin un evento de recepción física IPS 130 asociado al código S9. Si todas las sacas del despacho tienen ese evento, el despacho se excluye del índice. Si algunas lo tienen y otras no, el despacho permanece como pendiente y los conteos de recepción se informan en `summary`. La falta de un evento IPS no demuestra por sí sola que la carga no haya llegado físicamente. Para consultar el expediente completo de un despacho, usar `detail_path`.

| Parámetro | Tipo | Obligatorio | Regla |
|---|---|---:|---|
| `desde` | fecha `YYYY-MM-DD` | No | Inicio inclusivo de `DESPTCH_CLOSING_DATE`. Por defecto, hoy menos 6 días en `America/La_Paz`. |
| `hasta` | fecha `YYYY-MM-DD` | No | Fin inclusivo del periodo. Por defecto, hoy en `America/La_Paz`. |
| `limite` | entero | No | Resultados por página; por defecto `20`, rango permitido `1–100`. |
| `cursor` | string opaco firmado | No | Continuación incluida en la URL del encabezado HTTP `Link` con `rel="next"`; no editarlo. Normalmente el consumidor no necesita construirlo manualmente. |

El rango máximo es de 31 días. Los resultados se ordenan por fecha de cierre PREDES ascendente y, en caso de empate, por identificador interno de despacho. Si hay otra página, SITRA devuelve el encabezado HTTP `Link: <URL>; rel="next"`. El enlace incluye el cursor firmado y conserva las fechas y el límite efectivos. Sígalo sin modificar la URL y envíe nuevamente el Bearer Token. El cursor no es una marca de cambios ni una credencial de acceso.

El cuerpo de respuesta contiene únicamente `data.dispatches`: los filtros, el estado de IPS y la información del cursor no se agregan al JSON. La URL del encabezado `Link` es la referencia para continuar la consulta, también cuando SITRA aplicó fechas predeterminadas.

`detail_path` contiene la ruta desde `/v1` y comienza con `/`; para formar la URL, concatenar literalmente `{{baseUrl}}{{detail_path}}` (por ejemplo, `https://host/api` + `/v1/postal/prealertas/...`). No resolverlo como una URI absoluta contra `baseUrl`, porque eso puede quitar el prefijo `/api`. Una respuesta vacía es `200` con `dispatches: []`: puede significar que no hubo manifiestos en el periodo o que todos tienen recepción IPS registrada. Para sondeo continuo, Aduana debe consultar una ventana móvil reciente, guardar el S8 de forma idempotente y volver a leer detalles de despachos pendientes. El índice filtra por fecha de cierre PREDES y por recepción registrada al momento de cada página; no notifica cambios posteriores ni ofrece una instantánea transaccional entre páginas. SITRA no envía webhooks ni inicia conexiones hacia Aduana.

| Campo de `data` | Tipo | Significado |
|---|---|---|
| `dispatches[]` | array | Resumen de cada S8 PREDES entrante encontrado. |
| `dispatches[].identifier` | string | Código S8. |
| `dispatches[].predes_closing_at` | fecha/hora o `null` | `DESPTCH_CLOSING_DATE` de IPS; no es una confirmación de llegada física. |
| `dispatches[].origin_ctci`, `destination_ctci` | string | Códigos postales extraídos del S8. |
| `dispatches[].summary.sacks_count` | entero | Sacas S9 registradas bajo ese S8 PREDES. |
| `dispatches[].summary.packages_declared` | entero | Suma de cantidades declaradas en los registros S9. |
| `dispatches[].summary.packages_declared_complete` | boolean | `false` si alguna saca no tiene cantidad declarada. |
| `dispatches[].summary.packages_identified` | entero | Registros S10 con identificador no vacío en las sacas PREDES. |
| `dispatches[].summary.sacks_physically_received` | entero | Sacas del S8 con evento IPS 130 registrado. |
| `dispatches[].summary.sacks_without_receipt_event` | entero | Sacas sin evento IPS 130 asociado. Su ausencia no confirma que la saca no haya llegado físicamente. |
| `dispatches[].summary.sacks_weight_total_kg` | número | Suma del peso anunciado de las sacas S9. |
| `dispatches[].summary.sacks_weight_complete` | boolean | `false` si alguna saca no tiene peso informado. |
| `dispatches[].summary.sacks_with_count_mismatch` | entero | Sacas donde el número declarado no coincide con los S10 identificados. |
| `dispatches[].detail_path` | string | Sufijo de ruta desde `/v1`, con barra inicial; concatenar literalmente con `{{baseUrl}}`. |

Ejemplo real del índice local, consultado el 28/10/2026. El estado puede cambiar cuando IPS registre la recepción:

```json
{
  "data": {
    "dispatches": [
      {
        "identifier": "DEFRAABOLPBAAUX60098",
        "predes_closing_at": "2026-10-22 02:10:00",
        "origin_ctci": "DEFRAA",
        "destination_ctci": "BOLPBA",
        "summary": {
          "sacks_count": 1,
          "packages_declared": 5,
          "packages_declared_complete": true,
          "packages_identified": 5,
          "sacks_physically_received": 0,
          "sacks_without_receipt_event": 1,
          "sacks_weight_total_kg": 1.9,
          "sacks_weight_complete": true,
          "sacks_with_count_mismatch": 0
        },
        "detail_path": "/v1/postal/prealertas/DEFRAABOLPBAAUX60098"
      }
    ]
  }
}
```

### 5.6 Detalle de prealerta S8 con sacas, paquetes y CDS

```http
GET {{baseUrl}}/v1/postal/prealertas/DEFRAABOLPBAAUX60098
```

La ruta acepta solo un S8 con destino `BO`. `data.dispatch` presenta el anuncio PREDES y sus tramos de transporte; `data.sacks[]` conserva cada S9; dentro de cada saca `packages[]` contiene los S10 y el resultado de enlazarlos por identificador con CDS. No se eliminan paquetes PREDES cuando CDS no encuentra coincidencia: se informa explícitamente el estado de la consulta.

| Campo | Tipo | Significado |
|---|---|---|
| `data.identifier`, `data.type`, `data.status` | string | S8 consultado, valor `S8`, estado `prealert_available`; este estado significa que hay registro PREDES, no que la carga haya arribado. |
| `data.source_status.ips_predes` | string | `ok` cuando se consultó PREDES en IPS. |
| `data.source_status.cds` | string | `ok`, `disabled`, `unavailable` o `not_applicable` si no hay códigos S10 que consultar y CDS está habilitado; un fallo CDS no elimina la prealerta. |
| `data.dispatch.identifier` | string | S8. |
| `data.dispatch.origin_ctci`, `destination_ctci` | string | Códigos UPU de origen y destino del S8. |
| `data.dispatch.destination_country_code` | string | `BO`. |
| `data.dispatch.dispatch_sequence` | string | Secuencia del S8, caracteres 17–20. |
| `data.dispatch.predes_closing_at` | fecha/hora o `null` | Fecha de cierre registrada en PREDES. No es el evento de recepción física. |
| `data.dispatch.inward_parcels_weight_kg`, `inward_parcels_count` | número/entero o `null` | Totales informados directamente en el encabezado PREDES cuando IPS los recibió; no se calculan desde el peso de las sacas. |
| `data.dispatch.transport_legs[]` | array | Fechas de despacho y llegada anunciadas, aeropuertos, vuelo, transportista y tipo de transporte. Los valores se mantienen como los entregó IPS; `estimated_indicator` no se interpreta ni convierte. |
| `data.summary.sacks_count` | entero | Sacas PREDES incluidas. |
| `data.summary.packages_declared` | entero | Suma S9; interpretar junto a `packages_declared_complete`. |
| `data.summary.packages_identified` | entero | Filas S10 con código no vacío. |
| `data.summary.sacks_weight_total_kg` | número | Suma del peso que PREDES informa por cada saca. |
| `data.summary.sacks_weight_complete` | boolean | Indica si todas las sacas traen peso. |
| `data.summary.sacks_with_count_mismatch` | entero | Sacas cuyo conteo declarado difiere de los S10 identificados. |
| `data.summary.packages_with_cds_declaration` | entero | Códigos S10 distintos con al menos una declaración CDS encontrada. |
| `data.summary.declarations_count` | entero | Declaraciones CDS devueltas para esos códigos. |
| `data.summary.declaration_coverage` | string | `complete`, `partial`, `none_found`, `incomplete_lookup`, `disabled`, `unavailable` o `not_applicable`; ver semántica debajo. |
| `data.summary.sacks_physically_received` | entero | Sacas con evento IPS 130 de recepción física. |
| `data.summary.arrival_status` | string | Resumen de eventos IPS 130: `not_applicable`, `not_recorded_in_ips`, `partially_recorded_in_ips` o `received_in_ips`. No afirma por sí solo la presencia o ausencia física de la carga. |
| `data.sacks[].identifier` | string | S9 de la saca. |
| `data.sacks[].number_of_items_declared`, `items_identified` | entero o `null` | Cantidad declarada en PREDES y cantidad de filas S10 identificadas. |
| `data.sacks[].count_matches` | boolean o `null` | Resultado de comparar las dos cantidades; `null` si PREDES no trae cantidad. |
| `data.sacks[].weight_kg` | número o `null` | Peso de la saca registrado en el anuncio PREDES. |
| `data.sacks[].physical_receipt` | object | Recepción IPS separada del anuncio; `status` es `received_in_ips` solo si existe evento 130. `not_recorded_in_ips` no afirma que la saca no haya llegado físicamente. |
| `data.sacks[].packages[].identifier` | string o `null` | S10 anunciado. `null` cuando el registro PREDES no contiene identificador. |
| `data.sacks[].packages[].weight_kg` | número o `null` | Peso de paquete informado por PREDES. |
| `data.sacks[].packages[].origin_country_code`, `destination_country_code` | string o `null` | Países de origen/destino informados por PREDES. |
| `data.sacks[].packages[].cds.lookup_status` | string | `declaration_available`, `registered_without_declaration`, `not_found`, `not_confirmed`, `disabled`, `unavailable` o `identifier_missing`. |
| `data.sacks[].packages[].cds.declarations[]` | array | Número, estado CDS y datos CN decodificados (`fields`, `pieces`, `documents`). |
| `data.sacks[].packages[].cds.responses[]` | array | Estado, decisión y datos de respuesta CDS asociados al paquete. |
| `data.completeness` | object | `predes_sacks_complete` y `predes_items_complete` indican que se leyeron todas las filas S9/S10 PREDES; `cds_lookup_complete` y `cds_truncated` informan el resultado y límite CDS. |

La cobertura CDS se calcula sobre los códigos S10 únicos que se pudieron consultar: `complete` significa que todos tienen al menos una declaración enlazada; `partial`, que algunos tienen y otros no; `none_found`, que la búsqueda terminó sin declaraciones enlazadas; `incomplete_lookup`, que el límite impidió confirmar todos los códigos; `disabled`/`unavailable`, que CDS no se pudo consultar; y `not_applicable`, que no había S10 identificables. `none_found` describe el resultado de la búsqueda por los identificadores disponibles, no demuestra que no exista una declaración bajo otro código.

Los totales de peso `*_weight_total_kg` se devuelven redondeados a tres decimales, de acuerdo con la precisión de peso usada por este contrato. Esto evita artefactos de representación binaria como `33.300000000000004`.

Cada tramo de transporte expone `dispatch_event_at`, `dispatch_date`, `arrival_date`, `estimated_indicator`, `flight`, `origin_airport_code`, `destination_airport_code`, `scheduled_departure_at`, `scheduled_arrival_at`, `carrier_code`, `carrier_number` y `transport_type_code`. Los datos pueden ser nulos si el mensaje PREDES no anunció ese tramo.

`dispatch.inward_parcels_count` y `dispatch.inward_parcels_weight_kg` son totales del encabezado PREDES. `summary.packages_declared` suma las cantidades anotadas en cada S9, `summary.packages_identified` cuenta las filas S10 identificadas y `summary.sacks_weight_total_kg` suma los pesos de las S9. Son medidas de distinto nivel y origen; pueden diferir y deben conciliarse, no sustituirse entre sí.

`physical_receipt` incluye `status`, `received_at`, `office_code`, `office_name`, `actual_location_code`, `actual_local_at`, `inbound_dispatch_date`, `master_air_waybill`, `carrier_code`, `transport_number` y `transport_type_code`. La presencia de datos de transporte no significa que exista evento físico 130.

`physical_receipt.received_at` conserva la fecha `EVENT_GMT_DT` del evento IPS 130; `actual_local_at` conserva `ACTUAL_LCL_DT` de la ficha de ingreso. Son campos de origen distintos y SITRA no los convierte entre sí.

Cada S10 además lleva `category_code`, `associated_service_code`, `class_code`, `destination_id`, `insured_value_sdr`, `handling_class` y `origin_operator`, cuando esos datos están presentes en PREDES. Cada S9 lleva `content_format_code`, `subtype_code`, `exported_indicator`, `net_weight_indicator`, `exempt_indicator`, `dispatch_document_indicator` y `other_characteristics`.

La búsqueda de CDS intenta enlazar el S10 PREDES con el código postal principal del objeto CDS y sus dos identificadores locales. `not_found` significa que no se halló coincidencia por esos identificadores en la consulta actual; no prueba que Aduana no tenga una declaración bajo otro código. `not_confirmed` indica un límite de consulta CDS y no debe tratarse como ausencia. Si CDS está deshabilitado o fuera de servicio, el endpoint aún devuelve S8/S9/S10 PREDES con `source_status.cds` y cada `lookup_status` correspondiente.

Las declaraciones incluyen atributos de contenido declarado y pueden contener información personal remitente/destinatario. El integrador debe aplicar control de acceso, retención y auditoría acordes con esa sensibilidad. SITRA no corrige ni completa declaraciones faltantes a partir de los datos PREDES.

La fuente PREDES es un **preaviso del operador postal**. Las fechas de vuelo, pesos y contenidos describen lo anunciado y pueden cambiar. La recepción física se cruza por el identificador S9 contra la saca local IPS; los identificadores internos de PREDES e IPS no se presuponen iguales. La confirmación se entrega aparte mediante el evento 130; tampoco debe confundirse la falta de ese evento con una confirmación de que la carga no llegó.

Fragmento real del objeto `data`, contrastado con IPS el 28/10/2026. El despacho también aparece en el índice de pendientes: tiene una saca sin evento IPS 130 registrado. Se muestran una saca y un paquete; la respuesta completa conserva todos los campos del contrato:

```json
{
  "data": {
    "identifier": "DEFRAABOLPBAAUX60098",
    "type": "S8",
    "status": "prealert_available",
    "source_status": {"ips_predes": "ok", "cds": "ok"},
    "dispatch": {
      "identifier": "DEFRAABOLPBAAUX60098",
      "origin_ctci": "DEFRAA",
      "destination_ctci": "BOLPBA",
      "destination_country_code": "BO",
      "dispatch_sequence": "0098",
      "predes_closing_at": "2026-10-22 02:10:00",
      "inward_parcels_weight_kg": null,
      "inward_parcels_count": null,
      "transport_legs": [
        {
          "dispatch_event_at": "2026-10-22 10:00:00",
          "dispatch_date": "2026-10-22 10:00:00",
          "arrival_date": "2026-10-23 23:59:00",
          "estimated_indicator": "0",
          "flight": "TGM 191",
          "origin_airport_code": "FRA",
          "destination_airport_code": "LTN",
          "scheduled_departure_at": "2026-10-22 10:00:00",
          "scheduled_arrival_at": "2026-10-22 23:00:00",
          "carrier_code": "TGM",
          "carrier_number": "191",
          "transport_type_code": "TK"
        },
        {
          "dispatch_event_at": "2026-10-22 10:00:00",
          "dispatch_date": "2026-10-22 10:00:00",
          "arrival_date": "2026-10-23 23:59:00",
          "estimated_indicator": "0",
          "flight": "GM 0000",
          "origin_airport_code": "LTN",
          "destination_airport_code": "LPB",
          "scheduled_departure_at": "2026-10-23 00:01:00",
          "scheduled_arrival_at": "2026-10-23 23:59:00",
          "carrier_code": "GM",
          "carrier_number": "0000",
          "transport_type_code": "PL"
        }
      ]
    },
    "summary": {
      "sacks_count": 1,
      "packages_declared": 5,
      "packages_declared_complete": true,
      "packages_identified": 5,
      "sacks_weight_total_kg": 1.9,
      "sacks_weight_complete": true,
      "sacks_with_count_mismatch": 0,
      "packages_with_cds_declaration": 0,
      "declarations_count": 0,
      "declaration_coverage": "none_found",
      "sacks_physically_received": 0,
      "arrival_status": "not_recorded_in_ips"
    },
    "completeness": {
      "predes_sacks_complete": true,
      "predes_items_complete": true,
      "cds_lookup_complete": true,
      "cds_truncated": false
    },
    "sacks": [
      {
        "identifier": "DEFRAABOLPBAAUX60098001100019",
        "number_of_items_declared": 5,
        "items_identified": 5,
        "count_matches": true,
        "weight_kg": 1.9,
        "content_format_code": "E",
        "physical_receipt": {"status": "not_recorded_in_ips"},
        "packages": [
          {
            "identifier": "LF877211158DE",
            "origin_country_code": "DE",
            "destination_country_code": "BO",
            "weight_kg": 0.035,
            "cds": {"lookup_status": "not_found", "declarations": [], "responses": []}
          }
        ]
      }
    ]
  }
}
```

En la consulta del ejemplo, el encabezado PREDES anuncia cinco paquetes en una saca de `1.9 kg`; se identificaron los cinco S10 y el conteo coincide. IPS no registra el evento 130 para esa saca, mientras que la búsqueda CDS no halló declaraciones por los identificadores consultados. La fecha de llegada anunciada pertenece a PREDES y no reemplaza la confirmación de recepción registrada en IPS.

## 6. Convenciones y calidad de datos

- Toda respuesta satisfactoria de consulta usa `{ "data": ... }`. Los errores generados dentro del controlador usan `{ "message": "..." }`; autenticación, autorización, enrutamiento y límite de solicitudes pueden ser respuestas del middleware/framework.
- Los campos opcionales ausentes se representan como `null`; las colecciones sin elementos como `[]`.
- Los campos cuyo nombre termina en `_kg` son pesos expresados como números en kilogramos. La API no convierte a gramos.
- Las fechas conservan el valor/formato recibido de IPS/CDS. Revisar `local_time_offset` cuando exista; no inferir una zona horaria ausente.
- `sources` y `source_status` indican la situación de las fuentes según el tipo de respuesta. Los estados relevantes son `ok`, `empty`, `unavailable`, `disabled` y, en la búsqueda PREDES, `not_applicable`. Una fuente `empty` respondió, pero no encontró registros enlazados; `unavailable` indica que no se pudo consultar; `disabled` indica que CDS está deshabilitado en ese ambiente.
- Un `200` puede contener información parcial si una fuente secundaria como CDS no estuvo disponible. En el flujo PREDES, revisar `source_status.cds`, `summary.declaration_coverage` y `completeness`; en las demás consultas, revisar `sources` y los campos de truncamiento.
- `truncated: true` significa que una o más listas pudieron ser limitadas. No usar la respuesta para afirmar totales completos; atender también `packages_truncated` y `declarations_truncated`.
- Las respuestas S10 de `/paquetes/{codigo}` y `/consulta/{codigo}` devuelven como máximo el último evento postal IPS en `movements[]` o `events[]`, respectivamente. `summary.movement_count` conserva el total de eventos encontrados; el historial anterior se omite por diseño. Los eventos de declaraciones CDS y los registros `delivery_records[]` son colecciones independientes.
- Comparar por separado los datos declarados, enlazados y pesados. Una diferencia entre IPS y UPU/CDS es un hallazgo de conciliación, no una corrección automática de SITRA.
- La consulta de datos postales y la de CDS pueden tener disponibilidad independiente. Una respuesta de la ruta general puede contener datos de una fuente y señalar que otra está vacía o no disponible.

### 6.1 Límites actuales de consulta

Los límites evitan respuestas sin cota. Cuando la API puede identificar el recorte, informa `truncated`; en la consulta S9 un resultado IPS truncado se rechaza con `409` para no presentar una ficha incompleta como íntegra.

| Operación/dataset | Límite actual | Señal al consumidor |
|---|---:|---|
| Despacho S8 | 20 despachos y 100 sacas por resultado | `details.truncated` o `data.truncated` |
| Búsqueda S9 en IPS | 20 candidatos; 500 eventos; 1.000 paquetes relacionados; 100 manifiestos | La ficha S9 devuelve `409` si IPS detecta truncamiento |
| Resumen de paquete S10 | 1 último movimiento como máximo en `data.movements[]` | `summary.movement_count` informa el total encontrado; `data.truncated` solo señala truncamiento de fuentes |
| Búsqueda CDS de paquete | `postal.record_limit`, actualmente 100 paquetes/declaraciones/respuestas; hasta 500 eventos CDS | `truncated`, `packages_truncated` y `declarations_truncated` según la respuesta |
| CDS enlazado a una saca S9 | Hasta 1.000 paquetes/declaraciones/respuestas en el índice de la saca | `cds.complete` y `cds.truncated`; una saca IPS incompleta responde `409` |
| Prealerta S8 PREDES | Todas las filas S9/S10 PREDES del despacho; CDS procesa hasta 5.000 códigos S10 y hasta 5.000 paquetes, declaraciones y respuestas | `completeness.predes_items_complete`/`predes_sacks_complete`; recorte CDS en `cds_truncated` y `cds_lookup_complete` |
| Eventos internacionales IPS/EDI por paquete | Hasta 200 elementos en `logistics.international_events[]` | Si se alcanzó el límite, el cambio de backend marca el campo general `truncated` |
| Contenido declarado IPS por paquete | Hasta 200 elementos en `customs.declared_content[]` | Si se alcanzó el límite, el cambio de backend marca el campo general `truncated` |

Los límites CDS dependen de la configuración SITRA y pueden ajustarse. Confirmar el límite aplicado en el ambiente compartido antes de dimensionar lotes o procesos de conciliación.

### 6.2 Interpretación de estados y conteos

| Campo | Qué mide | Qué no significa |
|---|---|---|
| `sacks_declared` / `sacks_linked` | Sacas anotadas en despacho frente a sacas enlazadas en IPS | No certifica el contenido físico de cada saca |
| `packages_declared` / `packages_linked` | Paquetes anotados en saca frente a paquetes enlazados en IPS | No certifica que el paquete haya sido escaneado físicamente |
| `count_matches` | Comparación de los dos conteos si ambos se pueden evaluar | `null` no significa coincidencia; significa que no se pudo comparar |
| `declarations_status` / `source_status` | Disponibilidad/resultado de la consulta CDS | `available` no significa que cada paquete tenga declaración activa |
| `data_status` | Estado de lectura/decodificación de los datos CDS | No es el estado del trámite aduanero |
| `workflow_stage`, `state`, `decision` | Etiquetas interpretadas de los registros CDS | No reemplazan la revisión del contenido `data.fields` |
| `truncated` | Una parte del conjunto solicitado quedó fuera por un límite detectado | `false` confirma que no se detectó un límite; no certifica exactitud material de los datos fuente |

## 7. Errores y recuperación

Todas las respuestas de error de las rutas Aduana usan `Content-Type: application/json`, `Cache-Control: no-store` y el mismo formato. `error.code` es una clave estable para clasificar el error; el cliente debe tomar decisiones con esa clave y el estado HTTP, nunca con el texto de `message`. Incluir `error.request_id` en las consultas a soporte; las fallas internas `500` y las indisponibilidades `503` se registran con ese identificador. `error.retryable` indica si se recomienda reintentar automáticamente. Las validaciones agregan `errors` con mensajes por campo.

```json
{
  "message": "Los parámetros de consulta de prealertas no son válidos.",
  "error": {
    "code": "VALIDATION_ERROR",
    "request_id": "2b61d45e-9731-4d25-a241-a1c329c3429c",
    "retryable": false
  },
  "errors": {
    "desde": ["El parámetro desde debe usar el formato YYYY-MM-DD."]
  }
}
```

El header `X-Request-ID` repite el identificador incluido en `error.request_id`. No registrar tokens ni datos CDS para correlacionar una incidencia.

| HTTP | Cuándo ocurre | `message` actual o comportamiento | Acción del cliente |
|---|---|---|---|
| `200` | Consulta atendida; algunas respuestas pueden ser parciales si una fuente secundaria no está disponible | Respuesta `{ "data": ... }` | Revisar `source_status` en PREDES o `sources` en las demás consultas, además de `completeness` y los indicadores `truncated`. |
| `401` | Falta el token, expiró o no es válido | `error.code`: `UNAUTHENTICATED`; `message`: `No autenticado. Envía un token Bearer válido.`; `error.retryable`: `false`. | Verificar el token, el encabezado y su vigencia; no repetir hasta corregirlos. |
| `403` | La cuenta no tiene autorización, no se usó un token personal Bearer o el token carece de capacidad | `error.code`: `FORBIDDEN`; `message`: `La credencial no está autorizada para esta operación.`; `error.retryable`: `false`. | Revisar la autorización de la cuenta y la capacidad del token. Para el flujo PREDES, emitir `customs.prealerts.read`. |
| `404` | Código no encontrado o URL que no coincide con el formato de ruta; prealerta S8 sin PREDES en IPS; para S9 significa cero coincidencias. Más de una coincidencia S9 produce `422`. | `error.code`: `NOT_FOUND`; mensaje de dominio según la ruta. Una ruta inexistente usa `No se encontró la ruta solicitada.` Un paquete sin declaración CDS no implica por sí solo un `404`. | Confirmar identificador, ruta y ambiente; distinguir inexistencia de CDS vacío. |
| `405` | Se llamó una ruta existente con método distinto de `GET` | `error.code`: `METHOD_NOT_ALLOWED`; `message`: `El método HTTP no está permitido para esta ruta.` | Cambiar a `GET`; no enviar cuerpo. |
| `409` | IPS detectó truncamiento al resolver el S9 | `error.code`: `CONFLICT`; `message`: `La consulta de la saca está incompleta. No se puede confirmar el resultado.`; `error.retryable`: `false`. | Tratar el resultado como no disponible/incompleto y coordinar consulta con SITRA. No reintentar en bucle. |
| `422` | Código no es S8 para `/prealertas/{codigo}`, tipo incorrecto en rutas específicas, rango de fechas inválido/mayor a 31 días o cursor inválido | `error.code`: `VALIDATION_ERROR`; errores de parámetros incluyen `{ "errors": { ... } }`; `error.retryable`: `false`. | Corregir código o fechas; para continuar páginas, seguir la URL recibida en `Link rel="next"` sin modificarla. |
| `429` | Se excedieron 30 solicitudes por minuto para la credencial | `error.code`: `RATE_LIMITED`; `message`: `Se superó el límite de solicitudes.`; el header `Retry-After` indica cuándo reintentar; `error.retryable` es `true`. | Esperar el tiempo indicado, usar backoff y reducir concurrencia. |
| `500` | Fallo interno no clasificado como indisponibilidad de una fuente | `error.code`: `INTERNAL_ERROR`; `message`: `No se pudo completar la consulta aduanera.`; `error.retryable`: `false`. | No reintentar automáticamente. Informar `error.request_id` al soporte de SITRA. |
| `503` | IPS/CDS no disponible según el resultado de consulta o se detectó una falla de conexión/tiempo de espera con una fuente | `error.code`: `DEPENDENCY_UNAVAILABLE`; `message`: `Una fuente de datos necesaria no está disponible temporalmente.` cuando el controlador detecta la falla; `error.retryable`: `true`. | Reintentar con espera progresiva y límite de intentos; registrar status, ruta y `error.request_id`. |

### 7.1 Mensajes de dominio que devuelve SITRA

Los textos corresponden a la implementación documentada y pueden cambiar entre versiones. No usarlos como claves de lógica; tomar decisiones con el código HTTP y, cuando esté disponible, con los campos de estado de la respuesta.

| HTTP | Ruta/condición | Texto actual de `message` |
|---|---|---|
| `404` | `/despachos/{codigo}`: S8 sin registro | `No se encontró el despacho S8 en IPS.` |
| `404` | `/consulta/{codigo}`: S8 sin registro | `No se encontró el despacho S8 en IPS.` |
| `404` | `/sacas/{codigo}` o consulta general S9: no existe la saca | `No se encontró la saca S9 en IPS.` |
| `404` | `/paquetes/{codigo}` o `/declaraciones/{codigo}`: código sin registro | `No se encontró el paquete o identificador en IPS/CDS.` |
| `404` | `/consulta/{codigo}`: S10/local no encontrado | `No se encontró el paquete o identificador en IPS/CDS.` |
| `404` | `/prealertas/{codigo}`: S8 PREDES no encontrado entre los despachos con destino BO | `No se encontró una prealerta PREDES S8 con destino a Bolivia.` |
| `422` | `/prealertas/{codigo}`: código no es S8 | `El código de prealerta debe ser un despacho UPU S8.` |
| `422` | `/prealertas`: rango invertido o mayor a 31 días | `La fecha desde no puede ser posterior a la fecha hasta.` / `El rango de consulta no puede superar 31 días.` |
| `422` | `/prealertas`: cursor con formato inválido | `message`: `Los parámetros de consulta de prealertas no son válidos.`; `errors.cursor`: `El cursor debe ser el valor opaco devuelto por SITRA.` |
| `422` | `/prealertas`: cursor válido sintácticamente, pero alterado o de otro rango | `El cursor no es válido o pertenece a otro rango de fechas.` |
| `422` | `/despachos/{codigo}`: código no es S8 | `El código no corresponde a un despacho S8.` |
| `422` | `/sacas/{codigo}` o `/consulta/{codigo}`: se envió un S8 donde se requiere S9 | `El código corresponde a un despacho S8; se requiere una saca S9.` |
| `422` | La búsqueda S9 identifica más de una saca | `El código identifica más de una saca; proporciona un S9 único.` |
| `409` | La búsqueda IPS de la saca está truncada | `La consulta de la saca está incompleta. No se puede confirmar el resultado.` |
| `503` | `/consulta/{codigo}`: fuentes no disponibles | `IPS o CDS no está disponible; la consulta general quedó incompleta.`; `error.code`: `DEPENDENCY_UNAVAILABLE`; `error.retryable`: `true`. |
| `503` | `/paquetes/{codigo}` o `/declaraciones/{codigo}`: fuentes no disponibles | `IPS o CDS no está disponible; la consulta quedó incompleta.`; `error.code`: `DEPENDENCY_UNAVAILABLE`; `error.retryable`: `true`. |
| `503` | Fallo de conexión o tiempo de espera con IPS/CDS | `Una fuente de datos necesaria no está disponible temporalmente.`; `error.code`: `DEPENDENCY_UNAVAILABLE`; `error.retryable`: `true`. |
| `500` | Fallo interno no clasificado como indisponibilidad de fuente | `No se pudo completar la consulta aduanera.`; `error.code`: `INTERNAL_ERROR`; `error.retryable`: `false`. |

Si los caracteres o la longitud del código no pasan el filtro de ruta (`[A-Za-z0-9-]{1,35}`), el controlador no se ejecuta y la ruta responde `404` con `error.code: NOT_FOUND` y `message: No se encontró la ruta solicitada.` La forma y longitud válidas no garantizan que el código exista en IPS/CDS.

Ejemplo de error de negocio:

```json
{
  "message": "No se encontró el despacho S8 en IPS.",
  "error": {
    "code": "NOT_FOUND",
    "request_id": "2b61d45e-9731-4d25-a241-a1c329c3429c",
    "retryable": false
  }
}
```

Los errores de autenticación, autorización, enrutamiento y rate limit también usan el formato común. El limitador conserva el header `Retry-After` cuando está disponible.

No reintentar automáticamente `401`, `403`, `404` o `422` sin corregir la causa. Para `429` y `503`, aplicar reintentos limitados con backoff; para `409`, revisar con SITRA en vez de repetir en bucle.

## 8. Ejemplos de consumo

### 8.1 cURL

Definir estas variables en la terminal o cargarlas desde el gestor de secretos/configuración del ambiente. Los valores siguientes son marcadores, no credenciales reales:

```bash
SITRA_API_BASE_URL='https://<host-de-integracion>/api'
SITRA_API_TOKEN='<TOKEN>'
```

Primero solicitar el índice de prealertas. El ejemplo fija ambas fechas para que la misma ventana pueda reutilizarse al paginar:

```bash
curl --request GET \
  --url "${SITRA_API_BASE_URL}/v1/postal/prealertas?desde=2026-10-22&hasta=2026-10-28&limite=10" \
  --include \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${SITRA_API_TOKEN}"
```

Si existe otra página, la respuesta incluye el encabezado `Link` con `rel="next"`. Copiar esa URL completa y usarla directamente; no extraer ni reconstruir el cursor:

```bash
NEXT_URL='<URL completa indicada en Link rel="next">'
curl --request GET \
  --url "$NEXT_URL" \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${SITRA_API_TOKEN}"
```

Para cada `dispatches[].identifier`, solicitar el detalle. También se puede usar `detail_path`; concatenarlo como texto con `SITRA_API_BASE_URL` (no usar resolución URI, pues el valor empieza con `/`):

```bash
curl --request GET \
  --url "${SITRA_API_BASE_URL}/v1/postal/prealertas/DEFRAABOLPBAAUX60098" \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${SITRA_API_TOKEN}"
```

Si la respuesta incluye `Link` con `rel="next"`, hacer una solicitud GET a esa URL completa y conservar el Bearer Token. Persistir el S8 procesado con una clave única para que repetir una ventana no duplique expedientes.

Una consulta puntual de paquete, cuando se requiera, usa `GET ${SITRA_API_BASE_URL}/v1/postal/paquetes/{S10}`. Para el detalle CDS del mismo paquete se usa `GET ${SITRA_API_BASE_URL}/v1/postal/declaraciones/{S10}`. Estas consultas no son necesarias para reconstruir el expediente obtenido mediante `/prealertas/{S8}`.

### 8.2 Postman

1. Crear variables de ambiente `baseUrl` y `token`; configurar `baseUrl` incluyendo `/api`, sin barra final, y cargar el token como valor secreto.
2. Crear una solicitud `GET` a `{{baseUrl}}/v1/postal/prealertas?desde={{desde}}&hasta={{hasta}}&limite=20`.
3. En **Authorization**, seleccionar **Bearer Token** y asignar `{{token}}`.
4. En **Headers**, incluir `Accept: application/json`.
5. Dejar **Body** vacío.
6. Si la respuesta trae el encabezado `Link` con `rel="next"`, abrir esa URL para continuar la consulta; no se requiere extraer ni reconstruir el cursor.
7. Para ver el expediente de un despacho, crear otra solicitud `GET` a `{{baseUrl}}/v1/postal/prealertas/{{s8}}`.
8. Revisar el código HTTP y los campos de cobertura que cada respuesta incluya antes de consumir el resultado.

No guardar tokens reales en colecciones compartidas sin protección. Si Aduana necesita compartir la colección, usar variables secretas/privadas del ambiente y exportar una colección sin credenciales.

## 9. Pruebas y pasos para habilitar la integración

### 9.1 Evidencia disponible

Las pruebas automatizadas existentes cubren autenticación/autorización, contratos de consulta puntual S8/S9/S10 y CDS, tipos incompatibles, códigos inexistentes y la señalización del límite de 200 registros para eventos internacionales/contenido IPS. El 18/10/2026 se ejecutó:

```text
php artisan test --filter=CustomsOperationsApiTest
4 tests passed (94 assertions)

php artisan test --filter=SqlServerSearchServiceTest
1 test passed (5 assertions)

php artisan test
143 passed, 18 failed (922 assertions)
```

La suite específica de Aduana pasó e incluye dos movimientos IPS de prueba: el endpoint compacto y la consulta general devuelven solo el más reciente, mientras `movement_count` conserva el total `2`. La suite completa del proyecto no está verde: los 18 fallos aparecen en gestión de credenciales y pruebas de repositorios/reglas/vistas IPS; no aparecen en `CustomsOperationsApiTest`. No se deben presentar esos fallos globales como una validación integral del sistema.

También se verificó por HTTP el alcance mínimo `customs.prealerts.read`: el índice y el detalle PREDES respondieron `200`, mientras una consulta S10 respondió `403`. El token temporal de esta comprobación también fue revocado.

La suite aún no incluye pruebas automatizadas específicas de PREDES. El 18/10/2026 se ejercitaron las siete rutas HTTP contra una instancia temporal de este checkout, con un token de lectura de cinco minutos que fue revocado al terminar y consultas reales a IPS/CDS. Las siete respondieron `200` para los identificadores disponibles. La instancia que ya escuchaba en `127.0.0.1:8000` devolvió un `404` HTML genérico para estas rutas; antes de probar desde Postman en ese puerto, se debe confirmar que esté ejecutando esta versión de SITRA. También se verificó el `404` esperado al buscar algunos códigos PREDES todavía ausentes del registro operativo:

El 28/10/2026 se verificó el criterio actualizado sobre la ventana `2026-10-22` a `2026-10-28`: el índice devolvió 125 despachos en dos páginas, con 197 sacas sin evento IPS 130 y ninguna saca con recepción registrada en esas respuestas. El controlador respondió `200`, con `data.dispatches` como único campo del cuerpo y el encabezado `Link` cuando había otra página.

- En la ejecución histórica del 18/10, el índice respondió `200` y devolvió 20 despachos; la paginación con cursor devolvió páginas sucesivas sin repetir S8.
- En la ejecución histórica del 18/10, `ESMADCBOLPBCBCN60214` respondió `200` con 4 sacas, 8 S10 identificados, `33.3 kg` y sin eventos IPS 130. En la consulta del 28/10, IPS ya registraba las 4 recepciones; por eso este despacho quedó excluido del índice de pendientes.
- `/despachos/BOLPBCITMXPAACN60004` respondió `200` con un despacho operativo; `/sacas/BOLPBBCAYTOAAEN60031001100007` respondió `200` con 4 eventos IPS y 4 declaraciones CDS asociadas.
- `CAYTOABOLPBAAUR60012` respondió `200`: una saca declara 2 paquetes y no contiene S10 identificados; la API informa `count_matches: false` y una saca con diferencia.
- Para `ESMADBBOLPBBAEN60204`, el detalle PREDES respondió `200`, mientras que `/despachos/{S8}` y `/sacas/{S9}` respondieron `404` porque no había registro operativo local para esos códigos. IPS tenía 4 movimientos; la respuesta S10 presenta el más reciente y mantiene el conteo en `summary.movement_count`, aunque no hubiera atributos maestros de paquete ni vínculo local a saca. `/declaraciones/{S10}` respondió `200` sin grupos CDS. Esta diferencia confirma que el índice y detalle PREDES son necesarios para carga entrante.
- `/paquetes/EE000738405BO` y `/consulta/EE000738405BO` respondieron `200`: ambas fuentes informaron `ok`, `summary.movement_count` fue `8` y cada respuesta incluyó un único evento, el más reciente (`2026-10-13 20:47:10.480`, código `101`, “Cerrar envase (salida)”, oficina `BOLPBB`). `/declaraciones/EE000738405BO` respondió `200` con un paquete y una declaración CDS. La credencial temporal de lectura usada para las siete llamadas fue revocada al terminar.
- La consulta de carga mayor `NLHAGABOSRZAAUX60063` reunió 35 sacas, 1.062 S10 y 11 declaraciones CDS, sin truncamiento; el JSON generado localmente fue de aproximadamente 398 KB. Esta observación puntual no constituye un compromiso de rendimiento ni sustituye una prueba de carga del ambiente de integración.
- El rango de fechas invertido, un código S10 enviado a la ruta de prealerta y un cursor alterado respondieron `422`; una llamada sin token respondió `401`.

Estas pruebas validan el comportamiento del entorno local con los datos y fuentes disponibles al momento de la consulta. No prueban conectividad desde la red de Aduana ni sustituyen la aceptación conjunta en el ambiente de integración. En una validación anterior, `NLHAGABOSRZAAUX60063` tenía 35 sacas y 1.062 S10; CDS encontró 11 declaraciones y 31 sacas tenían evento IPS 130. Ese caso confirmó que la recepción se enlaza comparando el S9 contra la saca local, no suponiendo que el PID interno EDI sea el PID local.

### 9.2 Lista para la sesión de integración

**A cargo de SITRA**

- [ ] Proporcionar URL HTTPS del ambiente y confirmar DNS, puerto, certificados y reglas de firewall.
- [ ] Confirmar contactos técnicos, horario de soporte y procedimiento de incidentes.
- [ ] Crear una credencial de servicio con privilegio mínimo `customs.prealerts.read` y acordar vigencia/rotación.
- [ ] Confirmar límite de solicitudes aplicable al ambiente y canal de monitoreo.
- [ ] Compartir identificadores autorizados de prueba para despacho S8, saca S9, paquete S10, código local y paquete con declaración CDS.

**A cargo de Aduana**

- [ ] Configurar el host y token mediante secretos del ambiente.
- [ ] Confirmar desde el servidor de integración la resolución DNS, conectividad HTTPS y validación de certificado.
- [ ] Probar primero las dos operaciones principales PREDES y verificar paginación, detalle y fuentes; probar las rutas puntuales solo si Aduana confirma un caso de uso para ellas.
- [ ] Definir manejo de respuesta parcial, truncamiento, reintentos y tiempos de espera.
- [ ] Definir acceso, registro, retención y tratamiento de datos personales y comerciales recibidos en S10 y en las declaraciones CDS del detalle PREDES.

**Criterio de habilitación conjunta**

- [ ] SITRA y Aduana confirman resultados con códigos de prueba acordados y fuentes IPS/CDS disponibles.
- [ ] Se aprueba el esquema de autenticación, los límites operativos y el canal de soporte.
- [ ] Se registra la versión del documento y el responsable de cada extremo.

La revisión 2.0 incorpora una capacidad de token dedicada para PREDES, una cuota compartida por credencial, metadatos estables en errores y la devolución del último movimiento IPS de S10. No requiere una migración de permisos: `customs.prealerts.read` es una capacidad del token y la autorización de la cuenta se verifica contra el rol administrador o los permisos existentes. Antes de producción, validar que un token con esa capacidad acceda solo a las dos rutas principales y que una credencial sin autorización reciba `403`. La sesión conjunta también debe verificar errores `401`, `403`, `404`, `409`, `422`, `429`, `500` y `503`, el header `Retry-After`, el `X-Request-ID` y la correspondencia entre ese header y `error.request_id`. También se deben acordar tiempos de espera, volumen máximo/horario pico y objetivos de disponibilidad; el límite local de `30` solicitudes por minuto no es un SLA ni una capacidad de producción comprometida.

Los ejemplos de este documento ilustran el contrato y no son garantía de que los mismos códigos o cantidades existan en otro ambiente. Los resultados definitivos deben verificarse con identificadores permitidos en el ambiente de integración.

### 9.3 Control de cambios

Este documento describe las rutas bajo `/api/v1/postal`. Registrar cualquier cambio de contrato acordado, fecha, versión del documento y responsables SITRA/Aduana. Si una modificación afecta nombres, tipos o significado de campos existentes, acordar el despliegue con ambos equipos antes de actualizar el consumidor.

**Cambios de esta revisión 1.5:** flujo principal definido en torno al índice y detalle PREDES; rutas por entidad clasificadas como consultas puntuales y la consulta general como auxiliar; paginación aclarada para reutilizar el rango efectivo; separación explícita entre los totales del encabezado PREDES, los conteos S9/S10 y la recepción IPS; ejemplos principales actualizados al recorrido de Aduana; y mensajes documentados alineados con el backend.

**Cambios de esta revisión 1.6:** se precisó el paquete de información que recibe Aduana, el propósito operativo de cada bloque y la diferencia entre preaviso postal PREDES, declaración CDS y recepción física IPS. Se aclaró que la respuesta facilita revisión y conciliación, pero no constituye una declaración ni decisión aduanera.

**Cambios de esta revisión 1.7:** se redondearon a tres decimales los totales de peso; se hizo explícito el estado `none_found` cuando una búsqueda CDS completa no enlaza declaraciones; y se renombraron los valores de `arrival_status` para indicar que reflejan registros IPS 130, sin afirmar que la carga esté físicamente ausente. Se documentó que las rutas puntuales IPS/CDS pueden no contener todavía una entidad que sí está en PREDES, se corrigieron literales de texto mal codificados en los eventos IPS/EDI y se firmó el cursor de paginación para detectar alteraciones. Se validaron en el entorno local las respuestas HTTP, la paginación y expedientes S8/S10 reales.

**Cambios de esta revisión 1.8:** se aclaró que el consumo de Aduana requiere solo las dos rutas PREDES y que las fichas CDS pueden contener datos personales/comerciales; se corrigió la forma de concatenar `detail_path` con `baseUrl`; se añadió el identificador S9 canónico en las fichas de saca; se precisó el significado del estado CDS `available`; se actualizó la evidencia de pruebas de las siete rutas y se registraron los acuerdos pendientes de trazabilidad y capacidad para producción.

**Cambios de esta revisión 1.9:** se separó la capacidad mínima `customs.prealerts.read` del acceso completo `customs.read`; se documentó la cuota por credencial; se normalizó el formato de errores con código estable, identificador de solicitud y criterio de reintento; y se distinguieron fallos internos `500` de indisponibilidad de fuentes `503`.

**Cambios de esta revisión 2.0:** las respuestas S10 de `/paquetes/{codigo}` y `/consulta/{codigo}` presentan únicamente el evento postal IPS más reciente; `summary.movement_count` mantiene el total de eventos encontrados. Los eventos CDS de declaraciones y los registros de entrega conservan sus colecciones independientes.

**Cambios de esta revisión 2.1:** se agruparon las rutas públicas bajo `/api/v1/postal` para nombrar el dominio postal de la API, manteniendo las mismas operaciones, permisos y estructuras de respuesta.

**Cambios de esta revisión 2.2:** se aclaró en el catálogo qué devuelve cada consulta y la diferencia entre el despacho operativo IPS y el manifiesto PREDES; se precisó que las rutas de saca y paquete entregan resúmenes y referencias, mientras que el detalle PREDES reúne S8, S9, S10, CDS y la recepción física registrada.

**Cambios de esta revisión 2.3:** el índice de prealertas ahora devuelve en el cuerpo JSON únicamente `data.dispatches`. La continuación, cuando existe, se entrega mediante el encabezado HTTP `Link` con `rel="next"`, sin exponer filtros, estados de fuente ni datos de paginación en el JSON.

**Cambios de esta revisión 2.4:** el índice de prealertas excluye despachos cuyas sacas tienen todas un evento de recepción física IPS 130 registrado. Los despachos parcialmente recibidos continúan listándose e informan cuántas sacas tienen y no tienen evento registrado; la falta del evento no se presenta como prueba de ausencia física.

**Cambios de esta revisión 2.5:** se actualizaron los ejemplos del índice, del detalle y de cURL con `DEFRAABOLPBAAUX60098`, un despacho presente en el índice local con cinco S10 y una saca sin evento IPS 130. El ejemplo histórico `ESMADCBOLPBCBCN60214` se identifica como ya recibido y excluido del índice actual.

### Referencias técnicas

- [UPU, Guide to PREDES/RESDES](https://www.upu.int/UPU/media/upu/files/postalSolutions/programmesAndServices/postalSupplyChain/Transport/publications/guidePredesResdesTransportEn.pdf): describe PREDES como mensaje EDI de preaviso de despacho e incluye datos del despacho, transporte previsto, receptáculos e ítems identificados.
- [UPU, Electronic advance data in international exchange](https://www.upu.int/en/postal-solutions/technical-solutions/products/upu-ead-solutions/01-role-of-ead-in-international-exchange): explica el intercambio electrónico postal de datos de declaraciones, incluidos los mensajes ITMATT asociados a la declaración CN 23.
