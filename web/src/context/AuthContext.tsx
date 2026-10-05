import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { apiFetch, authHeader } from '@/api/client';
import type { AuthPayload, DataEnvelope, User } from '@/api/types';

export type AuthUser = User;

type AuthContextType = {
  user: AuthUser | null;
  token: string | null;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<AuthUser>;
  logout: () => void;
};

const STORAGE_KEY = 'auth_token';

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(() => {
    try {
      return localStorage.getItem(STORAGE_KEY);
    } catch {
      return null;
    }
  });
  const [user, setUser] = useState<AuthUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    if (!token) {
      setUser(null);
      setIsLoading(false);
      return;
    }

    apiFetch<DataEnvelope<User>>('/me', { headers: authHeader(token) })
      .then(({ data }) => setUser(data.data))
      .catch(() => {
        setToken(null);
        setUser(null);
        try { localStorage.removeItem(STORAGE_KEY); } catch { /* noop */ }
      })
      .finally(() => setIsLoading(false));
  }, [token]);

  function persistSession(payload: AuthPayload) {
    try { localStorage.setItem(STORAGE_KEY, payload.token); } catch { /* noop */ }
    setToken(payload.token);
    setUser(payload.user);
  }

  async function login(email: string, password: string) {
    const { data } = await apiFetch<DataEnvelope<AuthPayload>>('/login', {
      method: 'POST',
      body: { email, password },
    });
    persistSession(data.data);
    return data.data.user;
  }

  function logout() {
    const currentToken = token;
    try { localStorage.removeItem(STORAGE_KEY); } catch { /* noop */ }
    setToken(null);
    setUser(null);
    if (currentToken) {
      apiFetch('/logout', { method: 'POST', headers: authHeader(currentToken) }).catch(() => { /* best-effort */ });
    }
  }

  return (
    <AuthContext.Provider value={{ user, token, isLoading, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth precisa estar dentro de <AuthProvider>');
  return ctx;
}