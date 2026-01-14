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
        Schema::connection(config('database-sync.source_connection'))
            ->create(config('database-sync.audit_table'), function (Blueprint $table) {
                $table->id();
                $table->string('table_name', 64)->index();
                $table->string('record_id', 255)->nullable();
                $table->enum('operation', ['INSERT', 'UPDATE', 'DELETE'])->index();
                $table->json('old_data')->nullable();
                $table->json('new_data')->nullable();
                $table->boolean('synced')->default(false)->index();
                $table->timestamp('synced_at')->nullable();
                $table->text('error_message')->nullable();
                $table->integer('retry_count')->default(0);
                $table->timestamp('created_at')->useCurrent()->index();
                
                $table->index(['synced', 'created_at'], 'idx_sync_status');
                $table->index(['table_name', 'synced'], 'idx_table_sync');
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(config('database-sync.source_connection'))
            ->dropIfExists(config('database-sync.audit_table'));
    }
};
