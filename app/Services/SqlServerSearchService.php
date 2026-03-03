<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SqlServerSearchService
{
    public function search(string $codigo): array
    {
        $codigo = strtoupper(trim($codigo));

        $trackingRows = collect();
        $packageRows = collect();
        $customerRows = collect();
        $deliveryRows = collect();
        $logisticRows = collect();
        $manifestRows = collect();
        $ediRows = collect();
        $tableMap = $this->tableMap();

        if ($codigo !== '') {
            $params = [$codigo, $codigo];

            $packageRows = collect(DB::connection('sqlsrv')->select(
                "
                SELECT
                    mi.MAILITM_PID,
                    mi.MAILITM_FID AS MAILITM_FID,
                    RTRIM(LTRIM(mi.MAILITM_LOCAL_ID)) AS MAILITM_LOCAL_ID,
                    mi.MAILITM_WEIGHT,
                    mi.MAILITM_VALUE,
                    mi.DUTIES_AMOUNT,
                    mi.CUSTOMS_NO,
                    mi.MAIL_CLASS_CD,
                    mc.MAIL_CLASS_NM,
                    mi.MAILITM_CONTENT_CD,
                    mcon.MAILITM_CONTENT_NM,
                    mi.PRODUCT_TYPE_CD,
                    pt.PRODUCT_TYPE_NM,
                    mi.POSTAL_STATUS_CD,
                    ps.POSTAL_STATUS_NM,
                    mi.ORIG_COUNTRY_CD,
                    coo.COUNTRY_NM AS ORIG_COUNTRY_NM,
                    mi.DEST_COUNTRY_CD,
                    cod.COUNTRY_NM AS DEST_COUNTRY_NM,
                    mi.CURRENCY_CD,
                    cur.CURRENCY_NM,
                    mi.EVT_GMT_DT,
                    mi.EVT_TYPE_CD,
                    COALESCE(cte.LOCAL_EVENT_TYPE_NM, ce.EVENT_TYPE_NM) AS EVT_TYPE_NM_ES,
                    mi.EVT_OFFICE_CD,
                    nof.OFFICE_FCD AS EVT_OFFICE_FCD,
                    nof.OFFICE_NM AS EVT_OFFICE_NM
                FROM dbo.L_MAILITMS mi
                LEFT JOIN dbo.C_MAIL_CLASSES mc ON mc.MAIL_CLASS_CD = mi.MAIL_CLASS_CD
                LEFT JOIN dbo.C_MAILITM_CONTENTS mcon ON mcon.MAILITM_CONTENT_CD = mi.MAILITM_CONTENT_CD
                LEFT JOIN dbo.C_PRODUCT_TYPES pt ON pt.PRODUCT_TYPE_CD = mi.PRODUCT_TYPE_CD
                LEFT JOIN dbo.C_POSTAL_STATUSES ps ON ps.POSTAL_STATUS_CD = mi.POSTAL_STATUS_CD
                LEFT JOIN dbo.C_COUNTRIES coo ON coo.COUNTRY_CD = mi.ORIG_COUNTRY_CD
                LEFT JOIN dbo.C_COUNTRIES cod ON cod.COUNTRY_CD = mi.DEST_COUNTRY_CD
                LEFT JOIN dbo.C_CURRENCIES cur ON cur.CURRENCY_CD = mi.CURRENCY_CD
                LEFT JOIN dbo.C_EVENT_TYPES ce ON ce.EVENT_TYPE_CD = mi.EVT_TYPE_CD
                LEFT JOIN dbo.CT_EVENT_TYPES cte ON cte.EVENT_TYPE_CD = mi.EVT_TYPE_CD AND cte.LANGUAGE_CD = 'ES'
                LEFT JOIN dbo.N_OWN_OFFICES nof ON nof.OWN_OFFICE_CD = mi.EVT_OFFICE_CD
                WHERE UPPER(RTRIM(LTRIM(mi.MAILITM_FID))) = ?
                   OR UPPER(RTRIM(LTRIM(mi.MAILITM_LOCAL_ID))) = ?
                ORDER BY mi.EVT_GMT_DT DESC
                ",
                $params
            ));

            $trackingRowsLocal = collect(DB::connection('sqlsrv')->select(
                "
                SELECT
                    e.MAILITM_PID,
                    mi.MAILITM_FID AS MAILITM_FID,
                    RTRIM(LTRIM(mi.MAILITM_LOCAL_ID)) AS MAILITM_LOCAL_ID,
                    e.EVENT_GMT_DT,
                    e.EVENT_TYPE_CD,
                    CASE
                        WHEN e.EVENT_TYPE_CD = 32 THEN 'Paquete recibido en oficina de entrega(Listo para entregar).'
                        WHEN e.EVENT_TYPE_CD = 13 THEN 'Paquete incluido en la saca nacional.'
                        WHEN e.EVENT_TYPE_CD = 35 THEN 'Paquete en camino a ubicación nacional.'
                        WHEN e.EVENT_TYPE_CD = 30 THEN 'Paquete recibido en oficina de tránsito.'
                        ELSE COALESCE(ct.LOCAL_EVENT_TYPE_NM, c.EVENT_TYPE_NM)
                    END AS EVENT_TYPE_NM_ES,
                    e.USER_PID,
                    u.USER_FID,
                    u.USER_NM,
                    u.USER_DOMAIN,
                    e.EVENT_OFFICE_CD,
                    nof.OFFICE_FCD,
                    nof.OFFICE_NM,
                    nof_next.OFFICE_FCD AS NEXT_OFFICE_FCD,
                    nof_next.OFFICE_NM AS NEXT_OFFICE_NM,
                    CONCAT(COALESCE(u.USER_DOMAIN,''), '-', RTRIM(COALESCE(u.USER_FID,''))) AS SCANNED_TXT,
                    CONCAT(RTRIM(COALESCE(w.WORKSTATION_FID,'')), '-', RTRIM(COALESCE(w.WORKSTATION_DOMAIN,''))) AS WORKSTATION_TXT,
                    CASE
                        WHEN e.CONDITION_CD = 30 THEN 'Envío recibido en buen estado'
                        ELSE COALESCE(ic.ITEM_CONDITION_NM, '')
                    END AS CONDITION_TXT,
                    CAST('' AS varchar(200)) AS DETAIL_TXT,
                    'IPS5Db' AS SOURCE_DB
                FROM dbo.L_MAILITMS mi
                INNER JOIN dbo.L_MAILITM_EVENTS e ON e.MAILITM_PID = mi.MAILITM_PID
                LEFT JOIN dbo.C_EVENT_TYPES c ON c.EVENT_TYPE_CD = e.EVENT_TYPE_CD
                LEFT JOIN dbo.CT_EVENT_TYPES ct
                    ON ct.EVENT_TYPE_CD = e.EVENT_TYPE_CD
                    AND ct.LANGUAGE_CD = 'ES'
                LEFT JOIN dbo.N_OWN_OFFICES nof ON nof.OWN_OFFICE_CD = e.EVENT_OFFICE_CD
                LEFT JOIN dbo.N_OWN_OFFICES nof_next ON nof_next.OWN_OFFICE_CD = e.NEXT_OFFICE_CD
                LEFT JOIN dbo.L_USERS u ON u.USER_PID = e.USER_PID
                LEFT JOIN dbo.L_WORKSTATIONS w ON w.WORKSTATION_PID = e.WORKSTATION_PID
                LEFT JOIN dbo.C_ITEM_CONDITIONS ic ON ic.ITEM_CONDITION_CD = e.CONDITION_CD
                WHERE UPPER(RTRIM(LTRIM(mi.MAILITM_FID))) = ?
                   OR UPPER(RTRIM(LTRIM(mi.MAILITM_LOCAL_ID))) = ?
                ORDER BY e.EVENT_GMT_DT DESC
                ",
                $params
            ));

            $trackingRowsEdi = collect(DB::connection('sqlsrv')->select(
                "
                SELECT
                    nee.MAILITM_PID,
                    CAST('' AS varchar(40)) AS MAILITM_FID,
                    CAST('' AS varchar(40)) AS MAILITM_LOCAL_ID,
                    COALESCE(nee.CAPTURE_GMT_DT, CAST(nee.EVENT_LOCAL_DT AS datetime)) AS EVENT_GMT_DT,
                    nee.EVENT_TYPE_CD,
                    CASE
                        WHEN nee.EVENT_TYPE_CD = 12 THEN 'Paquete enviado al extranjero.'
                        WHEN nee.EVENT_TYPE_CD = 8 THEN 'Paquete incluido en la saca de envío.'
                        WHEN nee.EVENT_TYPE_CD = 3 THEN 'Paquete recibido en oficina de tránsito.'
                        WHEN nee.EVENT_TYPE_CD = 1 THEN 'Paquete recibido del cliente.'
                        ELSE COALESCE(ct.LOCAL_EVENT_TYPE_NM, c.EVENT_TYPE_NM)
                    END AS EVENT_TYPE_NM_ES,
                    CAST(NULL AS int) AS USER_PID,
                    CAST(NULL AS varchar(100)) AS USER_FID,
                    CAST(NULL AS varchar(200)) AS USER_NM,
                    CAST(NULL AS varchar(100)) AS USER_DOMAIN,
                    CAST(NULL AS int) AS EVENT_OFFICE_CD,
                    CAST(NULL AS varchar(30)) AS OFFICE_FCD,
                    CAST(NULL AS varchar(150)) AS OFFICE_NM,
                    nee.NEXT_POINT_ID AS NEXT_OFFICE_FCD,
                    CAST(NULL AS varchar(150)) AS NEXT_OFFICE_NM,
                    CAST('' AS varchar(200)) AS SCANNED_TXT,
                    CAST('' AS varchar(200)) AS WORKSTATION_TXT,
                    CASE
                        WHEN nee.CONDITION_CD = 30 THEN 'Envío recibido en buen estado'
                        ELSE COALESCE(ic.ITEM_CONDITION_NM, '')
                    END AS CONDITION_TXT,
                    CONCAT('PaÃ­s Origen: ', COALESCE(co.COUNTRY_NM, nee.PLACE_OF_ORIGIN_OFFICE_CD, '')) AS DETAIL_TXT,
                    'IPS5Db-EDI' AS SOURCE_DB
                FROM dbo.N_EDI_MAILITM_EVENTS nee
                INNER JOIN dbo.N_EDI_MAILITMS ne ON ne.MAILITM_PID = nee.MAILITM_PID
                LEFT JOIN dbo.C_EVENT_TYPES c ON c.EVENT_TYPE_CD = nee.EVENT_TYPE_CD
                LEFT JOIN dbo.CT_EVENT_TYPES ct ON ct.EVENT_TYPE_CD = nee.EVENT_TYPE_CD AND ct.LANGUAGE_CD = 'ES'
                LEFT JOIN dbo.C_ITEM_CONDITIONS ic ON ic.ITEM_CONDITION_CD = nee.CONDITION_CD
                LEFT JOIN dbo.C_COUNTRIES co ON co.COUNTRY_CD = LEFT(ne.ORIG_COUNTRY_CD, 2)
                WHERE UPPER(RTRIM(LTRIM(ne.MAILITM_FID))) = ?
                ORDER BY COALESCE(nee.CAPTURE_GMT_DT, CAST(nee.EVENT_LOCAL_DT AS datetime)) DESC
                ",
                [$codigo]
            ));

            $trackingRows = $trackingRowsLocal
                ->concat($trackingRowsEdi)
                ->sortByDesc('EVENT_GMT_DT')
                ->values();

            $customerRows = collect(DB::connection('sqlsrv')->select(
                "
                SELECT
                    mc.MAILITM_PID,
                    mc.SENDER_PAYEE_IND,
                    mc.CUSTOMER_NAME,
                    mc.CUSTOMER_FORENAME,
                    mc.CUSTOMER_ADDRESS,
                    mc.CUSTOMER_CITY,
                    mc.CUSTOMER_POST_CODE,
                    mc.COUNTRY_CD,
                    c.COUNTRY_NM,
                    mc.CUSTOMER_PHONE_NO,
                    mc.CUSTOMER_EMAIL_ADDRESS
                FROM dbo.L_MAILITMS mi
                INNER JOIN dbo.L_MAILITM_CUSTOMERS mc ON mc.MAILITM_PID = mi.MAILITM_PID
                LEFT JOIN dbo.C_COUNTRIES c ON c.COUNTRY_CD = mc.COUNTRY_CD
                WHERE UPPER(RTRIM(LTRIM(mi.MAILITM_FID))) = ?
                   OR UPPER(RTRIM(LTRIM(mi.MAILITM_LOCAL_ID))) = ?
                ORDER BY mc.SENDER_PAYEE_IND, mc.CUSTOMER_NAME
                ",
                $params
            ));

            $deliveryRows = collect(DB::connection('sqlsrv')->select(
                "
                SELECT
                    di.MAILITM_PID,
                    di.EVENT_GMT_DT,
                    di.EVENT_TYPE_CD,
                    COALESCE(ct.LOCAL_EVENT_TYPE_NM, c.EVENT_TYPE_NM) AS EVENT_TYPE_NM_ES,
                    di.NON_DELIVERY_REASON_CD,
                    di.NON_DELIVERY_MEASURE_CD,
                    di.SIGNATORY_NM,
                    di.DELIV_LOCATION,
                    di.DELIV_POSTCODE
                FROM dbo.L_MAILITMS mi
                INNER JOIN dbo.L_MAILITM_DELIV_INFOS di ON di.MAILITM_PID = mi.MAILITM_PID
                LEFT JOIN dbo.C_EVENT_TYPES c ON c.EVENT_TYPE_CD = di.EVENT_TYPE_CD
                LEFT JOIN dbo.CT_EVENT_TYPES ct ON ct.EVENT_TYPE_CD = di.EVENT_TYPE_CD AND ct.LANGUAGE_CD = 'ES'
                WHERE UPPER(RTRIM(LTRIM(mi.MAILITM_FID))) = ?
                   OR UPPER(RTRIM(LTRIM(mi.MAILITM_LOCAL_ID))) = ?
                ORDER BY di.EVENT_GMT_DT DESC
                ",
                $params
            ));

            $logisticRows = collect(DB::connection('sqlsrv')->select(
                "
                SELECT DISTINCT
                    r.RECPTCL_PID,
                    RTRIM(LTRIM(r.RECPTCL_FID)) AS RECPTCL_FID,
                    r.RECPTCL_WEIGHT,
                    r.RECPTCL_MAILITMS_NO,
                    d.DESPTCH_PID,
                    RTRIM(LTRIM(d.DESPTCH_FID)) AS DESPTCH_FID,
                    d.ORIG_OFFICE_FCD,
                    d.DEST_OFFICE_FCD,
                    d.DESPTCH_DEPARTURE_DT
                FROM dbo.L_MAILITMS mi
                INNER JOIN dbo.L_MAILITM_EVENTS e ON e.MAILITM_PID = mi.MAILITM_PID
                LEFT JOIN dbo.L_RECPTCLS r ON r.RECPTCL_PID = e.RECPTCL_PID
                LEFT JOIN dbo.L_DESPTCHS d ON d.DESPTCH_PID = r.DESPTCH_PID
                WHERE (UPPER(RTRIM(LTRIM(mi.MAILITM_FID))) = ?
                   OR UPPER(RTRIM(LTRIM(mi.MAILITM_LOCAL_ID))) = ?)
                  AND e.RECPTCL_PID IS NOT NULL
                ORDER BY d.DESPTCH_DEPARTURE_DT DESC
                ",
                $params
            ));

            $manifestRows = collect(DB::connection('sqlsrv')->select(
                "
                SELECT DISTINCT
                    ml.MANIFEST_LIST_ID,
                    ml.CREATION_LCL_DT,
                    ml.OWN_OFFICE_CD,
                    nof.OFFICE_FCD,
                    nof.OFFICE_NM,
                    ml.MANIF_TYPE_ID
                FROM dbo.L_MAILITMS mi
                INNER JOIN dbo.L_MANIFESTS_MAILITMS mm ON mm.MAILITM_PID = mi.MAILITM_PID
                INNER JOIN dbo.L_MANIFEST_LISTS ml ON ml.MANIFEST_LIST_ID = mm.MANIFEST_LIST_ID
                LEFT JOIN dbo.N_OWN_OFFICES nof ON nof.OWN_OFFICE_CD = ml.OWN_OFFICE_CD
                WHERE UPPER(RTRIM(LTRIM(mi.MAILITM_FID))) = ?
                   OR UPPER(RTRIM(LTRIM(mi.MAILITM_LOCAL_ID))) = ?
                ORDER BY ml.CREATION_LCL_DT DESC
                ",
                $params
            ));

            $ediRows = collect(DB::connection('sqlsrv')->select(
                "
                SELECT TOP 200
                    RTRIM(LTRIM(mi.MAILITM_FID)) AS MAILITM_FID,
                    ne.EVENT_TYPE_CD,
                    COALESCE(ct.LOCAL_EVENT_TYPE_NM, c.EVENT_TYPE_NM) AS EVENT_TYPE_NM_ES,
                    nee.EVENT_LOCAL_DT,
                    nee.CAPTURE_GMT_DT,
                    nee.LOCATION_ID,
                    nee.SENDER_ID,
                    nee.DESPATCH_NUMBER
                FROM dbo.L_MAILITMS mi
                INNER JOIN dbo.N_EDI_MAILITMS ne ON ne.MAILITM_PID = mi.MAILITM_PID
                LEFT JOIN dbo.N_EDI_MAILITM_EVENTS nee
                    ON nee.MAILITM_PID = ne.MAILITM_PID
                    AND nee.EVENT_TYPE_CD = ne.EVENT_TYPE_CD
                LEFT JOIN dbo.C_EVENT_TYPES c ON c.EVENT_TYPE_CD = ne.EVENT_TYPE_CD
                LEFT JOIN dbo.CT_EVENT_TYPES ct ON ct.EVENT_TYPE_CD = ne.EVENT_TYPE_CD AND ct.LANGUAGE_CD = 'ES'
                WHERE UPPER(RTRIM(LTRIM(mi.MAILITM_FID))) = ?
                   OR UPPER(RTRIM(LTRIM(mi.MAILITM_LOCAL_ID))) = ?
                ORDER BY COALESCE(nee.CAPTURE_GMT_DT, CAST(nee.EVENT_LOCAL_DT AS datetime)) DESC
                ",
                $params
            ));
        }

        return [
            'codigo' => $codigo,
            'packageRows' => $packageRows,
            'trackingRows' => $trackingRows,
            'customerRows' => $customerRows,
            'deliveryRows' => $deliveryRows,
            'logisticRows' => $logisticRows,
            'manifestRows' => $manifestRows,
            'ediRows' => $ediRows,
            'tableMap' => $tableMap,
        ];
    }

    private function tableMap(): array
    {
        return [
            ['table' => 'L_MAILITMS', 'purpose' => 'Cabecera del paquete.', 'attrs' => 'MAILITM_PID, MAILITM_FID(S10), MAILITM_LOCAL_ID, MAILITM_WEIGHT, MAILITM_VALUE, ORIG_COUNTRY_CD, DEST_COUNTRY_CD, POSTAL_STATUS_CD, EVT_*'],
            ['table' => 'L_MAILITM_EVENTS', 'purpose' => 'Historial cronologico de eventos.', 'attrs' => 'MAILITM_PID, EVENT_TYPE_CD, EVENT_GMT_DT, EVENT_OFFICE_CD, USER_PID, RECPTCL_PID'],
            ['table' => 'CT_EVENT_TYPES', 'purpose' => 'Texto del evento en espanol.', 'attrs' => 'EVENT_TYPE_CD, LANGUAGE_CD, LOCAL_EVENT_TYPE_NM'],
            ['table' => 'C_EVENT_TYPES', 'purpose' => 'Catalogo base de tipo de evento.', 'attrs' => 'EVENT_TYPE_CD, EVENT_TYPE_NM'],
            ['table' => 'L_MAILITM_CUSTOMERS', 'purpose' => 'Remitente/destinatario y contactos.', 'attrs' => 'SENDER_PAYEE_IND, CUSTOMER_NAME, CUSTOMER_ADDRESS, CUSTOMER_CITY, CUSTOMER_PHONE_NO, CUSTOMER_EMAIL_ADDRESS'],
            ['table' => 'L_MAILITM_DELIV_INFOS', 'purpose' => 'Intentos y resultado de entrega.', 'attrs' => 'EVENT_GMT_DT, NON_DELIVERY_REASON_CD, NON_DELIVERY_MEASURE_CD, SIGNATORY_NM, DELIV_LOCATION'],
            ['table' => 'L_RECPTCLS', 'purpose' => 'Sacas/receptaculos asociados.', 'attrs' => 'RECPTCL_PID, RECPTCL_FID, DESPTCH_PID, RECPTCL_WEIGHT, RECPTCL_MAILITMS_NO'],
            ['table' => 'L_DESPTCHS', 'purpose' => 'Despacho logistico del receptaculo.', 'attrs' => 'DESPTCH_PID, DESPTCH_FID, ORIG_OFFICE_FCD, DEST_OFFICE_FCD, DESPTCH_DEPARTURE_DT'],
            ['table' => 'L_MANIFESTS_MAILITMS', 'purpose' => 'Vinculo paquete-manifiesto.', 'attrs' => 'MANIFEST_LIST_ID, MAILITM_PID'],
            ['table' => 'L_MANIFEST_LISTS', 'purpose' => 'Cabecera del manifiesto.', 'attrs' => 'MANIFEST_LIST_ID, CREATION_LCL_DT, OWN_OFFICE_CD, MANIF_TYPE_ID'],
            ['table' => 'N_EDI_MAILITMS', 'purpose' => 'Representacion EDI del item.', 'attrs' => 'MAILITM_PID, MAILITM_FID, EVENT_TYPE_CD, DEST_COUNTRY_CD, RECPTCL_PID'],
            ['table' => 'N_EDI_MAILITM_EVENTS', 'purpose' => 'Eventos EDI transmitidos/registrados.', 'attrs' => 'MAILITM_PID, EVENT_TYPE_CD, EVENT_LOCAL_DT, CAPTURE_GMT_DT, LOCATION_ID, DESPATCH_NUMBER'],
            ['table' => 'N_OWN_OFFICES', 'purpose' => 'Catalogo de oficinas.', 'attrs' => 'OWN_OFFICE_CD, OFFICE_FCD, OFFICE_NM'],
            ['table' => 'L_USERS', 'purpose' => 'Usuario operador del evento.', 'attrs' => 'USER_PID, USER_FID, USER_NM'],
        ];
    }
}
