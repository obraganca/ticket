import { describe, it, expect, beforeEach } from 'vitest';
import { getOrCreateIdempotencyKey, getPendingIntent, clearIntent } from '@/lib/idempotency';

describe('idempotency key manager', () => {
  beforeEach(() => sessionStorage.clear());

  it('reuses the same key for an identical payload (retry / double click)', async () => {
    const payload = { batch_id: 1, quantity: 1, buyer: { name: 'A', email: 'a@x.com', document: '1' } };
    const key1 = await getOrCreateIdempotencyKey(payload);
    const key2 = await getOrCreateIdempotencyKey(payload);
    expect(key1).toBe(key2);
  });

  it('generates a new key when the payload changes', async () => {
    const key1 = await getOrCreateIdempotencyKey({ batch_id: 1, quantity: 1 });
    const key2 = await getOrCreateIdempotencyKey({ batch_id: 1, quantity: 2 });
    expect(key1).not.toBe(key2);
  });

  it('clearing the intent removes the pending key', async () => {
    await getOrCreateIdempotencyKey({ a: 1 });
    expect(getPendingIntent()).not.toBeNull();
    clearIntent();
    expect(getPendingIntent()).toBeNull();
  });

  it('does not throw when sessionStorage is unavailable', async () => {
    const original = Object.getOwnPropertyDescriptor(window, 'sessionStorage');
    // Simula modo privado/storage bloqueado: acessar sessionStorage lança.
    Object.defineProperty(window, 'sessionStorage', { configurable: true, get() { throw new Error('storage bloqueado'); } });
    try {
      await expect(getOrCreateIdempotencyKey({ a: 1 })).resolves.toBeTypeOf('string');
    } finally {
      if (original) Object.defineProperty(window, 'sessionStorage', original);
    }
  });
});
