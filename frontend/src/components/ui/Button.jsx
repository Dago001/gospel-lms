import { Link } from 'react-router-dom';

export function Button({ variant = 'primary', size, block, loading, children, className = '', ...p }) {
  const cls = `btn btn-${variant} ${size ? `btn-${size}` : ''} ${block ? 'btn-block' : ''} ${className}`;
  return <button type="button" className={cls} disabled={p.disabled || loading} aria-busy={loading || undefined} {...p}>{loading ? <><span className="spinner" aria-hidden="true" style={{ width: 14, height: 14, borderWidth: 2 }} /> <span>Please wait…</span></> : children}</button>;
}
export function LinkButton({ variant = 'primary', size, block, to, href, children, className = '', ...p }) {
  const cls = `btn btn-${variant} ${size ? `btn-${size}` : ''} ${block ? 'btn-block' : ''} ${className}`;
  return href ? <a className={cls} href={href} {...p}>{children}</a> : <Link className={cls} to={to} {...p}>{children}</Link>;
}
