<?php

namespace Tests\Support;

use App\Contracts\FinanceClient;
use App\Contracts\FinanceUnavailableException;
use App\Contracts\OrderNotifier;
use App\Contracts\TicketRenderer;
use App\Models\Order;

/** Financeiro em memória. Dedupe por Idempotency-Key, como o finance-sim real. */
class FakeFinanceClient implements FinanceClient
{
    /** @var array<string, array> registros efetivos, por chave */
    public array $registered = [];

    /** @var array<int, string> toda chamada recebida (inclusive as que falharam) */
    public array $calls = [];

    private int $failuresLeft = 0;

    private bool $failAfterRegister = false;

    /** Falha as próximas $times chamadas. Com $afterRegister, grava e ainda assim lança (o pior caso). */
    public function failNext(int $times, bool $afterRegister = false): static
    {
        $this->failuresLeft = $times;
        $this->failAfterRegister = $afterRegister;

        return $this;
    }

    public function register(string $idempotencyKey, array $payload): void
    {
        $this->calls[] = $idempotencyKey;

        if ($this->failuresLeft > 0) {
            $this->failuresLeft--;
            if ($this->failAfterRegister) {
                $this->registered[$idempotencyKey] ??= $payload;
            }
            throw new FinanceUnavailableException('financeiro instável (fake)');
        }

        $this->registered[$idempotencyKey] ??= $payload;
    }

    public function count(string $suffix): int
    {
        return count(array_filter(array_keys($this->registered), fn ($k) => str_ends_with($k, ':'.$suffix)));
    }
}

/** Notifier que registra o que "enviou" e pode falhar as primeiras N vezes (SMTP fora do ar). */
class FakeNotifier implements OrderNotifier
{
    /** @var array<int, array{kind: string, order: string, message_id: string}> */
    public array $sent = [];

    public int $attempts = 0;

    private int $failuresLeft = 0;

    public function failNext(int $times): static
    {
        $this->failuresLeft = $times;

        return $this;
    }

    public function sendReceipt(Order $order, string $messageId): void
    {
        $this->deliver('receipt', $order, $messageId);
    }

    public function sendTickets(Order $order, string $pdfContent, string $messageId): void
    {
        $this->deliver('tickets', $order, $messageId);
    }

    private function deliver(string $kind, Order $order, string $messageId): void
    {
        $this->attempts++;
        if ($this->failuresLeft > 0) {
            $this->failuresLeft--;
            throw new \RuntimeException('SMTP fora do ar (fake)');
        }
        $this->sent[] = ['kind' => $kind, 'order' => $order->id, 'message_id' => $messageId];
    }

    public function sentCount(string $kind): int
    {
        return count(array_filter($this->sent, fn ($s) => $s['kind'] === $kind));
    }
}

class FakeRenderer implements TicketRenderer
{
    public int $renders = 0;

    public function renderPdf(Order $order): string
    {
        $this->renders++;

        return '%PDF-fake';
    }
}
