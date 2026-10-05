import { useQuery } from '@tanstack/react-query';
import { apiFetch } from '@/api/client';
import type { DataEnvelope, Order, OrderStatus } from '@/api/types';

export type { Order, OrderStatus };

const TERMINAL: OrderStatus[] = ['declined', 'expired', 'refunded', 'refund_required'];

/**
 * Polling de 2s enquanto PENDING (item 5.3), reduzindo para ~30s quando PAID
 * (ainda pode virar REFUNDED) e parando de vez em estados terminais.
 */
export function useOrderStatus(orderId: string) {
  return useQuery<Order>({
    queryKey: ['order', orderId],
    queryFn: async () => (await apiFetch<DataEnvelope<Order>>(`/orders/${orderId}`)).data.data,
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      if (!status || TERMINAL.includes(status)) return false;
      return status === 'paid' ? 30000 : 2000;
    },
  });
}

export const STATUS_MESSAGES: Record<OrderStatus, string> = {
  pending: 'Aguardando pagamento.',
  paid: 'Pagamento confirmado! Seus ingressos foram enviados por e-mail.',
  declined: 'Pagamento recusado.',
  expired: 'A reserva expirou e os ingressos foram liberados.',
  refunded: 'Pedido reembolsado. Os ingressos foram invalidados.',
  refund_required: 'Pagamento recebido tardiamente, sem estoque disponível — reembolso em andamento.',
};

export const STATUS_BADGE_VARIANT: Record<OrderStatus, 'neutral' | 'success' | 'warning' | 'danger' | 'info'> = {
  pending: 'warning',
  paid: 'success',
  declined: 'danger',
  expired: 'neutral',
  refunded: 'info',
  refund_required: 'danger',
};
