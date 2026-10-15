import { useId } from 'react';

function Wrap({ id, label, required, help, error, children }) {
  return (
    <div className="field">
      {label && <label className="label" htmlFor={id}>{label}{required && <span className="req" aria-hidden="true"> *</span>}</label>}
      {children}
      {help && !error && <div className="help" id={`${id}-help`}>{help}</div>}
      {error && <div className="error-text" id={`${id}-err`} role="alert">{error}</div>}
    </div>
  );
}
const aria = (id, help, error) => ({ 'aria-invalid': error ? 'true' : undefined, 'aria-describedby': error ? `${id}-err` : help ? `${id}-help` : undefined });

export function TextField({ label, help, error, required, ...p }) {
  const id = useId();
  return <Wrap id={id} {...{ label, help, error, required }}><input id={id} className="input" required={required} {...aria(id, help, error)} {...p} /></Wrap>;
}
export function TextAreaField({ label, help, error, required, rows = 4, ...p }) {
  const id = useId();
  return <Wrap id={id} {...{ label, help, error, required }}><textarea id={id} className="textarea" rows={rows} required={required} {...aria(id, help, error)} {...p} /></Wrap>;
}
export function SelectField({ label, help, error, required, options = [], placeholder, children, ...p }) {
  const id = useId();
  return (
    <Wrap id={id} {...{ label, help, error, required }}>
      <select id={id} className="select" required={required} {...aria(id, help, error)} {...p}>
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {children || options.map((o) => <option key={o.value ?? o} value={o.value ?? o}>{o.label ?? o}</option>)}
      </select>
    </Wrap>
  );
}
export function CheckField({ label, error, children, ...p }) {
  const id = useId();
  return (
    <div className="field">
      <label className="check" htmlFor={id}><input id={id} type="checkbox" {...aria(id, null, error)} {...p} /><span>{label || children}</span></label>
      {error && <div className="error-text" id={`${id}-err`} role="alert">{error}</div>}
    </div>
  );
}
export function Alert({ type = 'info', title, children, ...p }) {
  return <div className={`alert alert-${type}`} role={type === 'error' ? 'alert' : 'status'} {...p}>{title && <strong>{title}</strong>}{children}</div>;
}
