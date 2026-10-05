<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_id');
            $table->string('kind');
            $table->string('message_id');
            $table->timestamp('sent_at');

            $table->unique(['order_id', 'kind']); // nunca 2 e-mails do mesmo tipo para o mesmo pedido (P4)
            $table->foreign('order_id')->references('id')->on('orders');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
    }
};
