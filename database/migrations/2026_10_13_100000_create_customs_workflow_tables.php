<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customs_receptacle_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('receptacle_pid', 36);
            $table->string('receptacle_code', 35);
            $table->unsignedSmallInteger('office_cd');
            $table->string('event', 24);
            $table->string('source', 24)->default('correos_manual');
            $table->string('status', 20)->default('processing');
            $table->string('idempotency_hash', 64);
            $table->string('request_hash', 64);
            $table->json('ips_request')->nullable();
            $table->string('ips_operation_id', 36)->nullable();
            $table->json('result')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['receptacle_pid', 'idempotency_hash']);
            $table->index(['receptacle_code', 'created_at']);
        });

        Schema::create('customs_work_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('dispatch_code', 35)->nullable();
            $table->string('receptacle_pid', 36);
            $table->string('receptacle_code', 35);
            $table->unsignedSmallInteger('office_cd')->nullable();
            $table->string('status', 20)->default('open');
            // Current operation is carried out by Correos; future CDS responses
            // can use a separate, explicit source without impersonating Customs.
            $table->string('operator_mode', 24)->default('correos_manual');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique('receptacle_pid');
            $table->index(['dispatch_code', 'status']);
        });

        Schema::create('customs_session_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('customs_work_sessions')->cascadeOnDelete();
            $table->string('ips_mailitm_pid', 36)->nullable();
            $table->string('postal_code', 35)->nullable();
            $table->string('local_code', 35)->nullable();
            $table->string('scan_status', 20)->default('pending');
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'ips_mailitm_pid']);
            $table->index(['session_id', 'scan_status']);
            $table->index(['session_id', 'postal_code']);
        });

        Schema::create('customs_physical_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('customs_work_sessions')->cascadeOnDelete();
            $table->foreignUuid('session_item_id')->nullable()->constrained('customs_session_items')->nullOnDelete();
            $table->string('scanned_code', 35);
            $table->string('result', 20);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('scanned_at');
            $table->timestamps();
            $table->index(['session_id', 'scanned_code']);
            $table->index(['session_id', 'result']);
        });

        Schema::create('customs_case_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('customs_work_sessions')->cascadeOnDelete();
            $table->string('postal_code', 35);
            $table->string('action', 24);
            $table->string('source', 24)->default('correos_manual');
            $table->string('status', 20)->default('processing');
            $table->string('decision_code', 8)->nullable();
            $table->text('notes')->nullable();
            $table->string('evidence_reference', 100)->nullable();
            $table->json('declaration_ids')->nullable();
            $table->string('idempotency_hash', 64);
            $table->string('request_hash', 64);
            $table->json('ips_request')->nullable();
            $table->string('ips_operation_id', 36)->nullable();
            $table->json('result')->nullable();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['session_id', 'idempotency_hash']);
            $table->index(['session_id', 'postal_code', 'action', 'status'], 'customs_actions_item_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customs_case_actions');
        Schema::dropIfExists('customs_physical_scans');
        Schema::dropIfExists('customs_session_items');
        Schema::dropIfExists('customs_work_sessions');
        Schema::dropIfExists('customs_receptacle_actions');
    }
};
