import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAction, useGet } from '../../api/hooks.js';
import { post, put } from '../../api/client.js';
import { EmptyState, ErrorState, Loading, PageHeader } from '../../components/ui/Display.jsx';
import { Pagination } from '../../components/ui/DataTable.jsx';
import { Tabs } from '../../components/ui/Tabs.jsx';
import { dateTime, titleCase } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Notifications() {
  useDocumentTitle('Notifications');
  const [tab, setTab] = useState('inbox');
  return (
    <div>
      <PageHeader title="Notifications" />
      <Tabs tabs={[{ id: 'inbox', label: 'Inbox' }, { id: 'prefs', label: 'Preferences' }]} active={tab} onChange={setTab}>{tab === 'inbox' ? <Inbox /> : <Prefs />}</Tabs>
    </div>
  );
}

function Inbox() {
  const [page, setPage] = useState(1);
  const q = useGet(['notifications', 'list'], '/notifications', { page });
  const read = useAction((id) => post(`/notifications/${id}/read`), { invalidate: [['notifications']] });
  const all = useAction(() => post('/notifications/read-all'), { invalidate: [['notifications']], success: 'All marked as read.' });
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  if (!q.data.data.length) return <EmptyState icon="bell" title="You are all caught up" />;
  return (
    <div>
      <div className="split" style={{ marginBottom: 12 }}><span className="muted small">{q.data.meta.unread} unread</span><button className="btn btn-secondary btn-sm" disabled={!q.data.meta.unread} onClick={() => all.mutate()}>Mark all as read</button></div>
      <ul className="list-plain panel list-rule" style={{ padding: '0 20px' }}>
        {q.data.data.map((n) => (
          <li key={n.id} className="split" style={{ opacity: n.read ? 0.75 : 1 }}>
            <div><strong>{n.data.title || titleCase(n.data.type)}</strong>{!n.read && <span className="badge badge-info" style={{ marginLeft: 8 }}>New</span>}<div>{n.data.body}</div><div className="micro muted">{dateTime(n.created_at)}</div></div>
            {!n.read && <button className="btn btn-quiet btn-sm" onClick={() => read.mutate(n.id)}>Mark read</button>}
          </li>
        ))}
      </ul>
      <Pagination meta={q.data.meta} onPage={setPage} />
    </div>
  );
}

const LABEL = { grades: 'Results and grades', reminders: 'Class and deadline reminders', announcements: 'Announcements', support: 'Support replies', completion: 'Course completion and certificates', forum: 'Discussion replies' };
function Prefs() {
  const q = useGet(['notifications', 'prefs'], '/notifications/preferences');
  const save = useAction((preferences) => put('/notifications/preferences', { preferences }), { invalidate: [['notifications', 'prefs']], success: 'Preferences saved.' });
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  const set = (type, key, val) => save.mutate(q.data.data.map((p) => ({ type: p.type, email: p.email, in_app: p.in_app, ...(p.type === type ? { [key]: val } : {}) })));
  return (
    <div style={{ maxWidth: 640 }}>
      <div className="table-wrap"><table className="table"><caption className="sr-only">Notification channels</caption><thead><tr><th scope="col">Notify me about</th><th scope="col">Email</th><th scope="col">In the app</th></tr></thead>
        <tbody>{q.data.data.map((p) => (
          <tr key={p.type}><th scope="row" style={{ background: 'transparent', color: 'inherit', fontWeight: 400 }}>{LABEL[p.type] || titleCase(p.type)}</th>
            <td><input type="checkbox" aria-label={`${LABEL[p.type]} by email`} checked={p.email} onChange={(e) => set(p.type, 'email', e.target.checked)} /></td>
            <td><input type="checkbox" aria-label={`${LABEL[p.type]} in the app`} checked={p.in_app} onChange={(e) => set(p.type, 'in_app', e.target.checked)} /></td></tr>))}</tbody></table></div>
      <p className="small muted" style={{ marginTop: 12 }}>Always sent: {q.data.mandatory.join(', ')}.</p>
      <p className="small"><Link to="/account">Back to profile &amp; security</Link></p>
    </div>
  );
}
