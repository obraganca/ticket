import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { apiFetch, ApiError, errorMessageFor } from '@/api/client';
import { centsToBRL } from '@/lib/money';
import { CheckoutForm } from '@/features/checkout/CheckoutForm';
import { Alert, Badge, Button, Card, EmptyState, LoadingState, PageContainer, PageHeader } from '@/components/ui';

interface PublicBatchLine {
  ticket_type_id: number;
  name: string; // nome do TIPO de ingresso (ex.: "Pista")
  id: number | null; // id do lote ativo, ou null se não houver nenhum ativo
  batch_label?: string | null; // nome do lote em si (ex.: "1º lote")
  price_cents: number | null;
  available: number;
  sold_out: boolean;
}

export function BatchesPage() {
  const { eventId } = useParams<{ eventId: string }>();
  const [selected, setSelected] = useState<number | null>(null);
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['batches', eventId],
    queryFn: async () => (await apiFetch<{ data: PublicBatchLine[] }>(`/events/${eventId}/batches`)).data.data,
  });

  return (
    <PageContainer>
      <Link to="/" className="mb-2 inline-block text-sm text-indigo-600 hover:underline">← Voltar para eventos</Link>
      <PageHeader title="Ingressos disponíveis" subtitle="Escolha o tipo, a quantidade e finalize sua compra." />

      {isLoading && <LoadingState label="Carregando ingressos..." />}

      {isError && (
        <Alert>{error instanceof ApiError ? errorMessageFor(error.body?.error?.code ?? '') : 'Não foi possível carregar os ingressos.'}</Alert>
      )}

      {!isLoading && !isError && data?.length === 0 && (
        <EmptyState title="Nenhum tipo de ingresso disponível para este evento" />
      )}

      <div className="space-y-4">
        {data?.map((line) => (
          <Card key={line.ticket_type_id}>
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p className="font-semibold text-slate-900">{line.name}</p>
                <p className="mt-1 text-sm text-slate-500">
                  {line.price_cents !== null ? (
                    <>
                      <span className="font-medium text-slate-700">{centsToBRL(line.price_cents)}</span>
                      {line.batch_label && <> · {line.batch_label}</>} · {line.available} disponíveis
                    </>
                  ) : (
                    'Nenhum lote em venda no momento'
                  )}
                </p>
              </div>
              {line.sold_out || line.id === null ? (
                <Badge variant="danger">Esgotado</Badge>
              ) : (
                <Button onClick={() => setSelected(selected === line.id ? null : line.id)}>
                  {selected === line.id ? 'Cancelar' : 'Comprar'}
                </Button>
              )}
            </div>
            {selected === line.id && line.id !== null && (
              <div className="mt-5 border-t border-slate-100 pt-5">
                <CheckoutForm batchId={line.id} />
              </div>
            )}
          </Card>
        ))}
      </div>
    </PageContainer>
  );
}