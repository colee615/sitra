<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
                $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_role_id_model_id_model_type_primary');
            });
        }

        if (!Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
                $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_id_model_id_model_type_primary');
            });
        }

        if (!Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
            });
        }

        // Backfill from legacy pivots used by this database dump.
        if (Schema::hasTable('role_user')) {
            $rows = DB::table('role_user')->select('role_id', 'user_id')->get();
            foreach ($rows as $row) {
                DB::table('model_has_roles')->updateOrInsert(
                    [
                        'role_id' => $row->role_id,
                        'model_id' => $row->user_id,
                        'model_type' => 'App\\Models\\User',
                    ],
                    []
                );
            }
        }

        if (Schema::hasTable('permission_role')) {
            $rows = DB::table('permission_role')->select('permission_id', 'role_id')->get();
            foreach ($rows as $row) {
                DB::table('role_has_permissions')->updateOrInsert(
                    [
                        'permission_id' => $row->permission_id,
                        'role_id' => $row->role_id,
                    ],
                    []
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
    }
};
