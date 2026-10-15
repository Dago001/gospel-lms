import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { useAuth, homeFor } from '../auth/AuthContext.jsx';
import { Icon } from '../components/ui/Icon.jsx';
import { useQuery } from '@tanstack/react-query';
import { get } from '../api/client.js';

export function Wordmark({ to = '/', light }) {
  return (
    <Link to={to} className="wordmark" aria-label="GOSPEL LMS home">
      <svg width="28" height="28" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="4" fill={light ? '#fff' : '#164b1c'} /><path d="M16 6v20M9 12h14" stroke={light ? '#164b1c' : '#fff'} strokeWidth="3" strokeLinecap="square" /><path d="M6 27h20" stroke="#b8954b" strokeWidth="2" /></svg>
      <span>GOSPEL LMS</span>
    </Link>
  );
}

const NAV = [['/courses', 'Courses'], ['/lecturers', 'Lecturers'], ['/pricing', 'Pricing'], ['/about', 'About'], ['/contact', 'Contact']];

export function PublicLayout() {
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);
  const loc = useLocation();
  useEffect(() => { setOpen(false); window.scrollTo(0, 0); }, [loc.pathname]);
  const banner = useQuery({ queryKey: ['public', 'banner'], queryFn: () => get('/public/site'), retry: false, staleTime: 300_000 });
  return (
    <>
      <a className="skip-link" href="#main">Skip to main content</a>
      {banner.data?.data?.maintenance_message && <div className="draft-banner" role="status" style={{ margin: 0, borderRadius: 0 }}>{banner.data.data.maintenance_message}</div>}
      <header className="site-header">
        <div className="site-header-inner">
          <Wordmark />
          <nav className={`site-nav ${open ? 'open' : ''}`} aria-label="Main">
            {NAV.map(([to, label]) => <NavLink key={to} to={to}>{label}</NavLink>)}
            {open && !user && <><NavLink to="/login">Sign in</NavLink><NavLink to="/register">Create account</NavLink></>}
          </nav>
          <div className="site-actions">
            {user ? (
              <><Link className="btn btn-secondary btn-sm hide-sm" to={homeFor(user)}>My dashboard</Link><button type="button" className="btn btn-quiet btn-sm hide-sm" onClick={logout}>Sign out</button></>
            ) : (
              <><Link className="btn btn-quiet btn-sm hide-sm" to="/login">Sign in</Link><Link className="btn btn-primary btn-sm hide-sm" to="/register">Create account</Link></>
            )}
          </div>
          <button type="button" className="icon-btn menu-toggle" aria-expanded={open} aria-label="Menu" onClick={() => setOpen((o) => !o)}><Icon name={open ? 'x' : 'menu'} /></button>
        </div>
      </header>
      <main id="main" className="site-main" tabIndex={-1}><Outlet /></main>
      <Footer />
    </>
  );
}

function Footer() {
  return (
    <footer className="site-footer on-dark">
      <div className="container">
        <div className="footer-grid">
          <div><Wordmark light /><p style={{ marginTop: 12, color: '#bcd2be' }}>An online Christian academy for theology, ministry and discipleship training.</p></div>
          <div><h3>Learn</h3><ul><li><Link to="/courses">Course catalogue</Link></li><li><Link to="/lecturers">Lecturers</Link></li><li><Link to="/pricing">Pricing</Link></li><li><Link to="/verify">Verify a certificate</Link></li></ul></div>
          <div><h3>Help</h3><ul><li><Link to="/faq">Frequently asked questions</Link></li><li><Link to="/support">Support</Link></li><li><Link to="/contact">Contact us</Link></li></ul></div>
          <div><h3>Legal</h3><ul><li><Link to="/terms">Terms and conditions</Link></li><li><Link to="/privacy">Privacy policy</Link></li><li><Link to="/refunds">Refund policy</Link></li></ul></div>
        </div>
        <div className="footer-base"><span>© GOSPEL LMS</span><span>Payments processed by Paystack. We never see or store your card details.</span></div>
      </div>
    </footer>
  );
}
