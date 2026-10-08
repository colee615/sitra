# Flujo operativo IPS en Bolipost

## Estados y eventos

El flujo internacional termina en Bolivia con `EMG` (32, llegada a la oficina de entrega). Para retiro se usa `EDH` (75) y para reparto `EDG` (74). La entrega definitiva es `EMI` (37). Un intento fallido se registra como `EMH` (36). La UPU define `EMG` como llegada a la oficina de entrega y `EMI` como entrega final; `delivery-office-ID` es obligatorio cuando corresponde.

## Flujo en la aplicación

1. **Recepción IPS** consulta paquetes de Bolivia cuyo último evento indica que ya salieron de intercambio hacia el destino (`35` envío a ubicación doméstica o `38` retorno de aduana) y aún no tiene `EMG`.
2. El usuario pulsa **Recepcionar**. Bolipost envía a SITRA `event=EMG`, `occurred_at`, `office_cd`, `expected_event_cd`, `expected_event_at` e idempotencia. SITRA valida la transición y registra el evento con el usuario técnico y estación configurados.
3. **Mis paquetes IPS** consulta solo paquetes de la oficina vinculada al usuario, con eventos `EMG`, `EDG`, `EDH` o `EMH`, estado pendiente y sin `EMI`/`EMH` terminal incompatible.
4. El usuario pulsa **Baja**. Bolipost envía `event=EMI`, fecha actual, oficina IPS, firmante y lugar de entrega. SITRA vuelve a validar existencia, oficina, estado, transición e idempotencia antes de ejecutar los procedimientos de IPS.
5. **Almacén internacional** también muestra paquetes que siguen en Aduana (`31` o `34`, estado interno 1). Si el operador confirma que Aduana ya devolvió físicamente el envío y lo entrega en ese momento, SITRA registra en una sola operación el evento `38` (devolución desde Aduana) y después `EMI` (entrega final).
6. Una operación confirmada queda registrada en la bitácora PostgreSQL y en `L_MAILITM_EVENTS`; al refrescar, desaparece de pendientes.

## Datos automáticos

El usuario solo selecciona el código. `USER_PID` se obtiene de la vinculación Bolipost–IPS; `WORKSTATION_PID` y los procedimientos se configuran en SITRA. `office_cd`, `signatory`, `delivery_location` y las fechas se generan automáticamente. No se permite registrar dos veces la misma operación.

## Límites

La recepción y la baja normalmente son acciones separadas. La excepción es Aduana: con confirmación física explícita, SITRA encadena el evento de devolución 38 y la entrega 37 dentro de la misma transacción. Bolipost no inventa eventos internacionales ni modifica directamente tablas IPS. Los códigos deben mantenerse alineados con el catálogo real de la instalación (`C_EVENT_TYPES`).

## Contratos HTTP

Consulta de recepción:

```http
GET /api/v1/ips/paquetes?status=reception&office_cd=12&page=1&per_page=25
Authorization: Bearer <token con ips.read>
```

Registro de recepción:

```json
{
  "event": "EMG",
  "occurred_at": "2026-09-14T15:30:00-04:00",
  "office_cd": 12,
  "expected_event_cd": 30,
  "expected_event_at": "2026-09-13T20:10:00.000Z",
  "idempotency_key": "bolipost-emg-RR512494491ES-20260914153000"
}
```

Registro de baja/entrega:

```json
{
  "event": "EMI",
  "occurred_at": "2026-09-14T16:05:00-04:00",
  "office_cd": 12,
  "signatory": "NOMBRE DEL DESTINATARIO",
  "delivery_location": "LA PAZ LC/AO",
  "idempotency_key": "bolipost-emi-RR512494491ES-20260914160500"
}
```

El cliente Bolipost no solicita `USER_PID`, `WORKSTATION_PID`, nombres de procedimientos ni estados internos. SITRA los resuelve con su configuración y aplica el contrato de IPS.

Para un envío todavía en Aduana, `POST /paquetes/{codigo}/entrega` admite `customs_return_confirmed: true` únicamente como confirmación explícita del operador. SITRA valida las transiciones `estado 1 → evento 38 → estado 0 → evento 37`; ambos eventos se escriben dentro de la transacción IPS y se revierten juntos si falla la verificación.

## Matriz de decisión

| Último evento | Estado | Pantalla | Acción |
|---|---:|---|---|
| EMD 30 | pendiente | Ninguna; continúa expedición/intercambio | Esperar despacho hacia destino |
| Evento 35 o 38 | pendiente | Recepción IPS | Registrar EMG |
| Evento 31 o 34, estado 1 | Aduana | Almacén internacional | Confirmar devolución física y entrega: registrar 38 y luego EMI |
| EMG | 0 u 8 | Mis paquetes IPS | Baja EMI |
| EDH | 0 u 8 | Mis paquetes IPS | Baja EMI |
| EDG | 0 u 8 | Mis paquetes IPS | Baja EMI |
| EMH | 0 u 8 | Mis paquetes IPS | Baja EMI si IPS permite la transición |
| EMI | 5 | ninguna pendiente | No repetir |
| Evento 76/terminal | cualquiera | ninguna pendiente | No repetir |

## Validaciones y errores

- Sin vínculo IPS o sin `office_cd`: la pantalla no permite operar.
- Código inexistente, oficina diferente, evento incompatible o paquete ya entregado: SITRA responde error y no escribe.
- Repetición de la misma clave de idempotencia: SITRA devuelve el resultado original, nunca crea un segundo evento.
- Error después de enviar a IPS: la operación queda `uncertain` en PostgreSQL y debe consultarse antes de reintentar.
- Los datos del destinatario se leen de `L_MAILITM_CUSTOMERS`; si no existen, se muestran vacíos sin impedir la entrega.

## Auditoría

Cada acción conserva en PostgreSQL el usuario Bolipost, código, evento, clave de idempotencia, operación SITRA, estado y respuesta. IPS conserva el evento con `USER_PID`, estación, oficina, fecha y datos de entrega. Esto permite comparar ambas bases sin alterar manualmente el historial.

## Tablas IPS involucradas

- `L_MAILITMS`: identificador, estado actual, último evento, oficina y peso.
- `L_MAILITM_EVENTS`: historial inmutable de eventos, fechas, oficina, usuario y receptáculo.
- `L_MAILITM_CUSTOMERS`: remitente/destinatario, teléfono, ciudad y correo.
- `L_MAILITM_DELIV_INFOS`: firmante, ubicación y datos de intentos o entrega.
- `N_OWN_OFFICES`: código y nombre de oficinas.
- `C_EVENT_TYPES` y `CT_EVENT_TYPES`: catálogo de eventos y traducciones.
- `C_STATEINDS_EVTTYPES`: transiciones permitidas entre estado y evento.

Las escrituras se realizan mediante `SP_SET_MAILITM` y `SP_SET_MAILITM_CUSTOMERS`, comprobando previamente que la firma de los procedimientos coincida con el catálogo esperado.

## Pruebas mínimas antes de producción

1. Consultar catálogo de eventos y oficinas de la base IPS destino.
2. Probar recepción en una copia o paquete de prueba y confirmar `EMG` en `L_MAILITM_EVENTS`.
3. Confirmar que el paquete aparece en Mis paquetes IPS después de recibirlo.
4. Probar baja y confirmar `EMI`, estado 5 y datos de entrega.
5. Repetir la misma solicitud y comprobar que no duplica el evento.
6. Probar usuario sin vínculo, oficina incorrecta, paquete inexistente y paquete ya entregado.
7. Revisar la bitácora de operación y el historial visible de IPS.
