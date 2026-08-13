<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_event_rules', function (Blueprint $table) {
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

            $table->index(['source_db', 'event_type_cd']);
            $table->index(['source_db', 'raw_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_event_rules');
    }
};
