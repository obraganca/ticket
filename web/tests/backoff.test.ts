import { describe, it, expect } from 'vitest';
import { exponentialBackoffMs, withJitter } from '@/lib/backoff';

describe('backoff with jitter', () => {
  it('never exceeds the configured max', () => {
    for (let attempt = 0; attempt < 20; attempt++) {
      expect(exponentialBackoffMs(attempt, 500, 5000)).toBeLessThanOrEqual(5000);
    }
  });

  it('grows with the attempt number (on average)', () => {
    expect(exponentialBackoffMs(0, 500, 30000)).toBeLessThan(exponentialBackoffMs(5, 500, 30000) + 1);
  });

  it('jitter stays within +-20% of the base', () => {
    for (let i = 0; i < 50; i++) {
      const jittered = withJitter(3000, 0.2);
      expect(jittered).toBeGreaterThanOrEqual(2400);
      expect(jittered).toBeLessThanOrEqual(3600);
    }
  });
});
