import { useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, errorMessageFor } from '@/api/client';
import { apiUpload } from '@/api/adminClient';
import type { AdminBatch, AdminEvent, AdminTicketType } from '@/api/adminTypes';
import { useAuth } from '@/context/AuthContext';
import { useApiClient } from '@/api/useApiClient';
import { centsToBRL, brlToCents } from '@/lib/money';
import { BatchTimeline } from './BatchTimeline';
import {
  Alert,
  Badge,
  Button,
  Card,
  Field,
  Input,
  LoadingState,
  PageContainer,
  PageHeader,
} from '@/components/ui';

export function AdminEventDetailPage() {
  const { eventId } = useParams<{ eventId: string }>();
  const { request } = useApiClient();
  const { token } = useAuth();
  const queryClient = useQueryClient();

  const { data: event, isLoading, isError } = useQuery({
    queryKey: ['admin-event', eventId],
    queryFn: async () => (await request<{ data: AdminEvent }>(`/admin/events/${eventId}`)).data.data,
    enabled: !!eventId,
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['admin-event', eventId] });
    queryClient.invalidateQueries({ queryKey: ['admin-events'] });
  };

  // ---------- editar evento (nome + imagem) ----------
  const [name, setName] = useState('');
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [eventError, setEventError] = useState('');

  const updateEventMutation = useMutation({
    mutationFn: async () => {
      const formData = new FormData();
      if (name.trim()) formData.append('name', name.trim());
      if (imageFile) formData.append('image', imageFile);
      return apiUpload<{ data: AdminEvent }>(`/admin/events/${eventId}`, formData, token);
    },
    onSuccess: () => {
      setImageFile(null);
      setEventError('');
      invalidate();
    },
    onError: (err) =>
      setEventError(err instanceof ApiError ? errorMessageFor(err.body?.error?.code ?? '') : 'Erro ao salvar evento.'),
  });

  // ---------- novo tipo de ingresso (ex.: Pista, Camarote) ----------
  const [showNewType, setShowNewType] = useState(false);
  const [typeName, setTypeName] = useState('');
  const [typeError, setTypeError] = useState('');

  const createTypeMutation = useMutation({
    mutationFn: async () =>
      (await request<{ data: AdminTicketType }>(`/admin/events/${eventId}/ticket-types`, {
        method: 'POST',
        body: { name: typeName },
      })).data,
    onSuccess: () => {
      setTypeName('');
      setShowNewType(false);
      setTypeError('');
      invalidate();
    },
    onError: (err) =>
      setTypeError(err instanceof ApiError ? errorMessageFor(err.body?.error?.code ?? '') : 'Erro ao criar tipo de ingresso.'),
  });

  if (isLoading) return <LoadingState label="Carregando evento..." />;
  if (isError || !event) return <Alert>Não foi possível carregar este evento.</Alert>;

  return (
    <PageContainer width="lg">
      <Link to="/admin/events" className="mb-2 inline-block text-sm text-indigo-600 hover:underline">
        ← Voltar para eventos
      </Link>
      <PageHeader title={`Editar: ${event.name}`} subtitle="Edite o evento, os tipos de ingresso e os lotes de cada um." />

      {/* Linha do tempo (uma por tipo de ingresso) */}
      <Card className="mb-6">
        <h2 className="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">
          Linha do tempo dos lotes
        </h2>
        {event.ticket_types.every((t) => t.batches.length === 0) ? (
          <p className="text-sm text-slate-400">Cadastre um tipo de ingresso e adicione lotes pra ver a timeline aqui.</p>
        ) : (
          <BatchTimeline ticketTypes={event.ticket_types} />
        )}
      </Card>

      {/* Edição do evento */}
      <Card className="mb-6">
        <h2 className="mb-4 text-lg font-semibold text-slate-900">Dados do evento</h2>
        {eventError && <Alert className="mb-4">{eventError}</Alert>}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
          {event.image_url && (
            <img src={event.image_url} alt={event.name} className="h-20 w-20 rounded-lg object-cover" />
          )}
          <div className="flex-1">
            <Field label="Nome do evento" id="editEventName">
              <Input id="editEventName" defaultValue={event.name} onChange={(e) => setName(e.target.value)} />
            </Field>
          </div>
          <div className="flex-1">
            <Field label="Trocar imagem" id="editEventImage">
              <input
                id="editEventImage"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                onChange={(e) => setImageFile(e.target.files?.[0] ?? null)}
                className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100"
              />
            </Field>
          </div>
          <Button onClick={() => updateEventMutation.mutate()} disabled={updateEventMutation.isPending}>
            {updateEventMutation.isPending ? 'Salvando...' : 'Salvar evento'}
          </Button>
        </div>
      </Card>

      {/* Tipos de ingresso */}
      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-lg font-semibold text-slate-900">Tipos de ingresso</h2>
        <Button onClick={() => setShowNewType(!showNewType)}>
          {showNewType ? 'Cancelar' : '+ Novo tipo de ingresso'}
        </Button>
      </div>

      {showNewType && (
        <Card className="mb-6 border-indigo-100 bg-indigo-50/30">
          {typeError && <Alert className="mb-3">{typeError}</Alert>}
          <div className="flex items-end gap-3">
            <div className="flex-1">
              <Field label="Nome (ex.: Pista, Camarote, VIP)" id="typeName">
                <Input id="typeName" value={typeName} onChange={(e) => setTypeName(e.target.value)} placeholder="Pista" autoFocus />
              </Field>
            </div>
            <Button onClick={() => createTypeMutation.mutate()} disabled={createTypeMutation.isPending || !typeName.trim()}>
              {createTypeMutation.isPending ? 'Salvando...' : 'Salvar tipo'}
            </Button>
          </div>
        </Card>
      )}

      {event.ticket_types.length === 0 && !showNewType && (
        <p className="mb-6 text-sm text-slate-400">Nenhum tipo de ingresso cadastrado ainda.</p>
      )}

      <div className="space-y-6">
        {event.ticket_types.map((type) => (
          <TicketTypeCard key={type.id} ticketType={type} onChanged={invalidate} />
        ))}
      </div>
    </PageContainer>
  );
}

/** Card de um tipo de ingresso: renomear + gerenciar seus próprios lotes. */
function TicketTypeCard({ ticketType, onChanged }: { ticketType: AdminTicketType; onChanged: () => void }) {
  const { request } = useApiClient();

  // ---------- renomear tipo ----------
  const [editingName, setEditingName] = useState(false);
  const [typeName, setTypeName] = useState(ticketType.name);
  const [renameError, setRenameError] = useState('');

  const renameMutation = useMutation({
    mutationFn: async () =>
      (await request(`/admin/ticket-types/${ticketType.id}`, { method: 'PUT', body: { name: typeName } })).data,
    onSuccess: () => {
      setEditingName(false);
      onChanged();
    },
    onError: (err) =>
      setRenameError(err instanceof ApiError ? errorMessageFor(err.body?.error?.code ?? '') : 'Erro ao renomear.'),
  });

  // ---------- novo lote ----------
  const [showNewBatch, setShowNewBatch] = useState(false);
  const [batchName, setBatchName] = useState('');
  const [batchPrice, setBatchPrice] = useState('');
  const [batchTotal, setBatchTotal] = useState('');
  const [batchError, setBatchError] = useState('');

  const createBatchMutation = useMutation({
    mutationFn: async () =>
      (
        await request<{ data: AdminBatch }>(`/admin/ticket-types/${ticketType.id}/batches`, {
          method: 'POST',
          body: {
            name: batchName,
            price_cents: brlToCents(batchPrice),
            total: Number(batchTotal),
          },
        })
      ).data,
    onSuccess: () => {
      setBatchName('');
      setBatchPrice('');
      setBatchTotal('');
      setShowNewBatch(false);
      setBatchError('');
      onChanged();
    },
    onError: (err) =>
      setBatchError(err instanceof ApiError ? errorMessageFor(err.body?.error?.code ?? '') : 'Erro ao criar lote.'),
  });

  // ---------- editar / ativar-inativar lote ----------
  const toggleBatchMutation = useMutation({
    mutationFn: async (batchId: number) =>
      (await request<{ data: AdminBatch }>(`/admin/batches/${batchId}/toggle`, { method: 'PATCH' })).data,
    onSuccess: onChanged,
  });

  const [editingBatchId, setEditingBatchId] = useState<number | null>(null);
  const [editName, setEditName] = useState('');
  const [editPrice, setEditPrice] = useState('');
  const [editTotal, setEditTotal] = useState('');
  const [editError, setEditError] = useState('');

  function startEditing(batch: AdminBatch) {
    setEditingBatchId(batch.id);
    setEditName(batch.name);
    setEditPrice((batch.price_cents / 100).toFixed(2).replace('.', ','));
    setEditTotal(String(batch.total));
    setEditError('');
  }

  const updateBatchMutation = useMutation({
    mutationFn: async (batchId: number) =>
      (
        await request<{ data: AdminBatch }>(`/admin/batches/${batchId}`, {
          method: 'PUT',
          body: { name: editName, price_cents: brlToCents(editPrice), total: Number(editTotal) },
        })
      ).data,
    onSuccess: () => {
      setEditingBatchId(null);
      onChanged();
    },
    onError: (err) =>
      setEditError(err instanceof ApiError ? errorMessageFor(err.body?.error?.code ?? '') : 'Erro ao salvar lote.'),
  });

  return (
    <Card>
      <div className="mb-4 flex items-center justify-between gap-3">
        {editingName ? (
          <div className="flex flex-1 items-end gap-2">
            <div className="flex-1">
              <Field label="Nome do tipo de ingresso" id={`typeName-${ticketType.id}`} error={renameError || undefined}>
                <Input id={`typeName-${ticketType.id}`} value={typeName} onChange={(e) => setTypeName(e.target.value)} />
              </Field>
            </div>
            <Button variant="ghost" onClick={() => setEditingName(false)}>Cancelar</Button>
            <Button onClick={() => renameMutation.mutate()} disabled={renameMutation.isPending}>Salvar</Button>
          </div>
        ) : (
          <>
            <h3 className="text-base font-semibold text-slate-900">{ticketType.name}</h3>
            <div className="flex gap-2">
              <Button variant="secondary" onClick={() => setEditingName(true)}>Renomear</Button>
              <Button onClick={() => setShowNewBatch(!showNewBatch)}>{showNewBatch ? 'Cancelar' : '+ Novo lote'}</Button>
            </div>
          </>
        )}
      </div>

      {showNewBatch && (
        <div className="mb-5 rounded-lg border border-indigo-100 bg-indigo-50/30 p-4">
          {batchError && <Alert className="mb-3">{batchError}</Alert>}
          <div className="grid gap-3 sm:grid-cols-3">
            <Field label="Nome do lote" id={`batchName-${ticketType.id}`}>
              <Input id={`batchName-${ticketType.id}`} value={batchName} onChange={(e) => setBatchName(e.target.value)} placeholder="1º lote" />
            </Field>
            <Field label="Preço (R$)" id={`batchPrice-${ticketType.id}`}>
              <Input id={`batchPrice-${ticketType.id}`} value={batchPrice} onChange={(e) => setBatchPrice(e.target.value)} placeholder="120,00" />
            </Field>
            <Field label="Quantidade total" id={`batchTotal-${ticketType.id}`}>
              <Input id={`batchTotal-${ticketType.id}`} type="number" min={1} value={batchTotal} onChange={(e) => setBatchTotal(e.target.value)} />
            </Field>
          </div>
          <div className="mt-3 flex justify-end">
            <Button onClick={() => createBatchMutation.mutate()} disabled={createBatchMutation.isPending}>
              {createBatchMutation.isPending ? 'Salvando...' : 'Salvar lote'}
            </Button>
          </div>
        </div>
      )}

      {ticketType.batches.length === 0 && (
        <p className="text-sm text-slate-400">Nenhum lote cadastrado pra este tipo ainda.</p>
      )}

      <div className="space-y-3">
        {ticketType.batches.map((batch) => (
          <div key={batch.id} className="rounded-lg border border-slate-200 p-4">
            {editingBatchId === batch.id ? (
              <div>
                {editError && <Alert className="mb-3">{editError}</Alert>}
                <div className="grid gap-3 sm:grid-cols-3">
                  <Field label="Nome" id={`editName-${batch.id}`}>
                    <Input id={`editName-${batch.id}`} value={editName} onChange={(e) => setEditName(e.target.value)} />
                  </Field>
                  <Field label="Preço (R$)" id={`editPrice-${batch.id}`}>
                    <Input id={`editPrice-${batch.id}`} value={editPrice} onChange={(e) => setEditPrice(e.target.value)} />
                  </Field>
                  <Field label="Quantidade total" id={`editTotal-${batch.id}`}>
                    <Input
                      id={`editTotal-${batch.id}`}
                      type="number"
                      min={batch.reserved + batch.sold}
                      value={editTotal}
                      onChange={(e) => setEditTotal(e.target.value)}
                    />
                  </Field>
                </div>
                <div className="mt-3 flex justify-end gap-2">
                  <Button variant="ghost" onClick={() => setEditingBatchId(null)}>Cancelar</Button>
                  <Button onClick={() => updateBatchMutation.mutate(batch.id)} disabled={updateBatchMutation.isPending}>
                    {updateBatchMutation.isPending ? 'Salvando...' : 'Salvar'}
                  </Button>
                </div>
              </div>
            ) : (
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <div className="flex items-center gap-2">
                    <p className="font-semibold text-slate-900">{batch.name}</p>
                    <Badge variant={batch.is_active ? 'success' : 'neutral'}>{batch.is_active ? 'Ativo' : 'Inativo'}</Badge>
                    {batch.available <= 0 && <Badge variant="danger">Esgotado</Badge>}
                  </div>
                  <p className="mt-1 text-sm text-slate-500">
                    {centsToBRL(batch.price_cents)} · {batch.sold} vendidos · {batch.reserved} reservados ·{' '}
                    {batch.available} disponíveis de {batch.total}
                  </p>
                </div>
                <div className="flex gap-2">
                  <Button variant="secondary" onClick={() => startEditing(batch)}>Editar</Button>
                  <Button
                    variant={batch.is_active ? 'danger' : 'primary'}
                    onClick={() => toggleBatchMutation.mutate(batch.id)}
                    disabled={toggleBatchMutation.isPending}
                  >
                    {batch.is_active ? 'Inativar' : 'Ativar'}
                  </Button>
                </div>
              </div>
            )}
          </div>
        ))}
      </div>
    </Card>
  );
}