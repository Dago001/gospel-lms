import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useAction, useGet } from '../../api/hooks.js';
import { post } from '../../api/client.js';
import { Alert, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Breadcrumbs, ErrorState, Loading, StatusBadge } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { bytes, dateTime } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Grading() {
  const { assignmentId } = useParams();
  useDocumentTitle('Grade assignment');
  const a = useGet(['manage', 'assignment', assignmentId], `/manage/assignments/${assignmentId}`);
  const [status, setStatus] = useState('ungraded'); const [page, setPage] = useState(1);
  const q = useGet(['manage', 'submissions', assignmentId], `/manage/assignments/${assignmentId}/submissions`, { status, page });
  const [open, setOpen] = useState(null);
  if (a.isLoading) return <Loading />;
  if (a.error) return <ErrorState error={a.error} onRetry={a.refetch} />;
  const as = a.data.data;
  return (
    <div>
      <Breadcrumbs items={[{ label: 'Courses', to: '/lecturer/courses' }, { label: 'Course', to: `/lecturer/courses/${as.course_id}?tab=assignments` }, { label: as.title }]} />
      <h1>{as.title}</h1><p className="muted">{as.max_score} marks · pass mark {as.pass_mark ?? 'none'} · weight {as.weight}%</p>
      <div className="filters"><SelectField label="Show" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} options={[{ value: 'ungraded', label: 'Needs grading' }, { value: 'graded', label: 'Graded' }, { value: 'returned', label: 'Returned for revision' }, { value: '', label: 'All' }]} /></div>
      <DataTable caption="Submissions" loading={q.isLoading} error={q.error} rows={q.data?.data} meta={q.data?.meta} onPage={setPage}
        columns={[
          { key: 'student', header: 'Student', render: (s) => s.student.name }, { key: 'attempt', header: 'Attempt', numeric: true },
          { key: 'submitted_at', header: 'Submitted', render: (s) => <>{dateTime(s.submitted_at)}{s.is_late && <span className="badge badge-warning" style={{ marginLeft: 6 }}>Late</span>}</> },
          { key: 'grade', header: 'Grade', render: (s) => (s.grade ? <>{s.grade.final_score} <StatusBadge status={s.grade.status} /></> : '-') },
          { key: 'a', header: '', render: (s) => <button className="btn btn-secondary btn-sm" onClick={() => setOpen(s.id)}>{s.grade ? 'Review' : 'Grade'}</button> },
        ]} />
      {open && <GradeModal id={open} assignment={as} onClose={() => setOpen(null)} />}
    </div>
  );
}

function GradeModal({ id, assignment, onClose }) {
  const q = useGet(['manage', 'submission', id], `/manage/submissions/${id}`);
  const s = q.data?.data;
  const f = useForm({ score: '', feedback: '', reason: '' });
  const [crit, setCrit] = useState({});
  const [ready, setReady] = useState(false);
  if (s && !ready) { setReady(true); f.setValues({ score: s.grade?.raw_score ?? '', feedback: (s.grade?.feedback || '').replace(/<[^>]+>/g, ''), reason: '' }); setCrit(s.grade?.criterion_scores || {}); }
  const act = useAction(() => Promise.resolve(), { invalidate: [['manage', 'submissions'], ['manage', 'submission']] });
  const save = (publish) => f.handle(async (v) => {
    const body = { feedback: v.feedback || null, publish, reason: v.reason || undefined };
    if (s.rubric?.length) body.criterion_scores = Object.fromEntries(s.rubric.map((c) => [c.id, Number(crit[c.id] ?? 0)])); else body.score = Number(v.score);
    await post(`/manage/submissions/${id}/grade`, body); act.mutate(); if (publish) onClose();
  });
  const ret = useAction(() => post(`/manage/submissions/${id}/return`), { success: 'Returned to the student for revision.', invalidate: [['manage', 'submissions']], onSuccess: onClose });
  const published = s?.grade?.status === 'published';
  return (
    <Modal title={s ? `${s.student.name} · attempt ${s.attempt}` : 'Submission'} size="lg" onClose={onClose}
      footer={s && <>{!published && assignment.allow_resubmission !== false && <button className="btn btn-quiet" onClick={() => ret.mutate()}>Return for revision</button>}<button className="btn btn-secondary" onClick={save(false)} disabled={f.submitting || published}>Save draft</button><button className="btn btn-primary" onClick={save(true)} disabled={f.submitting}>{published ? 'Save amendment' : 'Publish grade'}</button></>}>
      {q.isLoading ? <Loading /> : q.error ? <ErrorState error={q.error} /> : (
        <>
          {s.is_late && <Alert type="warning">Submitted {s.late_minutes} minutes late{assignment.late_policy === 'penalty' ? `; a ${assignment.late_penalty_percent}% penalty will be applied to the final mark.` : '.'}</Alert>}
          {s.text_response && <section><h3>Written response</h3><div className="panel panel-pad" style={{ whiteSpace: 'pre-wrap' }}>{s.text_response}</div></section>}
          {s.files.length > 0 && <section><h3>Files</h3><ul className="list-plain">{s.files.map((x) => <li key={x.id}><a href={`/api/v1/manage/submission-files/${x.id}`} download>{x.name}</a> <span className="muted small">({bytes(x.size_bytes)})</span></li>)}</ul></section>}
          <hr />
          {f.formError && <Alert type="error">{f.formError}</Alert>}
          {published && <Alert type="info" title="This grade is published">Any change is recorded with your reason, the previous mark and the time, and the student is notified.</Alert>}
          {s.rubric?.length ? s.rubric.map((c) => <TextField key={c.id} label={`${c.title} (max ${c.max_points})`} type="number" min="0" max={c.max_points} step="0.5" value={crit[c.id] ?? ''} onChange={(e) => setCrit({ ...crit, [c.id]: e.target.value })} />) : <TextField label={`Mark (out of ${assignment.max_score})`} type="number" min="0" max={assignment.max_score} step="0.5" {...f.bind('score')} />}
          <TextAreaField label="Feedback to the student" rows={4} {...f.bind('feedback')} />
          {published && <TextField label="Reason for the change" required help="Shown in the grade history." {...f.bind('reason')} />}
          {s.grade_history.length > 0 && <section><h3>Grade history</h3><ul className="list-plain small">{s.grade_history.map((h, i) => <li key={i}>{dateTime(h.at)}: {h.previous_score} → {h.new_score}. {h.reason}</li>)}</ul></section>}
        </>
      )}
    </Modal>
  );
}
