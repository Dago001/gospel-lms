import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { put } from '../../api/client.js';
import { Alert } from '../../components/ui/Fields.jsx';
import { ErrorState, Loading, PageHeader } from '../../components/ui/Display.jsx';
import { titleCase } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Roles() {
  useDocumentTitle('Roles & permissions');
  const q = useGet(['admin', 'roles'], '/admin/roles');
  const [draft, setDraft] = useState({});
  const save = useAction(({ id, permissions }) => put(`/admin/roles/${id}/permissions`, { permissions }), { invalidate: [['admin', 'roles']], success: 'Permissions saved.' });
  if (q.isLoading) return <Loading rows={5} />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  const { roles, permissions } = q.data;
  const groups = [...new Set(permissions.map((p) => p.group))];
  return (
    <div className="stack">
      <PageHeader title="Roles & permissions" subtitle="What each role can do. Changes apply on the person's next request." />
      <Alert type="info">The Administrator role is fixed so the platform can never be locked out. Finance officers should not be given grade or security permissions.</Alert>
      {roles.map((r) => {
        const cur = draft[r.id] ?? r.permissions;
        const dirty = draft[r.id] && JSON.stringify([...draft[r.id]].sort()) !== JSON.stringify([...r.permissions].sort());
        return (
          <section key={r.id} className="panel">
            <div className="panel-head"><div><h2>{r.name}</h2><span className="small muted">{r.description}</span></div>{r.is_privileged && <span className="badge badge-warning">Two-step verification required</span>}</div>
            <div className="panel-body">
              {groups.map((g) => (
                <fieldset key={g} style={{ border: 0, padding: 0, margin: '0 0 12px' }} disabled={r.slug === 'admin'}>
                  <legend className="label">{titleCase(g)}</legend>
                  <div className="form-grid">{permissions.filter((p) => p.group === g).map((p) => (
                    <label key={p.slug} className="check"><input type="checkbox" checked={cur.includes(p.slug)} onChange={(e) => setDraft({ ...draft, [r.id]: e.target.checked ? [...cur, p.slug] : cur.filter((x) => x !== p.slug) })} /><span>{p.name}<span className="micro muted"> ({p.slug})</span></span></label>
                  ))}</div>
                </fieldset>
              ))}
              {r.slug !== 'admin' && <button className="btn btn-primary" disabled={!dirty || save.isPending} onClick={() => save.mutate({ id: r.id, permissions: cur }, { onSuccess: () => setDraft({ ...draft, [r.id]: undefined }) })}>Save {r.name} permissions</button>}
            </div>
          </section>
        );
      })}
    </div>
  );
}
