# Dashboard operativo y diseño de SITRA

El inicio `/dashboard` ofrece resumen ejecutivo y detalle operativo. `/centro-de-trabajo` conserva los accesos a expedientes, IPS, CDS, despachos, sacas, remisiones y operación en oficina. El diseño compartido se carga al final de `resources/views/vendor/adminlte/page.blade.php`; autenticación usa `sitra-auth.css`. Los formatos postales de impresión conservan sus dimensiones y contenido.

## Fuentes y permisos

- PostgreSQL existente: identidad, roles, permisos y configuración de la aplicación.
- IPS: conexión `postal.ips_read_connection` existente (en el entorno verificado, `ips_catalog` / IPS5Db). Lectura de `L_MAILITMS`, `L_MAILITM_EVENTS`, `N_OWN_OFFICES`, `C_MAIL_CLASSES`, `C_COUNTRIES`, `C_EVENT_TYPES` y `CT_EVENT_TYPES`.
- CDS: conexión `postal.cds_connection`, controlada por `postal.cds_enabled`. Lectura de `O_MAIL_OBJECTS`, `O_DECLARATIONS`, `O_RESPONSES` y `M_CDS_STATES`.

`GET /dashboard/datos` requiere sesión y permiso `postal.access`. Cada fuente se consulta solamente con su permiso (`postal.ips` / `postal.cds`). Un fallo no se convierte en ceros y no impide presentar otra fuente disponible. Las respuestas no contienen mensajes internos SQL ni credenciales. No se agregaron migraciones ni datos de ejemplo a estas fuentes.

## Significado de los indicadores

| Indicador | Cálculo |
| --- | --- |
| Envíos con actividad | Distintos `MAILITM_PID` con eventos que cumplen fechas, oficina, servicio, país, tipo y estado. |
| Recibidos / despachados / entregados | Envíos distintos con al menos un evento de la categoría. Pueden superponerse y no se suman. |
| Entradas / salidas | Cantidad de eventos de recepción / salida. Un envío puede generar varios. |
| Situación | Último evento operativo del periodo y oficina seleccionados; los eventos técnicos tienen prioridad inferior. No es inventario histórico pendiente ni ubicación física garantizada. |
| Devolución en curso | Estado postal actual del envío 6/7, entre los envíos con actividad del periodo. No equivale a devolución final. Los códigos 22/23 del catálogo pertenecen a sacas, por eso no se usan para paquetes. |
| Peso | Suma / promedio de pesos no nulos, una vez por envío, con cobertura explícita. |
| Oficinas / operadores | Identidades distintas en los movimientos filtrados. |
| Servicios / países / tipo | Envíos únicos del periodo; las oficinas y eventos se ordenan por movimientos. Los datos ausentes se mantienen como desconocidos. |
| Registro histórico | Conteo completo `L_MAILITMS`, expresamente independiente de los filtros. |
| CDS | Objetos según `POSTING_DATE`, sus declaraciones y respuestas asociadas. Consultas separadas evitan multiplicar declaraciones al unir respuestas. |

Recepciones: eventos 1, 3, 5, 30, 32, 33, 42, 43, 44, 68, 71, 75 y 78. Salidas: 2, 12, 35, 72 y 74. Entregas: 37 y 1250. El método completo está disponible en la propia pantalla.

La fecha IPS se filtra como intervalo semiabierto `[inicio 00:00 Bolivia, día siguiente al final 00:00 Bolivia)`, convertido a UTC. Las series se agrupan en UTC−4. CDS no informa zona en `POSTING_DATE`: se utiliza su fecha de calendario, identificada por separado.

La comparación utiliza el periodo inmediatamente anterior de igual duración. No se calcula un porcentaje cuando la base anterior es cero. Las series semanales y mensuales suman movimientos; los distintos diarios no se suman como distintos del periodo.

## Filtros y límites

Fechas (hasta 366 días), hoy, semana, mes, año, oficina, clase/servicio, estado operativo, origen, destino y nacional/internacional/desconocido. Los rankings se pueden seleccionar para filtrar. Los filtros se conservan en la URL.

No existe una regional estructurada en las oficinas inspeccionadas (`CITY` vacío); no se inventa esa relación. CDS tampoco ofrece una equivalencia verificada con oficinas, estados postales, países o tipos IPS: con esos filtros se presenta un aviso en CDS, no cifras sin filtrar. Fecha y clase son compatibles.

Los agregados incluyen toda la población filtrada. Solo la lista de expedientes recientes se limita a 12. La caché de agregados dura 90 segundos, catálogos y registro histórico 30 minutos; se identifica por conexiones, zona y filtros. El CSV exporta los resultados consultados, conserva los filtros y neutraliza fórmulas de hoja de cálculo.

## Verificación

Pruebas: `php vendor/phpunit/phpunit/phpunit`. Compilación: `npm run build`. Las pruebas automatizadas usan SQLite aislado; la validación de fuentes reales utiliza consultas de lectura.

Validación de septiembre de 2026 en las conexiones existentes: 9.747 envíos distintos, 24.429 eventos y 3.585 envíos con evento de entrega IPS; 1.035 declaraciones CDS. Las cifras son una comprobación puntual del 2 de octubre de 2026, no valores fijos en el código.

Se revisaron filtros (incluida oficina 0), estados, periodos anteriores, límites UTC, permisos por fuente, fallos parciales, ausencia de datos, duplicación de eventos y declaraciones, exportaciones y formularios administrativos. Navegador: dashboard a 390, 768 y 1280 px; expedientes, logística, Aduana, reportes, usuarios, roles, permisos, accesos, reglas y perfil en móvil y escritorio.
