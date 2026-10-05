/**
 * Gerencia a chave de idempotência de UMA tentativa de compra (P3: clique
 * duplo, reenvio por queda de conexão). Guardada em sessionStorage junto do
 * hash do payload: enquanto o payload não mudar, retries reusam a MESMA
 * chave; se o comprador editar o formulário, uma chave nova é gerada (senão
 * a API responderia 422 idempotency_payload_mismatch).
 *
 * Acesso a sessionStorage sempre em try/catch: em modo privado/preferências
 * restritivas do navegador ele pode lançar exceção, e o checkout não pode
 * quebrar por causa disso (nesse caso, cai para uma chave em memória, válida
 * só durante a sessão do componente).
 */
const STORAGE_KEY = 'checkout:intent';

export type CheckoutIntent = {
  idempotencyKey: string;
  payloadHash: string;
};

async function hashPayload(payload: unknown): Promise<string> {
  const data = new TextEncoder().encode(JSON.stringify(payload));
  const digest = await crypto.subtle.digest('SHA-256', data);
  return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('');
}

function safeGet(): CheckoutIntent | null {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    return raw ? (JSON.parse(raw) as CheckoutIntent) : null;
  } catch {
    return null;
  }
}

function safeSet(intent: CheckoutIntent): void {
  try {
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(intent));
  } catch {
    // sessionStorage indisponível: a chave ainda existe em memória no
    // componente chamador para a duração da sessão do React, só não
    // sobrevive a um reload. Documentado como limitação aceitável.
  }
}

function safeClear(): void {
  try {
    sessionStorage.removeItem(STORAGE_KEY);
  } catch {
    /* noop */
  }
}

/** Retorna a chave a usar para este payload: reusa se for a mesma tentativa, gera nova caso contrário. */
export async function getOrCreateIdempotencyKey(payload: unknown): Promise<string> {
  const hash = await hashPayload(payload);
  const existing = safeGet();

  if (existing && existing.payloadHash === hash) {
    return existing.idempotencyKey;
  }

  const key = crypto.randomUUID();
  safeSet({ idempotencyKey: key, payloadHash: hash });
  return key;
}

export function getPendingIntent(): CheckoutIntent | null {
  return safeGet();
}

export function clearIntent(): void {
  safeClear();
}
