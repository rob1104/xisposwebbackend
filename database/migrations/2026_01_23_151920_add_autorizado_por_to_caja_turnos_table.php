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
        Schema::table('caja_turnos', function (Blueprint $table) {
            $table->unsignedBigInteger('autorizado_por')->nullable()->after('user_id');
            $table->foreign('autorizado_por')->references('id')->on('users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caja_turnos', function (Blueprint $table) {
            $table->dropForeign(['autorizado_por']);
            $table->dropColumn('autorizado_por');
        });
    }
};
