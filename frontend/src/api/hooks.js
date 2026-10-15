import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, get } from './client.js';
import { useToast } from '../components/ui/Toast.jsx';

/** GET helper. key is a stable array; query params are part of the cache key. */
export function useGet(key, path, query, options = {}) {
  return useQuery({ queryKey: [...key, query ?? null], queryFn: () => get(path, query), ...options });
}

/**
 * Mutation helper: toasts success/failure and invalidates cache prefixes.
 * fn receives the variables and returns a promise (use post/put/del from client.js).
 */
export function useAction(fn, { invalidate = [], success, onSuccess, quietError } = {}) {
  const qc = useQueryClient();
  const toast = useToast();
  return useMutation({
    mutationFn: fn,
    onSuccess: (data, vars) => {
      invalidate.forEach((k) => qc.invalidateQueries({ queryKey: Array.isArray(k) ? k : [k] }));
      if (success) toast.success(typeof success === 'function' ? success(data, vars) : success);
      onSuccess?.(data, vars);
    },
    onError: (e) => { if (!quietError) toast.error(e.field?.call ? (e.errors ? Object.values(e.errors)[0]?.[0] || e.message : e.message) : e.message); },
  });
}
export { api };
