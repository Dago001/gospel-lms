import { Link } from 'react-router-dom';
import { titleCase } from '../../lib/format.js';
import { Icon } from './Icon.jsx';

const VARIANT = {
  success: ['active', 'published', 'paid', 'success', 'completed', 'graded', 'ready', 'processed', 'valid', 'ok', 'present', 'approved', 'free', 'sent', 'resolved', 'live'],
  warning: ['pending', 'pending_review', 'initialized', 'grading', 'awaiting_results', 'awaiting_grade', 'draft', 'in_progress', 'submitted', 'review', 'returned', 'scheduled', 'processing', 'uploading', 'degraded', 'open', 'partially_refunded', 'suppressed'],
  error: ['failed', 'revoked', 'suspended', 'cancelled', 'rejected', 'mismatch', 'expired', 'absent', 'down', 'anonymised', 'abandoned'],
  info: ['refunded', 'archived', 'closed', 'manual', 'ended', 'normal'],
};
export function StatusBadge({ status, label }) {
  const v = Object.keys(VARIANT).find((k) => VARIANT[k].includes(String(status))) || 'neutral';
  return <span className={`badge badge-${v}`}>{label || titleCase(status)}</span>;
}

export function PageHeader({ title, subtitle, actions, breadcrumbs }) {
  return (
    <div className="page-head">
      {breadcrumbs && <Breadcrumbs items={breadcrumbs} />}
      <div className="split">
        <div><h1>{title}</h1>{subtitle && <p>{subtitle}</p>}</div>
        {actions && <div className="btn-row">{actions}</div>}
      </div>
    </div>
  );
}
export function Breadcrumbs({ items }) {
  return (
    <nav className="breadcrumbs" aria-label="Breadcrumb">
      <ol>{items.map((it, i) => <li key={i}>{it.to && i < items.length - 1 ? <Link to={it.to}>{it.label}</Link> : <span aria-current={i === items.length - 1 ? 'page' : undefined}>{it.label}</span>}</li>)}</ol>
    </nav>
  );
}
export function SectionHeading({ title, action, level = 2 }) {
  const H = `h${level}`;
  return <div className="section-heading"><H>{title}</H>{action}</div>;
}
export function Stats({ items }) {
  return <dl className="stats">{items.map((s) => <div className="stat" key={s.label}><dt>{s.label}</dt><dd>{s.value}{s.hint && <> <small>{s.hint}</small></>}</dd></div>)}</dl>;
}
export function EmptyState({ title, children, action, icon = 'book' }) {
  return <div className="empty"><Icon name={icon} size={32} /><h3>{title}</h3>{children && <p>{children}</p>}{action}</div>;
}
export function Loading({ rows = 3, label = 'Loading' }) {
  return <div role="status" aria-live="polite" aria-label={label}>{Array.from({ length: rows }).map((_, i) => <div key={i} className="skeleton" style={{ height: 18, marginBottom: 12, width: `${90 - i * 12}%` }} />)}<span className="sr-only">{label}…</span></div>;
}
export function ErrorState({ error, onRetry }) {
  const forbidden = error?.status === 403;
  return (
    <div className="alert alert-error" role="alert">
      <strong>{forbidden ? 'You do not have access to this' : 'We could not load this'}</strong>
      {error?.message || 'Please try again.'}
      {onRetry && !forbidden && <div style={{ marginTop: 8 }}><button type="button" className="btn btn-secondary btn-sm" onClick={onRetry}>Try again</button></div>}
    </div>
  );
}
export function ProgressBar({ value, label }) {
  const v = Math.max(0, Math.min(100, Number(value) || 0));
  return <div className="progress" role="progressbar" aria-valuenow={Math.round(v)} aria-valuemin={0} aria-valuemax={100} aria-label={label || 'Progress'}><span style={{ width: `${v}%` }} /></div>;
}
export function RichText({ html, className = '' }) {
  // The API purifies lecturer-authored HTML on write with an allow-list; this only renders that sanitised output.
  return <div className={`rich ${className}`} dangerouslySetInnerHTML={{ __html: html || '' }} />;
}
export function Money({ minor, currency }) {
  return <>{new Intl.NumberFormat('en-NG', { style: 'currency', currency: currency || 'NGN' }).format((minor ?? 0) / 100)}</>;
}
