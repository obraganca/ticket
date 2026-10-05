<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->string('event_id')->primary(); // id do próprio gateway; PK = dedup (P5/P6)
            $table->uuid('order_id');
            $table->string('type'); // approved|declined|refunded
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->jsonb('payload')->nullable();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
