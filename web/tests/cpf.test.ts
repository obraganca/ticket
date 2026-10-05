import { describe, it, expect } from 'vitest';
import { isValidCpf, maskCpf } from '@/lib/cpf';

describe('cpf validation', () => {
  it('accepts a valid cpf', () => expect(isValidCpf('529.982.247-25')).toBe(true));
  it('rejects repeated digits', () => expect(isValidCpf('111.111.111-11')).toBe(false));
  it('rejects wrong check digits', () => expect(isValidCpf('529.982.247-26')).toBe(false));
  it('masks progressively', () => expect(maskCpf('52998224725')).toBe('529.982.247-25'));
});
