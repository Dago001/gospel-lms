import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useAction, useGet } from '../../api/hooks.js';
import { post, put } from '../../api/client.js';
import { useAuth } from '../../auth/AuthContext.jsx';
import { Alert, CheckField, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { Breadcrumbs, ErrorState, Loading, RichText, StatusBadge } from '../../components/ui/Display.jsx';
import { ConfirmDialog, Modal } from '../../components/ui/Modal.jsx';
import { Tabs } from '../../components/ui/Tabs.jsx';
import { Curriculum } from './CurriculumTab.jsx';
import { AssignmentsTab, AssessmentsTab, BanksTab } from './AssessTabs.jsx';
import { LiveTab } from './LiveTab.jsx';
import { AnnouncementsTab, StudentsTab, AnalyticsTab, VersionsTab } from './PeopleTabs.jsx';
import { useForm } from '../../lib/useForm.js';
import { dateTime } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

const TABS = [['details', 'Details'], ['curriculum', 'Curriculum'], ['assignments', 'Assignments'], ['banks', 'Question banks'], ['assessments', 'Quizzes & exams'], ['live', 'Live classes'], ['announcements', 'Announcements'], ['students', 'Students'], ['analytics', 'Analytics'], ['history', 'Version history']];

export function CourseEditor() {
  const { courseId } = useParams();
  const [sp, setSp] = useSearchParams();
  const tab = sp.get('tab') || 'details';
  const q = useGet(['manage', 'course', courseId], `/manage/courses/${courseId}`);
  const c = q.data?.data;
  useDocumentTitle(c?.title || 'Course');
  if (q.isLoading) return <Loading rows={6} />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  return (
    <div>
      <Breadcrumbs items={[{ label: 'Courses', to: '/lecturer/courses' }, { label: c.title }]} />
      <div className="split" style={{ marginBottom: 16 }}><h1 style={{ margin: 0 }}>{c.title}</h1><StatusBadge status={c.status} /></div>
      <Workflow course={c} />
      <Tabs tabs={TABS.map(([id, label]) => ({ id, label }))} active={tab} onChange={(t) => setSp({ tab: t })}>
        {tab === 'details' && <Details course={c} />}
        {tab === 'curriculum' && <Curriculum course={c} />}
        {tab === 'assignments' && <AssignmentsTab course={c} />}
        {tab === 'banks' && <BanksTab course={c} />}
        {tab === 'assessments' && <AssessmentsTab course={c} />}
        {tab === 'live' && <LiveTab course={c} />}
        {tab === 'announcements' && <AnnouncementsTab course={c} />}
        {tab === 'students' && <StudentsTab course={c} />}
        {tab === 'analytics' && <AnalyticsTab course={c} />}
        {tab === 'history' && <VersionsTab course={c} />}
      </Tabs>
    </div>
  );
}

function Workflow({ course: c }) {
  const { can } = useAuth();
  const [dlg, setDlg] = useState(null);
  const f = useForm({ notes: '', publish_at: '' });
  const inv = [['manage'], ['lecturer'], ['admin']];
  const submit = useAction(() => post(`/manage/courses/${c.id}/submit`), { invalidate: inv, success: 'Submitted for review.', quietError: true });
  const run = useAction(({ path, body }) => post(`/manage/courses/${c.id}/${path}`, body), { invalidate: inv, success: 'Done.', onSuccess: () => setDlg(null) });
  const [err, setErr] = useState(null);
  const approver = can('courses.approve');
  const problems = c.readiness_problems || [];
  return (
    <div className="stack-sm" style={{ marginBottom: 16 }}>
      {c.review_notes && c.status === 'draft' && <Alert type="warning" title="Changes requested by the reviewer">{c.review_notes}</Alert>}
      {c.status === 'draft' && problems.length > 0 && <Alert type="info" title="Before you can submit for review"><ul style={{ margin: '4px 0 0' }}>{problems.map((p) => <li key={p}>{p}</li>)}</ul></Alert>}
      {c.status === 'pending_review' && <Alert type="info">This course is being reviewed. Content is locked until the review is finished.</Alert>}
      {c.status === 'approved' && <Alert type="success">{c.scheduled_publish_at ? `Approved. Scheduled to publish ${dateTime(c.scheduled_publish_at)}.` : 'Approved and ready to publish.'}</Alert>}
      {err && <Alert type="error">{err}</Alert>}
      <div className="btn-row">
        {c.status === 'draft' && <button className="btn btn-primary" disabled={problems.length > 0 || submit.isPending} onClick={() => { setErr(null); submit.mutate(undefined, { onError: (e) => setErr([e.message, ...(e.details || [])].join(' ')) }); }}>Submit for review</button>}
        {approver && c.status === 'pending_review' && <><button className="btn btn-primary" onClick={() => run.mutate({ path: 'approve' })}>Approve</button><button className="btn btn-secondary" onClick={() => setDlg('reject')}>Request changes</button></>}
        {approver && c.status === 'approved' && <><button className="btn btn-primary" onClick={() => run.mutate({ path: 'publish' })}>Publish now</button><button className="btn btn-secondary" onClick={() => setDlg('schedule')}>Schedule…</button></>}
        {approver && ['draft', 'approved', 'published'].includes(c.status) && <button className="btn btn-quiet" onClick={() => setDlg('archive')}>Archive</button>}
        {approver && c.status === 'archived' && <button className="btn btn-secondary" onClick={() => run.mutate({ path: 'restore' })}>Restore as draft</button>}
        {c.status === 'published' && <Link className="btn btn-secondary" to={`/courses/${c.slug}`}>View public page</Link>}
      </div>
      {dlg === 'reject' && <Modal title="Request changes" onClose={() => setDlg(null)} footer={<><button className="btn btn-secondary" onClick={() => setDlg(null)}>Cancel</button><button className="btn btn-primary" disabled={!f.values.notes.trim()} onClick={() => run.mutate({ path: 'reject', body: { notes: f.values.notes } })}>Send back</button></>}><TextAreaField label="What needs to change?" rows={4} {...f.bind('notes')} /></Modal>}
      {dlg === 'schedule' && <Modal title="Schedule publication" onClose={() => setDlg(null)} footer={<><button className="btn btn-secondary" onClick={() => setDlg(null)}>Cancel</button><button className="btn btn-primary" disabled={!f.values.publish_at} onClick={() => run.mutate({ path: 'publish', body: { publish_at: new Date(f.values.publish_at).toISOString() } })}>Schedule</button></>}><TextField label="Publish at (your local time)" type="datetime-local" {...f.bind('publish_at')} /></Modal>}
      {dlg === 'archive' && <ConfirmDialog title="Archive this course?" danger confirmLabel="Archive" message="It disappears from the catalogue. Enrolled students keep their records." onCancel={() => setDlg(null)} onConfirm={() => run.mutate({ path: 'archive' })} busy={run.isPending} />}
    </div>
  );
}

function Details({ course: c }) {
  const { can } = useAuth();
  const cats = useGet(['public', 'categories'], '/public/categories');
  const f = useForm({ title: c.title, summary: c.summary || '', description: c.description || '', objectives: (c.objectives || []).join('\n'), requirements: (c.requirements || []).join('\n'), category_id: c.category_id || '', level: c.level,
    video_completion_percent: c.video_completion_percent, sequential_progression: c.sequential_progression ?? true });
  const [preview, setPreview] = useState(false);
  const [ok, setOk] = useState(false);
  const lines = (s) => s.split('\n').map((x) => x.trim()).filter(Boolean);
  const save = f.handle(async (v) => { await put(`/manage/courses/${c.id}`, { ...v, objectives: lines(v.objectives), requirements: lines(v.requirements), category_id: v.category_id || null, video_completion_percent: Number(v.video_completion_percent) }); setOk(true); });
  const inv = useAction(() => Promise.resolve(), { invalidate: [['manage', 'course']] });
  const editable = ['draft', 'approved', 'published'].includes(c.status);
  return (
    <div className="grid-2" style={{ gridTemplateColumns: 'minmax(0,1fr) 320px' }}>
      <form onSubmit={async (e) => { await save(e); inv.mutate(); }} noValidate>
        {ok && <Alert type="success">Saved. A new version was recorded.</Alert>}{f.formError && <Alert type="error">{f.formError}</Alert>}
        {!editable && <Alert type="info">Editing is locked in this state.</Alert>}
        <fieldset disabled={!editable} style={{ border: 0, padding: 0, margin: 0 }}>
          <TextField label="Title" required {...f.bind('title')} />
          <TextAreaField label="Summary" rows={2} help="Shown in the catalogue (max 500 characters)." {...f.bind('summary')} />
          <div className="field"><div className="split"><label className="label" htmlFor="desc">Description</label><button type="button" className="link-btn small" onClick={() => setPreview(!preview)}>{preview ? 'Edit' : 'Preview'}</button></div>
            {preview ? <div className="panel panel-pad"><RichText html={f.values.description} /></div> : <textarea id="desc" className="textarea" rows={10} {...{ value: f.values.description, onChange: (e) => f.set('description', e.target.value) }} />}
            <div className="help">Plain text or simple HTML (paragraphs, headings, bold, italic, lists, links, tables). Scripts and styles are removed for safety.</div></div>
          <TextAreaField label="Learning objectives" rows={4} help="One per line." {...f.bind('objectives')} />
          <TextAreaField label="Requirements" rows={3} help="One per line." {...f.bind('requirements')} />
          <div className="form-grid">
            <SelectField label="Subject" {...f.bind('category_id')} placeholder="Choose…" options={(cats.data?.data || []).map((x) => ({ value: x.id, label: x.name }))} />
            <SelectField label="Level" {...f.bind('level')} options={['beginner', 'intermediate', 'advanced']} />
            <TextField label="Video completion needed (%)" type="number" min="1" max="100" help="Share of each video that must be genuinely watched. Default 90." {...f.bind('video_completion_percent')} />
          </div>
          <CheckField {...f.bind('sequential_progression', { checkbox: true })}>Lessons unlock in order (students must finish required lessons before the next one)</CheckField>
          <button className="btn btn-primary" disabled={f.submitting || !editable}>Save details</button>
        </fieldset>
      </form>
      <aside className="stack-sm">
        <div className="panel panel-pad"><h2 style={{ fontSize: 'var(--fs-sub)' }}>Pricing</h2>
          <p><strong>{c.price.is_free ? 'Free' : new Intl.NumberFormat('en-NG', { style: 'currency', currency: c.price.currency }).format(c.price.amount_minor / 100)}</strong>{c.price.discount_active && <span className="badge badge-warning" style={{ marginLeft: 8 }}>Discount active</span>}</p>
          {can('courses.manage-all') ? <PriceEditor course={c} /> : <p className="small muted">Pricing is set by an administrator.</p>}</div>
        <div className="panel panel-pad"><h2 style={{ fontSize: 'var(--fs-sub)' }}>Lecturers</h2><ul className="list-plain">{c.instructors.map((i) => <li key={i.id}>{i.name} <span className="muted small">({i.role})</span></li>)}</ul></div>
      </aside>
    </div>
  );
}

function PriceEditor({ course: c }) {
  const [open, setOpen] = useState(false);
  const f = useForm({ price: (c.price.list_minor / 100).toFixed(2), discount: c.price.discount_active ? (c.price.amount_minor / 100).toFixed(2) : '', starts: '', ends: '' });
  const save = useAction(() => put(`/manage/courses/${c.id}/pricing`, {
    price_minor: Math.round(Number(f.values.price) * 100), discount_price_minor: f.values.discount ? Math.round(Number(f.values.discount) * 100) : null,
    discount_starts_at: f.values.starts ? new Date(f.values.starts).toISOString() : null, discount_ends_at: f.values.ends ? new Date(f.values.ends).toISOString() : null,
  }), { invalidate: [['manage', 'course']], success: 'Pricing saved.', onSuccess: () => setOpen(false) });
  return (
    <>
      <button className="btn btn-secondary btn-sm" onClick={() => setOpen(true)}>Change pricing</button>
      {open && <Modal title="Course pricing (NGN)" onClose={() => setOpen(false)} footer={<><button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button><button className="btn btn-primary" disabled={save.isPending} onClick={() => save.mutate()}>Save</button></>}>
        <TextField label="Price (₦)" type="number" min="0" step="0.01" help="0 makes the course free." {...f.bind('price')} />
        <TextField label="Discounted price (₦, optional)" type="number" min="0" step="0.01" {...f.bind('discount')} />
        <div className="form-grid"><TextField label="Discount starts" type="datetime-local" {...f.bind('starts')} /><TextField label="Discount ends" type="datetime-local" {...f.bind('ends')} /></div>
      </Modal>}
    </>
  );
}
