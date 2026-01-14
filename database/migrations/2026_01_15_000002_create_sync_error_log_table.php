<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSyncErrorLogTable extends Migration
{
    public function up()
    {
        Schema::connection(config('database-sync.source_connection'))->create('sync_error_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('table_name', 255)->index();
            $table->integer('batch_offset')->index();
            $table->integer('batch_size');
            $table->text('error_message')->nullable();
            $table->text('failed_columns')->nullable(); // JSON array of columns that failed
            $table->text('sample_data')->nullable(); // JSON sample of failed row
            $table->boolean('resolved')->default(false)->index();
            $table->timestamp('error_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            
            $table->index(['table_name', 'resolved']);
        });
    }

    public function down()
    {
        Schema::connection(config('database-sync.source_connection'))->dropIfExists('sync_error_log');
    }
}
