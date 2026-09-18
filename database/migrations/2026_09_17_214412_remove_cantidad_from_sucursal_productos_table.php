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
        Schema::table('sucursal_productos', function (Blueprint $table) {
            $table->dropColumn('cantidad');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sucursal_productos', function (Blueprint $table) {
            $table->decimal('cantidad', 14, 6)->default(0);
        });
    }
};
