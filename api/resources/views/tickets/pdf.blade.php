<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif}</style></head>
<body>
  @foreach ($tickets as $ticket)
    <div @if (! $loop->last) style="page-break-after: always;" @endif>
      <h1>Ingresso {{ $loop->iteration }} de {{ $loop->count }}</h1>
      <p>Pedido {{ $order->id }} · {{ $order->batch->name }}</p>
      <p>Titular: {{ $ticket['holder'] }}</p>
      <p>Código: {{ $ticket['code'] }}</p>
      <img src="data:image/png;base64,{{ $ticket['qr_base64'] }}" width="220" height="220" alt="QR Code" />
    </div>
  @endforeach
</body>
</html>
