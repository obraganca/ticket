import { useState } from 'react';
import { ApiError, errorMessageFor } from '@/api/client';
import { useApiClient } from '@/api/useApiClient';
import { Alert, Button, Card, Field, Input, PageContainer, PageHeader } from '@/components/ui';

type Action = 'approve' | 'decline' | 'refund' | 'duplicate' | 'out-of-order' | 'retry-on-timeout';

const ACTIONS: { action: Action; label: string; help: string }[] = [
  { action: 'approve', label: 'Aprovar', help: 'Envia um aviso "approved" assinado.' },
  { action: 'decline', label: 'Recusar', help: 'Envia "declined" (devolve a reserva).' },
  { action: 'refund', label: 'Reembolsar', help: 'Envia "refunded": devolve estoque e invalida ingressos.' },
  { action: 'duplicate', label: 'Duplicar (x5)', help: '4.2(a): o MESMO aviso 5 vezes em paralelo. Só o 1º tem efeito.' },
  { action: 'out-of-order', label: 'Fora de ordem', help: '4.2(b): "refunded" chega ANTES do "approved" mais antigo.' },
  { action: 'retry-on-timeout', label: 'Reenvio por lentidão', help: '4.2(c): o gateway desiste da resposta e reenvia o mesmo aviso na hora.' },
];

/** Tela auxiliar do item 4.4: simula o gateway chamando o webhook real (apenas admin). */
export function GatewaySimulatorPage() {
  const { request } = useApiClient();
  const [orderId, setOrderId] = useState('');
  const [running, setRunning] = useState<Action | null>(null);
  const [result, setResult] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function run(action: Action) {
    setRunning(action);
    setError(null);
    setResult(null);
    try {
      const { data } = await request<{ ok: boolean; result: unknown }>(`/admin/dev/gateway/${action}`, {
        method: 'POST',
        body: { order_id: orderId.trim(), ...(action === 'duplicate' ? { times: 5 } : {}) },
      });
      setResult(JSON.stringify(data.result, null, 2));
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.status === 422
            ? 'Informe um ID de pedido válido (UUID).'
            : errorMessageFor(err.body?.error?.code ?? '')
          : 'Falha ao chamar a API.',
      );
    } finally {
      setRunning(null);
    }
  }

  return (
    <PageContainer width="lg">
      <PageHeader
        title="Simulador do gateway de pagamento"
        subtitle="Dispara avisos assinados para o webhook real. Cole o ID do pedido (veja em /orders/ID depois da compra)."
      />
      <Card>
        <Field label="ID do pedido" id="order_id">
          <Input id="order_id" placeholder="00000000-0000-0000-0000-000000000000" value={orderId} onChange={(e) => setOrderId(e.target.value)} />
        </Field>

        <div className="mt-5 grid gap-3 sm:grid-cols-2">
          {ACTIONS.map(({ action, label, help }) => (
            <div key={action} className="rounded-lg border border-slate-200 p-3">
              <Button
                variant={action === 'refund' ? 'danger' : 'secondary'}
                className="w-full"
                disabled={!orderId.trim() || running !== null}
                onClick={() => run(action)}
              >
                {running === action ? 'Enviando...' : label}
              </Button>
              <p className="mt-2 text-xs text-slate-500">{help}</p>
            </div>
          ))}
        </div>

        {error && <Alert className="mt-5">{error}</Alert>}
        {result && (
          <pre aria-label="resultado" className="mt-5 overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-emerald-200">{result}</pre>
        )}
      </Card>
    </PageContainer>
  );
}
