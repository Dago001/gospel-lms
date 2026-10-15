import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAction, useGet } from '../../api/hooks.js';
import { del, post, put } from '../../api/client.js';
import { Alert, CheckField, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { EmptyState, Loading, SectionHeading, StatusBadge } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { dateTime, titleCase } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';

const iso = (v) => (v ? new Date(v).toISOString() : null);

// ------------------------------------------------------------------ assignments
export function AssignmentsTab({ course }) {
  const q = useGet(['manage', 'assignments', course.id], `/manage/courses/${course.id}/assignments`);
  const rubrics = useGet(['manage', 'rubrics', course.id], `/manage/courses/${course.id}/rubrics`);
  const [open, setOpen] = useState(false);
  const publish = useAction((id) => post(`/manage/assignments/${id}/publish`), { invalidate: [['manage', 'assignments']], success: 'Published to students.' });
  return (
    <div className="stack">
      <div className="split"><SectionHeading title="Assignments" /><button className="btn btn-primary" onClick={() => setOpen(true)}>New assignment</button></div>
      <DataTable caption="Assignments" loading={q.isLoading} error={q.error} onRetry={q.refetch} rows={q.data?.data}
        empty={<EmptyState icon="clipboard" title="No assignments yet" />}
        columns={[
          { key: 'title', header: 'Title', render: (a) => <Link to={`/lecturer/assignments/${a.id}`}>{a.title}</Link> },
          { key: 'status', header: 'Status', render: (a) => <StatusBadge status={a.status} /> },
          { key: 'due_at', header: 'Due', render: (a) => dateTime(a.due_at) },
          { key: 'max_score', header: 'Marks', numeric: true }, { key: 'weight', header: 'Weight', numeric: true, render: (a) => `${a.weight}%` },
          { key: 'ungraded_count', header: 'To grade', numeric: true, render: (a) => `${a.ungraded_count} / ${a.submission_count}` },
          { key: 'act', header: '', render: (a) => a.status === 'draft' ? <button className="btn btn-secondary btn-sm" onClick={() => publish.mutate(a.id)}>Publish</button> : <Link to={`/lecturer/assignments/${a.id}`}>Grade</Link> },
        ]} />
      {open && <AssignmentModal course={course} rubrics={rubrics.data?.data || []} onClose={() => setOpen(false)} />}
    </div>
  );
}

function AssignmentModal({ course, rubrics, onClose }) {
  const f = useForm({ title: '', instructions: '', max_score: 100, pass_mark: 50, weight: 0, is_required: true, due_at: '', late_policy: 'reject', late_penalty_percent: 10, late_cutoff_at: '', allow_resubmission: false, max_attempts: 1, rubric_id: '' });
  const [rows, setRows] = useState([]);       // inline rubric builder
  const act = useAction(() => Promise.resolve(), { invalidate: [['manage', 'assignments'], ['manage', 'rubrics']] });
  const save = f.handle(async (v) => {
    let rubric_id = v.rubric_id || null;
    if (rows.length) { rubric_id = (await post(`/manage/courses/${course.id}/rubrics`, { title: `${v.title} rubric`, criteria: rows.map((r) => ({ title: r.title, max_points: Number(r.points) })) })).data.id; }
    const total = rows.length ? rows.reduce((n, r) => n + Number(r.points), 0) : rubric_id ? rubrics.find((r) => r.id === rubric_id)?.total_points : Number(v.max_score);
    await post(`/manage/courses/${course.id}/assignments`, { title: v.title, instructions: v.instructions, max_score: total, pass_mark: v.pass_mark === '' ? null : Math.min(Number(v.pass_mark), total), weight: Number(v.weight), is_required: v.is_required,
      due_at: iso(v.due_at), late_policy: v.late_policy, late_penalty_percent: Number(v.late_penalty_percent), late_cutoff_at: iso(v.late_cutoff_at), allow_resubmission: v.allow_resubmission, max_attempts: Number(v.max_attempts), rubric_id });
    act.mutate(); onClose();
  });
  return (
    <Modal title="New assignment" size="lg" onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" form="af" disabled={f.submitting}>Create draft</button></>}>
      <form id="af" onSubmit={save} noValidate>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        <TextField label="Title" required {...f.bind('title')} /><TextAreaField label="Instructions" rows={5} {...f.bind('instructions')} />
        <div className="form-grid"><TextField label="Maximum marks" type="number" min="1" {...f.bind('max_score')} /><TextField label="Pass mark" type="number" min="0" {...f.bind('pass_mark')} /><TextField label="Weight in course grade (%)" type="number" min="0" max="100" help="Weights of all graded work must total 100." {...f.bind('weight')} /></div>
        <div className="form-grid"><TextField label="Due" type="datetime-local" {...f.bind('due_at')} /><SelectField label="After the deadline" {...f.bind('late_policy')} options={[{ value: 'reject', label: 'Reject late work' }, { value: 'penalty', label: 'Accept with a penalty' }]} /></div>
        {f.values.late_policy === 'penalty' && <div className="form-grid"><TextField label="Penalty (%)" type="number" min="0" max="100" {...f.bind('late_penalty_percent')} /><TextField label="Closes completely at" type="datetime-local" {...f.bind('late_cutoff_at')} /></div>}
        <div className="form-grid"><CheckField {...f.bind('allow_resubmission', { checkbox: true })}>Allow resubmission</CheckField><TextField label="Maximum attempts" type="number" min="1" max="10" {...f.bind('max_attempts')} /></div>
        <CheckField {...f.bind('is_required', { checkbox: true })}>Must be passed to complete the course</CheckField>
        <h3>Marking rubric (optional)</h3>
        {rubrics.length > 0 && !rows.length && <SelectField label="Use an existing rubric" {...f.bind('rubric_id')} placeholder="No rubric" options={rubrics.map((r) => ({ value: r.id, label: `${r.title} (${r.total_points} marks)` }))} />}
        {!f.values.rubric_id && (
          <>
            {rows.map((r, i) => <div key={i} className="form-grid row-3" style={{ alignItems: 'end' }}><TextField label={`Criterion ${i + 1}`} value={r.title} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)))} /><TextField label="Marks" type="number" min="1" value={r.points} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, points: e.target.value } : x)))} /><button type="button" className="btn btn-quiet" style={{ marginBottom: 16 }} onClick={() => setRows(rows.filter((_, j) => j !== i))}>Remove</button></div>)}
            <button type="button" className="btn btn-secondary btn-sm" onClick={() => setRows([...rows, { title: '', points: 10 }])}>Add criterion</button>
            {rows.length > 0 && <p className="small muted">Total marks will be {rows.reduce((n, r) => n + Number(r.points || 0), 0)} (overrides “Maximum marks”).</p>}
          </>
        )}
      </form>
    </Modal>
  );
}

// ------------------------------------------------------------------ question banks
export function BanksTab({ course }) {
  const banks = useGet(['manage', 'banks', course.id], `/manage/courses/${course.id}/question-banks`);
  const [bank, setBank] = useState(null);
  const [title, setTitle] = useState('');
  const create = useAction(() => post(`/manage/courses/${course.id}/question-banks`, { title }), { invalidate: [['manage', 'banks']], success: 'Bank created.', onSuccess: (r) => { setTitle(''); setBank(r.data.id); } });
  return (
    <div className="cols cols-rev-260" style={{ alignItems: 'start' }}>
      <div className="panel"><div className="panel-head"><h2>Banks</h2></div>
        <ul className="list-plain">{(banks.data?.data || []).map((b) => <li key={b.id}><button className="btn btn-quiet btn-block" style={{ justifyContent: 'space-between', borderRadius: 0 }} aria-current={bank === b.id} onClick={() => setBank(b.id)}><span>{b.title}</span><span className="muted">{b.question_count}</span></button></li>)}</ul>
        <form className="panel-body" onSubmit={(e) => { e.preventDefault(); create.mutate(); }}><TextField label="New bank" value={title} onChange={(e) => setTitle(e.target.value)} /><button className="btn btn-secondary btn-sm" disabled={!title.trim()}>Create</button></form></div>
      {bank ? <Questions bankId={bank} key={bank} /> : <EmptyState icon="clipboard" title="Choose or create a question bank">Banks hold reusable questions. Quizzes and exams draw from them.</EmptyState>}
    </div>
  );
}

function Questions({ bankId }) {
  const [filters, setFilters] = useState({ difficulty: '', type: '' });
  const q = useGet(['manage', 'questions', bankId], `/manage/question-banks/${bankId}/questions`, filters);
  const [edit, setEdit] = useState(null);
  const arch = useAction((id) => del(`/manage/questions/${id}`), { invalidate: [['manage', 'questions'], ['manage', 'banks']], success: 'Archived.' });
  return (
    <div className="stack">
      <div className="filters">
        <SelectField label="Type" value={filters.type} onChange={(e) => setFilters({ ...filters, type: e.target.value })} placeholder="All types" options={[['mcq', 'Single choice'], ['multi', 'Multiple select'], ['tf', 'True / false'], ['short', 'Short answer'], ['essay', 'Essay']].map(([value, label]) => ({ value, label }))} />
        <SelectField label="Difficulty" value={filters.difficulty} onChange={(e) => setFilters({ ...filters, difficulty: e.target.value })} placeholder="Any" options={['easy', 'medium', 'hard']} />
        <button className="btn btn-primary" onClick={() => setEdit({})}>Add question</button>
      </div>
      <DataTable caption="Questions" loading={q.isLoading} error={q.error} rows={q.data?.data}
        columns={[
          { key: 'prompt', header: 'Question', render: (x) => <button className="link-btn" style={{ textAlign: 'left' }} onClick={() => setEdit(x)}>{x.prompt.replace(/<[^>]+>/g, '').slice(0, 90)}</button> },
          { key: 'type', header: 'Type', render: (x) => titleCase(x.type) }, { key: 'difficulty', header: 'Difficulty' }, { key: 'category', header: 'Category' }, { key: 'points', header: 'Marks', numeric: true },
          { key: 'a', header: '', render: (x) => <button className="link-btn" onClick={() => arch.mutate(x.id)}>Archive</button> },
        ]} empty={<EmptyState icon="clipboard" title="No questions yet" />} />
      {edit && <QuestionModal bankId={bankId} q={edit.id ? edit : null} onClose={() => setEdit(null)} />}
    </div>
  );
}

function QuestionModal({ bankId, q, onClose }) {
  const f = useForm({ type: q?.type || 'mcq', prompt: q?.prompt || '', category: q?.category || '', difficulty: q?.difficulty || 'medium', points: q?.points || 1, explanation: q?.explanation || '',
    accepted: (q?.accepted_answers || []).join('\n'), partial_credit: q?.partial_credit || false, true_is_correct: q ? q.options?.find((o) => o.label === 'True')?.is_correct !== false : true });
  const [opts, setOpts] = useState(q?.options?.length ? q.options.map((o) => ({ label: o.label, is_correct: o.is_correct })) : [{ label: '', is_correct: true }, { label: '', is_correct: false }]);
  const act = useAction(() => Promise.resolve(), { invalidate: [['manage', 'questions'], ['manage', 'banks']] });
  const save = f.handle(async (v) => {
    const body = { type: v.type, prompt: v.prompt, category: v.category || null, difficulty: v.difficulty, points: Number(v.points), explanation: v.explanation || null, partial_credit: v.partial_credit };
    if (v.type === 'mcq' || v.type === 'multi') body.options = opts.filter((o) => o.label.trim());
    if (v.type === 'tf') body.true_is_correct = v.true_is_correct;
    if (v.type === 'short') body.accepted_answers = v.accepted.split('\n').map((x) => x.trim()).filter(Boolean);
    await (q ? put(`/manage/questions/${q.id}`, body) : post(`/manage/question-banks/${bankId}/questions`, body));
    act.mutate(); onClose();
  });
  const t = f.values.type;
  const setCorrect = (i) => setOpts(opts.map((o, j) => (t === 'mcq' ? { ...o, is_correct: j === i } : j === i ? { ...o, is_correct: !o.is_correct } : o)));
  return (
    <Modal title={q ? 'Edit question' : 'Add question'} size="lg" onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" form="qf" disabled={f.submitting}>Save question</button></>}>
      <form id="qf" onSubmit={save} noValidate>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        {q && <Alert type="info">Changing a question does not alter attempts that have already started; they keep the version they were given.</Alert>}
        <div className="form-grid"><SelectField label="Type" {...f.bind('type')} options={[['mcq', 'Single choice'], ['multi', 'Multiple select'], ['tf', 'True / false'], ['short', 'Short answer'], ['essay', 'Essay (marked by you)']].map(([value, label]) => ({ value, label }))} /><TextField label="Marks" type="number" min="0.5" step="0.5" {...f.bind('points')} /><SelectField label="Difficulty" {...f.bind('difficulty')} options={['easy', 'medium', 'hard']} /><TextField label="Category (optional)" {...f.bind('category')} /></div>
        <TextAreaField label="Question" required rows={3} {...f.bind('prompt')} />
        {(t === 'mcq' || t === 'multi') && (
          <fieldset style={{ border: 0, padding: 0 }}><legend className="label">Options — tick {t === 'mcq' ? 'the one correct option' : 'every correct option'}</legend>
            {opts.map((o, i) => <div key={i} style={{ display: 'flex', gap: 8, marginBottom: 8, alignItems: 'center' }}><input type={t === 'mcq' ? 'radio' : 'checkbox'} name="correct" checked={o.is_correct} onChange={() => setCorrect(i)} aria-label={`Option ${i + 1} is correct`} /><input className="input" aria-label={`Option ${i + 1} text`} value={o.label} onChange={(e) => setOpts(opts.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))} />{opts.length > 2 && <button type="button" className="btn btn-quiet btn-sm" onClick={() => setOpts(opts.filter((_, j) => j !== i))}>Remove</button>}</div>)}
            {opts.length < 8 && <button type="button" className="btn btn-secondary btn-sm" onClick={() => setOpts([...opts, { label: '', is_correct: false }])}>Add option</button>}
            {t === 'multi' && <CheckField {...f.bind('partial_credit', { checkbox: true })}>Award partial credit for partly correct answers</CheckField>}
          </fieldset>
        )}
        {t === 'tf' && <SelectField label="Correct answer" value={String(f.values.true_is_correct)} onChange={(e) => f.set('true_is_correct', e.target.value === 'true')} options={[{ value: 'true', label: 'True' }, { value: 'false', label: 'False' }]} />}
        {t === 'short' && <TextAreaField label="Accepted answers" rows={3} help="One per line. Matching ignores capital letters and extra spaces." {...f.bind('accepted')} />}
        <TextAreaField label="Explanation (shown after results, if you allow review)" rows={2} {...f.bind('explanation')} />
      </form>
    </Modal>
  );
}

// ------------------------------------------------------------------ assessments
export function AssessmentsTab({ course }) {
  const q = useGet(['manage', 'assessments', course.id], `/manage/courses/${course.id}/assessments`);
  const [open, setOpen] = useState(false);
  const [grant, setGrant] = useState(null);
  const publish = useAction((id) => post(`/manage/assessments/${id}/publish`), { invalidate: [['manage', 'assessments']], success: 'Published.', quietError: true });
  const [err, setErr] = useState(null);
  const release = useAction((id) => post(`/manage/assessments/${id}/publish-results`), { invalidate: [['manage', 'assessments']], success: (r) => `${r.data.published} result(s) released.` });
  return (
    <div className="stack">
      <div className="split"><SectionHeading title="Quizzes, exams and supplementary assessments" /><button className="btn btn-primary" onClick={() => setOpen(true)}>New assessment</button></div>
      {err && <Alert type="error" title={err.message}>{(err.details || []).join(' ')}</Alert>}
      <DataTable caption="Assessments" loading={q.isLoading} error={q.error} rows={q.data?.data} empty={<EmptyState icon="clipboard" title="No assessments yet" />}
        columns={[
          { key: 'title', header: 'Title', render: (a) => <Link to={`/lecturer/assessments/${a.id}`}>{a.title}</Link> },
          { key: 'kind', header: 'Kind', render: (a) => <>{titleCase(a.kind)}{a.is_final_exam && <span className="badge badge-warning" style={{ marginLeft: 6 }}>Final</span>}</> },
          { key: 'status', header: 'Status', render: (a) => <StatusBadge status={a.status} /> },
          { key: 'time_limit_minutes', header: 'Time', render: (a) => (a.time_limit_minutes ? `${a.time_limit_minutes} min` : 'None') },
          { key: 'weight', header: 'Weight', numeric: true, render: (a) => `${a.weight}%` },
          { key: 'attempt_count', header: 'Attempts', numeric: true, render: (a) => `${a.attempt_count}${a.awaiting_marking ? ` (${a.awaiting_marking} to mark)` : ''}` },
          { key: 'act', header: '', render: (a) => (
            <span className="btn-row">
              {a.status === 'draft' && <button className="btn btn-secondary btn-sm" onClick={() => { setErr(null); publish.mutate(a.id, { onError: (e) => setErr(e) }); }}>Publish</button>}
              {a.status === 'published' && a.results_release === 'manual' && <button className="btn btn-secondary btn-sm" onClick={() => release.mutate(a.id)}>Release results</button>}
              {a.status === 'published' && <button className="btn btn-quiet btn-sm" onClick={() => setGrant(a)}>Extra attempt</button>}
            </span>) },
        ]} />
      {open && <AssessmentModal course={course} existing={q.data?.data || []} onClose={() => setOpen(false)} />}
      {grant && <GrantModal a={grant} course={course} onClose={() => setGrant(null)} />}
    </div>
  );
}

function AssessmentModal({ course, existing, onClose }) {
  const banks = useGet(['manage', 'banks', course.id], `/manage/courses/${course.id}/question-banks`);
  const f = useForm({ kind: 'quiz', title: '', instructions: '', time_limit_minutes: 30, opens_at: '', closes_at: '', max_attempts: 1, pass_mark_percent: 50, weight: 0, is_required: true, is_final_exam: false, require_all_lessons: false,
    shuffle_questions: false, shuffle_options: false, results_release: 'immediate', review_policy: 'never', parent_assessment_id: '', bank_id: '', pool_count: 5, pool_difficulty: '' });
  const act = useAction(() => Promise.resolve(), { invalidate: [['manage', 'assessments']] });
  const bankQs = useGet(['manage', 'questions', f.values.bank_id], `/manage/question-banks/${f.values.bank_id}/questions`, undefined, { enabled: !!f.values.bank_id });
  const [picked, setPicked] = useState([]);
  const [mode, setMode] = useState('fixed');
  const save = f.handle(async (v) => {
    const body = { kind: v.kind, title: v.title, instructions: v.instructions || null, time_limit_minutes: v.time_limit_minutes ? Number(v.time_limit_minutes) : null, opens_at: iso(v.opens_at), closes_at: iso(v.closes_at),
      max_attempts: Number(v.max_attempts), pass_mark_percent: Number(v.pass_mark_percent), weight: Number(v.weight), is_required: v.is_required, is_final_exam: v.is_final_exam, require_all_lessons: v.require_all_lessons,
      shuffle_questions: v.shuffle_questions, shuffle_options: v.shuffle_options, results_release: v.results_release, review_policy: v.review_policy, parent_assessment_id: v.kind === 'supplementary' ? v.parent_assessment_id || null : null };
    if (mode === 'fixed') body.question_ids = picked; else if (v.bank_id) body.pools = [{ bank_id: v.bank_id, count: Number(v.pool_count), difficulty: v.pool_difficulty || undefined }];
    await post(`/manage/courses/${course.id}/assessments`, body); act.mutate(); onClose();
  });
  return (
    <Modal title="New assessment" size="lg" onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" form="asf" disabled={f.submitting}>Create draft</button></>}>
      <form id="asf" onSubmit={save} noValidate>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        <div className="form-grid"><SelectField label="Kind" {...f.bind('kind')} options={[{ value: 'quiz', label: 'Quiz' }, { value: 'exam', label: 'Examination' }, { value: 'supplementary', label: 'Supplementary (retake for those who failed)' }]} /><TextField label="Title" required {...f.bind('title')} /></div>
        {f.values.kind === 'supplementary' && <SelectField label="Supplements" {...f.bind('parent_assessment_id')} placeholder="Choose the original…" options={existing.filter((a) => a.kind !== 'supplementary').map((a) => ({ value: a.id, label: a.title }))} />}
        <TextAreaField label="Instructions" rows={3} {...f.bind('instructions')} />
        <div className="form-grid"><TextField label="Time limit (minutes)" type="number" min="1" help="Blank for no limit." {...f.bind('time_limit_minutes')} /><TextField label="Opens" type="datetime-local" {...f.bind('opens_at')} /><TextField label="Closes" type="datetime-local" {...f.bind('closes_at')} /><TextField label="Attempts allowed" type="number" min="1" {...f.bind('max_attempts')} /></div>
        <div className="form-grid"><TextField label="Pass mark (%)" type="number" min="0" max="100" {...f.bind('pass_mark_percent')} /><TextField label="Weight in course grade (%)" type="number" min="0" max="100" {...f.bind('weight')} />
          <SelectField label="Release results" {...f.bind('results_release')} options={[{ value: 'immediate', label: 'As soon as marked' }, { value: 'manual', label: 'When I release them' }]} /><SelectField label="Show correct answers" {...f.bind('review_policy')} options={[{ value: 'never', label: 'Never' }, { value: 'after_close', label: 'After the assessment closes' }, { value: 'immediate', label: 'With the result' }]} /></div>
        <div className="form-grid"><CheckField {...f.bind('is_required', { checkbox: true })}>Required to complete the course</CheckField><CheckField {...f.bind('is_final_exam', { checkbox: true })}>Final examination (must be passed on its own)</CheckField><CheckField {...f.bind('require_all_lessons', { checkbox: true })}>Only after all required lessons</CheckField><CheckField {...f.bind('shuffle_questions', { checkbox: true })}>Shuffle question order</CheckField><CheckField {...f.bind('shuffle_options', { checkbox: true })}>Shuffle answer order</CheckField></div>
        <h3>Questions</h3>
        <SelectField label="Question bank" {...f.bind('bank_id')} placeholder="Choose a bank…" options={(banks.data?.data || []).map((b) => ({ value: b.id, label: `${b.title} (${b.question_count})` }))} />
        <div role="radiogroup" aria-label="How to choose questions" className="btn-row" style={{ marginBottom: 12 }}>
          <label className="check"><input type="radio" checked={mode === 'fixed'} onChange={() => setMode('fixed')} /> Pick specific questions</label>
          <label className="check"><input type="radio" checked={mode === 'pool'} onChange={() => setMode('pool')} /> Random draw for each student</label>
        </div>
        {mode === 'pool' ? <div className="form-grid"><TextField label="Number of questions" type="number" min="1" {...f.bind('pool_count')} /><SelectField label="Difficulty" {...f.bind('pool_difficulty')} placeholder="Any" options={['easy', 'medium', 'hard']} /></div> : (
          bankQs.isLoading ? <Loading rows={2} /> : <ul className="list-plain panel list-rule" style={{ padding: '0 16px', maxHeight: 240, overflow: 'auto' }}>{(bankQs.data?.data || []).map((x) => <li key={x.id}><label className="check"><input type="checkbox" checked={picked.includes(x.id)} onChange={() => setPicked(picked.includes(x.id) ? picked.filter((i) => i !== x.id) : [...picked, x.id])} /><span>{x.prompt.replace(/<[^>]+>/g, '').slice(0, 100)} <span className="muted small">({titleCase(x.type)}, {x.points} marks)</span></span></label></li>)}</ul>
        )}
        {mode === 'fixed' && <p className="small muted">{picked.length} selected.</p>}
      </form>
    </Modal>
  );
}

function GrantModal({ a, course, onClose }) {
  const students = useGet(['manage', 'students', course.id], `/manage/courses/${course.id}/completion-report`, { per_page: 100 });
  const f = useForm({ user_id: '', extra_attempts: 1, reason: '' });
  const grant = useAction(() => post(`/manage/assessments/${a.id}/grant-attempts`, { ...f.values, extra_attempts: Number(f.values.extra_attempts) }), { success: 'Extra attempt granted.', onSuccess: onClose });
  return (
    <Modal title={`Extra attempt: ${a.title}`} onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" disabled={!f.values.user_id || f.values.reason.length < 5 || grant.isPending} onClick={() => grant.mutate()}>Grant</button></>}>
      <SelectField label="Student" {...f.bind('user_id')} placeholder="Choose…" options={(students.data?.data || []).map((s) => ({ value: s.user.id, label: s.user.name }))} />
      <TextField label="Extra attempts" type="number" min="1" max="5" {...f.bind('extra_attempts')} /><TextField label="Reason (recorded in the audit log)" {...f.bind('reason')} />
    </Modal>
  );
}
