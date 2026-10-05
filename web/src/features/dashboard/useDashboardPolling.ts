// features/dashboard/useDashboardPolling.ts
import { useEffect, useRef, useState } from 'react';
import { withJitter, exponentialBackoffMs } from '@/lib/backoff';
import type { DashboardBatch, DashboardEvent, DashboardSnapshot } from '@/api/types';
import { ApiError } from '@/api/client';
import { useApiClient } from '@/api/useApiClient';

export type { DashboardBatch, DashboardEvent, DashboardSnapshot };
/** @deprecated use DashboardSnapshot (tipo gerado do contrato) */
export type Dashboard = DashboardSnapshot;

export function useDashboardPolling() {
  const { request } = useApiClient();

  const [data, setData] = useState<DashboardSnapshot | null>(null);
  const [lastCheckedAt, setLastCheckedAt] = useState<Date | null>(null);
  const [status, setStatus] = useState<'idle' | 'ok' | 'error' | 'unauthorized'>('idle');
  const etagRef = useRef<string | null>(null);
  const inFlightRef = useRef(false);
  const timerRef = useRef<ReturnType<typeof setTimeout>>();
  const errorStreakRef = useRef(0);

  useEffect(() => {
    let cancelled = false;
    let controller: AbortController | null = null;

    inFlightRef.current = false;
    etagRef.current = null;

    async function poll() {
      if (document.hidden) return scheduleNext(withJitter(3000));
      if (inFlightRef.current) return;
      inFlightRef.current = true;
      controller = new AbortController();

      try {
        const headers: Record<string, string> = {};
        if (etagRef.current) {
          headers['If-None-Match'] = etagRef.current;
        }

        const { data: responseData, response } = await request<DashboardSnapshot>(
          '/admin/dashboard',
          {
            headers,
            signal: controller.signal,
          },
        );

        if (response.status === 304) {
          setLastCheckedAt(new Date());
          setStatus('ok');
          errorStreakRef.current = 0;
          return scheduleNext(withJitter(3000));
        }

        const etag = response.headers.get('ETag');
        if (etag) etagRef.current = etag;

        if (!cancelled) {
          setData(responseData);
          setLastCheckedAt(new Date());
          setStatus('ok');
        }
        errorStreakRef.current = 0;
        scheduleNext(withJitter(3000));
      } catch (err) {
        if (controller?.signal.aborted) return;

        if (err instanceof ApiError && (err.status === 401 || err.status === 403)) {
          setStatus('unauthorized');
          return;
        }

        errorStreakRef.current++;
        setStatus('error');
        scheduleNext(exponentialBackoffMs(errorStreakRef.current, 3000, 30000));
      } finally {
        inFlightRef.current = false;
      }
    }

    function scheduleNext(ms: number) {
      if (!cancelled) timerRef.current = setTimeout(poll, ms);
    }

    poll();
    document.addEventListener('visibilitychange', poll);

    return () => {
      cancelled = true;
      controller?.abort();
      clearTimeout(timerRef.current);
      document.removeEventListener('visibilitychange', poll);
    };
  }, [request]);

  return { data, lastCheckedAt, status };
}