import { createContext, useCallback, useContext, useMemo, useState } from 'react';

const ToastCtx = createContext(null);
export const useToast = () => useContext(ToastCtx);

export function ToastProvider({ children }) {
  const [items, setItems] = useState([]);
  const dismiss = useCallback((id) => setItems((s) => s.filter((t) => t.id !== id)), []);
  const push = useCallback((message, type = 'info') => {
    const id = crypto.randomUUID();
    setItems((s) => [...s.slice(-3), { id, message, type }]);
    setTimeout(() => dismiss(id), type === 'error' ? 9000 : 5000);
  }, [dismiss]);
  const api = useMemo(() => ({ success: (m) => push(m, 'success'), error: (m) => push(m, 'error'), info: (m) => push(m, 'info') }), [push]);
  return (
    <ToastCtx.Provider value={api}>
      {children}
      {/* polite live region: screen readers announce each message without stealing focus */}
      <div className="toasts" role="status" aria-live="polite" aria-atomic="false">
        {items.map((t) => (
          <div key={t.id} className={`toast ${t.type}`}>
            <span>{t.message}</span>
            <button type="button" aria-label="Dismiss notification" onClick={() => dismiss(t.id)}>×</button>
          </div>
        ))}
      </div>
    </ToastCtx.Provider>
  );
}
