/**
 * Cliente HTTP fino, tipado a partir do contrato (ApiErrorBody vem de
 * `schema.d.ts`, gerado por `npm run gen:api` a partir de
 * `api/docs/openapi.yaml`). Mantido como fetch simples (em vez de
 * `openapi-fetch`) porque os fluxos de checkout/polling precisam de acesso
 * fino a status/headers (Idempotent-Replay, ETag, Retry-After) durante
 * retries — ver `features/checkout/useCheckout.ts`.
 */
import type { ApiErrorBody } from './types';

export type { ApiErrorBody };

const BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1';

export class ApiError extends Error {
  constructor(
    public status: number,
    public body: ApiErrorBody,
    public requestId?: string | null,
  ) {
    super(body?.error?.message ?? 'Erro inesperado.');
  }
}

type RequestOptions = {
  method?: string;
  headers?: Record<string, string>;
  body?: unknown;
  signal?: AbortSignal;
};

export async function apiFetch<T>(path: string, opts: RequestOptions = {}): Promise<{ data: T; response: Response }> {
  const response = await fetch(`${BASE_URL}${path}`, {
    method: opts.method ?? 'GET',
    headers: { 'Content-Type': 'application/json', ...opts.headers },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
    signal: opts.signal,
  });

  if (response.status === 304) {
    return { data: undefined as T, response };
  }

  const json = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new ApiError(response.status, json as ApiErrorBody, response.headers.get('X-Request-Id'));
  }

  return { data: json as T, response };
}

/** Cabeçalho Authorization pronto para uso, ou objeto vazio quando não há token. */
export function authHeader(token: string | null): Record<string, string> {
  return token ? { Authorization: `Bearer ${token}` } : {};
}

/** Mensagens em pt-BR para os códigos de erro do contrato (ver README raiz, seção Contrato). */
export function errorMessageFor(code: string): string {
  const messages: Record<string, string> = {
    sold_out: 'Este lote está esgotado.',
    insufficient_stock: 'Não há ingressos suficientes disponíveis.',
    validation_failed: 'Verifique os dados informados.',
    idempotency_key_required: 'Erro interno ao preparar a compra. Recarregue a página.',
    idempotency_payload_mismatch: 'Os dados da compra mudaram. Tente novamente.',
    rate_limited: 'Muitas tentativas. Aguarde um instante.',
    service_unavailable: 'Serviço temporariamente indisponível. Tentando novamente...',
    order_not_found: 'Pedido não encontrado.',
    not_found: 'Recurso não encontrado.',
    batch_not_found: 'Lote não encontrado.',
    batch_inactive: 'Este lote não está disponível para venda.',
    unauthenticated: 'Sessão expirada. Entre novamente.',
    forbidden: 'Você não tem permissão para acessar esta área.',
    unauthorized: 'Sessão de administrador expirada.',
    internal_error: 'Erro interno. Tente novamente em instantes.',
  };
  return messages[code] ?? 'Ocorreu um erro inesperado.';
}
