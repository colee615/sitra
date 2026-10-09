<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ips_operations', function (Blueprint $table) {
            // The event timestamp sent to external IPS is kept separate from the
            // app-side submission time (created_at/registered_at_utc).
            $table->string('event_at_utc', 35)->nullable();
            $table->smallInteger('event_local_offset_minutes')->nullable();
            $table->string('registered_at_utc', 35)->nullable();
            $table->string('delivery_mode', 24)->nullable();
            $table->string('time_reason', 255)->nullable();
            $table->string('customs_return_event_at_utc', 35)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ips_operations', function (Blueprint $table) {
            $table->dropColumn([
                'event_at_utc',
                'event_local_offset_minutes',
                'registered_at_utc',
                'delivery_mode',
                'time_reason',
                'customs_return_event_at_utc',
            ]);
        });
    }
};
