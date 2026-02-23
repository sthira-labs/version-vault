<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(config('version-vault.table_name','version_vault_versions'), function (Blueprint $table) {
            $table->id();
            $table->string('versionable_type',191);
            $table->unsignedBigInteger('versionable_id');
            $table->unsignedBigInteger('version')->default(1);
            $table->json('diff')->nullable();
            $table->json('snapshot')->nullable();
            $table->json('changed_paths')->nullable();
            $table->string('action',64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['versionable_type','versionable_id','version'], 'version_vault_versions_versionable_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('version-vault.table_name','version_vault_versions'));
    }
};
