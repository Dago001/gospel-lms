import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useAction, useGet } from '../../api/hooks.js';
import { put } from '../../api/client.js';
import { Alert, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Breadcrumbs, ErrorState, Loading, RichText, StatusBadge } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { dateTime } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Marking() {
  const { assessmentId } = useParams();
  useDocumentTitle('Mark assessment');
  const a = useGet(['manage', 'assessment', assessmentId], `/manage/assessments/${assessmentId}`);
  const [status, setStatus] = useState('grading');
  const q = useGet(['manage', 'attempts', assessmentId], `/manage/assessments/${assessmentId}/attempts`, { status });
  const [open, setOpen] = useState(null);
  if (a.isLoading) return <Loading />;
  if (a.error) return <ErrorState error={a.error} onRetry={a.refetch} />;
  const as = a.data.data;
  return (
    <div>
      <Breadcrumbs items={[{ label: 'Courses', to: '/lecturer/courses' }, { label: 'Course', to: `/lecturer/courses/${as.course_id}?tab=assessments` }, { label: as.title }]} />
      <h1>{as.title}</h1><p className="muted">Pass mark {as.pass_mark_percent}% · results {as.results_release === 'manual' ? 'released by you' : 'released as soon as marked'}</p>
      <div className="filters"><SelectField label="Show" value={status} onChange={(e) => setStatus(e.target.value)} options={[{ value: 'grading', label: 'Waiting for marking' }, { value: 'graded', label: 'Marked' }, { value: 'in_progress', label: 'In progress' }, { value: '', label: 'All' }]} /></div>
      <DataTable caption="Attempts" loading={q.isLoading} error={q.error} rows={q.data?.data} meta={q.data?.meta}
        columns={[
          { key: 'student', header: 'Student', render: (t) => t.student.name }, { key: 'attempt_no', header: 'Attempt', numeric: true }, { key: 'status', header: 'Status', render: (t) => <StatusBadge status={t.status} /> },
          { key: 'score', header: 'Score', numeric: true, render: (t) => (t.total_score != null ? `${t.total_score} / ${t.max_score} (${t.percent}%)` : '-') },
          { key: 'passed', header: 'Outcome', render: (t) => (t.passed == null ? '-' : <StatusBadge status={t.passed ? 'success' : 'failed'} label={t.passed ? 'Passed' : 'Not passed'} />) },
          { key: 'submitted_at', header: 'Submitted', render: (t) => dateTime(t.submitted_at) + (t.auto_submitted ? ' (time ran out)' : '') },
          { key: 'a', header: '', render: (t) => t.status !== 'in_progress' && <button className="btn btn-secondary btn-sm" onClick={() => setOpen(t.id)}>{t.status === 'grading' ? 'Mark' : 'Review'}</button> },
        ]} />
      {open && <AttemptModal id={open} onClose={() => setOpen(null)} />}
    </div>
  );
}

function AttemptModal({ id, onClose }) {
  const q = useGet(['manage', 'attempt', id], `/manage/attempts/${id}`);
  const [edit, setEdit] = useState({});          // question_id -> { points, feedback, reason }
  const mark = useAction(({ qid, ...b }) => put(`/manage/attempts/${id}/responses/${qid}/mark`, b), { invalidate: [['manage', 'attempt'], ['manage', 'attempts']], success: 'Saved.', quietError: true });
  const [err, setErr] = useState({});
  const d = q.data?.data;
  return (
    <Modal title={d ? `${d.student.name}: ${d.status === 'grading' ? 'marking' : 'result'}` : 'Attempt'} size="lg" onClose={onClose}>
      {q.isLoading ? <Loading /> : q.error ? <ErrorState error={q.error} /> : (
        <>
          <Alert type={d.status === 'graded' ? 'info' : 'warning'}>{d.status === 'graded' ? `Total ${d.total_score} / ${d.max_score} (${d.percent}%). ${d.results_published ? 'Released to the student; changes need a reason and are audited.' : 'Not released yet.'}` : 'Mark the essay answers below. The total is worked out when every essay has a mark.'}</Alert>
          {d.questions.map((x, i) => {
            const e = edit[x.question_id] || {};
            const subjective = x.type === 'essay' || x.type === 'short';
            return (
              <section key={x.question_id} className="panel panel-pad" style={{ marginBottom: 12 }}>
                <div className="split"><strong>Q{i + 1} · {x.points} marks</strong>{x.is_correct != null && <StatusBadge status={x.is_correct ? 'success' : 'failed'} label={x.is_correct ? 'Correct' : 'Incorrect'} />}</div>
                <RichText html={x.prompt} />
                <p className="small"><strong>Answer:</strong> {x.answer?.text ? <span style={{ whiteSpace: 'pre-wrap' }}>{x.answer.text}</span> : x.answer?.option_ids?.length ? x.options.filter((o) => x.answer.option_ids.includes(o.id)).map((o) => o.label).join('; ') : <em>No answer</em>}</p>
                {x.type === 'short' && <p className="small muted">Accepted: {(x.accepted_answers || []).join('; ')}</p>}
                <p className="small muted">Auto mark: {x.auto_points ?? '-'}{x.manual_points != null ? ` · Your mark: ${x.manual_points}` : ''}</p>
                {subjective && (
                  <div className="form-grid" style={{ alignItems: 'end' }}>
                    <TextField label={`Your mark (0–${x.points})`} type="number" min="0" max={x.points} step="0.5" value={e.points ?? x.manual_points ?? ''} onChange={(ev) => setEdit({ ...edit, [x.question_id]: { ...e, points: ev.target.value } })} />
                    <TextField label="Feedback" value={e.feedback ?? x.feedback ?? ''} onChange={(ev) => setEdit({ ...edit, [x.question_id]: { ...e, feedback: ev.target.value } })} />
                    {d.results_published && <TextField label="Reason for change" value={e.reason ?? ''} onChange={(ev) => setEdit({ ...edit, [x.question_id]: { ...e, reason: ev.target.value } })} />}
                    <button className="btn btn-primary" style={{ marginBottom: 16 }} disabled={e.points === undefined || e.points === ''} onClick={() => { setErr({}); mark.mutate({ qid: x.question_id, points: Number(e.points), feedback: e.feedback ?? x.feedback ?? null, reason: e.reason }, { onError: (er) => setErr({ [x.question_id]: er.message }) }); }}>Save mark</button>
                  </div>
                )}
                {err[x.question_id] && <Alert type="error">{err[x.question_id]}</Alert>}
              </section>
            );
          })}
        </>
      )}
    </Modal>
  );
}
