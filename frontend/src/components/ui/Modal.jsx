import { useEffect, useId, useRef } from 'react';
import { Icon } from './Icon.jsx';

const FOCUSABLE = 'a[href], button:not([disabled]), textarea, input:not([disabled]), select, [tabindex]:not([tabindex="-1"])';

/** Accessible dialog: focus trap, Esc to close, focus returns to the opener, scroll lock. */
export function Modal({ title, onClose, children, footer, size, dismissible = true }) {
  const ref = useRef(null);
  const titleId = useId();
  useEffect(() => {
    const opener = document.activeElement;
    const node = ref.current;
    const first = node.querySelector('[data-autofocus]') || node.querySelector(FOCUSABLE);
    first?.focus();
    document.body.style.overflow = 'hidden';
    const onKey = (e) => {
      if (e.key === 'Escape' && dismissible) { e.stopPropagation(); onClose?.(); }
      if (e.key !== 'Tab') return;
      const f = [...node.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null);
      if (!f.length) return;
      const a = f[0]; const z = f[f.length - 1];
      if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
    };
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('keydown', onKey); document.body.style.overflow = ''; opener?.focus?.(); };
  }, [onClose, dismissible]);
  return (
    <div className="overlay" onMouseDown={(e) => { if (dismissible && e.target === e.currentTarget) onClose?.(); }}>
      <div className={`modal ${size === 'lg' ? 'modal-lg' : ''}`} role="dialog" aria-modal="true" aria-labelledby={titleId} ref={ref}>
        <div className="modal-head">
          <h2 id={titleId}>{title}</h2>
          {dismissible && <button type="button" className="icon-btn" aria-label="Close dialog" onClick={onClose}><Icon name="x" /></button>}
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="modal-foot">{footer}</div>}
      </div>
    </div>
  );
}

export function ConfirmDialog({ title, message, confirmLabel = 'Confirm', danger, busy, onConfirm, onCancel, children }) {
  return (
    <Modal title={title} onClose={onCancel}
      footer={<>
        <button type="button" className="btn btn-secondary" onClick={onCancel} disabled={busy}>Cancel</button>
        <button type="button" className={`btn ${danger ? 'btn-danger' : 'btn-primary'}`} onClick={onConfirm} disabled={busy} data-autofocus>{busy ? 'Working…' : confirmLabel}</button>
      </>}>
      {message && <p>{message}</p>}
      {children}
    </Modal>
  );
}

export function Drawer({ title, onClose, children }) {
  const ref = useRef(null);
  const titleId = useId();
  useEffect(() => {
    const opener = document.activeElement;
    ref.current?.querySelector(FOCUSABLE)?.focus();
    const onKey = (e) => e.key === 'Escape' && onClose?.();
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('keydown', onKey); opener?.focus?.(); };
  }, [onClose]);
  return (
    <>
      <div className="overlay" style={{ zIndex: 50 }} onMouseDown={onClose} aria-hidden="true" />
      <aside className="drawer" role="dialog" aria-modal="true" aria-labelledby={titleId} ref={ref}>
        <div className="modal-head"><h2 id={titleId}>{title}</h2><button type="button" className="icon-btn" aria-label="Close panel" onClick={onClose}><Icon name="x" /></button></div>
        <div className="modal-body" style={{ flex: 1 }}>{children}</div>
      </aside>
    </>
  );
}
