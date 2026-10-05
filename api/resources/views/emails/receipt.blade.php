<h1>Comprovante de pagamento</h1>
<p>Olá, {{ $order->buyer_name }}! Confirmamos o pagamento do pedido #{{ $order->id }}.</p>
<p>Lote: {{ $order->batch->name }} · Quantidade: {{ $order->quantity }} · Total: R$ {{ number_format($order->total_cents / 100, 2, ',', '.') }}</p>
