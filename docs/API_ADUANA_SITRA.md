# SITRA | Integración de consulta para Aduana

## Especificación técnica de la API

Documento de transferencia para el Área de Sistemas de Aduana. Describe el contrato HTTP de consulta expuesto por SITRA, los campos de respuesta y las condiciones para iniciar una integración.

| Control del documento | Valor |
|---|---|
| Sistema proveedor | SITRA — Gestión postal y aduanera |
| Sistema consumidor | Aduana — nombre del sistema consumidor pendiente de confirmar |
| Responsable técnico proveedor | Equipo de Sistemas SITRA — contacto operativo pendiente de completar |
| Versión del documento | 1.3 |
| Fecha | 14/10/2026 |
| Estado | Contrato revisado en código y pruebas automatizadas; conectividad del ambiente compartido pendiente de validar |
| Interfaz | API REST, JSON, solo lectura |
| Clasificación | Documento técnico de integración |

> Antes de circular externamente, completar los nombres de contacto, el host del ambiente de integración, el procedimiento de emisión de credenciales y la ventana de soporte acordada.

## Resumen ejecutivo

SITRA ofrece cinco operaciones `GET` autenticadas. Aduana puede consultar una ficha completa por identificador, o pedir un resumen de despacho S8, una saca S9, un paquete S10 o sus declaraciones CDS. Las respuestas se agrupan por entidad postal y usan nombres de campos estables en inglés; los valores proceden de IPS y CDS.

La API no registra actuaciones ni modifica información. Una ficha completa S10 puede incluir datos personales de remitente y destinatario, por lo que su consumo debe limitarse a usuarios y procesos autorizados.

### Decisiones que debe conocer el consumidor

- Usar `/consulta/{codigo}` para recuperar la ficha completa adecuada al tipo detectado.
- Usar las rutas específicas cuando la integración solo necesita el resumen de una entidad.
- En una saca, `weight_kg` es el peso informado por IPS. Para S9 también se devuelve el peso codificado en el identificador UPU como dato separado; no son intercambiables.
- Revisar `sources` y los indicadores `truncated` antes de tratar una respuesta como completa.
- Para inspeccionar las declaraciones CDS de un paquete, consultar `/declaraciones/{codigo}` con el código postal o local del paquete.

## Índice

1. [Propósito y alcance](#1-propósito-y-alcance)
2. [Ambientes, autenticación y seguridad](#2-ambientes-autenticación-y-seguridad)
3. [Catálogo de operaciones](#3-catálogo-de-operaciones)
4. [Contrato de la consulta general](#4-contrato-de-la-consulta-general)
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

Si cambia el host, el puerto o el prefijo común, actualizar solo esta variable en el ambiente de consumo; los paths `/v1/aduana/...` permanecen iguales. El host compartido es un dato pendiente de coordinación y no se debe inferir a partir del ejemplo local.

### 2.2 Autenticación y permisos

Enviar en cada solicitud:

```http
Authorization: Bearer <TOKEN>
Accept: application/json
```

| Elemento | Requisito | Notas |
|---|---|---|
| Método | `GET` | Las cinco operaciones son de consulta |
| URL | `{{baseUrl}}/v1/aduana/...` | `{{baseUrl}}` ya incluye `/api` y no termina en `/` |
| `Authorization` | `Bearer <TOKEN>` | Token personal SITRA vigente |
| `Accept` | `application/json` | Solicita respuestas JSON, incluidos errores de autenticación |
| `Body` | Vacío | No enviar JSON ni formulario |

En una respuesta satisfactoria se espera `Content-Type: application/json` y el envoltorio `{ "data": ... }`. Las consultas procesadas por el controlador incluyen `Cache-Control: no-store`.

El token debe ser una credencial personal de SITRA emitida desde **Credenciales API** y tener capacidad `customs.read`. El middleware también acepta `customs.process` para esta consulta; para una integración de Aduana de solo lectura se recomienda emitir el permiso de menor alcance, `customs.read`.

La cuenta propietaria debe ser administradora o disponer del permiso de usuario `customs.read` o `customs.process`. La capacidad del token y el permiso de la cuenta son controles separados: ambos deben permitir la consulta. Las credenciales pueden rotarse o revocarse desde SITRA.

### 2.3 Límites y protección de datos

- Límite configurado: 30 solicitudes por minuto en el grupo de rutas de Aduana. Si se recibe `429`, aplicar espera progresiva y reducir la concurrencia.
- Las respuestas generadas por el controlador incluyen `Cache-Control: no-store`. El cliente también debe evitar almacenar respuestas sensibles en caché.
- Mantener el token en un gestor de secretos o almacén seguro del ambiente. No incorporarlo a repositorios, capturas, tickets ni registros de aplicación.
- La consulta S10 completa puede devolver nombre, domicilio, teléfono y correo de contactos postales. Aplicar mínimo privilegio, limitar retención y acceso, y evitar registrar esos campos en logs.
- Usar HTTPS en todo ambiente compartido y validar el certificado del servidor. No desactivar la comprobación TLS para resolver problemas de conectividad.

## 3. Catálogo de operaciones

Todas las operaciones requieren Bearer Token, usan `Accept: application/json`, son `GET` y no reciben contenido en `Body`. El permiso de token esperado es `customs.read`.

| Operación | URL completa | Identificador de entrada | Fuentes principales | Información devuelta |
|---|---|---|---|---|
| Ficha general | `GET {{baseUrl}}/v1/aduana/consulta/{codigo}` | S8, S9, S10 o código local | IPS y/o CDS, según tipo y disponibilidad | Tipo detectado, análisis del identificador y detalle postal/aduanero correspondiente |
| Resumen de despacho | `GET {{baseUrl}}/v1/aduana/despachos/{codigo}` | Código S8 | IPS | Datos básicos del despacho, cantidad declarada/enlazada de sacas y truncamiento |
| Resumen de saca | `GET {{baseUrl}}/v1/aduana/sacas/{codigo}` | Código S9 que resuelva a una sola saca | IPS y CDS | Peso IPS, conteos, despacho y referencias a paquetes asociados |
| Resumen de paquete | `GET {{baseUrl}}/v1/aduana/paquetes/{codigo}` | Código S10 o código local | IPS y CDS | Datos resumidos del paquete, conteos, hasta 10 movimientos y referencias relacionadas |
| Declaraciones CDS | `GET {{baseUrl}}/v1/aduana/declaraciones/{codigo}` | Código postal o local de un paquete | CDS, con resolución postal en la búsqueda | Paquete(s) CDS asociados, declaraciones, respuestas y eventos CDS |

`{codigo}` admite de 1 a 35 caracteres: letras, números y guion. La búsqueda normaliza a mayúsculas. Enviar el identificador como un único segmento de la ruta; no enviar listas separadas por coma en estas cinco operaciones.

La ruta de declaraciones busca el paquete en CDS usando el identificador postal/local. El número de una declaración CN se devuelve dentro de la respuesta cuando existe; no sustituye el código de paquete de la ruta.

## 4. Contrato de la consulta general

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
| `data.identifier` | string | Código normalizado utilizado para la consulta; en código local conserva el código consultado |
| `data.identifier_analysis` | object o `null` | Desglose UPU para S8/S9; para S10 o local informa clasificación, validez y mensaje. S10 incluye además `issuer` |
| `data.details` | object | Campos específicos de la entidad detectada |

### 4.2 Análisis del identificador UPU

La API devuelve el análisis como un objeto de campos; no reemplaza el identificador registrado en IPS/CDS.

| Campo | Tipo JSON | Cuándo aparece | Significado |
|---|---|---|---|
| `type` | string | Todos | `s8`, `s9`, `S10` o `Código local`; el casing depende del analizador de código |
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
| `events[]` | array de objetos | Historial de eventos IPS normalizado |
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
GET {{baseUrl}}/v1/aduana/despachos/BOLPBCITMXPAACN60004
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
GET {{baseUrl}}/v1/aduana/sacas/BOLPBBCAYTOAAEN60031001100007
```

Respuesta representativa:

```json
{
  "data": {
    "identifier": "BOLPBBCAYTOAAEN60031001100007",
    "sack": {
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
| `data.identifier` | string | Código S9 consultado |
| `data.sack.number` | string o `null` | Número de saca codificado en S9 |
| `data.sack.weight_kg` | number o `null` | Peso registrado en IPS |
| `data.sack.packages_declared` | integer o `null` | Cantidad anotada en IPS |
| `data.sack.packages_linked` | integer | Paquetes distintos enlazados en IPS |
| `data.sack.counts_match` | boolean o `null` | Resultado de la comparación; `null` si no puede calcularse |
| `data.related_identifiers.dispatch` | string o `null` | Código del S8 relacionado |
| `data.related_identifiers.packages[]` | array de strings | Códigos postales de paquetes enlazados |
| `data.summary.events_count` | integer | Eventos IPS localizados para la saca |
| `data.summary.declarations_count` | integer | Declaraciones CDS contadas para paquetes asociados |
| `data.summary.declarations_status` | string | `available`, `unavailable` o `disabled` |
| `data.truncated` | boolean | Indicador de truncamiento IPS; si IPS truncó la consulta, esta ruta devuelve `409` |

### 5.3 Resumen de paquete S10

```http
GET {{baseUrl}}/v1/aduana/paquetes/EE000738405BO
```

`data` incluye `identifier`, `sources`, `summary`, `package`, `movements[]`, `related_identifiers` y `truncated`.

| Campo | Tipo JSON | Descripción |
|---|---|---|
| `identifier` | string | Código de paquete recibido en la ruta, normalizado |
| `sources` | object | Estado de IPS y CDS (`ok`, `empty`, `unavailable` o `disabled`) |
| `summary.movement_count` | integer | Total de movimientos encontrados antes del límite de salida |
| `summary.delivery_count` | integer | Registros de entrega encontrados |
| `summary.sack_count` | integer | Sacas relacionadas |
| `summary.declaration_count` | integer | Declaraciones CDS asociadas |
| `summary.content_item_count` | integer | Líneas de contenido aduanero |
| `package` | object | Resumen postal con `local_code`, `weight_kg`, `value`, `duties_amount`, `customs_number`, `class`, `content`, `product_type`, `postal_status`, `origin`, `destination` y `currency` |
| `movements[]` | array de objetos | Hasta 10 movimientos, cada uno con `occurred_at`, `code`, `name` y `office` |
| `related_identifiers` | object | Listas `sacks[]`, `dispatches[]` y `declarations[]` |
| `truncated` | boolean | `true` si hay más de 10 movimientos o alguna fuente informó truncamiento |

En este resumen `package` no repite el campo `code`: el código consultado se entrega en `data.identifier`. Para el modelo completo del paquete, eventos sin el límite de 10, contactos, entregas y datos aduaneros, usar `/consulta/{codigo}`. `truncated` será `true` si hay más de 10 movimientos o alguna fuente informa truncamiento.

Ejemplo ilustrativo de la forma de la respuesta:

```json
{
  "data": {
    "identifier": "EE000738405BO",
    "sources": {"IPS": "ok", "CDS": "ok"},
    "summary": {
      "movement_count": 1,
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
GET {{baseUrl}}/v1/aduana/declaraciones/EE000738405BO
```

La respuesta raíz es `data` con:

| Campo | Tipo | Descripción |
|---|---|---|
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

## 6. Convenciones y calidad de datos

- Toda respuesta satisfactoria de consulta usa `{ "data": ... }`. Los errores usan `{ "message": "..." }`.
- Los campos opcionales ausentes se representan como `null`; las colecciones sin elementos como `[]`.
- Los campos cuyo nombre termina en `_kg` son pesos expresados como números en kilogramos. La API no convierte a gramos.
- Las fechas conservan el valor/formato recibido de IPS/CDS. Revisar `local_time_offset` cuando exista; no inferir una zona horaria ausente.
- `sources` indica la situación de las fuentes. Para estas operaciones, los estados relevantes son `ok`, `empty`, `unavailable` y `disabled`. Una fuente `empty` respondió, pero no encontró registros enlazados; `unavailable` indica que no se pudo consultar; `disabled` indica que la integración CDS está deshabilitada en ese ambiente.
- `truncated: true` significa que una o más listas pudieron ser limitadas. No usar la respuesta para afirmar totales completos; atender también `packages_truncated` y `declarations_truncated`.
- La ruta compacta de paquetes limita los movimientos retornados a 10. El `movement_count` informa el total observado antes de ese límite.
- Comparar por separado los datos declarados, enlazados y pesados. Una diferencia entre IPS y UPU/CDS es un hallazgo de conciliación, no una corrección automática de SITRA.
- La consulta de datos postales y la de CDS pueden tener disponibilidad independiente. Una respuesta de la ruta general puede contener datos de una fuente y señalar que otra está vacía o no disponible.

### 6.1 Límites actuales de consulta

Los límites evitan respuestas sin cota. Cuando la API puede identificar el recorte, informa `truncated`; en la consulta S9 un resultado IPS truncado se rechaza con `409` para no presentar una ficha incompleta como íntegra.

| Operación/dataset | Límite actual | Señal al consumidor |
|---|---:|---|
| Despacho S8 | 20 despachos y 100 sacas por resultado | `details.truncated` o `data.truncated` |
| Búsqueda S9 en IPS | 20 candidatos; 500 eventos; 1.000 paquetes relacionados; 100 manifiestos | La ficha S9 devuelve `409` si IPS detecta truncamiento |
| Resumen de paquete S10 | 10 movimientos en `data.movements[]` | `summary.movement_count` informa el total; `data.truncated` indica que hay más de 10 o que una fuente reportó truncamiento |
| Búsqueda CDS de paquete | `postal.record_limit`, actualmente 100 paquetes/declaraciones/respuestas; hasta 500 eventos CDS | `truncated`, `packages_truncated` y `declarations_truncated` según la respuesta |
| CDS enlazado a una saca S9 | Hasta 1.000 paquetes/declaraciones/respuestas en el índice de la saca | `cds.complete` y `cds.truncated`; una saca IPS incompleta responde `409` |
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

Los errores JSON generados por estas rutas tienen el campo `message`. El contrato no incluye un código de error de negocio separado, por lo que el consumidor debe tomar decisiones con el **HTTP status** y usar el texto solo como ayuda operativa.

| HTTP | Cuándo ocurre | `message` actual o comportamiento | Acción del cliente |
|---|---|---|---|
| `200` | Consulta atendida | No aplica. Respuesta `{ "data": ... }` | Leer `sources` y todos los indicadores de truncamiento antes de aceptar la respuesta como completa. |
| `401` | No se presentó una autenticación válida | `No autenticado. Envía un token Bearer válido.` | Verificar el token, el encabezado y su vigencia; no repetir hasta corregirlos. |
| `403` | La cuenta no tiene el permiso, no se usó un token personal Bearer o el token carece de capacidad | Si solo falla la capacidad del token: `El token no tiene permiso para esta operación aduanera.` En otros rechazos el texto proviene del middleware/framework y no está fijado por el contrato. | Pedir al administrador que revise permiso de usuario y capacidad del token. Emitir con `customs.read`. |
| `404` | Código no encontrado o URL que no coincide con el formato de ruta; para S9 significa cero coincidencias. Más de una coincidencia S9 produce `422`. | El texto depende del endpoint; ver la tabla de mensajes exactos debajo. Un paquete existente sin declaración CDS no es por sí solo un `404`. | Confirmar identificador, ruta y ambiente; distinguir inexistencia de CDS vacío. |
| `405` | Se llamó una ruta existente con método distinto de `GET` | Respuesta estándar del framework; texto no contractual | Cambiar a `GET`; no enviar cuerpo. |
| `409` | IPS detectó truncamiento al resolver el S9 | `La consulta del marbete está incompleta. No se habilitó el trámite.` | Tratar el resultado como no disponible/incompleto y coordinar consulta con SITRA. No reintentar en bucle. |
| `422` | El código no corresponde al tipo de la ruta o la saca es ambigua | Ver mensajes exactos debajo | Corregir el código o elegir la ruta correspondiente. |
| `429` | Se excedió `throttle:30,1` | Respuesta del limitador Laravel; el mensaje puede aparecer como `Too Many Attempts.`. Revisar `Retry-After` si está presente. | Esperar el tiempo indicado, usar backoff y reducir concurrencia. |
| `503` | Fuente no disponible o error inesperado durante la consulta | Mensajes según ruta en la tabla debajo; un error no previsto se devuelve como `No se pudo completar la consulta aduanera.` | Reintentar con espera progresiva; guardar status y ruta, sin registrar token ni datos personales. |

### 7.1 Mensajes de dominio que devuelve SITRA

Los textos reflejan el mensaje actual del código y pueden contener diferencias de acentuación entre endpoints. No usarlos como claves de lógica.

| HTTP | Ruta/condición | Texto actual de `message` |
|---|---|---|
| `404` | `/despachos/{codigo}`: S8 sin registro | `No se encontro el despacho S8 en IPS.` |
| `404` | `/consulta/{codigo}`: S8 sin registro | `No se encontró el despacho S8 en IPS.` |
| `404` | `/sacas/{codigo}` o consulta general S9: no existe la saca | `No se encontró la saca S9 en IPS.` |
| `404` | `/paquetes/{codigo}` o `/declaraciones/{codigo}`: código sin registro | `No se encontro el paquete o identificador en IPS/CDS.` |
| `404` | `/consulta/{codigo}`: S10/local no encontrado | `No se encontró el paquete o identificador en IPS/CDS.` |
| `422` | `/despachos/{codigo}`: código no es S8 | `El código no corresponde a un despacho S8.` |
| `422` | `/sacas/{codigo}` o `/consulta/{codigo}`: se envió un S8 donde se requiere S9 | `El código corresponde a un despacho S8; se requiere una saca S9.` |
| `422` | La búsqueda S9 identifica más de una saca | `El código identifica más de una saca; proporciona un S9 único.` |
| `409` | La búsqueda IPS de la saca está truncada | `La consulta del marbete está incompleta. No se habilitó el trámite.` |
| `503` | `/consulta/{codigo}`: fuentes no disponibles | `IPS o CDS no está disponible; la consulta general quedó incompleta.` |
| `503` | `/paquetes/{codigo}` o `/declaraciones/{codigo}`: fuentes no disponibles | `IPS o CDS no esta disponible; la consulta quedo incompleta.` |
| `503` | Fallo no previsto capturado por el controlador | `No se pudo completar la consulta aduanera.` |

Si los caracteres o la longitud del código no pasan el filtro de ruta (`[A-Za-z0-9-]{1,35}`), Laravel no invoca el controlador y devuelve `404`; el contenido de ese error de enrutamiento puede variar. La forma y longitud válidas no garantizan que el código exista en IPS/CDS.

Ejemplo de error de negocio:

```json
{
  "message": "No se encontró el despacho S8 en IPS."
}
```

En el caso de una respuesta generada por el controlador, los headers incluyen `Cache-Control: no-store`. Los errores de autenticación, enrutamiento y rate limit los genera el middleware/framework y pueden tener headers adicionales o distintos.

No reintentar automáticamente `401`, `403`, `404` o `422` sin corregir la causa. Para `429` y `503`, aplicar reintentos limitados con backoff; para `409`, revisar con SITRA en vez de repetir en bucle.

## 8. Ejemplos de consumo

### 8.1 cURL

Definir estas variables en la terminal o cargarlas desde el gestor de secretos/configuración del ambiente. Los valores siguientes son marcadores, no credenciales reales:

```bash
SITRA_API_BASE_URL='https://<host-de-integracion>/api'
SITRA_API_TOKEN='<TOKEN>'
```

```bash
curl --request GET \
  --url "${SITRA_API_BASE_URL}/v1/aduana/consulta/BOLPBBCAYTOAAEN60031001100007" \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${SITRA_API_TOKEN}"
```

Para consultar el resumen de un S8:

```bash
curl --request GET \
  --url "${SITRA_API_BASE_URL}/v1/aduana/despachos/BOLPBCITMXPAACN60004" \
  --header 'Accept: application/json' \
  --header "Authorization: Bearer ${SITRA_API_TOKEN}"
```

### 8.2 Postman

1. Crear variables de ambiente `baseUrl` y `token`; configurar `baseUrl` incluyendo `/api`, sin barra final, y cargar el token como valor secreto.
2. Crear una solicitud `GET` a `{{baseUrl}}/v1/aduana/consulta/{{codigo}}` o a una ruta específica.
3. En **Authorization**, seleccionar **Bearer Token** y asignar `{{token}}`.
4. En **Headers**, incluir `Accept: application/json`.
5. Dejar **Body** vacío.
6. Revisar el código HTTP, `sources` y los campos de truncamiento antes de consumir el resultado.

No guardar tokens reales en colecciones compartidas sin protección. Si Aduana necesita compartir la colección, usar variables secretas/privadas del ambiente y exportar una colección sin credenciales.

## 9. Pruebas y pasos para habilitar la integración

### 9.1 Evidencia disponible

Las pruebas automatizadas cubren autenticación/autorización, contratos de consulta S8/S9/S10 y CDS, tipos incompatibles, códigos inexistentes y la señalización del límite de 200 registros para eventos internacionales/contenido IPS. Se ejecutaron:

```text
php artisan test --filter=CustomsOperationsApiTest
4 tests passed (85 assertions)

php artisan test --filter=SqlServerSearchServiceTest
1 test passed (5 assertions)
```

Estas pruebas validan la aplicación y su forma de respuesta; no prueban conectividad desde la red de Aduana ni sustituyen la validación contra IPS/CDS del ambiente compartido.

### 9.2 Lista para la sesión de integración

**A cargo de SITRA**

- [ ] Proporcionar URL HTTPS del ambiente y confirmar DNS, puerto, certificados y reglas de firewall.
- [ ] Confirmar contactos técnicos, horario de soporte y procedimiento de incidentes.
- [ ] Crear una credencial de servicio con privilegio mínimo `customs.read` y acordar vigencia/rotación.
- [ ] Confirmar límite de solicitudes aplicable al ambiente y canal de monitoreo.
- [ ] Compartir identificadores autorizados de prueba para despacho S8, saca S9, paquete S10, código local y paquete con declaración CDS.

**A cargo de Aduana**

- [ ] Configurar el host y token mediante secretos del ambiente.
- [ ] Confirmar desde el servidor de integración la resolución DNS, conectividad HTTPS y validación de certificado.
- [ ] Ejecutar una llamada de cada operación y verificar estructuras y códigos HTTP.
- [ ] Definir manejo de respuesta parcial, truncamiento, reintentos y tiempos de espera.
- [ ] Definir acceso, registro, retención y tratamiento de datos personales recibidos en S10.

**Criterio de habilitación conjunta**

- [ ] SITRA y Aduana confirman resultados con códigos de prueba acordados y fuentes IPS/CDS disponibles.
- [ ] Se aprueba el esquema de autenticación, los límites operativos y el canal de soporte.
- [ ] Se registra la versión del documento y el responsable de cada extremo.

Los ejemplos de este documento ilustran el contrato y no son garantía de que los mismos códigos o cantidades existan en otro ambiente. Los resultados definitivos deben verificarse con identificadores permitidos en el ambiente de integración.

### 9.3 Control de cambios

Este documento describe las rutas bajo `/api/v1/aduana`. Registrar cualquier cambio de contrato acordado, fecha, versión del documento y responsables SITRA/Aduana. Si una modificación afecta nombres, tipos o significado de campos existentes, acordar el despliegue con ambos equipos antes de actualizar el consumidor.

**Cambios de esta revisión 1.3:** base URL centralizada como `{{baseUrl}}`; tablas de campos y tipos ampliadas; mensajes de error contrastados con el backend; límites de respuesta documentados; y señalización de truncamiento para las listas IPS de eventos internacionales y contenido declarado.
