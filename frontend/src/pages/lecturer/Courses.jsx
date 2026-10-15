import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAction, useGet } from '../../api/hooks.js';
import { post } from '../../api/client.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Alert, SelectField, TextField, TextAreaField } from '../../components/ui/Fields.jsx';
import { PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { date } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function LecturerCourses() {
  useDocumentTitle('My courses');
  const [page, setPage] = useState(1); const [status, setStatus] = useState(''); const [q, setQ] = useState('');
  const [creating, setCreating] = useState(false);
  const res = useGet(['lecturer', 'courses'], '/manage/courses', { page, status, q });
  return (
    <div>
      <PageHeader title="Courses" actions={<button className="btn btn-primary" onClick={() => setCreating(true)}>New course</button>} />
      <div className="filters">
        <TextField label="Search" type="search" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
        <SelectField label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} placeholder="Any status" options={['draft', 'pending_review', 'approved', 'published', 'archived']} />
      </div>
      <DataTable caption="Courses" loading={res.isLoading} error={res.error} onRetry={res.refetch} rows={res.data?.data} meta={res.data?.meta} onPage={setPage}
        columns={[
          { key: 'title', header: 'Course', sortable: true, render: (c) => <Link to={`/lecturer/courses/${c.id}`}>{c.title}</Link> },
          { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
          { key: 'readiness_problems', header: 'Ready?', render: (c) => c.status === 'draft' ? (c.readiness_problems.length ? <span className="small muted">{c.readiness_problems.length} to fix</span> : <span className="badge badge-success">Ready to submit</span>) : '-' },
          { key: 'updated_at', header: 'Updated', sortable: true, render: (c) => date(c.updated_at) },
        ]} />
      {creating && <NewCourse onClose={() => setCreating(false)} />}
    </div>
  );
}

function NewCourse({ onClose }) {
  const nav = useNavigate();
  const cats = useGet(['public', 'categories'], '/public/categories');
  const f = useForm({ title: '', summary: '', category_id: '', level: 'beginner' });
  const submit = f.handle(async (v) => { const r = await post('/manage/courses', { ...v, category_id: v.category_id || null }); nav(`/lecturer/courses/${r.data.id}`); });
  return (
    <Modal title="New course" onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" form="nc" disabled={f.submitting}>Create draft</button></>}>
      <form id="nc" onSubmit={submit} noValidate>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        <TextField label="Course title" required {...f.bind('title')} />
        <TextAreaField label="Short summary" rows={2} help="One or two sentences shown in the catalogue." {...f.bind('summary')} />
        <div className="form-grid">
          <SelectField label="Subject" {...f.bind('category_id')} placeholder="Choose…" options={(cats.data?.data || []).map((c) => ({ value: c.id, label: c.name }))} />
          <SelectField label="Level" {...f.bind('level')} options={['beginner', 'intermediate', 'advanced']} />
        </div>
        <p className="small muted">You can add the curriculum, assessments and live classes next. Courses stay private until an administrator approves and publishes them.</p>
      </form>
    </Modal>
  );
}
