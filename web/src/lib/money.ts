export function centsToBRL(cents: number): string {
  return (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

/** Converte texto digitado ("120,50" ou "120.50") para centavos inteiros. */
export function brlToCents(value: string): number {
  const normalized = value.replace(/\./g, '').replace(',', '.').replace(/[^\d.]/g, '');
  return Math.round((parseFloat(normalized || '0') || 0) * 100);
}