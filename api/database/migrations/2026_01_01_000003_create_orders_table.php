<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('batch_id')->constrained();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('total_cents');
            $table->string('status')->default('pending');
            $table->string('buyer_name');
            $table->string('buyer_email');
            $table->string('buyer_document');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('finance_registered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']); // usado pelo expirador
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
