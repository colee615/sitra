<?php

namespace Tests\Unit;

use App\Models\TrackingEventRule;
use App\Services\TrackingEventRuleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TrackingEventRuleServiceTest extends TestCase
{
    public function test_customs_retention_and_custody_events_have_explicit_public_labels(): void
    {
        $service = app(TrackingEventRuleService::class);

        $this->assertSame(
            'Aduana registró un motivo de retención de tu paquete',
            $service->customerEventName(6)
        );
        $this->assertSame(
            'La saca que contiene tu envío quedó bajo custodia de Aduana o seguridad',
            $service->customerEventName(194)
        );
        $this->assertSame(
            'La importación de tu paquete fue detenida',
            $service->customerEventName(76)
        );
    }

    public function test_explicit_display_rule_takes_precedence_over_automatic_event_label(): void
    {
        Schema::create('tracking_event_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('source_db', 80)->default('*');
            $table->unsignedInteger('event_type_cd')->nullable();
            $table->string('raw_name', 255)->default('');
            $table->string('display_name', 255)->nullable();
            $table->boolean('is_visible')->default(true);
            $table->boolean('append_source_context')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        TrackingEventRule::query()->create([
            'source_db' => '*',
            'event_type_cd' => 31,
            'raw_name' => 'Send item to customs (Inb)',
            'display_name' => 'Send item to customs (Inb)',
            'is_visible' => true,
            'append_source_context' => false,
        ]);

        $this->assertSame(
            'Send item to customs (Inb)',
            app(TrackingEventRuleService::class)->present(
                'Send item to customs (Inb)',
                'IPS5Db',
                31
            )
        );

        TrackingEventRule::query()->delete();
        $service = app(TrackingEventRuleService::class);
        $service->clearCache();

        $this->assertSame(
            'Enviado a control aduanero',
            $service->present('Enviado a control aduanero', 'IPS5Db', 31)
        );
    }
}
