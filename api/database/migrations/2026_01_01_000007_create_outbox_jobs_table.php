<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_id');
            $table->string('kind');
            $table->string('status')->default('pending'); // pending|running|done|failed|cancelled
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_run_at')->useCurrent();
            $table->timestamp('locked_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'kind']); // 1 job de cada tipo por pedido, mesmo com webhook duplicado concorrente
            $table->index(['status', 'next_run_at']); // usado pelo sweeper e pelo worker
            $table->foreign('order_id')->references('id')->on('orders');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_jobs');
    }
};
