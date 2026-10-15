import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { get } from '../api/client.js';
import { useAuth } from '../auth/AuthContext.jsx';
import { Icon } from '../components/ui/Icon.jsx';
import { Alert } from '../components/ui/Fields.jsx';
import { Wordmark } from './PublicLayout.jsx';
import { ADMIN_NAV, LECTURER_NAV, STUDENT_NAV } from './nav.js';

function useMenu() {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  useEffect(() => {
    if (!open) return undefined;
    const close = (e) => { if (e.type === 'keydown' ? e.key === 'Escape' : !ref.current?.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', close); document.addEventListener('keydown', close);
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', close); };
  }, [open]);
  return { open, setOpen, ref };
}

/** area: 'student' | 'lecturer' | 'admin' */
export function AppShell({ area }) {
  const { user, can, logout } = useAuth();
  const loc = useLocation();
  const [nav, setNav] = useState(false);
  const menu = useMenu();
  useEffect(() => { setNav(false); }, [loc.pathname]);

  const groups = (area === 'admin' ? ADMIN_NAV : area === 'lecturer' ? LECTURER_NAV : STUDENT_NAV)
    .map((g) => ({ ...g, items: g.items.filter((i) => !i[4] || can(...i[4])) })).filter((g) => g.items.length);
  const unread = useQuery({ queryKey: ['notifications', 'unread'], queryFn: () => get('/notifications/unread-count'), refetchInterval: 60_000, enabled: !!user });
  const count = unread.data?.data?.unread ?? 0;
  const notifTo = area === 'admin' ? '/admin/notifications' : `/${area}/notifications`;
  const others = [['student', '/student', 'Student area', true], ['lecturer', '/lecturer', 'Teaching area', user.roles.includes('lecturer')], ['admin', '/admin', 'Administration', user.roles.includes('admin') || user.roles.includes('finance')]].filter((o) => o[3] && o[0] !== area);

  return (
    <div className="shell">
      <a className="skip-link" href="#content">Skip to main content</a>
      <aside className={`sidebar on-dark ${nav ? 'open' : ''}`} aria-label="Sidebar">
        <Wordmark light to={`/${area}`} />
        <nav aria-label={`${area} navigation`}>
          {groups.map((g) => (
            <div key={g.group}>
              <div className="nav-group">{g.group}</div>
              {g.items.map(([to, label, icon, end]) => <NavLink key={to} to={to} end={end} className="nav-link"><Icon name={icon} size={18} />{label}</NavLink>)}
            </div>
          ))}
        </nav>
        <div className="sidebar-foot">
          {others.map(([, to, label]) => <div key={to}><Link to={to} style={{ color: '#cfe6d1' }}>Switch to {label}</Link></div>)}
          <div style={{ marginTop: 8 }}><Link to="/" style={{ color: '#cfe6d1' }}>Public website</Link></div>
        </div>
      </aside>
      <div className={`sidebar-scrim ${nav ? 'open' : ''}`} onClick={() => setNav(false)} aria-hidden="true" />
      <div className="shell-main">
        <header className="topbar">
          <button type="button" className="icon-btn sidebar-toggle" aria-label="Open navigation" aria-expanded={nav} onClick={() => setNav(true)}><Icon name="menu" /></button>
          <span className="spacer" />
          <Link className="icon-btn" to={notifTo} aria-label={count ? `Notifications, ${count} unread` : 'Notifications'}><Icon name="bell" />{count > 0 && <span className="dot" aria-hidden="true">{count > 9 ? '9+' : count}</span>}</Link>
          <div style={{ position: 'relative' }} ref={menu.ref}>
            <button type="button" className="btn btn-quiet" aria-haspopup="menu" aria-expanded={menu.open} onClick={() => menu.setOpen((o) => !o)}>
              <Icon name="user" size={18} /><span className="hide-sm" style={{ maxWidth: 160, overflow: 'hidden', textOverflow: 'ellipsis' }}>{user.name}</span><Icon name="chevdown" size={14} />
            </button>
            {menu.open && (
              <div className="menu" role="menu">
                <div style={{ padding: '8px 16px' }} className="small muted">{user.email}</div>
                <Link role="menuitem" to="/account" onClick={() => menu.setOpen(false)}>Profile & security</Link>
                <button role="menuitem" type="button" onClick={logout}>Sign out</button>
              </div>
            )}
          </div>
        </header>
        <main id="content" className="shell-content" tabIndex={-1}>
          {!user.email_verified && <VerifyEmailNotice />}
          <Outlet />
        </main>
      </div>
    </div>
  );
}

function VerifyEmailNotice() {
  const [sent, setSent] = useState(false);
  const { post } = { post: (p) => import('../api/client.js').then((m) => m.post(p)) };
  return (
    <Alert type="warning" title="Verify your email address">
      Some actions, such as enrolling and paying, need a verified email.{' '}
      {sent ? 'Verification email sent.' : <button type="button" className="link-btn" onClick={async () => { await post('/auth/email/resend'); setSent(true); }}>Resend the email</button>}
    </Alert>
  );
}
