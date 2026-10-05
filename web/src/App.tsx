import { Routes, Route, Link, NavLink, Navigate } from 'react-router-dom';
import { EventsPage } from '@/features/catalog/EventsPage';
import { BatchesPage } from '@/features/catalog/BatchesPage';
import { OrderStatusPage } from '@/features/orders/OrderStatusPage';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { LoginPage } from '@/features/auth/LoginPage';
import { GatewaySimulatorPage } from '@/features/gateway/GatewaySimulatorPage';
import { RequireAdmin } from '@/features/auth/RequireAdmin';
import { useAuth } from '@/context/AuthContext';
import { Button } from '@/components/ui';
import { AdminEventsPage } from './features/admin/AdminEventsPage';
import { AdminEventDetailPage } from './features/admin/AdminEventDetailPage';

function NavItem({ to, children }: { to: string; children: React.ReactNode }) {
  return (
    <NavLink
      to={to}
      className={({ isActive }) =>
        `rounded-md px-3 py-2 text-sm font-medium transition-colors ${
          isActive ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
        }`
      }
    >
      {children}
    </NavLink>
  );
}

// Visitante (comprador): sem login. O único acesso restrito é a área da equipe.
function GuestNav() {
  return (
    <>
      <NavItem to="/">Eventos</NavItem>
      <div className="ml-auto">
        <NavItem to="/login">Área administrativa</NavItem>
      </div>
    </>
  );
}

// Equipe autenticada (admin)
function AdminNav({ onLogout }: { onLogout: () => void }) {
  return (
    <>
      <NavItem to="/admin/dashboard">Painel</NavItem>
      <NavItem to="/admin/events">Eventos</NavItem>
      <NavItem to="/admin/gateway">Simulador do gateway</NavItem>
      <div className="ml-auto flex items-center gap-2">
        <span className="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">Admin</span>
        <Button variant="ghost" className="px-3 py-1.5" onClick={onLogout}>
          Sair
        </Button>
      </div>
    </>
  );
}

export function App() {
  const { user, logout } = useAuth();

  const isAdmin = user?.role === 'admin';

  return (
    <div className="min-h-screen bg-slate-50">
      <header className="sticky top-0 z-10 border-b border-slate-200 bg-white/80 backdrop-blur">
        <nav className="mx-auto flex max-w-6xl items-center gap-1 px-4 py-3 sm:px-6">
          <Link to={isAdmin ? '/admin/dashboard' : '/'} className="mr-4 flex items-center gap-2 text-base font-bold text-slate-900">
            <span className="flex h-7 w-7 items-center justify-center rounded-md bg-indigo-600 text-sm text-white">C</span>
            Codificar Ingressos
          </Link>
          {isAdmin ? <AdminNav onLogout={logout} /> : <GuestNav />}
        </nav>
      </header>

      <main>
        <Routes>
          <Route path="/" element={isAdmin ? <Navigate to="/admin/dashboard" replace /> : <EventsPage />} />

          <Route path="/events/:eventId" element={<BatchesPage />} />

          <Route path="/orders/:orderId" element={<OrderStatusPage />} />
          <Route path="/login" element={<LoginPage />} />

          {/* Aba exclusiva de edição do admin */}
          <Route path="/admin/events" element={<RequireAdmin><AdminEventsPage /></RequireAdmin>} />
          <Route path="/admin/events/:eventId" element={<RequireAdmin><AdminEventDetailPage /></RequireAdmin>} />
          <Route path="/admin/dashboard" element={<RequireAdmin><DashboardPage /></RequireAdmin>} />
          <Route path="/admin/gateway" element={<RequireAdmin><GatewaySimulatorPage /></RequireAdmin>} />
        </Routes>
      </main>
    </div>
  );
}