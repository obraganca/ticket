import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from '@/App';
import { RequireAdmin } from '@/features/auth/RequireAdmin';
import { LoginPage } from '@/features/auth/LoginPage';
import { GatewaySimulatorPage } from '@/features/gateway/GatewaySimulatorPage';

const auth = vi.hoisted(() => ({ value: { user: null as null | { id: number; name: string; email: string; role: string }, token: null as string | null, isLoading: false, login: vi.fn(), logout: vi.fn() } }));
vi.mock('@/context/AuthContext', () => ({ useAuth: () => auth.value, AuthProvider: ({ children }: { children: React.ReactNode }) => children }));
vi.mock('../../context/AuthContext', () => ({ useAuth: () => auth.value }));

const withQuery = (ui: React.ReactElement) => (
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>{ui}</QueryClientProvider>
);

const admin = { id: 1, name: 'Admin', email: 'admin@codificar.dev', role: 'admin' };
const asGuest = () => Object.assign(auth.value, { user: null, token: null, isLoading: false });
const asAdmin = () => Object.assign(auth.value, { user: admin, token: 'tok', isLoading: false });

describe('área administrativa', () => {
  beforeEach(() => { asGuest(); auth.value.login.mockReset(); vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, headers: new Headers(), json: async () => ({ data: [] }) }))); });

  it('visitante é mandado para /login ao abrir uma rota admin', () => {
    render(
      <MemoryRouter initialEntries={['/admin/gateway']}>
        <Routes>
          <Route path="/login" element={<div>tela de login</div>} />
          <Route path="/admin/gateway" element={<RequireAdmin><GatewaySimulatorPage /></RequireAdmin>} />
        </Routes>
      </MemoryRouter>,
    );
    expect(screen.getByText('tela de login')).toBeTruthy();
  });

  it('usuário autenticado que NÃO é admin não vê as ferramentas', () => {
    Object.assign(auth.value, { user: { ...admin, role: 'customer' }, token: 't' });
    render(
      <MemoryRouter initialEntries={['/admin/gateway']}>
        <Routes>
          <Route path="/" element={<div>vitrine</div>} />
          <Route path="/admin/gateway" element={<RequireAdmin><GatewaySimulatorPage /></RequireAdmin>} />
        </Routes>
      </MemoryRouter>,
    );
    expect(screen.getByText('vitrine')).toBeTruthy();
  });

  it('o visitante vê a vitrine, sem links de cadastro nem "Meus pedidos"', () => {
    render(withQuery(<MemoryRouter initialEntries={['/']}><App /></MemoryRouter>));
    expect(screen.queryByText(/cadastr/i)).toBeNull();
    expect(screen.queryByText(/meus pedidos/i)).toBeNull();
    expect(screen.getByText('Área administrativa')).toBeTruthy();
  });

  it('as rotas /register e /meus-pedidos não existem mais', () => {
    for (const path of ['/register', '/meus-pedidos']) {
      const { container, unmount } = render(withQuery(<MemoryRouter initialEntries={[path]}><App /></MemoryRouter>));
      expect(container.querySelector('main')?.textContent).toBe('');
      unmount();
    }
  });

  it('admin vê Painel e Simulador do gateway no menu', () => {
    asAdmin();
    render(withQuery(<MemoryRouter initialEntries={['/admin/gateway']}><App /></MemoryRouter>));
    expect(screen.getByText('Painel')).toBeTruthy();
    expect(screen.getAllByText('Simulador do gateway').length).toBeGreaterThan(0);
  });

  it('login com credenciais erradas mostra erro (422)', async () => {
    const { ApiError } = await import('@/api/client');
    auth.value.login.mockRejectedValue(new ApiError(422, { error: { code: 'validation_failed', message: 'x' } }));
    render(<MemoryRouter><LoginPage /></MemoryRouter>);
    fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'a@a.com' } });
    fireEvent.change(screen.getByLabelText('Senha'), { target: { value: 'errada' } });
    fireEvent.click(screen.getByRole('button', { name: 'Entrar' }));

    expect(await screen.findByText('E-mail ou senha inválidos.')).toBeTruthy();
  });

  it('login não oferece cadastro', () => {
    render(<MemoryRouter><LoginPage /></MemoryRouter>);
    expect(screen.queryByText(/cadastre/i)).toBeNull();
  });
});

describe('simulador do gateway', () => {
  beforeEach(() => asAdmin());

  it('dispara a ação escolhida com o token do admin e mostra o resultado', async () => {
    const fetchMock = vi.fn(async () => ({
      ok: true, status: 200, headers: new Headers(), json: async () => ({ ok: true, result: { status: 200, event_id: 'evt-1' } }),
    }));
    vi.stubGlobal('fetch', fetchMock);
    render(<MemoryRouter><GatewaySimulatorPage /></MemoryRouter>);

    expect((screen.getByRole('button', { name: 'Aprovar' }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.change(screen.getByLabelText('ID do pedido'), { target: { value: 'a2dde811-9f0d-4fc4-8fd6-8fec92299b70' } });
    fireEvent.click(screen.getByRole('button', { name: 'Reenvio por lentidão' }));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toMatch(/\/admin\/dev\/gateway\/retry-on-timeout$/);
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer tok');
    expect(JSON.parse(init.body as string)).toEqual({ order_id: 'a2dde811-9f0d-4fc4-8fd6-8fec92299b70' });
    expect((await screen.findByLabelText('resultado')).textContent).toContain('evt-1');
  });

  it('as seis ações do item 4.2/4.4 estão disponíveis', () => {
    render(<MemoryRouter><GatewaySimulatorPage /></MemoryRouter>);
    for (const label of ['Aprovar', 'Recusar', 'Reembolsar', 'Duplicar (x5)', 'Fora de ordem', 'Reenvio por lentidão']) {
      expect(screen.getByRole('button', { name: label })).toBeTruthy();
    }
  });
});
