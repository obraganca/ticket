import { useCallback, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { apiFetch, ApiError, errorMessageFor } from '@/api/client';
import type { CreateOrderInput, DataEnvelope, Order } from '@/api/types';
import { clearIntent, getOrCreateIdempotencyKey, getPendingIntent } from '@/lib/idempotency';
import { exponentialBackoffMs } from '@/lib/backoff';


const MAX_ATTEMPTS = 3;
const REQUEST_TIMEOUT_MS = 10000;

export function useCheckout() {
  const navigate = useNavigate();
  const [status, setStatus] = useState<'idle' | 'submitting' | 'error'>('idle');
  const [attempt, setAttempt] = useState(0);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [requestId, setRequestId] = useState<string | null>(null);

  // Guarda SÍNCRONA contra clique duplo: bloqueia a 2ª chamada antes do
  // re-render, porque o estado do React (`status`) é assíncrono e um
  // segundo clique pode acontecer antes do primeiro re-render acontecer.
  const inFlight = useRef(false);

  const submit = useCallback(
    async (payload: CreateOrderInput) => {
      if (inFlight.current) return;
      inFlight.current = true;
      setStatus('submitting');
      setErrorMessage(null);

      const idempotencyKey = await getOrCreateIdempotencyKey(payload);

      for (let i = 0; i < MAX_ATTEMPTS; i++) {
        setAttempt(i + 1);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

        try {
          const { data, response } = await apiFetch<DataEnvelope<Order>>('/orders', { // público: o comprador não tem login
            method: 'POST',
            headers: { 'Idempotency-Key': idempotencyKey },
            body: payload,
            signal: controller.signal,
          });
          clearTimeout(timeout);

          // 200 com Idempotent-Replay é sucesso normal, nunca duplicidade.
          void response.headers.get('Idempotent-Replay');

          clearIntent();
          inFlight.current = false;
          setStatus('idle');
          navigate(`/orders/${data.data.id}`);
          return;
        } catch (err) {
          clearTimeout(timeout);

          if (err instanceof ApiError) {
            // Erros 4xx definitivos: descarta a chave e para de tentar.
            if (err.status === 409 || err.status === 422) {
              clearIntent();
              inFlight.current = false;
              setStatus('error');
              setErrorMessage(errorMessageFor(err.body.error.code));
              setRequestId(err.requestId ?? null);
              return;
            }

            // 429/503: honra Retry-After quando presente, senão backoff exponencial.
            const retryAfterMs = (err.body.error.retry_after ?? 0) * 1000;
            const wait = retryAfterMs || exponentialBackoffMs(i);
            if (i < MAX_ATTEMPTS - 1) {
              await sleep(wait);
              continue;
            }
          } else {
            // Falha de rede/timeout: mantém a MESMA chave e tenta de novo.
            if (i < MAX_ATTEMPTS - 1) {
              await sleep(exponentialBackoffMs(i));
              continue;
            }
          }

          inFlight.current = false;
          setStatus('error');
          setErrorMessage('Não foi possível confirmar sua compra. Tente novamente.');
        }
      }
    },
    [navigate],
  );

  /** Retomada: se a página recarregar com uma tentativa em andamento, reenvia com a mesma chave. */
  const resumeIfPending = useCallback(
    (currentPayload: CreateOrderInput) => {
      const pending = getPendingIntent();
      if (pending) {
        submit(currentPayload);
      }
    },
    [submit],
  );

  return { submit, resumeIfPending, status, attempt, maxAttempts: MAX_ATTEMPTS, errorMessage, requestId };
}

function sleep(ms: number) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
