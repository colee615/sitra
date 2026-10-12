<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens') || Schema::hasColumn('personal_access_tokens', 'secret_encrypted')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->text('secret_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('personal_access_tokens') && Schema::hasColumn('personal_access_tokens', 'secret_encrypted')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->dropColumn('secret_encrypted');
            });
        }
    }
};
