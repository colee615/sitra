<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Primary application database only; no IPS schema changes.
        Schema::create('ips_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->string('action', 20);
            $table->string('codigo', 35);
            $table->string('event', 3);
            $table->string('status', 20);
            $table->json('result')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key_hash']);
            $table->index(['codigo', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ips_operations');
    }
};
