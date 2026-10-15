import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import QRCode from 'qrcode';
import { post } from '../../api/client.js';
import { homeFor, useAuth } from '../../auth/AuthContext.jsx';
import { Alert, TextField } from '../../components/ui/Fields.jsx';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

/** Two-step enrolment. Mandatory for administrators and finance officers; optional for everyone else. */
export function MfaSetup() {
  useDocumentTitle('Set up two-step verification');
  const { user, refresh, logout } = useAuth();
  const nav = useNavigate();
  const [setup, setSetup] = useState(null);
  const [qr, setQr] = useState(null);
  const [codes, setCodes] = useState(null);
  const pw = useForm({ password: '' });
  const code = useForm({ code: '' });

  useEffect(() => { if (setup?.otpauth_uri) QRCode.toDataURL(setup.otpauth_uri, { margin: 1, width: 200 }).then(setQr); }, [setup]);
  const start = pw.handle(async (v) => setSetup(await post('/auth/two-factor/setup', v)));
  const confirm = code.handle(async (v) => { const r = await post('/auth/two-factor/confirm', v); setCodes(r.recovery_codes); await refresh(); });

  if (user.two_factor_enabled && !codes) return <div className="auth-wrap"><div className="panel"><h1 style={{ fontSize: 'var(--fs-section)' }}>Two-step verification is on</h1><Link className="btn btn-primary" to={homeFor(user)}>Continue</Link></div></div>;
  return (
    <div className="auth-wrap" style={{ maxWidth: 520 }}>
      <div className="panel">
        <h1 style={{ fontSize: 'var(--fs-section)' }}>Set up two-step verification</h1>
        {user.mfa_setup_required && !codes && <Alert type="warning" title="Required for your role">Administrator and finance accounts must use a second step to protect student and payment records.</Alert>}
        {codes ? (
          <>
            <Alert type="success" title="Two-step verification is on">Save these recovery codes somewhere safe. Each works once if you lose your phone. <strong>They will not be shown again.</strong></Alert>
            <ul className="list-plain" style={{ columnCount: 2, fontFamily: 'ui-monospace, monospace', margin: '16px 0' }}>{codes.map((c) => <li key={c}>{c}</li>)}</ul>
            <div className="btn-row">
              <button className="btn btn-secondary" onClick={() => navigator.clipboard?.writeText(codes.join('\n'))}>Copy codes</button>
              <button className="btn btn-primary" onClick={() => nav(homeFor(user), { replace: true })}>I have saved them</button>
            </div>
          </>
        ) : !setup ? (
          <form onSubmit={start} noValidate>
            <p>Confirm your password to begin. You will need an authenticator app such as Google Authenticator, Microsoft Authenticator or Authy.</p>
            {pw.formError && <Alert type="error">{pw.formError}</Alert>}
            <TextField label="Your password" type="password" autoComplete="current-password" required {...pw.bind('password')} />
            <button className="btn btn-primary" disabled={pw.submitting}>Continue</button>
            {user.mfa_setup_required && <button type="button" className="btn btn-quiet" onClick={logout} style={{ marginLeft: 8 }}>Sign out</button>}
          </form>
        ) : (
          <form onSubmit={confirm} noValidate>
            <ol><li>Scan this code with your authenticator app.</li></ol>
            {qr && <img src={qr} width="200" height="200" alt="QR code for your authenticator app" />}
            <p className="small">Cannot scan? Enter this key manually: <code>{setup.secret}</code></p>
            <ol start="2"><li>Enter the 6-digit code the app shows.</li></ol>
            {code.formError && <Alert type="error">{code.formError}</Alert>}
            <TextField label="6-digit code" inputMode="numeric" autoComplete="one-time-code" required {...code.bind('code')} />
            <button className="btn btn-primary" disabled={code.submitting}>Turn on</button>
          </form>
        )}
      </div>
    </div>
  );
}
