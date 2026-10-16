import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAction, useGet } from '../../api/hooks.js';
import { del, post, put } from '../../api/client.js';
import { useAuth } from '../../auth/AuthContext.jsx';
import { Alert, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { ErrorState, Loading, PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { Tabs } from '../../components/ui/Tabs.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { dateTime } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Account() {
  useDocumentTitle('Profile & security');
  const [tab, setTab] = useState('profile');
  return (
    <div>
      <PageHeader title="Profile & security" />
      <Tabs tabs={[['profile', 'Profile'], ['password', 'Password'], ['twofactor', 'Two-step verification'], ['sessions', 'Signed-in devices'], ['privacy', 'Privacy & data']].map(([id, label]) => ({ id, label }))} active={tab} onChange={setTab}>
        <div style={{ maxWidth: 640 }}>
          {tab === 'profile' && <Profile />}{tab === 'password' && <Password />}{tab === 'twofactor' && <TwoFactor />}{tab === 'sessions' && <Sessions />}{tab === 'privacy' && <Privacy />}
        </div>
      </Tabs>
    </div>
  );
}

function Profile() {
  const { user, refresh } = useAuth();
  const p = user.profile || {};
  const f = useForm({ name: user.name, phone: p.phone || '', bio: p.bio || '', country: p.country || '', timezone: p.timezone || 'Africa/Lagos' });
  const [ok, setOk] = useState(false);
  const submit = f.handle(async (v) => { await put('/account/profile', { ...v, country: v.country || null, phone: v.phone || null, bio: v.bio || null }); await refresh(); setOk(true); });
  const [photoErr, setPhotoErr] = useState(null);
  return (
    <form onSubmit={submit} noValidate>
      {ok && <Alert type="success">Profile saved.</Alert>}{f.formError && <Alert type="error">{f.formError}</Alert>}
      <TextField label="Full name" required help="Used on your certificates." {...f.bind('name')} />
      <TextField label="Email" value={user.email} disabled help="Contact support to change your email address." readOnly />
      <div className="form-grid"><TextField label="Phone" type="tel" {...f.bind('phone')} /><TextField label="Country (2-letter code)" maxLength={2} {...f.bind('country')} /></div>
      <TextAreaField label="About you" rows={4} {...f.bind('bio')} />
      <div className="field"><label className="label" htmlFor="photo">Profile photograph (optional)</label><input id="photo" type="file" accept="image/jpeg,image/png,image/webp" onChange={async (e) => { const fd = new FormData(); fd.append('photo', e.target.files[0]); try { await post('/account/photo', fd); setPhotoErr(null); await refresh(); } catch (er) { setPhotoErr(er.errors?.photo?.[0] || er.message); } }} />{photoErr && <div className="error-text" role="alert">{photoErr}</div>}<div className="help">JPG, PNG or WebP, at least 100×100 and under 2 MB.</div></div>
      <button className="btn btn-primary" disabled={f.submitting}>Save profile</button>
    </form>
  );
}

function Password() {
  const f = useForm({ current_password: '', password: '', password_confirmation: '' });
  const [ok, setOk] = useState(false);
  const submit = f.handle(async (v) => { await put('/account/password', v); f.setValues({ current_password: '', password: '', password_confirmation: '' }); setOk(true); });
  return (
    <form onSubmit={submit} noValidate>
      {ok && <Alert type="success">Password changed. Your other devices were signed out.</Alert>}{f.formError && <Alert type="error">{f.formError}</Alert>}
      <TextField label="Current password" type="password" autoComplete="current-password" required {...f.bind('current_password')} />
      <TextField label="New password" type="password" autoComplete="new-password" required help="At least 8 characters, with upper and lower case letters and a number." {...f.bind('password')} />
      <TextField label="Confirm new password" type="password" autoComplete="new-password" required {...f.bind('password_confirmation')} />
      <button className="btn btn-primary" disabled={f.submitting}>Change password</button>
    </form>
  );
}

function TwoFactor() {
  const { user, refresh } = useAuth();
  const f = useForm({ password: '' });
  const [off, setOff] = useState(false);
  const disable = f.handle(async (v) => { await post('/auth/two-factor/disable', v); await refresh(); setOff(false); });
  if (!user.two_factor_enabled) return <><p>Add a second step at sign-in using an authenticator app.</p><Link className="btn btn-primary" to="/mfa-setup">Set up two-step verification</Link></>;
  return (
    <>
      <Alert type="success" title="Two-step verification is on">You will be asked for a code from your authenticator app each time you sign in.</Alert>
      {user.mfa_required ? <p className="muted small">Your role requires two-step verification, so it cannot be turned off.</p> : <button className="btn btn-secondary" onClick={() => setOff(true)}>Turn off</button>}
      {off && <Modal title="Turn off two-step verification" onClose={() => setOff(false)} footer={<><button className="btn btn-secondary" onClick={() => setOff(false)}>Cancel</button><button className="btn btn-danger" form="off" disabled={f.submitting}>Turn off</button></>}>
        <form id="off" onSubmit={disable}>{f.formError && <Alert type="error">{f.formError}</Alert>}<TextField label="Confirm your password" type="password" required {...f.bind('password')} /></form></Modal>}
    </>
  );
}

function Sessions() {
  const q = useGet(['account', 'sessions'], '/account/sessions');
  const revoke = useAction((id) => del(`/account/sessions/${id}`), { invalidate: [['account', 'sessions']], success: 'Device signed out.' });
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  return (
    <ul className="list-plain panel list-rule" style={{ padding: '0 20px' }}>
      {q.data.data.map((s) => (
        <li key={s.id} className="split"><div><strong>{(s.user_agent || 'Unknown device').slice(0, 70)}</strong>{s.current && <span className="badge badge-success" style={{ marginLeft: 8 }}>This device</span>}<div className="small muted">{s.ip_address} · last active {dateTime(s.last_active_at)}</div></div>
          {!s.current && <button className="btn btn-secondary btn-sm" onClick={() => revoke.mutate(s.id)}>Sign out</button>}</li>
      ))}
    </ul>
  );
}

function Privacy() {
  const q = useGet(['account', 'exports'], '/account/data-exports');
  const consents = useGet(['account', 'consents'], '/account/consents');
  const req = useAction(() => post('/account/data-exports'), { invalidate: [['account', 'exports']], success: 'Your export is being prepared.' });
  return (
    <div className="stack">
      <section><h2>Download my data</h2><p>Get a machine-readable copy of the personal data we hold about you. You can request one per day; the file is kept for 7 days.</p>
        <button className="btn btn-primary" onClick={() => req.mutate()} disabled={req.isPending}>Request my data</button>
        {q.data?.data?.length > 0 && <ul className="list-plain list-rule" style={{ marginTop: 16 }}>{q.data.data.map((e) => <li key={e.id} className="split"><span>{dateTime(e.requested_at)} <StatusBadge status={e.status} /></span>{e.status === 'ready' && <a className="btn btn-secondary btn-sm" href={`/api/v1/account/data-exports/${e.id}/download`} download>Download</a>}</li>)}</ul>}</section>
      <section><h2>Your consents</h2>{consents.data?.data?.length ? <ul className="small">{consents.data.data.map((c) => <li key={c.type + c.granted_at}>{c.type} (version {c.version}) accepted {dateTime(c.granted_at)}</li>)}</ul> : <p className="muted">None recorded.</p>}</section>
      <section><h2>Delete my account</h2><p>To have your personal details removed, <Link to="/support">contact support</Link>. Grades, payment records and certificates that the academy must keep will remain, without your identifying details.</p></section>
    </div>
  );
}
