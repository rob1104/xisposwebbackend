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
        Schema::create('compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sucursale_id')->constrained();
            $table->foreignId('provider_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('folio')->unique();
            $table->string('referencia')->nullable(); // Factura del proveedor
            $table->date('fecha');
            $table->decimal('subtotal', 14, 6);
            $table->decimal('iva', 14, 6);
            $table->decimal('total', 14, 6);
            $table->decimal('saldo', 14, 6)->default(0); // Para compras a crédito
            $table->enum('metodo_pago', ['CONTADO', 'CREDITO']);
            $table->enum('estatus', ['PENDIENTE', 'PAGADA', 'CANCELADA'])->default('PENDIENTE');
            $table->date('fecha_vencimiento')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compras');
    }
};
