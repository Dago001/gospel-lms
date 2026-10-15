import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { post, put } from '../../api/client.js';
import { useAuth } from '../../auth/AuthContext.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { CheckField, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { Loading, PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { dateTime } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function SupportDesk() {
  useDocumentTitle('Support desk');
  const [f, setF] = useState({ status: 'open', q: '', assigned: '', page: 1 });
  const q = useGet(['admin', 'tickets'], '/admin/support/tickets', f);
  const [open, setOpen] = useState(null);
  return (
    <div>
      <PageHeader title="Support desk" />
      <div className="filters">
        <TextField label="Search number or subject" type="search" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value, page: 1 })} />
        <SelectField label="Status" value={f.status} onChange={(e) => setF({ ...f, status: e.target.value, page: 1 })} placeholder="Any" options={['open', 'pending', 'resolved', 'closed']} />
        <SelectField label="Assigned" value={f.assigned} onChange={(e) => setF({ ...f, assigned: e.target.value, page: 1 })} placeholder="Anyone" options={[{ value: 'me', label: 'To me' }, { value: 'none', label: 'Unassigned' }]} />
      </div>
      <DataTable caption="Support requests" loading={q.isLoading} error={q.error} onRetry={q.refetch} rows={q.data?.data} meta={q.data?.meta} onPage={(p) => setF({ ...f, page: p })}
        columns={[
          { key: 'number', header: 'No.' }, { key: 'subject', header: 'Subject', render: (t) => <button className="link-btn" onClick={() => setOpen(t.id)}>{t.subject}</button> },
          { key: 'requester', header: 'From', render: (t) => <>{t.requester.name}<div className="micro muted">{t.requester.email}</div></> }, { key: 'category', header: 'Topic' },
          { key: 'priority', header: 'Priority', render: (t) => <StatusBadge status={t.priority} /> }, { key: 'status', header: 'Status', render: (t) => <StatusBadge status={t.status} /> },
          { key: 'last_activity_at', header: 'Last activity', sortable: true, render: (t) => dateTime(t.last_activity_at) },
        ]} />
      {open && <Ticket id={open} onClose={() => setOpen(null)} />}
    </div>
  );
}

function Ticket({ id, onClose }) {
  const { user } = useAuth();
  const q = useGet(['admin', 'ticket', id], `/admin/support/tickets/${id}`);
  const [msg, setMsg] = useState(''); const [internal, setInternal] = useState(false);
  const inv = { invalidate: [['admin', 'ticket'], ['admin', 'tickets']] };
  const reply = useAction(() => post(`/admin/support/tickets/${id}/messages`, { message: msg, internal }), { ...inv, success: internal ? 'Note added.' : 'Reply sent.', onSuccess: () => setMsg('') });
  const update = useAction((b) => put(`/admin/support/tickets/${id}`, b), { ...inv, success: 'Updated.' });
  const t = q.data?.data;
  return (
    <Modal title={t ? `${t.number}: ${t.subject}` : 'Request'} size="lg" onClose={onClose}>
      {q.isLoading ? <Loading /> : (
        <>
          <div className="filters">
            <SelectField label="Status" value={t.status} onChange={(e) => update.mutate({ status: e.target.value })} options={['open', 'pending', 'resolved', 'closed']} />
            <SelectField label="Priority" value={t.priority} onChange={(e) => update.mutate({ priority: e.target.value })} options={['low', 'normal', 'high']} />
            <button className="btn btn-secondary" onClick={() => update.mutate({ assigned_to: t.assigned_to === user.id ? null : user.id })}>{t.assigned_to === user.id ? 'Unassign me' : 'Assign to me'}</button>
          </div>
          <p className="small muted">From {t.requester.name} ({t.requester.email})</p>
          <ul className="list-plain stack-sm">{t.messages.map((m) => <li key={m.id} className="alert" style={{ background: m.internal ? 'var(--warning-surface)' : m.author_type === 'staff' ? 'var(--info-surface)' : 'var(--white)', borderColor: 'var(--border)' }}><strong>{m.author}{m.internal ? ' · internal note' : ''}</strong> <span className="micro muted">{dateTime(m.created_at)}</span><div style={{ whiteSpace: 'pre-wrap' }}>{m.body}</div></li>)}</ul>
          <form onSubmit={(e) => { e.preventDefault(); reply.mutate(); }}><TextAreaField label={internal ? 'Internal note (not sent to the user)' : 'Reply to the user'} rows={4} value={msg} onChange={(e) => setMsg(e.target.value)} /><CheckField checked={internal} onChange={(e) => setInternal(e.target.checked)}>Internal note only</CheckField><button className="btn btn-primary" disabled={!msg.trim() || reply.isPending}>{internal ? 'Add note' : 'Send reply'}</button></form>
        </>
      )}
    </Modal>
  );
}
