import { Navigate, Outlet, useLocation, useSearchParams } from 'react-router-dom';
import { Loading } from '../components/ui/Display.jsx';
import { homeFor, useAuth } from './AuthContext.jsx';

/** Only same-site paths are honoured, so a crafted ?next= cannot send users elsewhere. */
export const safeNext = (n) => (n && n.startsWith('/') && !n.startsWith('//') && !n.startsWith('/\\') ? n : null);

/**
 * Client-side routing guard for *navigation only*. Every API call is authorised again on the server;
 * this just avoids showing screens a user cannot use.
 */
export function RequireAuth({ roles, permissions, children }) {
  const { user, loading, hasRole, can } = useAuth();
  const loc = useLocation();
  if (loading) return <div className="container band"><Loading label="Checking your session" /></div>;
  if (!user) return <Navigate to={`/login?next=${encodeURIComponent(loc.pathname + loc.search)}`} replace />;
  if (user.mfa_setup_required && loc.pathname !== '/mfa-setup') return <Navigate to="/mfa-setup" replace />;
  if (roles && !hasRole(...roles)) return <Forbidden />;
  if (permissions && !can(...permissions)) return <Forbidden />;
  return children ?? <Outlet />;
}

export function Forbidden() {
  return (
    <div className="container band">
      <div className="alert alert-error" role="alert"><strong>You do not have access to this page</strong>If you think this is a mistake, contact your administrator.</div>
    </div>
  );
}

export function GuestOnly({ children }) {
  const { user, loading } = useAuth();
  const [sp] = useSearchParams();
  if (loading) return null;
  if (user) return <Navigate to={safeNext(sp.get('next')) || homeFor(user)} replace />;
  return children;
}
