import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ApiError, errorMessageFor } from '@/api/client';
import type { DataEnvelope, EventSummary } from '@/api/types';
import { Alert, Card, EmptyState, LoadingState, PageContainer, PageHeader } from '@/components/ui';
import { useApiClient } from '@/api/useApiClient';

/**
 * Rota pública ("/", sem <RequireAuth>): a listagem de eventos é sempre a
 * mesma para visitantes anônimos e usuários logados — não depende de token
 * e não deve ser movida para dentro de uma rota protegida.
 */
export function EventsPage() {

  const { request } = useApiClient();
  
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['events'],
    queryFn: async () => (await request<DataEnvelope<EventSummary[]>>('/events')).data.data,
  });

  return (
    <PageContainer width="lg">
      <PageHeader title="Eventos" subtitle="Escolha um evento para ver os lotes disponíveis." />

      {isLoading && <LoadingState label="Carregando eventos..." />}

      {isError && (
        <Alert>{error instanceof ApiError ? errorMessageFor(error.body?.error?.code ?? '') : 'Não foi possível carregar os eventos.'}</Alert>
      )}

      {!isLoading && !isError && data?.length === 0 && (
        <EmptyState title="Nenhum evento disponível no momento" description="Volte em breve para conferir novidades." />
      )}

      {!isLoading && !isError && data && data.length > 0 && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {data.map((event) => (
            <Link key={event.id} to={`/events/${event.id}`} className="block">
              <Card className="h-full">
                <div className="flex h-24 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-700 text-3xl font-bold text-white/90">
                  {event.name.charAt(0).toUpperCase()}
                </div>
                <p className="mt-4 font-semibold text-slate-900">{event.name}</p>
                <p className="mt-1 text-sm font-medium text-indigo-600">Ver lotes e ingressos →</p>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </PageContainer>
  );
}
