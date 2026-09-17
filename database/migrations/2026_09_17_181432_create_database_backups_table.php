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
        Schema::create('database_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('filename')->nullable();
            $table->string('original_name')->nullable();
            $table->string('disk')->default('local');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status')->default('pending'); // pending, running, completed, failed
            $table->string('database_name')->nullable();
            $table->string('driver')->nullable();
            $table->string('database_version')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_backups');
    }
};
