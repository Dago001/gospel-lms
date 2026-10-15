import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { post } from '../../api/client.js';
import { Alert, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { EmptyState, ErrorState, Loading, PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { dateTime } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function SupportPage() {
  useDocumentTitle('Support');
  const q = useGet(['support', 'mine'], '/support/tickets');
  const [open, setOpen] = useState(null);
  const [creating, setCreating] = useState(false);
  return (
    <div>
      <PageHeader title="Support" subtitle="Ask a question or report a problem. We reply here and by email." actions={<button className="btn btn-primary" onClick={() => setCreating(true)}>New request</button>} />
      {q.isLoading ? <Loading /> : q.error ? <ErrorState error={q.error} onRetry={q.refetch} /> : q.data.data.length === 0 ? <EmptyState icon="help" title="No support requests">When you contact support, the conversation is kept here.</EmptyState> : (
        <ul className="list-plain panel list-rule" style={{ padding: '0 20px' }}>{q.data.data.map((t) => (
          <li key={t.id} className="split"><div><h3 style={{ margin: 0 }}><button className="link-btn" onClick={() => setOpen(t.id)}>{t.subject}</button></h3><div className="small muted">{t.number} · {dateTime(t.last_activity_at)}</div></div><StatusBadge status={t.status} /></li>))}</ul>
      )}
      {creating && <NewTicket onClose={() => setCreating(false)} />}
      {open && <TicketThread id={open} onClose={() => setOpen(null)} />}
    </div>
  );
}

function NewTicket({ onClose }) {
  const f = useForm({ subject: '', category: 'other', message: '' });
  const act = useAction(() => Promise.resolve(), { invalidate: [['support']] });
  const submit = f.handle(async (v) => { await post('/support/tickets', v); act.mutate(); onClose(); });
  return (
    <Modal title="New support request" onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" form="new-ticket" disabled={f.submitting}>Send</button></>}>
      <form id="new-ticket" onSubmit={submit}>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        <SelectField label="About" {...f.bind('category')} options={[['payment', 'A payment'], ['course', 'A course'], ['account', 'My account'], ['technical', 'A technical problem'], ['other', 'Something else']].map(([value, label]) => ({ value, label }))} />
        <TextField label="Subject" required {...f.bind('subject')} /><TextAreaField label="What happened?" required rows={6} help="Include any payment reference or course name. Never include your password." {...f.bind('message')} />
      </form>
    </Modal>
  );
}

function TicketThread({ id, onClose }) {
  const q = useGet(['support', 'ticket', id], `/support/tickets/${id}`);
  const [msg, setMsg] = useState('');
  const reply = useAction(() => post(`/support/tickets/${id}/messages`, { message: msg }), { invalidate: [['support']], onSuccess: () => setMsg(''), success: 'Reply sent.' });
  const close = useAction(() => post(`/support/tickets/${id}/close`), { invalidate: [['support']], success: 'Request closed.', onSuccess: onClose });
  const t = q.data?.data;
  return (
    <Modal title={t ? `${t.number}: ${t.subject}` : 'Request'} size="lg" onClose={onClose} footer={t && t.status !== 'closed' && <button className="btn btn-secondary" onClick={() => close.mutate()}>Mark as resolved and close</button>}>
      {q.isLoading ? <Loading /> : q.error ? <ErrorState error={q.error} /> : (
        <>
          <ul className="list-plain stack-sm">{t.messages.map((m) => <li key={m.id} className={`alert ${m.author_type === 'staff' ? 'alert-info' : ''}`} style={m.author_type !== 'staff' ? { background: 'var(--white)', borderColor: 'var(--border)' } : undefined}><strong>{m.author}</strong> <span className="micro muted">{dateTime(m.created_at)}</span><div style={{ whiteSpace: 'pre-wrap' }}>{m.body}</div></li>)}</ul>
          {t.status !== 'closed' && <form onSubmit={(e) => { e.preventDefault(); reply.mutate(); }}><TextAreaField label="Reply" rows={3} value={msg} onChange={(e) => setMsg(e.target.value)} /><button className="btn btn-primary" disabled={!msg.trim() || reply.isPending}>Send reply</button></form>}
        </>
      )}
    </Modal>
  );
}
