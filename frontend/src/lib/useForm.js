import { useCallback, useState } from 'react';
import { ApiError } from '../api/client.js';

/** Small form state helper: values, per-field server errors, and a submit wrapper that maps 422 responses onto fields. */
export function useForm(initial = {}) {
  const [values, setValues] = useState(initial);
  const [errors, setErrors] = useState({});
  const [formError, setFormError] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const set = useCallback((name, value) => setValues((v) => ({ ...v, [name]: value })), []);
  const bind = (name, { checkbox = false } = {}) => ({
    name,
    ...(checkbox ? { checked: !!values[name] } : { value: values[name] ?? '' }),
    onChange: (e) => set(name, checkbox ? e.target.checked : e.target.value),
    error: errors[name]?.[0],
  });
  const handle = (fn) => async (e) => {
    e?.preventDefault?.();
    setSubmitting(true); setErrors({}); setFormError(null);
    try { return await fn(values); } catch (err) {
      if (err instanceof ApiError) { setErrors(err.errors || {}); setFormError(err.status === 422 && err.errors ? 'Please correct the highlighted fields.' : err.message); } else { setFormError('Something went wrong. Please try again.'); }
      return undefined;
    } finally { setSubmitting(false); }
  };
  return { values, set, setValues, bind, errors, setErrors, formError, setFormError, submitting, handle };
}
