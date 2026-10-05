// features/dashboard/DashboardPage.tsx
import { useDashboardPolling } from './useDashboardPolling';
import { centsToBRL } from '@/lib/money';
import { Alert, Badge, Card, EmptyState, LoadingState, PageContainer, PageHeader } from '@/components/ui';

export function DashboardPage() {
  const { data, lastCheckedAt, status } = useDashboardPolling();

  return (
    <PageContainer width="xl">
      <PageHeader
        title="Painel de vendas"
        subtitle="Atualiza automaticamente a cada ~3s."
        action={
          <div className="flex items-center gap-3">
            <Badge variant={status === 'error' ? 'warning' : 'info'}>
              {status === 'error'
                ? 'reconectando...'
                : lastCheckedAt
                  ? `atualizado há ${Math.round((Date.now() - lastCheckedAt.getTime()) / 1000)}s`
                  : 'carregando...'}
            </Badge>
          </div>
        }
      />

      {status === 'unauthorized' && (
        <Alert variant="danger" className="mb-6">
          Sua sessão expirou ou você não tem permissão para acessar estes dados.
        </Alert>
      )}

      {!data && status !== 'error' && <LoadingState label="Carregando painel..." />}
      {!data && status === 'error' && <Alert variant="danger">Não foi possível carregar o painel. Tentando novamente...</Alert>}

      {data?.events.length === 0 && <EmptyState title="Nenhum evento com vendas registradas ainda" />}

      <div className="space-y-6">
        {data?.events.map((event) => (
          <Card key={event.id}>
            <h2 className="font-semibold text-slate-900">{event.name}</h2>
            <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
              <Stat label="Vendidos" value={event.totals.sold} />
              <Stat label="Aguardando" value={event.totals.pending} />
              <Stat label="Disponíveis" value={event.totals.available} />
              <Stat label="Receita" value={centsToBRL(event.totals.revenue_cents)} />
            </div>
            <div className="mt-4 overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="text-xs uppercase tracking-wide text-slate-400">
                    <th className="pb-2 font-medium">Lote</th>
                    <th className="pb-2 font-medium">Vendidos</th>
                    <th className="pb-2 font-medium">Aguardando</th>
                    <th className="pb-2 font-medium">Disponíveis</th>
                    <th className="pb-2 font-medium">Receita</th>
                  </tr>
                </thead>
                <tbody>
                  {event.batches.map((b) => (
                    <tr key={b.id} className="border-t border-slate-100">
                      <td className="py-2 font-medium text-slate-700">{b.name}</td>
                      <td className="py-2 text-slate-600">{b.sold}</td>
                      <td className="py-2 text-slate-600">{b.pending}</td>
                      <td className="py-2 text-slate-600">{b.available}</td>
                      <td className="py-2 text-slate-600">{centsToBRL(b.revenue_cents)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        ))}

        {data && <JobsPanel jobs={data.jobs} />}
      </div>
    </PageContainer>
  );
}

function JobsPanel({ jobs }: { jobs: { pending: number; running: number; failed: number } }) {
  const hasFailures = jobs.failed > 0;

  return (
    <Card className={hasFailures ? 'border-red-200 bg-red-50/40' : ''}>
      <div className="flex items-center justify-between">
        <h2 className="font-semibold text-slate-900">Fila de jobs (outbox)</h2>
        {hasFailures ? <Badge variant="danger">Requer atenção</Badge> : <Badge variant="success">Saudável</Badge>}
      </div>
      <div className="mt-3 grid grid-cols-3 gap-3">
        <Stat label="Pendentes" value={jobs.pending} />
        <Stat label="Em execução" value={jobs.running} />
        <Stat label="Falhos" value={jobs.failed} tone={hasFailures ? 'danger' : 'default'} />
      </div>
      {hasFailures && (
        <p className="mt-3 text-sm text-red-700">
          {jobs.failed} {jobs.failed === 1 ? 'job falhou' : 'jobs falharam'} — verifique os logs do worker e rode `php artisan outbox:retry --all-failed`.
        </p>
      )}
    </Card>
  );
}

function Stat({ label, value, tone = 'default' }: { label: string; value: string | number; tone?: 'default' | 'danger' }) {
  return (
    <div className={`rounded-lg p-3 text-center ${tone === 'danger' ? 'bg-red-100/60' : 'bg-slate-50'}`}>
      <div className={`text-lg font-semibold ${tone === 'danger' ? 'text-red-700' : 'text-slate-900'}`}>{value}</div>
      <div className="text-xs text-slate-500">{label}</div>
    </div>
  );
}