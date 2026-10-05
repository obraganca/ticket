/** Backoff exponencial com jitter, usado no retry do checkout e no polling do painel. */
export function exponentialBackoffMs(attempt: number, baseMs = 500, maxMs = 30000): number {
  const exp = Math.min(maxMs, baseMs * 2 ** attempt);
  const jitter = exp * (0.8 + Math.random() * 0.4); // ±20%
  return Math.round(Math.min(maxMs, jitter));
}

/** Aplica jitter simétrico a um intervalo fixo (usado no polling do painel, item 5.4). */
export function withJitter(baseMs: number, jitterRatio = 0.2): number {
  const delta = baseMs * jitterRatio;
  return Math.round(baseMs + (Math.random() * 2 - 1) * delta);
}
