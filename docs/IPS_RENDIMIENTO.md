# Rendimiento del listado IPS

Mediciones del 15/09/2026 contra la copia `(localdb)\MSSQLLocalDB`, base `IPS5Db`: 353.933 paquetes y 1.488.168 eventos. No se ejecutaron altas, recepciones ni entregas durante esta optimización.

## Causas comprobadas

- Cada página calculaba nuevamente el total mediante el último evento de todo el historial. El conteo consumía habitualmente 1,5–1,9 segundos; una primera consulta de recepción llegó a 10 segundos.
- El índice primario del historial estaba ordenado por paquete, tipo y fecha. La consulta necesita paquete, fecha descendente y tipo descendente, además de oficina actual y siguiente.
- La paginación solicitaba la fila completa del paquete antes de limitar los resultados.
- Bolipost volvía a generar toda la plantilla del panel en cada búsqueda. Una respuesta completa midió aproximadamente 813 KB, con unos 415 KB de CSS/JavaScript de facturación incluidos en línea. En una ejecución nueva, renderizar el panel tardó aproximadamente 3 segundos adicionales.
- No es correcto sustituir el historial por la cabecera: se encontraron 231 cabeceras con evento técnico y 282 cabeceras no técnicas con diferencias de evento, fecha u oficina frente al historial ordenado. Estas cifras no prueban por sí solas errores del sistema IPS.

## Cambios implementados

1. Índice local `IX_SITRA_MAILITM_EVENTS_LATEST` sobre `(MAILITM_PID, EVENT_GMT_DT DESC, EVENT_TYPE_CD DESC)`, incluyendo `EVENT_OFFICE_CD` y `NEXT_OFFICE_CD`. El script `database/sql/ips_local_listing_index.sql` exige LocalDB e IPS5Db; no se ejecuta automáticamente como migración ni permite modificar producción. Incluye la instrucción de reversión comentada. La elección de columnas sigue la [guía de índices de Microsoft](https://learn.microsoft.com/en-us/sql/relational-databases/sql-server-index-design-guide?view=sql-server-ver17). Todo índice adicional ocupa espacio y añade mantenimiento a las escrituras; su despliegue productivo requiere medir esa carga en la instalación correspondiente.
2. Primero se seleccionan los identificadores de la página; después se cargan exclusivamente sus filas completas y datos relacionados. Se mantiene el orden por fecha e identificador, así como el cálculo del estado operativo a partir del historial.
3. Las búsquedas exactas por código usan consultas al historial limitadas al paquete. No almacenan sus resultados ni sus totales en caché.
4. Solo los totales generales se guardan durante 30 segundos mediante la [caché de Laravel](https://laravel.com/docs/12.x/cache). La clave separa la conexión/base, oficina, estado y filtros; página y tamaño reutilizan el mismo total. `IPS_TOTALS_CACHE_SECONDS=0` deshabilita esta mejora. `IPS_TOTALS_CACHE_STORE` permite elegir el almacén; por defecto se usa el configurado por Laravel.
5. Una operación confirmada por `IpsOperationService` invalida los totales mediante una nueva generación. Una consulta iniciada antes de esa invalidación no puede volver a publicar el total anterior en la generación nueva. Los cambios externos efectuados desde IPS pueden tardar hasta el TTL en reflejarse en el número total de páginas. Los estados y acciones de las filas siempre se vuelven a consultar. Si la caché falla, el conteo se obtiene directamente de IPS.
6. Bolipost solicita únicamente el HTML del listado al buscar, cambiar de pestaña o paginar. Mantiene la bandeja, los valores introducidos en ella y el panel. Cancela solicitudes previas y descarta respuestas antiguas; si falla la consulta conserva el listado anterior y muestra un mensaje. Los enlaces y formularios GET siguen funcionando con navegación normal si no hay JavaScript. Las solicitudes de movimientos no son interceptadas por este código.

## Resultados observados

| Medición | Antes | Después |
| --- | --- | --- |
| Repositorio, sin reutilizar total | 1,6–2,4 s habituales, pico de 10,1 s | 0,85–1,02 s |
| Repositorio, total reutilizado | Se recalculaba en cada página | 0,064–0,125 s |
| Búsqueda del código RW660505166CH | Sin medición comparable previa | 0,027–0,148 s según filtro |
| Navegación parcial desde Bolipost, con total reutilizado | Regeneraba el panel completo | 0,63–0,66 s, aproximadamente 38 KB |

Las cuatro primeras páginas de estados devolvieron exactamente los mismos datos antes y después (comparación SHA-256 del payload completo). Totales de oficina 1: Todos 158.333, Por recibir 1.908, Para retiro/reparto 1.870, Entregados 123.385. Se probaron además la segunda página y la página 1.000; los filtros sin tantos resultados devolvieron un listado vacío.

Estas mediciones corresponden a consultas y renderizado de servidor en la máquina local, no a tiempos de pintura en un navegador ni a garantías de rendimiento en producción. No se vació la caché de buffers SQL ni se reinició la base para simular un arranque en frío. La primera apertura completa del panel todavía puede tardar unos 4–5 segundos: carga los componentes globales, incluido facturación. La navegación parcial evita repetir ese trabajo; este cambio no modifica el funcionamiento de facturación.

## Verificación repetible

```powershell
php artisan ips:benchmark --office=1 --repeat=2
php artisan ips:benchmark --office=1 --repeat=1 --fresh-count
php artisan ips:benchmark --office=1 --q=RW660505166CH --repeat=1
php artisan ips:benchmark --office=1 --page=2 --repeat=1
php artisan test --compact --filter=Ips
```

El diagnóstico informa tiempo de conteo, tiempo SQL, tiempo total, cantidad de filas y hash del contenido; no imprime nombres, teléfonos ni direcciones. `--fresh-count` omite solo la caché de totales de la aplicación, no la caché interna de SQL Server.

En Bolipost:

```powershell
php artisan test --compact --filter=UserIpsLinkTest
node --test tests/js/ips-listing.test.cjs
```

Pruebas añadidas: separación de caché entre oficinas y bases, expiración, invalidación concurrente, búsqueda sin caché, lectura ante caída de caché, cambios de estado visibles con total guardado, invalidación tras entrega simulada, fragmentos sin panel ni facturación, navegación con respuestas fuera de orden y conservación del listado ante fallos. Las pruebas de movimientos usan dobles de prueba; no constituyen una prueba de entrega real en producción.
