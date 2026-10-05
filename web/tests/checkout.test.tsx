import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { CheckoutForm } from '@/features/checkout/CheckoutForm';

/**
 * Checkout PÚBLICO (sem AuthProvider, sem token): clique duplo => 1 requisição (P3) e titulares
 * por ingresso opcionais (decisão 7).
 */
function fill() {
  fireEvent.change(screen.getByLabelText('Nome completo'), { target: { value: 'Fulano de Tal' } });
  fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'fulano@example.com' } });
  fireEvent.change(screen.getByLabelText('CPF'), { target: { value: '52998224725' } });
}

function sentBody() {
  const call = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[0];
  return { url: call[0] as string, init: call[1] as RequestInit, body: JSON.parse(call[1].body as string) };
}

describe('CheckoutForm (público)', () => {
  beforeEach(() => {
    sessionStorage.clear();
    vi.stubGlobal('fetch', vi.fn(async () => ({
      ok: true, status: 201, headers: new Headers(), json: async () => ({ data: { id: 'order-123' } }),
    })));
  });

  const renderForm = () => render(<MemoryRouter><CheckoutForm batchId={1} /></MemoryRouter>);

  it('a rapid double click sends exactly one request', async () => {
    renderForm();
    fill();
    const button = screen.getByRole('button', { name: /comprar/i });
    fireEvent.click(button);
    fireEvent.click(button);

    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
  });

  it('compra sem token, com Idempotency-Key e SEM tickets[] por padrão', async () => {
    renderForm();
    fill();
    fireEvent.click(screen.getByRole('button', { name: /comprar/i }));

    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    const { url, init, body } = sentBody();
    const headers = init.headers as Record<string, string>;
    expect(url).toMatch(/\/orders$/);
    expect(headers['Idempotency-Key']).toBeTruthy();
    expect(headers.Authorization).toBeUndefined();
    expect(body).toEqual({ batch_id: 1, quantity: 1, buyer: { name: 'Fulano de Tal', email: 'fulano@example.com', document: '52998224725' } });
    expect(screen.queryByLabelText('Nome do titular')).toBeNull();
  });

  it('titulares por ingresso aparecem só se o comprador pedir e são enviados', async () => {
    renderForm();
    fill();
    fireEvent.change(screen.getByLabelText('Quantidade de ingressos'), { target: { value: '2' } });
    fireEvent.click(screen.getByLabelText(/no nome de outras pessoas/i));

    await waitFor(() => expect(screen.getAllByLabelText('Nome do titular')).toHaveLength(2));
    const names = screen.getAllByLabelText('Nome do titular');
    const emails = screen.getAllByLabelText('E-mail do titular');
    names.forEach((el, i) => fireEvent.change(el, { target: { value: `Titular ${i + 1}` } }));
    emails.forEach((el, i) => fireEvent.change(el, { target: { value: `t${i + 1}@example.com` } }));
    fireEvent.click(screen.getByRole('button', { name: /comprar/i }));

    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    expect(sentBody().body.tickets).toEqual([
      { name: 'Titular 1', email: 't1@example.com' },
      { name: 'Titular 2', email: 't2@example.com' },
    ]);
  });

  it('com titulares separados, deixar um titular em branco bloqueia o envio', async () => {
    renderForm();
    fill();
    fireEvent.click(screen.getByLabelText(/no nome de outras pessoas/i));
    await screen.findByLabelText('Nome do titular');
    fireEvent.click(screen.getByRole('button', { name: /comprar/i }));

    expect(await screen.findByText('Informe o nome de quem vai usar o ingresso.')).toBeTruthy();
    expect(fetch).not.toHaveBeenCalled();
  });

  it('CPF inválido bloqueia o envio', async () => {
    renderForm();
    fill();
    fireEvent.change(screen.getByLabelText('CPF'), { target: { value: '11111111111' } });
    fireEvent.click(screen.getByRole('button', { name: /comprar/i }));

    expect(await screen.findByText('CPF inválido.')).toBeTruthy();
    expect(fetch).not.toHaveBeenCalled();
  });

  it('lote esgotado (409) mostra mensagem e não repete a chamada', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({
      ok: false, status: 409, headers: new Headers(), json: async () => ({ error: { code: 'sold_out', message: 'x' } }),
    })));
    renderForm();
    fill();
    fireEvent.click(screen.getByRole('button', { name: /comprar/i }));

    expect(await screen.findByText(/lote está esgotado/i)).toBeTruthy();
    expect(fetch).toHaveBeenCalledTimes(1);
  });
});
