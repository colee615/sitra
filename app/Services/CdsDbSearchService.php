<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CdsDbSearchService
{
    public function search(string $codigo): array
    {
        $codigo = strtoupper(trim($codigo));
        $connection = 'sqlsrv2';

        $objectRows = collect();
        $stateRows = collect();
        $declarationRows = collect();
        $declarationEventRows = collect();
        $responseRows = collect();
        $responseEventRows = collect();
        $ediExportRows = collect();
        $auditRows = collect();
        $anDeclarationRows = collect();

        if ($codigo !== '') {
            $params = [$codigo, $codigo, $codigo];

            $objectRows = collect(DB::connection($connection)->select(
                "
                SELECT TOP 200
                    o.MAIL_OBJECT_PID,
                    o.MAIL_OBJECT_ID,
                    o.MAIL_OBJECT_LOCAL_ID,
                    o.MAIL_OBJECT_LOCAL_ID2,
                    o.MAIL_OBJECT_TYPE_CD,
                    o.MAIL_CLASS_CD,
                    o.MAIL_CATEGORY_CD,
                    o.MAIL_FLOW_CD,
                    o.ORIG_POST_ORGANIZATION_CD,
                    o.DEST_POST_ORGANIZATION_CD,
                    o.MAIL_STATE_CD,
                    o.MAIL_STATE_REMARKS,
                    o.POSTING_DATE
                FROM dbo.O_MAIL_OBJECTS o
                WHERE UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_ID))) = ?
                   OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID))) = ?
                   OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID2))) = ?
                ORDER BY o.POSTING_DATE DESC
                ",
                $params
            ));

            $stateRows = collect(DB::connection($connection)->select(
                "
                SELECT TOP 200
                    s.MAIL_STATE_CD,
                    s.MAIL_STATE_NM,
                    s.VALID,
                    s.ORGANIZATION_CD,
                    s.MAIL_FLOW_CD,
                    s.MAIL_OBJECT_TYPE_CD,
                    s.EDI_ALIAS
                FROM dbo.M_MAIL_STATES s
                WHERE s.MAIL_STATE_CD IN (
                    SELECT DISTINCT o.MAIL_STATE_CD
                    FROM dbo.O_MAIL_OBJECTS o
                    WHERE UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_ID))) = ?
                       OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID))) = ?
                       OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID2))) = ?
                )
                ORDER BY s.MAIL_STATE_CD
                ",
                $params
            ));

            $declarationRows = collect(DB::connection($connection)->select(
                "
                SELECT TOP 200
                    d.DECLARATION_PID,
                    d.MAIL_OBJECT_PID,
                    d.POST_ORGANIZATION_CD,
                    d.CUST_ORGANIZATION_CD,
                    d.CDS_STATE_CD,
                    d.AN_DECLARATION_ID,
                    d.DATA AS DECLARATION_DATA
                FROM dbo.O_DECLARATIONS d
                WHERE d.MAIL_OBJECT_PID IN (
                    SELECT o.MAIL_OBJECT_PID
                    FROM dbo.O_MAIL_OBJECTS o
                    WHERE UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_ID))) = ?
                       OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID))) = ?
                       OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID2))) = ?
                )
                ",
                $params
            ));

            $declarationEventRows = collect(DB::connection($connection)->select(
                "
                SELECT TOP 300
                    de.DECLARATION_PID,
                    de.CDS_EVENT_TYPE_CD,
                    de.D_EVENT_GMT_DT,
                    de.EVENT_LOCAL_OFFSET,
                    de.USER_CD,
                    de.OFFICE_CD
                FROM dbo.O_DECLARATION_EVENTS de
                WHERE de.DECLARATION_PID IN (
                    SELECT d.DECLARATION_PID
                    FROM dbo.O_DECLARATIONS d
                    WHERE d.MAIL_OBJECT_PID IN (
                        SELECT o.MAIL_OBJECT_PID
                        FROM dbo.O_MAIL_OBJECTS o
                        WHERE UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_ID))) = ?
                           OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID))) = ?
                           OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID2))) = ?
                    )
                )
                ORDER BY de.D_EVENT_GMT_DT DESC
                ",
                $params
            ));

            $responseRows = collect(DB::connection($connection)->select(
                "
                SELECT TOP 200
                    r.RESPONSE_PID,
                    r.MAIL_OBJECT_PID,
                    r.POST_ORGANIZATION_CD,
                    r.CUST_ORGANIZATION_CD,
                    r.CDS_STATE_CD
                FROM dbo.O_RESPONSES r
                WHERE r.MAIL_OBJECT_PID IN (
                    SELECT o.MAIL_OBJECT_PID
                    FROM dbo.O_MAIL_OBJECTS o
                    WHERE UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_ID))) = ?
                       OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID))) = ?
                       OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID2))) = ?
                )
                ",
                $params
            ));

            $responseEventRows = collect(DB::connection($connection)->select(
                "
                SELECT TOP 300
                    re.RESPONSE_PID,
                    re.CDS_EVENT_TYPE_CD,
                    re.R_EVENT_GMT_DT,
                    re.EVENT_LOCAL_OFFSET,
                    re.USER_CD,
                    re.OFFICE_CD
                FROM dbo.O_RESPONSE_EVENTS re
                WHERE re.RESPONSE_PID IN (
                    SELECT r.RESPONSE_PID
                    FROM dbo.O_RESPONSES r
                    WHERE r.MAIL_OBJECT_PID IN (
                        SELECT o.MAIL_OBJECT_PID
                        FROM dbo.O_MAIL_OBJECTS o
                        WHERE UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_ID))) = ?
                           OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID))) = ?
                           OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID2))) = ?
                    )
                )
                ORDER BY re.R_EVENT_GMT_DT DESC
                ",
                $params
            ));

            $ediExportRows = collect(DB::connection($connection)->select(
                "
                SELECT TOP 200
                    e.MAIL_OBJECT_PID,
                    e.EDI_MESSAGE_CD,
                    e.EDI_MESSAGE_TYPE,
                    e.SENDER_ORGANIZATION,
                    e.RECIPIENT_ORGANIZATION,
                    e.REASON_ID,
                    e.EVENT_DATE,
                    e.EDI_EXCHANGE_ID,
                    e.EDI_SENDER_ADDRESS,
                    e.EDI_RECIPIENT_ADDRESS
                FROM dbo.A_EDI_EXPORT_EVENTS e
                WHERE e.MAIL_OBJECT_PID IN (
                    SELECT o.MAIL_OBJECT_PID
                    FROM dbo.O_MAIL_OBJECTS o
                    WHERE UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_ID))) = ?
                       OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID))) = ?
                       OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID2))) = ?
                )
                ORDER BY e.EVENT_DATE DESC
                ",
                $params
            ));

            $auditRows = collect(DB::connection($connection)->select(
                "
                SELECT TOP 200
                    ae.LOG_ENTRY_ID,
                    ae.MESSAGE,
                    ae.LOG_ENTRY_DT,
                    ae.LOG_ENTRY_TYPE_CD,
                    ae.ORGANIZATION_CD,
                    ae.LOG_SOURCE_CD,
                    ae.PARAMETERS
                FROM dbo.A_LOG_ENTRIES ae
                WHERE ae.ORGANIZATION_CD IN (
                    SELECT DISTINCT o.ORIG_POST_ORGANIZATION_CD
                    FROM dbo.O_MAIL_OBJECTS o
                    WHERE UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_ID))) = ?
                       OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID))) = ?
                       OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID2))) = ?
                )
                ORDER BY ae.LOG_ENTRY_DT DESC
                ",
                $params
            ));

            $anDeclarationRows = collect(DB::connection($connection)->select(
                "
                SELECT TOP 200
                    an.AN_DECLARATION_PID,
                    an.AN_DECLARATION_ID,
                    an.POSTING_DATE,
                    an.SOURCE_CD,
                    an.SOURCE_CLASS,
                    an.CONVERTED_DT,
                    an.DATA AS AN_DECLARATION_DATA
                FROM dbo.O_AN_DECLARATIONS an
                WHERE an.AN_DECLARATION_ID IN (
                    SELECT d.AN_DECLARATION_ID
                    FROM dbo.O_DECLARATIONS d
                    WHERE d.MAIL_OBJECT_PID IN (
                        SELECT o.MAIL_OBJECT_PID
                        FROM dbo.O_MAIL_OBJECTS o
                        WHERE UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_ID))) = ?
                           OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID))) = ?
                           OR UPPER(RTRIM(LTRIM(o.MAIL_OBJECT_LOCAL_ID2))) = ?
                    )
                )
                ORDER BY an.POSTING_DATE DESC
                ",
                $params
            ));
        }

        return [
            'codigo' => $codigo,
            'objectRows' => $objectRows,
            'stateRows' => $stateRows,
            'declarationRows' => $declarationRows,
            'declarationEventRows' => $declarationEventRows,
            'responseRows' => $responseRows,
            'responseEventRows' => $responseEventRows,
            'ediExportRows' => $ediExportRows,
            'auditRows' => $auditRows,
            'anDeclarationRows' => $anDeclarationRows,
        ];
    }
}
