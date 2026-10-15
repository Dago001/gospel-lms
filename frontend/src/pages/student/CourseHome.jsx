import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useAction, useGet } from '../../api/hooks.js';
import { get, post } from '../../api/client.js';
import { Alert, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { Breadcrumbs, EmptyState, ErrorState, Loading, ProgressBar, RichText, SectionHeading, StatusBadge } from '../../components/ui/Display.jsx';
import { Tabs } from '../../components/ui/Tabs.jsx';
import { FileInput } from '../../components/ui/FileInput.jsx';
import { Icon } from '../../components/ui/Icon.jsx';
import { Contents } from './LessonPlayer.jsx';
import { bytes, date, dateTime } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';
import { useNavigate } from 'react-router-dom';

const TABS = [['overview', 'Overview'], ['lessons', 'Lessons'], ['assignments', 'Assignments'], ['assessments', 'Assessments'], ['live', 'Live classes'], ['discussions', 'Discussions'], ['announcements', 'Announcements'], ['completion', 'Completion']];

export function CourseHome() {
  const { courseId } = useParams();
  const [sp, setSp] = useSearchParams();
  const tab = sp.get('tab') || 'overview';
  const q = useGet(['learn', 'course', courseId], `/learn/courses/${courseId}`);
  const c = q.data?.data;
  useDocumentTitle(c?.title);
  if (q.isLoading) return <Loading rows={5} />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  return (
    <div>
      <Breadcrumbs items={[{ label: 'My courses', to: '/student/courses' }, { label: c.title }]} />
      <h1>{c.title}</h1>
      <Tabs tabs={TABS.map(([id, label]) => ({ id, label }))} active={tab} onChange={(t) => setSp({ tab: t })}>
        {tab === 'overview' && <Overview course={c} courseId={courseId} />}
        {tab === 'lessons' && <div className="panel"><Contents course={c} courseId={courseId} /></div>}
        {tab === 'assignments' && <Assignments courseId={courseId} />}
        {tab === 'assessments' && <Assessments courseId={courseId} />}
        {tab === 'live' && <Live courseId={courseId} />}
        {tab === 'discussions' && <Discussions courseId={courseId} />}
        {tab === 'announcements' && <Announcements courseId={courseId} />}
        {tab === 'completion' && <Completion courseId={courseId} />}
      </Tabs>
    </div>
  );
}

function Overview({ course, courseId }) {
  const p = course.progress;
  return (
    <div className="grid-2" style={{ gridTemplateColumns: 'minmax(0,1fr) 340px' }}>
      <div>
        {p?.percent != null && <div className="panel panel-pad" style={{ marginBottom: 24 }}>
          <div className="split"><h2 style={{ margin: 0, fontSize: 'var(--fs-sub)' }}>Your progress</h2><strong>{Math.round(p.percent)}%</strong></div>
          <div style={{ margin: '12px 0' }}><ProgressBar value={p.percent} label="Course progress" /></div>
          <p className="small muted">{p.required_completed} of {p.required_total} required lessons complete.</p>
          {p.next_lesson_id && <Link className="btn btn-primary" to={`/student/courses/${courseId}/lessons/${p.next_lesson_id}`}>{p.required_completed ? 'Continue' : 'Start the first lesson'}</Link>}
        </div>}
        {course.description && <RichText html={course.description} />}
        {course.objectives?.length > 0 && <><h2 style={{ marginTop: 24 }}>Objectives</h2><ul>{course.objectives.map((o) => <li key={o}>{o}</li>)}</ul></>}
      </div>
      <aside><div className="panel"><div className="panel-head"><h2>Taught by</h2></div><ul className="list-plain panel-body list-rule">{course.instructors.map((i) => <li key={i.id}>{i.name}{i.headline && <div className="small muted">{i.headline}</div>}</li>)}</ul></div></aside>
    </div>
  );
}

function Assignments({ courseId }) {
  const q = useGet(['learn', 'assignments', courseId], `/learn/courses/${courseId}/assignments`);
  const [open, setOpen] = useState(null);
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  if (!q.data.data.length) return <EmptyState icon="clipboard" title="No assignments yet">Your lecturer has not published any assignments for this course.</EmptyState>;
  return (
    <div className="stack">
      {q.data.data.map((a) => (
        <div key={a.id} className="panel">
          <div className="panel-head"><h2>{a.title}</h2><span className="small muted">{a.due_at ? `Due ${dateTime(a.due_at)}` : 'No deadline'} · {a.max_score} marks</span></div>
          <div className="panel-body">
            <RichText html={a.instructions} />
            {a.late_policy === 'penalty' && <p className="small muted">Late submissions lose {a.late_penalty_percent}%{a.late_cutoff_at ? ` and close ${dateTime(a.late_cutoff_at)}` : ''}.</p>}
            {a.my_submissions.map((s) => (
              <div key={s.id} className="alert alert-info">
                <strong>Attempt {s.attempt} · {s.status === 'graded' ? 'Graded' : s.status === 'returned' ? 'Returned for revision' : 'Submitted, awaiting grade'}{s.is_late ? ' · Late' : ''}</strong>
                <div className="small">Submitted {dateTime(s.submitted_at)}</div>
                {s.files.map((f) => <div key={f.id} className="small"><a href={`/api/v1/learn/submission-files/${f.id}`} download>{f.name}</a> ({bytes(f.size_bytes)})</div>)}
                {s.grade && <div style={{ marginTop: 8 }}><strong>Result: {s.grade.final_score} / {a.max_score}</strong>{s.grade.penalty_percent > 0 && <span className="small"> (after {s.grade.penalty_percent}% late penalty)</span>}{s.grade.feedback && <RichText html={s.grade.feedback} />}</div>}
              </div>
            ))}
            {open === a.id ? <SubmitForm a={a} courseId={courseId} onDone={() => setOpen(null)} /> : (
              <button className="btn btn-primary" onClick={() => setOpen(a.id)}>{a.my_submissions.length ? 'Submit another attempt' : 'Submit work'}</button>
            )}
          </div>
        </div>
      ))}
    </div>
  );
}

function SubmitForm({ a, courseId, onDone }) {
  const [files, setFiles] = useState([]);
  const [fileErr, setFileErr] = useState(null);
  const f = useForm({ text: '' });
  const submit = f.handle(async (v) => {
    const fd = new FormData();
    if (v.text) fd.append('text_response', v.text);
    files.forEach((x) => fd.append('files[]', x));
    await post(`/learn/assignments/${a.id}/submissions`, fd);
    onDone();
  });
  const qc = useAction(() => Promise.resolve(), { invalidate: [['learn', 'assignments', courseId]] });
  return (
    <form onSubmit={async (e) => { await submit(e); qc.mutate(); }} noValidate style={{ marginTop: 16 }}>
      {f.formError && <Alert type="error">{f.formError}</Alert>}
      <TextAreaField label="Written response (optional if you attach a file)" rows={6} {...f.bind('text')} />
      <FileInput label="Attach files" multiple files={files} error={fileErr || f.errors.files?.[0]} onFiles={(fs, e) => { setFiles(fs); setFileErr(e || null); }} maxMb={a.max_file_mb}
        help={`Up to ${a.max_files} files, ${a.max_file_mb} MB each. Accepted: ${a.allowed_extensions.join(', ')}.`} />
      <div className="btn-row"><button className="btn btn-primary" disabled={f.submitting}>{f.submitting ? 'Submitting…' : 'Submit'}</button><button type="button" className="btn btn-secondary" onClick={onDone}>Cancel</button></div>
    </form>
  );
}

function Assessments({ courseId }) {
  const nav = useNavigate();
  const q = useGet(['learn', 'assessments', courseId], `/learn/courses/${courseId}/assessments`);
  const [err, setErr] = useState(null);
  const start = useAction((id) => post(`/learn/assessments/${id}/attempts`), { quietError: true, onSuccess: (r) => nav(`/student/attempts/${r.data.id}`), invalidate: [['learn', 'assessments']] });
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  if (!q.data.data.length) return <EmptyState icon="clipboard" title="No quizzes or exams yet" />;
  return (
    <div className="stack">
      {err && <Alert type="error" title="Cannot start">{err}</Alert>}
      {q.data.data.map((a) => {
        const live = a.attempts.find((t) => t.status === 'in_progress');
        const left = a.max_attempts - a.attempts.length;
        return (
          <div className="panel" key={a.id}>
            <div className="panel-head"><h2>{a.title} <span className="badge badge-neutral" style={{ marginLeft: 8 }}>{a.kind}</span></h2>{a.is_final_exam && <span className="badge badge-warning">Final exam: must pass</span>}</div>
            <div className="panel-body">
              <RichText html={a.instructions} />
              <dl className="kv"><dt>Time allowed</dt><dd>{a.time_limit_minutes ? `${a.time_limit_minutes} minutes` : 'No time limit'}</dd><dt>Pass mark</dt><dd>{a.pass_mark_percent}%</dd><dt>Attempts</dt><dd>{a.attempts.length} of {a.max_attempts} used</dd>{a.closes_at && <><dt>Closes</dt><dd>{dateTime(a.closes_at)}</dd></>}</dl>
              {a.attempts.length > 0 && <ul className="list-plain" style={{ margin: '12px 0' }}>{a.attempts.map((t) => <li key={t.id} className="small">Attempt {t.attempt_no}: <StatusBadge status={t.status} />{t.percent != null && <> {t.percent}% {t.passed ? 'passed' : 'not passed'}</>} <Link to={`/student/attempts/${t.id}`}>{t.status === 'in_progress' ? 'Resume' : 'View'}</Link></li>)}</ul>}
              <button className="btn btn-primary" disabled={!a.is_open || (!live && left <= 0) || start.isPending}
                onClick={() => { setErr(null); start.mutate(a.id, { onError: (e) => setErr(e.message) }); }}>
                {live ? 'Resume attempt' : !a.is_open ? 'Not open' : left <= 0 ? 'No attempts left' : 'Start'}
              </button>
              {a.time_limit_minutes && !live && a.is_open && left > 0 && <p className="small muted" style={{ marginTop: 8 }}>The clock starts when you press Start and cannot be paused.</p>}
            </div>
          </div>
        );
      })}
    </div>
  );
}

function Live({ courseId }) {
  const q = useGet(['learn', 'live', courseId], `/learn/courses/${courseId}/live-sessions`);
  const [err, setErr] = useState(null);
  const join = async (id) => { setErr(null); try { const r = await get(`/learn/live-sessions/${id}/join`); window.open(r.data.join_url, '_blank', 'noopener,noreferrer'); } catch (e) { setErr(e.message); } };
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  if (!q.data.data.length) return <EmptyState icon="calendar" title="No live classes scheduled" />;
  return (
    <div>
      {err && <Alert type="warning">{err}</Alert>}
      <ul className="list-plain panel list-rule" style={{ padding: '0 20px' }}>
        {q.data.data.map((s) => (
          <li key={s.id} className="split">
            <div><h3 style={{ margin: 0 }}>{s.title}</h3><div className="small muted">{dateTime(s.starts_at)} · {s.duration_minutes} min{s.counts_for_attendance ? ' · attendance recorded' : ''}</div>
              {s.held && s.counts_for_attendance && <div className="small">{s.my_attendance ? <StatusBadge status="present" /> : <StatusBadge status="absent" />}</div>}</div>
            <div className="btn-row">{!s.held && <button className="btn btn-primary btn-sm" onClick={() => join(s.id)}><Icon name="video" size={16} /> Join class</button>}{s.recordings > 0 && <RecordingLink id={s.id} />}</div>
          </li>
        ))}
      </ul>
      <p className="small muted" style={{ marginTop: 12 }}>The join button opens about 15 minutes before the start. Use the same email you registered with so your attendance is recorded.</p>
    </div>
  );
}
function RecordingLink({ id }) {
  const [url, setUrl] = useState(null);
  return url ? <a className="btn btn-secondary btn-sm" href={url} target="_blank" rel="noopener noreferrer">Watch recording</a> : <button className="btn btn-secondary btn-sm" onClick={async () => setUrl((await get(`/learn/live-sessions/${id}/recordings`)).data[0]?.play_url)}>Recording</button>;
}

function Discussions({ courseId }) {
  const q = useGet(['learn', 'threads', courseId], `/learn/courses/${courseId}/threads`);
  const [adding, setAdding] = useState(false);
  const f = useForm({ title: '', body: '' });
  const create = useAction(() => post(`/learn/courses/${courseId}/threads`, f.values), { invalidate: [['learn', 'threads']], success: 'Discussion started.', onSuccess: () => { setAdding(false); f.setValues({ title: '', body: '' }); } });
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  return (
    <div>
      <div className="split" style={{ marginBottom: 16 }}><SectionHeading title="Course discussion" />{!adding && <button className="btn btn-primary" onClick={() => setAdding(true)}>New discussion</button>}</div>
      {adding && (
        <form className="panel panel-pad" style={{ marginBottom: 16 }} onSubmit={(e) => { e.preventDefault(); create.mutate(); }}>
          <TextField label="Title" required {...f.bind('title')} /><TextAreaField label="Your question or thought" required rows={5} {...f.bind('body')} />
          <div className="btn-row"><button className="btn btn-primary" disabled={create.isPending}>Post</button><button type="button" className="btn btn-secondary" onClick={() => setAdding(false)}>Cancel</button></div>
        </form>
      )}
      {q.data.data.length === 0 ? <EmptyState icon="message" title="No discussions yet">Be the first to ask a question.</EmptyState> : (
        <ul className="list-plain panel list-rule" style={{ padding: '0 20px' }}>{q.data.data.map((t) => (
          <li key={t.id} className="split"><div><h3 style={{ margin: 0 }}><Link to={`/student/courses/${courseId}/discussions/${t.id}`}>{t.title}</Link></h3><div className="small muted">{t.author} · {t.replies} repl{t.replies === 1 ? 'y' : 'ies'} · last activity {date(t.last_post_at)}</div></div>
            <div className="btn-row">{t.pinned && <span className="badge badge-info">Pinned</span>}{t.locked && <span className="badge badge-neutral">Locked</span>}</div></li>
        ))}</ul>
      )}
    </div>
  );
}

function Announcements({ courseId }) {
  const q = useGet(['learn', 'announcements', courseId], `/learn/courses/${courseId}/announcements`);
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  if (!q.data.data.length) return <EmptyState icon="bell" title="No announcements" />;
  return <div className="stack">{q.data.data.map((a) => <div className="panel panel-pad" key={a.id}><div className="split"><h3 style={{ margin: 0 }}>{a.title}</h3><span className="small muted">{date(a.publish_at)}</span></div><RichText html={a.body} /><div className="micro muted">{a.author}</div></div>)}</div>;
}

function Completion({ courseId }) {
  const q = useGet(['learn', 'completion', courseId], `/learn/courses/${courseId}/completion`);
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  const d = q.data.data;
  return (
    <div className="stack">
      {d.eligible ? <Alert type="success" title="You have met every requirement">Your certificate is available under <Link to="/student/certificates">Certificates</Link>.</Alert> : (
        <Alert type="warning" title="Not yet complete"><ul style={{ margin: '8px 0 0' }}>{d.reasons.map((r) => <li key={r.code + (r.item || '')}>{r.message}</li>)}</ul></Alert>
      )}
      {d.grade_percent != null && <p>Weighted grade so far: <strong>{d.grade_percent}%</strong> (pass mark {d.course_pass_percent}%).</p>}
      <div className="table-wrap"><table className="table stack-mobile"><caption className="sr-only">Components of your course grade</caption>
        <thead><tr><th scope="col">Component</th><th scope="col">Status</th><th scope="col" className="num">Weight</th><th scope="col" className="num">Result</th></tr></thead>
        <tbody>{d.components.map((c, i) => (
          <tr key={i}>
            <td data-label="Component">{c.type === 'lessons' ? 'Required lessons' : c.type === 'attendance' ? 'Live class attendance' : c.title}{c.final_exam && <span className="badge badge-warning" style={{ marginLeft: 8 }}>Final exam</span>}</td>
            <td data-label="Status">{c.type === 'lessons' ? `${c.completed} of ${c.required}` : c.type === 'attendance' ? `${c.attended} of ${c.held} sessions` : <StatusBadge status={c.status} />}</td>
            <td className="num" data-label="Weight">{c.weight ? `${c.weight}%` : '-'}</td>
            <td className="num" data-label="Result">{c.percent != null ? `${c.percent}%` : '-'}</td>
          </tr>))}</tbody></table></div>
    </div>
  );
}
