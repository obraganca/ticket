import { Link, useParams } from 'react-router-dom';
import { STATUS_BADGE_VARIANT, STATUS_MESSAGES, useOrderStatus } from './useOrderStatus';
import { centsToBRL } from '@/lib/money';
import { Alert, Badge, Button, Card, LoadingState, PageContainer } from '@/components/ui';

export function OrderStatusPage() {
  const { orderId } = useParams<{ orderId: string }>();
  const { data: order, isLoading, isError } = useOrderStatus(orderId!);

  if (isLoading) return <PageContainer width="sm"><LoadingState label="Carregando pedido..." /></PageContainer>;

  if (isError || !order) {
    return (
      <PageContainer width="sm">
        <Alert>Pedido não encontrado.</Alert>
      </PageContainer>
    );
  }

  return (
    <PageContainer width="sm">
      <Card>
        <div className="flex items-center justify-between gap-3">
          <h1 className="text-lg font-semibold text-slate-900">Pedido #{order.id.slice(0, 8)}</h1>
          <Badge variant={STATUS_BADGE_VARIANT[order.status]}>{order.status}</Badge>
        </div>

        <p aria-live="polite" className="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
          {STATUS_MESSAGES[order.status]}
        </p>

        <dl className="mt-4 space-y-2 text-sm text-slate-600">
          <div className="flex justify-between"><dt>Lote</dt><dd className="font-medium text-slate-900">{order.batch_name}</dd></div>
          <div className="flex justify-between"><dt>Quantidade</dt><dd className="font-medium text-slate-900">{order.quantity}</dd></div>
          <div className="flex justify-between"><dt>Total</dt><dd className="font-medium text-slate-900">{centsToBRL(order.total_cents)}</dd></div>
          <div className="flex justify-between"><dt>E-mail</dt><dd className="font-medium text-slate-900">{order.buyer_email}</dd></div>
        </dl>

        {order.status === 'expired' && (
          <Link to="/" className="mt-5 block">
            <Button className="w-full">Comprar novamente</Button>
          </Link>
        )}
      </Card>
    </PageContainer>
  );
}
