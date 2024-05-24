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
        Schema::create('sospecha_ratas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->nullable();
            $table->string('sku')->nullable();
            $table->string('tienda')->nullable();
            $table->string('url')->nullable();
            $table->string('img')->nullable();
            $table->decimal('precio_normal', 14,4)->nullable();
            $table->decimal('precio_oferta', 14,4)->nullable();
            $table->decimal('precio_tarjeta', 14,4)->nullable();
            $table->integer('descuento')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sospecha_ratas');
    }
};
