import { useState, type FormEvent } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { ApiError, errorMessageFor } from '@/api/client';
import { Alert, Button, Card, Field, Input, PageContainer } from '@/components/ui';

/** Login exclusivo da equipe (admin). Compradores não têm conta. */
export function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await login(email, password);
      const from = (location.state as { from?: string } | null)?.from ?? '/admin/dashboard';
      navigate(from, { replace: true });
    } catch (err) {
      if (err instanceof ApiError) {
        setError(
          err.status === 422
            ? 'E-mail ou senha inválidos.'
            : err.status === 429
              ? errorMessageFor('rate_limited')
              : errorMessageFor(err.body?.error?.code ?? ''),
        );
      } else {
        setError('Não foi possível entrar. Tente novamente.');
      }
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <PageContainer width="sm">
      <Card>
        <form onSubmit={onSubmit} className="space-y-4">
          <div className="mb-2 text-center">
            <h1 className="text-xl font-bold text-slate-900">Área administrativa</h1>
            <p className="mt-1 text-sm text-slate-500">Acesso restrito à equipe da produtora.</p>
          </div>

          <Field label="E-mail" id="email">
            <Input id="email" type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />
          </Field>

          <Field label="Senha" id="password">
            <Input id="password" type="password" required autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
          </Field>

          {error && <Alert>{error}</Alert>}

          <Button type="submit" disabled={submitting} className="w-full">
            {submitting ? 'Entrando...' : 'Entrar'}
          </Button>
        </form>
      </Card>
    </PageContainer>
  );
}
