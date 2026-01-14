<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncProgressTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sync_progress', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('table_name')->unique();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])->default('pending');
            $table->bigInteger('total_rows')->default(0);
            $table->bigInteger('synced_rows')->default(0);
            $table->bigInteger('failed_rows')->default(0);
            $table->bigInteger('last_synced_offset')->default(0);
            $table->integer('batch_size')->default(500);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->index('status');
            $table->index('table_name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sync_progress');
    }
}
