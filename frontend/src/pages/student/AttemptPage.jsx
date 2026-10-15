import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { get, post, put } from '../../api/client.js';
import { Alert } from '../../components/ui/Fields.jsx';
import { ErrorState, Loading, RichText } from '../../components/ui/Display.jsx';
import { ConfirmDialog } from '../../components/ui/Modal.jsx';
import { clock } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function AttemptPage() {
  const { attemptId } = useParams();
  useDocumentTitle('Assessment');
  const [state, setState] = useState({ loading: true });
  const load = useCallback(async () => {
    try { setState({ data: (await get(`/learn/attempts/${attemptId}`)).data }); } catch (e) { setState({ error: e }); }
  }, [attemptId]);
  useEffect(() => { load(); }, [load]);
  if (state.loading) return <Loading rows={5} />;
  if (state.error) return <ErrorState error={state.error} onRetry={load} />;
  const d = state.data;
  if (d.status === 'in_progress') return <Exam attempt={d} onFinished={load} />;
  return <Finished attemptId={attemptId} status={d.status} />;
}

function Finished({ attemptId, status }) {
  const [res, setRes] = useState({ loading: true });
  useEffect(() => { get(`/learn/attempts/${attemptId}/result`).then((r) => setRes({ data: r.data })).catch((e) => setRes({ error: e })); }, [attemptId]);
  if (res.loading) return <Loading />;
  if (res.error?.code === 'results_pending') {
    return <div><h1>Submitted</h1><Alert type="info" title={status === 'grading' ? 'Waiting for your lecturer' : 'Result not released yet'}>Your answers are saved. You will be notified when your result is available.</Alert><Link className="btn btn-secondary" to="/student/courses">Back to my courses</Link></div>;
  }
  if (res.error) return <ErrorState error={res.error} />;
  const r = res.data;
  return (
    <div className="stack">
      <h1>Your result</h1>
      <Alert type={r.passed ? 'success' : 'warning'} title={r.passed ? 'Passed' : 'Not passed'}>You scored {r.total_score} out of {r.max_score} ({r.percent}%). The pass mark is {r.pass_mark_percent}%.</Alert>
      <div className="panel"><div className="panel-head"><h2>Question review</h2></div>
        <ol className="panel-body stack-sm" style={{ paddingLeft: 40 }}>
          {r.review.map((q) => (
            <li key={q.question_id}>
              <RichText html={q.prompt} />
              <p className="small"><strong>{q.points_awarded ?? 0} / {q.points_available}</strong> marks{q.your_answer?.text ? <> · Your answer: “{q.your_answer.text.slice(0, 300)}”</> : null}</p>
              {q.feedback && <Alert type="info" title="Lecturer feedback">{q.feedback}</Alert>}
              {q.explanation && <p className="small muted">Explanation: {q.explanation}</p>}
              {q.accepted_answers?.length > 0 && <p className="small muted">Accepted answers: {q.accepted_answers.join('; ')}</p>}
            </li>
          ))}
        </ol></div>
      <Link className="btn btn-secondary" to="/student/results">All my results</Link>
    </div>
  );
}

function Exam({ attempt, onFinished }) {
  const nav = useNavigate();
  const qc = useQueryClient();
  const qs = attempt.questions;
  const [answers, setAnswers] = useState(() => Object.fromEntries(qs.map((q) => [q.question_id, q.answer || {}])));
  const [idx, setIdx] = useState(0);
  const [save, setSave] = useState({});                         // question_id -> saving | saved | error
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState(false);
  const [timeUp, setTimeUp] = useState(false);
  const [online, setOnline] = useState(navigator.onLine);
  const timers = useRef({});
  const pending = useRef({});

  // The server's clock is the only clock: measure our skew once and count down to the server-set deadline.
  const skew = useMemo(() => (attempt.server_now ? Date.parse(attempt.server_now) - Date.now() : 0), [attempt.server_now]);
  const deadline = attempt.deadline_at ? Date.parse(attempt.deadline_at) : null;
  const [left, setLeft] = useState(deadline ? Math.max(0, Math.round((deadline - (Date.now() + skew)) / 1000)) : null);
  useEffect(() => {
    if (deadline === null) return undefined;
    const t = setInterval(() => setLeft(Math.max(0, Math.round((deadline - (Date.now() + skew)) / 1000))), 1000);
    return () => clearInterval(t);
  }, [deadline, skew]);
  useEffect(() => { const on = () => setOnline(true); const off = () => setOnline(false); window.addEventListener('online', on); window.addEventListener('offline', off); return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off); }; }, []);

  const persistRef = useRef(null);
  persistRef.current = async (qid) => {
    setSave((s) => ({ ...s, [qid]: 'saving' }));
    try {
      await put(`/learn/attempts/${attempt.id}/answers/${qid}`, pending.current[qid]);
      setSave((s) => ({ ...s, [qid]: 'saved' }));
    } catch (e) {
      if (e.code === 'attempt_expired' || e.code === 'attempt_closed') { setTimeUp(true); return; }
      setSave((s) => ({ ...s, [qid]: 'error' }));
      timers.current[qid] = setTimeout(() => persistRef.current(qid), 5000);   // keep trying; the answer is held in memory meanwhile
    }
  };
  const persist = useCallback((qid) => persistRef.current(qid), []);
  const change = (qid, body) => {
    setAnswers((a) => ({ ...a, [qid]: body }));
    pending.current[qid] = body;
    clearTimeout(timers.current[qid]);
    timers.current[qid] = setTimeout(() => persist(qid), 500);
  };
  useEffect(() => () => Object.values(timers.current).forEach(clearTimeout), []);

  const finish = useCallback(async () => {
    setBusy(true);
    // Flush anything still waiting, then submit; the server marks what it has.
    await Promise.all(Object.keys(pending.current).map((q) => put(`/learn/attempts/${attempt.id}/answers/${q}`, pending.current[q]).catch(() => null)));
    try { await post(`/learn/attempts/${attempt.id}/submit`); } catch { /* already closed */ }
    qc.invalidateQueries({ queryKey: ['learn', 'assessments'] });
    setBusy(false); setConfirm(false); onFinished();
  }, [attempt.id, onFinished, qc]);
  useEffect(() => { if (left === 0 && !timeUp) { setTimeUp(true); finish(); } }, [left, timeUp, finish]);

  const q = qs[idx];
  const a = answers[q.question_id] || {};
  const answered = (qq) => { const x = answers[qq.question_id]; return !!(x?.text?.trim() || x?.option_ids?.length); };
  const unanswered = qs.filter((x) => !answered(x)).length;
  const toggleMulti = (id) => { const cur = a.option_ids || []; change(q.question_id, { option_ids: cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id] }); };

  if (timeUp) return <div><Alert type="warning" title="Time is up">Your saved answers have been submitted.</Alert><button className="btn btn-primary" onClick={() => nav('/student/courses')}>Back to my courses</button></div>;
  return (
    <div>
      <div className="split" style={{ position: 'sticky', top: 56, background: 'var(--page)', padding: '8px 0', zIndex: 5 }}>
        <h1 style={{ margin: 0, fontSize: 'var(--fs-sub)' }}>Question {idx + 1} of {qs.length}</h1>
        <div className="cluster">
          {!online && <span className="badge badge-error">Offline: answers kept, will save when back online</span>}
          {left !== null && <span className={`timer ${left < 300 ? 'low' : ''}`} role="timer" aria-label={`Time remaining ${clock(left)}`}>{clock(left)}</span>}
          <span className="small muted" aria-live="polite">{save[q.question_id] === 'saving' ? 'Saving…' : save[q.question_id] === 'error' ? 'Not saved, retrying…' : save[q.question_id] === 'saved' ? 'Saved' : ''}</span>
        </div>
      </div>
      <div className="cols cols-220" style={{ alignItems: 'start' }}>
        <div className="panel panel-pad">
          <RichText html={q.prompt} />
          <p className="small muted">{q.points} mark{q.points === 1 ? '' : 's'}{q.type === 'multi' ? ' · Select all that apply' : ''}</p>
          {(q.type === 'mcq' || q.type === 'tf') && (
            <fieldset style={{ border: 0, padding: 0, margin: 0 }}><legend className="sr-only">Choose one answer</legend>
              {q.options.map((o) => <label className="q-option" key={o.id}><input type="radio" name={q.question_id} checked={a.option_ids?.[0] === o.id} onChange={() => change(q.question_id, { option_ids: [o.id] })} /><span>{o.label}</span></label>)}</fieldset>
          )}
          {q.type === 'multi' && (
            <fieldset style={{ border: 0, padding: 0, margin: 0 }}><legend className="sr-only">Choose all correct answers</legend>
              {q.options.map((o) => <label className="q-option" key={o.id}><input type="checkbox" checked={!!a.option_ids?.includes(o.id)} onChange={() => toggleMulti(o.id)} /><span>{o.label}</span></label>)}</fieldset>
          )}
          {q.type === 'short' && <div className="field"><label className="label" htmlFor="short">Your answer</label><input id="short" className="input" value={a.text || ''} onChange={(e) => change(q.question_id, { text: e.target.value })} autoComplete="off" /></div>}
          {q.type === 'essay' && <div className="field"><label className="label" htmlFor="essay">Your answer</label><textarea id="essay" className="textarea" rows={12} value={a.text || ''} onChange={(e) => change(q.question_id, { text: e.target.value })} /><div className="help">{(a.text || '').trim().split(/\s+/).filter(Boolean).length} words</div></div>}
          <div className="split" style={{ marginTop: 16 }}>
            <button className="btn btn-secondary" disabled={idx === 0} onClick={() => setIdx(idx - 1)}>Previous</button>
            {idx < qs.length - 1 ? <button className="btn btn-primary" onClick={() => setIdx(idx + 1)}>Next</button> : <button className="btn btn-primary" onClick={() => setConfirm(true)}>Review and submit</button>}
          </div>
        </div>
        <aside className="panel panel-pad">
          <h2 style={{ fontSize: 'var(--fs-small)' }}>Questions</h2>
          <div className="q-nav" role="group" aria-label="Jump to question">{qs.map((x, i) => <button key={x.question_id} type="button" className={answered(x) ? 'answered' : ''} aria-current={i === idx} aria-label={`Question ${i + 1}${answered(x) ? ', answered' : ', not answered'}`} onClick={() => setIdx(i)}>{i + 1}</button>)}</div>
          <button className="btn btn-primary btn-block" style={{ marginTop: 16 }} onClick={() => setConfirm(true)}>Submit</button>
        </aside>
      </div>
      {confirm && <ConfirmDialog title="Submit your answers?" confirmLabel="Submit now" busy={busy} onCancel={() => setConfirm(false)} onConfirm={finish}
        message={unanswered ? `You have ${unanswered} unanswered question${unanswered === 1 ? '' : 's'}. You cannot change answers after submitting.` : 'You cannot change your answers after submitting.'} />}
    </div>
  );
}
