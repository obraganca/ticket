import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ApiError, errorMessageFor } from '@/api/client';
import { apiUpload } from '@/api/adminClient';
import type { AdminEvent } from '@/api/adminTypes';
import { useAuth } from '@/context/AuthContext';
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Field,
  Input,
  LoadingState,
  PageContainer,
  PageHeader,
} from '@/components/ui';
import { useApiClient } from '@/api/useApiClient';

export function AdminEventsPage() {
  const { request } = useApiClient();
  const { token } = useAuth();
  const queryClient = useQueryClient();

  const [isCreating, setIsCreating] = useState(false);
  const [eventName, setEventName] = useState('');
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [formError, setFormError] = useState('');

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['admin-events'],
    queryFn: async () => (await request<{ data: AdminEvent[] }>('/admin/events')).data.data,
  });

  const createMutation = useMutation({
    mutationFn: async () => {
      const formData = new FormData();
      formData.append('name', eventName);
      if (imageFile) formData.append('image', imageFile);
      return apiUpload<{ data: AdminEvent }>('/admin/events', formData, token);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-events'] });
      setEventName('');
      setImageFile(null);
      setIsCreating(false);
      setFormError('');
    },
    onError: (err) => {
      setFormError(
        err instanceof ApiError
          ? errorMessageFor(err.body?.error?.code ?? '')
          : 'Erro ao criar evento. Tente novamente.'
      );
    },
  });

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!eventName.trim()) return;
    createMutation.mutate();
  }

  return (
    <PageContainer width="lg">
      <PageHeader
        title="Gerenciamento de Eventos"
        subtitle="Crie eventos, edite lotes, imagens e a virada de lote."
        action={
          <Button onClick={() => setIsCreating(!isCreating)}>
            {isCreating ? 'Cancelar' : '+ Novo Evento'}
          </Button>
        }
      />

      {isCreating && (
        <Card className="mb-6 border-indigo-100 bg-indigo-50/30">
          <h2 className="mb-4 text-lg font-semibold text-slate-900">Cadastrar Novo Evento</h2>

          {formError && <Alert className="mb-4">{formError}</Alert>}

          <form onSubmit={handleSubmit} className="space-y-4">
            <Field label="Nome do Evento" id="eventName">
              <Input
                id="eventName"
                value={eventName}
                onChange={(e) => setEventName(e.target.value)}
                placeholder="Ex: Festival de Verão 2026"
                required
                autoFocus
              />
            </Field>

            <Field label="Imagem do evento (opcional)" id="eventImage">
              <input
                id="eventImage"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                onChange={(e) => setImageFile(e.target.files?.[0] ?? null)}
                className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100"
              />
            </Field>

            <div className="flex justify-end gap-2">
              <Button type="button" variant="ghost" onClick={() => setIsCreating(false)}>
                Cancelar
              </Button>
              <Button type="submit" disabled={createMutation.isPending}>
                {createMutation.isPending ? 'Salvando...' : 'Salvar Evento'}
              </Button>
            </div>
          </form>
        </Card>
      )}

      {isLoading && <LoadingState label="Carregando eventos..." />}

      {isError && (
        <Alert>
          {error instanceof ApiError ? errorMessageFor(error.body?.error?.code ?? '') : 'Não foi possível carregar os eventos.'}
        </Alert>
      )}

      {!isLoading && !isError && data?.length === 0 && (
        <EmptyState title="Nenhum evento cadastrado" description="Clique no botão acima para criar o primeiro evento." />
      )}

      {!isLoading && !isError && data && data.length > 0 && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {data.map((event) => {
  const totalTypes = event.ticket_types.length;
  const activeCount = event.ticket_types.filter((t) => t.batches.some((b) => b.is_active)).length;
  return (
    <Link key={event.id} to={`/admin/events/${event.id}`} className="block">
      <Card className="h-full transition-shadow hover:shadow-md">
        {event.image_url ? (
          <img src={event.image_url} alt={event.name} className="h-24 w-full rounded-lg object-cover" />
        ) : (
          <div className="flex h-24 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-700 text-3xl font-bold text-white/90">
            {event.name.charAt(0).toUpperCase()}
          </div>
        )}
        <div className="mt-4 flex items-center justify-between">
          <p className="font-semibold text-slate-900">{event.name}</p>
          <Badge variant="neutral">{totalTypes} tipo(s)</Badge>
        </div>
        <p className="mt-1 text-sm text-slate-500">{activeCount} com lote em venda</p>
        <p className="mt-1 text-sm font-medium text-indigo-600">Editar evento e lotes →</p>
      </Card>
    </Link>
  );
})}
        </div>
      )}
    </PageContainer>
  );
}