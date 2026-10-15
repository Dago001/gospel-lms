import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useAction, useGet } from '../../api/hooks.js';
import { post } from '../../api/client.js';
import { Alert } from '../../components/ui/Fields.jsx';
import { Breadcrumbs, ErrorState, Loading, ProgressBar, RichText } from '../../components/ui/Display.jsx';
import { Icon } from '../../components/ui/Icon.jsx';
import { PlaybackTracker } from '../../player/PlaybackTracker.js';
import { YouTubePlayer } from '../../player/YouTubePlayer.jsx';
import { HlsPlayer } from '../../player/HlsPlayer.jsx';
import { bytes, clock } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function LessonPlayer() {
  const { courseId, lessonId } = useParams();
  const qc = useQueryClient();
  const course = useGet(['learn', 'course', courseId], `/learn/courses/${courseId}`);
  const lesson = useGet(['learn', 'lesson', lessonId], `/learn/lessons/${lessonId}`, undefined, { retry: false });
  const c = course.data?.data;
  const l = lesson.data?.data;
  useDocumentTitle(l?.title);

  const flat = useMemo(() => (c?.curriculum || []).flatMap((m) => m.lessons), [c]);
  const idx = flat.findIndex((x) => x.id === lessonId);
  const next = idx >= 0 ? flat[idx + 1] : null;
  const state = flat[idx] || {};

  if (lesson.isLoading || course.isLoading) return <Loading rows={6} />;
  if (lesson.error?.code === 'lesson_locked') return (
    <div><Breadcrumbs items={[{ label: 'My courses', to: '/student/courses' }, { label: c?.title || 'Course', to: `/student/courses/${courseId}` }, { label: 'Locked lesson' }]} />
      <Alert type="warning" title="This lesson is locked">Complete the earlier lessons first. <Link to={`/student/courses/${courseId}`}>Back to the course</Link></Alert></div>
  );
  if (lesson.error) return <ErrorState error={lesson.error} onRetry={lesson.refetch} />;

  return (
    <div>
      <Breadcrumbs items={[{ label: 'My courses', to: '/student/courses' }, { label: c.title, to: `/student/courses/${courseId}` }, { label: l.title }]} />
      <div className="lesson-layout">
        <div>
          <h1>{l.title}</h1>
          {l.type === 'video' && <VideoLesson key={lessonId} lesson={l} onCompleted={() => { qc.invalidateQueries({ queryKey: ['learn', 'course', courseId] }); qc.invalidateQueries({ queryKey: ['student'] }); }} />}
          {l.type !== 'video' && l.content && <RichText html={l.content} />}
          {l.type === 'video' && l.content && <div style={{ marginTop: 16 }}><RichText html={l.content} /></div>}
          {l.type === 'live' && <Alert type="info" title="Live class">Join live sessions from the <Link to={`/student/courses/${courseId}?tab=live`}>Live classes</Link> tab. Attendance is recorded automatically.</Alert>}
          <Resources lesson={l} />
          {l.type !== 'video' && l.type !== 'live' && <ReadingComplete lesson={l} done={state.completed} courseId={courseId} next={next} />}
          <div className="split" style={{ marginTop: 32 }}>
            {idx > 0 ? <Link className="btn btn-secondary" to={`/student/courses/${courseId}/lessons/${flat[idx - 1].id}`}>← Previous</Link> : <span />}
            {next && !next.locked ? <Link className="btn btn-primary" to={`/student/courses/${courseId}/lessons/${next.id}`}>Next lesson →</Link> : next ? <span className="muted small"><Icon name="lock" size={14} /> Next lesson unlocks when this one is complete</span> : <Link className="btn btn-secondary" to={`/student/courses/${courseId}`}>Back to the course</Link>}
          </div>
        </div>
        <aside aria-label="Course contents">
          <div className="panel">
            <div className="panel-head"><h2>Course contents</h2></div>
            {c.progress?.percent != null && <div style={{ padding: '12px 16px' }}><ProgressBar value={c.progress.percent} label="Course progress" /><div className="micro muted" style={{ marginTop: 4 }}>{c.progress.required_completed} of {c.progress.required_total} required lessons complete</div></div>}
            <Contents course={c} courseId={courseId} current={lessonId} />
          </div>
        </aside>
      </div>
    </div>
  );
}

export function Contents({ course, courseId, current }) {
  return (
    <div>
      {course.curriculum.map((m) => (
        <div key={m.id}>
          <div className="module-title">{m.title}</div>
          <ul className="curriculum">
            {m.lessons.map((x) => (
              <li key={x.id}>
                {x.locked ? <span className="lesson-row locked" aria-disabled="true"><Icon name="lock" size={16} /><span style={{ flex: 1 }}>{x.title}<span className="sr-only"> (locked)</span></span></span> : (
                  <Link to={`/student/courses/${courseId}/lessons/${x.id}`} aria-current={x.id === current ? 'page' : undefined}>
                    <Icon name={x.completed ? 'check' : x.type === 'video' ? 'video' : x.type === 'live' ? 'calendar' : 'book'} size={16} style={x.completed ? { color: 'var(--success)' } : undefined} />
                    <span style={{ flex: 1 }}>{x.title}{x.completed && <span className="sr-only"> (completed)</span>}</span>
                    {x.duration_seconds && <span className="micro muted">{clock(x.duration_seconds)}</span>}
                  </Link>
                )}
              </li>
            ))}
          </ul>
        </div>
      ))}
    </div>
  );
}

function Resources({ lesson }) {
  const ack = useAction((id) => post(`/learn/resources/${id}/acknowledge`), { success: 'Acknowledged.' });
  const [done, setDone] = useState({});
  if (!lesson.resources?.length) return null;
  return (
    <section style={{ marginTop: 24 }}>
      <h2 style={{ fontSize: 'var(--fs-sub)' }}>Study materials</h2>
      <ul className="list-plain list-rule panel" style={{ padding: '0 16px' }}>
        {lesson.resources.map((r) => (
          <li key={r.id} style={{ display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
            <Icon name="file" size={18} />
            <div style={{ flex: 1, minWidth: 180 }}>{r.title}<div className="micro muted">{r.name} · {bytes(r.size_bytes)}</div></div>
            <a className="btn btn-secondary btn-sm" href={`/api/v1/learn/resources/${r.id}/download`} download>Download</a>
            {r.requires_acknowledgement !== false && <button type="button" className="btn btn-quiet btn-sm" disabled={done[r.id]} onClick={() => ack.mutate(r.id, { onSuccess: () => setDone((d) => ({ ...d, [r.id]: true })) })}>{done[r.id] ? 'Acknowledged' : 'I have read this'}</button>}
          </li>
        ))}
      </ul>
    </section>
  );
}

function ReadingComplete({ lesson, done, courseId, next }) {
  const qc = useQueryClient();
  const [msg, setMsg] = useState(null);
  const complete = useAction(() => post(`/learn/lessons/${lesson.id}/complete`), {
    quietError: true,
    onSuccess: () => { setMsg({ type: 'success', text: 'Lesson complete.' }); qc.invalidateQueries({ queryKey: ['learn', 'course', courseId] }); qc.invalidateQueries({ queryKey: ['student'] }); },
  });
  if (done) return <Alert type="success" title="Lesson complete">{next && !next.locked ? 'The next lesson is unlocked.' : 'Nice work.'}</Alert>;
  return (
    <div style={{ marginTop: 24 }}>
      {msg && <Alert type={msg.type}>{msg.text}</Alert>}
      <button className="btn btn-primary" disabled={complete.isPending} onClick={() => complete.mutate(undefined, { onError: (e) => setMsg({ type: 'error', text: e.message }) })}>Mark lesson as complete</button>
    </div>
  );
}

function VideoLesson({ lesson, onCompleted }) {
  const [session, setSession] = useState(null);       // { session_id, source, resume_position, tracked, progress, limits }
  const [err, setErr] = useState(null);
  const [progress, setProgress] = useState(null);
  const [saveState, setSaveState] = useState('idle');
  const [done, setDone] = useState(false);
  const [expired, setExpired] = useState(false);
  const tracker = useRef(null);
  const timeFn = useRef(() => 0);

  const open = async () => {
    setErr(null); setExpired(false);
    try {
      const r = (await post(`/learn/lessons/${lesson.id}/playback`)).data;
      setSession(r); setProgress(r.progress || null);
    } catch (e) { setErr(e); }
  };
  useEffect(() => { open(); return () => tracker.current?.dispose(); }, [lesson.id]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!session?.tracked) return undefined;
    const t = new PlaybackTracker({
      sessionId: session.session_id, getTime: () => timeFn.current(),
      onProgress: (p, completed) => { setProgress(p); if (completed) { setDone(true); onCompleted?.(); } },
      onExpired: () => setExpired(true),
    });
    t.onStatus = setSaveState;
    tracker.current = t;
    return () => { t.dispose(); tracker.current = null; };
  }, [session?.session_id]); // eslint-disable-line react-hooks/exhaustive-deps

  if (err) return <Alert type={err.status === 409 ? 'info' : 'error'} title={err.status === 409 ? 'This video is not ready yet' : 'Could not start the video'}>{err.message} <button type="button" className="link-btn" onClick={open}>Try again</button></Alert>;
  if (!session) return <Loading rows={2} label="Preparing the video" />;
  const src = session.source;
  const ready = (h) => { if (h?.getTime) timeFn.current = h.getTime; if (h?.error) setErr({ message: 'The player could not load this video.' }); };
  return (
    <div>
      {src.type === 'youtube'
        ? <YouTubePlayer key={src.youtube_id} videoId={src.youtube_id} startAt={session.resume_position} trackerRef={tracker} onReady={ready} />
        : <HlsPlayer key={src.hls_url} src={src.hls_url} startAt={session.resume_position} trackerRef={tracker} onReady={ready} />}
      <div style={{ marginTop: 12 }} aria-live="polite">
        {!session.tracked && <Alert type="info">Preview mode: your viewing is not recorded as student progress.</Alert>}
        {expired && <Alert type="warning" title="Session expired">You were away for a while. <button className="link-btn" onClick={open}>Resume the lesson</button> (your verified progress is kept).</Alert>}
        {done && <Alert type="success" title="Lesson complete">You have watched enough of this video to complete the lesson.</Alert>}
        {session.tracked && progress && !done && (
          <>
            <div className="split small"><span>Watched {Math.round(progress.percent)}% · {session.required_completion_percent}% needed to complete</span><span className="muted">{saveState === 'saving' ? 'Saving…' : saveState === 'offline' ? 'Offline. Will retry.' : saveState === 'saved' ? 'Progress saved' : ''}</span></div>
            <ProgressBar value={(progress.percent / session.required_completion_percent) * 100} label="Progress towards completing this lesson" />
          </>
        )}
        {session.limits && <p className="micro muted" style={{ marginTop: 8 }}>{session.limits}</p>}
      </div>
    </div>
  );
}
