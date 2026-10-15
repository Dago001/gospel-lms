import { useId, useRef } from 'react';

/** WAI-ARIA tabs with arrow-key navigation. tabs: [{ id, label }] */
export function Tabs({ tabs, active, onChange, children }) {
  const base = useId();
  const refs = useRef({});
  const onKey = (e, i) => {
    let n = null;
    if (e.key === 'ArrowRight') n = (i + 1) % tabs.length;
    if (e.key === 'ArrowLeft') n = (i - 1 + tabs.length) % tabs.length;
    if (e.key === 'Home') n = 0;
    if (e.key === 'End') n = tabs.length - 1;
    if (n !== null) { e.preventDefault(); onChange(tabs[n].id); refs.current[tabs[n].id]?.focus(); }
  };
  return (
    <>
      <div className="tabs" role="tablist">
        {tabs.map((t, i) => (
          <button key={t.id} type="button" role="tab" className="tab" id={`${base}-t-${t.id}`} ref={(el) => { refs.current[t.id] = el; }}
            aria-selected={active === t.id} aria-controls={`${base}-p-${t.id}`} tabIndex={active === t.id ? 0 : -1}
            onClick={() => onChange(t.id)} onKeyDown={(e) => onKey(e, i)}>{t.label}</button>
        ))}
      </div>
      <div role="tabpanel" id={`${base}-p-${active}`} aria-labelledby={`${base}-t-${active}`}>{children}</div>
    </>
  );
}
