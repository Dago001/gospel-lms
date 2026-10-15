import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { post } from '../../api/client.js';
import { useAuth } from '../../auth/AuthContext.jsx';
import { Alert, CheckField, TextField } from '../../components/ui/Fields.jsx';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

function AuthPanel({ title, children, foot }) {
  return <div className="auth-wrap"><div className="panel"><h1 style={{ fontSize: 'var(--fs-section)' }}>{title}</h1>{children}</div>{foot && <p className="small" style={{ textAlign: 'center', marginTop: 16 }}>{foot}</p>}</div>;
}

export function Login() {
  useDocumentTitle('Sign in');
  const { login, challenge } = useAuth();
  const [sp] = useSearchParams();
  const [step, setStep] = useState('credentials');
  const f = useForm({ email: '', password: '', code: '' });
  // On success the auth context holds the user and <GuestOnly> redirects to ?next or the role's dashboard.
  const submit = f.handle(async (v) => {
    if (step === 'credentials') { if ((await login(v.email, v.password)) === 'two_factor_required') setStep('code'); } else { await challenge(v.code); }
  });
  return (
    <AuthPanel title={step === 'code' ? 'Two-step verification' : 'Sign in'} foot={<>New here? <Link to="/register">Create an account</Link></>}>
      {sp.get('reset') && <Alert type="success">Your password was reset. Sign in with the new password.</Alert>}
      <form onSubmit={submit} noValidate>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        {step === 'credentials' ? (
          <>
            <TextField label="Email" type="email" autoComplete="username" required autoFocus {...f.bind('email')} />
            <TextField label="Password" type="password" autoComplete="current-password" required {...f.bind('password')} />
            <p className="small"><Link to="/forgot-password">Forgot your password?</Link></p>
          </>
        ) : (
          <>
            <p>Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
            <TextField label="Code" inputMode="numeric" autoComplete="one-time-code" autoFocus required {...f.bind('code')} />
          </>
        )}
        <button className="btn btn-primary btn-block" disabled={f.submitting}>{f.submitting ? 'Checking…' : step === 'code' ? 'Verify' : 'Sign in'}</button>
      </form>
    </AuthPanel>
  );
}

export function Register() {
  useDocumentTitle('Create account');
  const { register } = useAuth();
  const f = useForm({ name: '', email: '', password: '', password_confirmation: '', accept_terms: false });
  const submit = f.handle(async (v) => { await register(v); });   // <GuestOnly> then redirects to ?next or the dashboard
  return (
    <AuthPanel title="Create your account" foot={<>Already registered? <Link to="/login">Sign in</Link></>}>
      <form onSubmit={submit} noValidate>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        <TextField label="Full name" autoComplete="name" required {...f.bind('name')} help="As you want it to appear on your certificate." />
        <TextField label="Email" type="email" autoComplete="email" required {...f.bind('email')} />
        <TextField label="Password" type="password" autoComplete="new-password" required help="At least 10 characters with upper and lower case letters and a number." {...f.bind('password')} />
        <TextField label="Confirm password" type="password" autoComplete="new-password" required {...f.bind('password_confirmation')} />
        <CheckField error={f.errors.accept_terms?.[0]} {...f.bind('accept_terms', { checkbox: true })}>I agree to the <Link to="/terms" target="_blank">terms</Link> and <Link to="/privacy" target="_blank">privacy policy</Link>.</CheckField>
        <button className="btn btn-primary btn-block" disabled={f.submitting}>{f.submitting ? 'Creating…' : 'Create account'}</button>
      </form>
    </AuthPanel>
  );
}

export function Forgot() {
  useDocumentTitle('Reset password');
  const [sent, setSent] = useState(false);
  const f = useForm({ email: '' });
  const submit = f.handle(async (v) => { await post('/auth/forgot-password', v); setSent(true); });
  return (
    <AuthPanel title="Reset your password" foot={<Link to="/login">Back to sign in</Link>}>
      {sent ? <Alert type="success" title="Check your email">If an account exists for that address, we have sent a link to reset the password. It expires soon.</Alert> : (
        <form onSubmit={submit} noValidate>
          {f.formError && <Alert type="error">{f.formError}</Alert>}
          <TextField label="Email" type="email" autoComplete="email" required autoFocus {...f.bind('email')} />
          <button className="btn btn-primary btn-block" disabled={f.submitting}>Send reset link</button>
        </form>
      )}
    </AuthPanel>
  );
}

export function ResetPassword() {
  useDocumentTitle('Choose a new password');
  const [sp] = useSearchParams();
  const nav = useNavigate();
  const f = useForm({ token: sp.get('token') || '', email: sp.get('email') || '', password: '', password_confirmation: '' });
  const submit = f.handle(async (v) => { await post('/auth/reset-password', v); nav('/login?reset=1', { replace: true }); });
  return (
    <AuthPanel title="Choose a new password">
      <form onSubmit={submit} noValidate>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        <TextField label="Email" type="email" required {...f.bind('email')} />
        <TextField label="New password" type="password" autoComplete="new-password" required {...f.bind('password')} />
        <TextField label="Confirm new password" type="password" autoComplete="new-password" required {...f.bind('password_confirmation')} />
        <button className="btn btn-primary btn-block" disabled={f.submitting}>Reset password</button>
      </form>
    </AuthPanel>
  );
}

export function VerifyEmailResult() {
  useDocumentTitle('Email verification');
  const [sp] = useSearchParams();
  const { refresh } = useAuth();
  const ok = sp.get('status') === 'success';
  useEffect(() => { if (ok) refresh(); }, [ok, refresh]);
  return (
    <AuthPanel title={ok ? 'Email verified' : 'Link not valid'}>
      {ok ? <Alert type="success">Your email address is verified. You can now enrol in courses.</Alert> : <Alert type="error">This verification link is invalid or has expired. Sign in and request a new one.</Alert>}
      <Link className="btn btn-primary btn-block" to={ok ? '/student' : '/login'}>{ok ? 'Go to my dashboard' : 'Sign in'}</Link>
    </AuthPanel>
  );
}
