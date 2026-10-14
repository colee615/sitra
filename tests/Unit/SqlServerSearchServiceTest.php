<?php

namespace Tests\Unit;

use App\Services\SqlServerSearchService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqlServerSearchServiceTest extends TestCase
{
    public function test_search_marks_edi_and_declared_content_limits_as_truncated(): void
    {
        config(['tracking.sqlserver.connection' => 'sqlsrv']);

        $connection = \Mockery::mock();
        DB::shouldReceive('connection')->with('sqlsrv')->zeroOrMoreTimes()->andReturn($connection);
        $connection->shouldReceive('select')->zeroOrMoreTimes()->andReturnUsing(function (string $query) {
            if (str_contains($query, 'SELECT TOP 201') && str_contains($query, 'N_EDI_MAILITMS')) {
                return array_fill(0, 201, (object) [
                    'MAILITM_FID' => 'EE000738405BO',
                    'EVENT_TYPE_CD' => 30,
                    'EVENT_TYPE_NM_ES' => 'Evento',
                    'EVENT_LOCAL_DT' => null,
                    'CAPTURE_GMT_DT' => null,
                    'LOCATION_ID' => null,
                    'SENDER_ID' => null,
                    'DESPATCH_NUMBER' => null,
                ]);
            }

            if (str_contains($query, 'SELECT TOP 201') && str_contains($query, 'L_CONTENT_PIECES')) {
                return array_fill(0, 201, (object) [
                    'IDENTIFIER' => '1',
                    'NUMBER_OF_UNITS' => 1,
                    'DESCRIPTION' => 'Contenido',
                    'DECLARED_VALUE' => null,
                    'DECLARED_VALUE_CURRENCY_CD' => null,
                    'NET_WEIGHT' => null,
                    'ORIGIN_LOCATION' => null,
                    'TARIFF_HEADING' => null,
                ]);
            }

            return [];
        });

        $result = app(SqlServerSearchService::class)->search('EE000738405BO');

        $this->assertTrue($result['truncated']);
        $this->assertCount(200, $result['ediRows']);
        $this->assertCount(200, $result['contentPieceRows']);
    }
}
