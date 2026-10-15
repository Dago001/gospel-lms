import { createContext, useCallback, useContext, useEffect, useMemo } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, authEvents, get, post } from '../api/client.js';

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const qc = useQueryClient();
  const q = useQuery({
    queryKey: ['me'],
    queryFn: async () => (await get('/auth/session')).data,
    staleTime: 60_000, retry: false,
  });
  const user = q.data ?? null;

  useEffect(() => {
    const onExpired = () => { qc.setQueryData(['me'], null); qc.removeQueries({ predicate: (x) => x.queryKey[0] !== 'me' && x.queryKey[0] !== 'public' }); };
    authEvents.addEventListener('expired', onExpired);
    return () => authEvents.removeEventListener('expired', onExpired);
  }, [qc]);

  const afterLogin = useCallback((u) => { qc.removeQueries({ predicate: (x) => x.queryKey[0] !== 'public' }); qc.setQueryData(['me'], u); return u; }, [qc]);
  const login = useCallback(async (email, password) => {
    const r = await post('/auth/login', { email, password });
    if (r.status === 'authenticated') afterLogin(r.user);
    return r.status;
  }, [afterLogin]);
  const challenge = useCallback(async (code) => { const r = await post('/auth/two-factor/challenge', { code }); afterLogin(r.user); }, [afterLogin]);
  const register = useCallback(async (payload) => { const r = await post('/auth/register', payload); afterLogin(r.data); }, [afterLogin]);
  const logout = useCallback(async () => { try { await post('/auth/logout'); } finally { qc.clear(); qc.setQueryData(['me'], null); } }, [qc]);
  const refresh = useCallback(() => qc.invalidateQueries({ queryKey: ['me'] }), [qc]);

  const value = useMemo(() => ({
    user, loading: q.isLoading, login, challenge, register, logout, refresh,
    hasRole: (...r) => !!user && r.some((x) => user.roles.includes(x)),
    can: (...p) => !!user && p.some((x) => user.permissions.includes(x)),
  }), [user, q.isLoading, login, challenge, register, logout, refresh]);
  return <AuthCtx.Provider value={value}>{children}</AuthCtx.Provider>;
}

/** Where a signed-in user lands by default. */
export function homeFor(user) {
  if (!user) return '/login';
  if (user.roles.includes('admin') || user.roles.includes('finance')) return '/admin';
  if (user.roles.includes('lecturer')) return '/lecturer';
  return '/student';
}
