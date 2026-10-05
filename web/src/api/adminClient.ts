import { ApiError, authHeader } from './client';
import type { ApiErrorBody } from './types';

const BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1';

/**
 * O apiFetch padrão sempre serializa o body como JSON. Upload de imagem
 * precisa de multipart/form-data, então isso fica num helper separado.
 */
export async function apiUpload<T>(path: string, formData: FormData, token: string | null): Promise<T> {
  const response = await fetch(`${BASE_URL}${path}`, {
    method: 'POST',
    headers: { ...authHeader(token) }, // sem Content-Type: o browser define o boundary
    body: formData,
  });

  const json = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new ApiError(response.status, json as ApiErrorBody, response.headers.get('X-Request-Id'));
  }

  return json as T;
}