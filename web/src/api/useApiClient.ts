// api/useApiClient.ts
import { useCallback } from 'react';
import { useAuth } from '@/context/AuthContext';
import { apiFetch, authHeader } from './client';

type RequestOptions = Parameters<typeof apiFetch>[1];

export function useApiClient() {
  const { token } = useAuth();

  const request = useCallback(
    <T,>(path: string, opts: RequestOptions = {}) =>
      apiFetch<T>(path, {
        ...opts,
        headers: { ...authHeader(token), ...opts.headers },
      }),
    [token],
  );

  return { request };
}