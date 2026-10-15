import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { post, put } from '../../api/client.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Alert, SelectField, TextField } from '../../components/ui/Fields.jsx';
import { PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { date } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Enrolments() {
  useDocumentTitle('Enrolments');
  const [f, setF] = useState({ status: '', page: 1 });
  const q = useGet(['admin', 'enrolments'], '/admin/enrolments', f);
  const [modal, setModal] = useState(null);
  return (
    <div>
      <PageHeader title="Enrolments" actions={<button className="btn btn-primary" onClick={() => setModal({ kind: 'add' })}>Enrol a student manually</button>} />
      <div className="filters"><SelectField label="Status" value={f.status} onChange={(e) => setF({ status: e.target.value, page: 1 })} placeholder="Any" options={['active', 'completed', 'suspended', 'cancelled']} /></div>
      <DataTable caption="Enrolments" loading={q.isLoading} error={q.error} onRetry={q.refetch} rows={q.data?.data} meta={q.data?.meta} onPage={(p) => setF({ ...f, page: p })}
        columns={[
          { key: 'student', header: 'Student', render: (e) => <>{e.user.name}<div className="micro muted">{e.user.email}</div></> }, { key: 'course', header: 'Course', render: (e) => e.course.title },
          { key: 'source', header: 'Source' }, { key: 'status', header: 'Status', render: (e) => <StatusBadge status={e.status} /> }, { key: 'enrolled_at', header: 'Enrolled', render: (e) => date(e.enrolled_at) },
          { key: 'a', header: '', render: (e) => <button className="btn btn-secondary btn-sm" onClick={() => setModal({ kind: 'edit', e })}>Change</button> },
        ]} />
      {modal?.kind === 'add' && <Add onClose={() => setModal(null)} />}
      {modal?.kind === 'edit' && <Change e={modal.e} onClose={() => setModal(null)} />}
    </div>
  );
}
function Add({ onClose }) {
  const users = useGet(['admin', 'users', 'students'], '/admin/users', { role: 'student', per_page: 100 });
  const courses = useGet(['public', 'courses', 'all'], '/public/courses', { per_page: 50 });
  const f = useForm({ user_id: '', course_id: '', reason: '' });
  const act = useAction(() => Promise.resolve(), { invalidate: [['admin', 'enrolments']], success: 'Enrolled.' });
  const save = f.handle(async (v) => { await post('/admin/enrolments', v); act.mutate(); onClose(); });
  return (
    <Modal title="Enrol a student manually" onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" form="en" disabled={f.submitting}>Enrol</button></>}>
      <form id="en" onSubmit={save} noValidate>{f.formError && <Alert type="error">{f.formError}</Alert>}
        <SelectField label="Student" required {...f.bind('user_id')} placeholder="Choose…" options={(users.data?.data || []).map((u) => ({ value: u.id, label: `${u.name} (${u.email})` }))} />
        <SelectField label="Course" required {...f.bind('course_id')} placeholder="Choose…" options={(courses.data?.data || []).map((c) => ({ value: c.id, label: c.title }))} />
        <TextField label="Reason" required help="For example “Scholarship”. Recorded in the audit log." {...f.bind('reason')} /></form>
    </Modal>
  );
}
function Change({ e, onClose }) {
  const f = useForm({ status: e.status === 'completed' ? 'active' : e.status, reason: '' });
  const act = useAction(() => put(`/admin/enrolments/${e.id}`, f.values), { invalidate: [['admin', 'enrolments']], success: 'Updated.', onSuccess: onClose });
  return (
    <Modal title={`${e.user.name}: ${e.course.title}`} onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" disabled={f.values.reason.length < 3 || act.isPending} onClick={() => act.mutate()}>Save</button></>}>
      <SelectField label="Status" {...f.bind('status')} options={['active', 'suspended', 'cancelled']} /><TextField label="Reason" required {...f.bind('reason')} />
    </Modal>
  );
}
