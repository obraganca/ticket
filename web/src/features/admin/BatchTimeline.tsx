import type { AdminTicketType } from '@/api/adminTypes';
import { centsToBRL } from '@/lib/money';

function statusOf(batch: AdminTicketType['batches'][number]) {
  if (batch.is_active) return { label: 'Em venda', dot: 'bg-emerald-500', text: 'text-emerald-700' };
  if (batch.available <= 0) return { label: 'Esgotado', dot: 'bg-slate-400', text: 'text-slate-500' };
  return { label: 'Inativo', dot: 'bg-amber-500', text: 'text-amber-700' };
}

/** Uma timeline por tipo de ingresso: Pista tem a sua, Camarote tem a dela. */
export function BatchTimeline({ ticketTypes }: { ticketTypes: AdminTicketType[] }) {
  const withBatches = ticketTypes.filter((t) => t.batches.length > 0);
  if (withBatches.length === 0) return null;

  return (
    <div className="space-y-6">
      {withBatches.map((type) => {
        const ordered = [...type.batches].sort((a, b) => a.id - b.id);
        return (
          <div key={type.id}>
            <p className="mb-3 text-sm font-semibold text-slate-700">{type.name}</p>
            <div className="overflow-x-auto pb-2">
              <ol className="flex min-w-max items-start gap-0">
                {ordered.map((batch, index) => {
                  const status = statusOf(batch);
                  const isLast = index === ordered.length - 1;
                  return (
                    <li key={batch.id} className="flex items-start">
                      <div className="flex w-44 flex-col items-center text-center">
                        <span className={`h-3.5 w-3.5 rounded-full ring-4 ring-white ${status.dot}`} />
                        <p className="mt-2 text-sm font-semibold text-slate-900">{batch.name}</p>
                        <p className={`text-xs font-medium ${status.text}`}>{status.label}</p>
                        <p className="mt-1 text-xs text-slate-500">{centsToBRL(batch.price_cents)}</p>
                        <p className="text-xs text-slate-400">{batch.sold}/{batch.total} vendidos</p>
                      </div>
                      {!isLast && <div className="mt-[7px] h-0.5 w-10 flex-shrink-0 bg-slate-200" />}
                    </li>
                  );
                })}
              </ol>
            </div>
          </div>
        );
      })}
    </div>
  );
}