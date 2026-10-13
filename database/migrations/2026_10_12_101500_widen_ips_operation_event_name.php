<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ips_operations', function (Blueprint $table) {
            $table->string('event', 32)->change();
        });
    }

    public function down(): void
    {
        // Keep the wider column: operation history may already contain descriptive event aliases.
    }
};
