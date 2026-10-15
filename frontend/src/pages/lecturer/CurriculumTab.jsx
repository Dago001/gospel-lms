import { useEffect, useRef, useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { api, del, get, post, put } from '../../api/client.js';
import { Alert, CheckField, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { EmptyState, ProgressBar, StatusBadge } from '../../components/ui/Display.jsx';
import { ConfirmDialog, Modal } from '../../components/ui/Modal.jsx';
import { FileInput } from '../../components/ui/FileInput.jsx';
import { Icon } from '../../components/ui/Icon.jsx';
import { tusUpload } from '../../lib/tus.js';
import { bytes, clock } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';

export function Curriculum({ course }) {
  const q = useGet(['manage', 'course', course.id], `/manage/courses/${course.id}`);
  const modules = q.data?.data?.modules || [];
  const locked = !['draft', 'approved', 'published'].includes(course.status);
  const inv = [['manage', 'course']];
  const [modal, setModal] = useState(null);       // { kind, module?, lesson? }
  const [confirm, setConfirm] = useState(null);
  const addModule = useAction((b) => post(`/manage/courses/${course.id}/modules`, b), { invalidate: inv, success: 'Module added.', onSuccess: () => setModal(null) });
  const editModule = useAction(({ id, ...b }) => put(`/manage/modules/${id}`, b), { invalidate: inv, success: 'Module saved.', onSuccess: () => setModal(null) });
  const reorderModules = useAction((ids) => put(`/manage/courses/${course.id}/modules/order`, { ids }), { invalidate: inv });
  const reorderLessons = useAction(({ moduleId, ids }) => put(`/manage/modules/${moduleId}/lessons/order`, { ids }), { invalidate: inv });
  const remove = useAction(({ kind, id }) => del(`/manage/${kind}/${id}`), { invalidate: inv, success: 'Deleted.', onSuccess: () => setConfirm(null), onError: () => setConfirm(null) });
  const move = (arr, i, d) => { const ids = arr.map((x) => x.id); [ids[i], ids[i + d]] = [ids[i + d], ids[i]]; return ids; };

  return (
    <div className="stack">
      <div className="split"><p className="muted" style={{ margin: 0 }}>Modules group lessons. Use the arrows to change the order.</p>{!locked && <button className="btn btn-primary" onClick={() => setModal({ kind: 'module' })}>Add module</button>}</div>
      {modules.length === 0 && <EmptyState icon="book" title="No modules yet">Add a module, then add lessons to it.</EmptyState>}
      {modules.map((m, mi) => (
        <section key={m.id} className="panel">
          <div className="panel-head">
            <h2>{m.title}</h2>
            {!locked && <div className="btn-row">
              <button className="icon-btn" aria-label={`Move ${m.title} up`} disabled={mi === 0} onClick={() => reorderModules.mutate(move(modules, mi, -1))}>↑</button>
              <button className="icon-btn" aria-label={`Move ${m.title} down`} disabled={mi === modules.length - 1} onClick={() => reorderModules.mutate(move(modules, mi, 1))}>↓</button>
              <button className="btn btn-secondary btn-sm" onClick={() => setModal({ kind: 'module', module: m })}>Rename</button>
              <button className="btn btn-secondary btn-sm" onClick={() => setModal({ kind: 'lesson', module: m })}>Add lesson</button>
              <button className="btn btn-quiet btn-sm" onClick={() => setConfirm({ kind: 'modules', id: m.id, title: m.title })}>Delete</button>
            </div>}
          </div>
          {m.lessons.length === 0 ? <div className="panel-body muted">No lessons in this module.</div> : (
            <ul className="list-plain">{m.lessons.map((l, li) => (
              <li key={l.id} className="split" style={{ padding: '10px 20px', borderBottom: '1px solid var(--border)' }}>
                <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                  <Icon name={l.type === 'video' ? 'video' : l.type === 'live' ? 'calendar' : l.type === 'document' ? 'file' : 'book'} size={18} />
                  <div><button className="link-btn" onClick={() => setModal({ kind: 'lesson', module: m, lesson: l })}>{l.title}</button>
                    <div className="micro muted">{l.type}{l.video_source ? ` · ${l.video_source}` : ''}{l.duration_seconds ? ` · ${clock(l.duration_seconds)}` : l.type === 'video' ? ' · duration missing' : ''}{l.is_preview ? ' · free preview' : ''}{!l.is_required ? ' · optional' : ''}{l.resources?.length ? ` · ${l.resources.length} file(s)` : ''}</div></div>
                </div>
                {!locked && <div className="btn-row">
                  <button className="icon-btn" aria-label={`Move ${l.title} up`} disabled={li === 0} onClick={() => reorderLessons.mutate({ moduleId: m.id, ids: move(m.lessons, li, -1) })}>↑</button>
                  <button className="icon-btn" aria-label={`Move ${l.title} down`} disabled={li === m.lessons.length - 1} onClick={() => reorderLessons.mutate({ moduleId: m.id, ids: move(m.lessons, li, 1) })}>↓</button>
                  <button className="btn btn-quiet btn-sm" onClick={() => setConfirm({ kind: 'lessons', id: l.id, title: l.title })}>Delete</button>
                </div>}
              </li>))}</ul>
          )}
        </section>
      ))}
      {modal?.kind === 'module' && <ModuleModal m={modal.module} onClose={() => setModal(null)} onSave={(b) => (modal.module ? editModule.mutate({ id: modal.module.id, ...b }) : addModule.mutate(b))} busy={addModule.isPending || editModule.isPending} />}
      {modal?.kind === 'lesson' && <LessonModal key={modal.lesson?.id || 'new'} courseId={course.id} module={modal.module} lesson={modal.lesson} locked={locked} onClose={() => setModal(null)} />}
      {confirm && <ConfirmDialog title={`Delete “${confirm.title}”?`} danger confirmLabel="Delete" message="This cannot be undone. Content cannot be deleted once students are enrolled." busy={remove.isPending} onCancel={() => setConfirm(null)} onConfirm={() => remove.mutate(confirm)} />}
    </div>
  );
}

function ModuleModal({ m, onClose, onSave, busy }) {
  const f = useForm({ title: m?.title || '', description: m?.description || '' });
  return (
    <Modal title={m ? 'Rename module' : 'Add module'} onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" form="mm" disabled={busy}>Save</button></>}>
      <form id="mm" onSubmit={(e) => { e.preventDefault(); onSave(f.values); }}><TextField label="Module title" required {...f.bind('title')} /><TextAreaField label="Description (optional)" rows={3} {...f.bind('description')} /></form>
    </Modal>
  );
}

function LessonModal({ courseId, module: m, lesson, locked, onClose }) {
  const isNew = !lesson;
  const f = useForm({ title: lesson?.title || '', type: lesson?.type || 'text', content: lesson?.content || '', youtube_url: lesson?.youtube_id ? `https://www.youtube.com/watch?v=${lesson.youtube_id}` : '',
    duration_minutes: lesson?.duration_seconds ? Math.round(lesson.duration_seconds / 60) : '', required_completion_percent: lesson?.required_completion_percent || '', is_required: lesson?.is_required ?? true, is_preview: lesson?.is_preview ?? false });
  const [videoMode, setVideoMode] = useState(lesson?.video_source === 'hosted' ? 'upload' : 'youtube');
  const qcAct = useAction(() => Promise.resolve(), { invalidate: [['manage', 'course']] });
  const [saved, setSaved] = useState(lesson || null);
  const save = f.handle(async (v) => {
    const body = { title: v.title, type: v.type, content: v.content || null, is_required: v.is_required, is_preview: v.is_preview,
      required_completion_percent: v.required_completion_percent ? Number(v.required_completion_percent) : null };
    if (v.type === 'video' && videoMode === 'youtube') { body.youtube_url = v.youtube_url || null; body.duration_seconds = v.duration_minutes ? Math.round(Number(v.duration_minutes) * 60) : null; }
    const r = isNew && !saved ? await post(`/manage/modules/${m.id}/lessons`, body) : await put(`/manage/lessons/${(saved || lesson).id}`, body);
    setSaved(r.data); qcAct.mutate();
    if (!(v.type === 'video' && videoMode === 'upload') && v.type !== 'document') onClose();
  });
  return (
    <Modal title={isNew ? `Add lesson to ${m.title}` : 'Edit lesson'} size="lg" onClose={onClose}
      footer={<><button className="btn btn-secondary" onClick={onClose}>Close</button>{!locked && <button className="btn btn-primary" form="lf" disabled={f.submitting}>{saved ? 'Save changes' : 'Save lesson'}</button>}</>}>
      <form id="lf" onSubmit={save} noValidate>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        <fieldset disabled={locked} style={{ border: 0, padding: 0, margin: 0 }}>
          <div className="form-grid"><TextField label="Lesson title" required {...f.bind('title')} /><SelectField label="Type" {...f.bind('type')} options={[{ value: 'text', label: 'Reading' }, { value: 'video', label: 'Video' }, { value: 'document', label: 'Study document' }, { value: 'live', label: 'Live class (attendance)' }]} /></div>
          {f.values.type === 'video' && (
            <div className="panel panel-pad" style={{ marginBottom: 16 }}>
              <SelectField label="Video source" value={videoMode} onChange={(e) => setVideoMode(e.target.value)} options={[{ value: 'youtube', label: 'YouTube link' }, { value: 'upload', label: 'Upload a video file (private hosting)' }]} />
              {videoMode === 'youtube' ? (
                <>
                  <TextField label="YouTube link" type="url" placeholder="https://www.youtube.com/watch?v=…" help="Only youtube.com and youtu.be links are accepted." {...f.bind('youtube_url')} />
                  <TextField label="Video length (minutes)" type="number" min="1" help="Required for progress tracking: YouTube does not tell the server the length." {...f.bind('duration_minutes')} />
                  <Alert type="info">Videos hosted on YouTube are played in YouTube&apos;s own player, so the academy cannot fully control them. For lessons where completion must be strongly enforced, upload the video instead.</Alert>
                </>
              ) : saved ? <VideoUpload lessonId={saved.id} existing={saved.video_source === 'hosted'} onDone={() => qcAct.mutate()} /> : <Alert type="info">Save the lesson first, then upload the video file.</Alert>}
              <TextField label="Required completion for this lesson (%)" type="number" min="1" max="100" help="Leave blank to use the course setting (90% by default)." {...f.bind('required_completion_percent')} />
            </div>
          )}
          {f.values.type !== 'live' && <TextAreaField label={f.values.type === 'video' ? 'Notes (optional)' : 'Content'} rows={8} help="Plain text or simple HTML. Scripts and styles are removed." {...f.bind('content')} />}
          <div className="form-grid"><CheckField {...f.bind('is_required', { checkbox: true })}>Required to complete the course</CheckField><CheckField {...f.bind('is_preview', { checkbox: true })}>Free preview (visible without enrolling)</CheckField></div>
        </fieldset>
      </form>
      {saved && f.values.type !== 'live' && <Documents courseId={courseId} lesson={saved} locked={locked} />}
    </Modal>
  );
}

function VideoUpload({ lessonId, existing, onDone }) {
  const [files, setFiles] = useState([]);
  const [pct, setPct] = useState(null);
  const [status, setStatus] = useState(existing ? 'processing' : null);
  const [err, setErr] = useState(null);
  const [assetId, setAssetId] = useState(null);
  const ctrl = useRef(null);
  useEffect(() => () => ctrl.current?.abort(), []);
  useEffect(() => {
    if (!assetId || status === 'ready' || status === 'failed') return undefined;
    const t = setInterval(async () => { try { const r = (await get(`/manage/video-assets/${assetId}`)).data; setStatus(r.status); if (r.status === 'ready') onDone?.(); } catch { /* retry */ } }, 5000);
    return () => clearInterval(t);
  }, [assetId, status, onDone]);
  const start = async () => {
    setErr(null);
    try {
      const f = files[0];
      const auth = (await post(`/manage/lessons/${lessonId}/video/upload`, { size_bytes: f.size, filename: f.name })).data;
      setAssetId(auth.asset_id); setStatus('uploading'); ctrl.current = new AbortController();
      await tusUpload(f, auth.upload_url, setPct, ctrl.current.signal);
      setStatus('processing');
    } catch (e) { if (e.name !== 'AbortError') { setErr(e.message || 'Upload failed'); setStatus(null); } }
  };
  return (
    <div>
      {status === 'ready' && <Alert type="success">Video is ready. Its length was detected automatically.</Alert>}
      {status === 'processing' && <Alert type="info" title="Processing">The video host is preparing your video. This can take a few minutes; you can close this window.</Alert>}
      {status === 'failed' && <Alert type="error">The video host could not process this file. Try a different file.</Alert>}
      {err && <Alert type="error">{err}</Alert>}
      {(status === null || status === 'failed') && <><FileInput label="Video file" accept="video/*" files={files} onFiles={setFiles} help="Uploads go straight to the secure video host and can resume if your connection drops." /><button type="button" className="btn btn-primary" disabled={!files.length} onClick={start}>Upload video</button></>}
      {status === 'uploading' && <><ProgressBar value={pct || 0} label="Upload progress" /><p className="small">{pct || 0}% uploaded. Keep this window open.</p></>}
    </div>
  );
}

function Documents({ courseId, lesson, locked }) {
  const q = useGet(['manage', 'lesson-docs', lesson.id], `/manage/courses/${courseId}`, undefined, { select: (r) => r.data.modules.flatMap((x) => x.lessons).find((x) => x.id === lesson.id)?.resources || [] });
  const [files, setFiles] = useState([]); const [ferr, setFerr] = useState(null);
  const f = useForm({ title: '', ack: false });
  const upload = useAction(async () => { const fd = new FormData(); fd.append('title', f.values.title || files[0].name); fd.append('file', files[0]); if (f.values.ack) fd.append('requires_acknowledgement', '1'); return api(`/manage/lessons/${lesson.id}/resources`, { method: 'POST', body: fd }); },
    { invalidate: [['manage']], success: 'Document added.', onSuccess: () => { setFiles([]); f.setValues({ title: '', ack: false }); } });
  const remove = useAction((id) => del(`/manage/resources/${id}`), { invalidate: [['manage']], success: 'Removed.' });
  return (
    <section style={{ marginTop: 16 }}>
      <h3>Study documents</h3>
      <ul className="list-plain list-rule">{(q.data || []).map((r) => <li key={r.id} className="split"><span>{r.title} <span className="muted small">({r.name}, {bytes(r.size_bytes)})</span></span>{!locked && <button className="link-btn" onClick={() => remove.mutate(r.id)}>Remove</button>}</li>)}</ul>
      {!locked && (
        <div className="panel panel-pad">
          <FileInput label="Add a document" files={files} error={ferr} onFiles={(fs, e) => { setFiles(fs); setFerr(e || null); }} maxMb={50} accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.jpg,.jpeg,.png" help="PDF, Word, PowerPoint, Excel, text or images up to 50 MB. Files are stored privately and shared only with enrolled students." />
          <TextField label="Title" {...f.bind('title')} /><CheckField {...f.bind('ack', { checkbox: true })}>Students must acknowledge they have read this</CheckField>
          <button type="button" className="btn btn-primary" disabled={!files.length || upload.isPending} onClick={() => upload.mutate()}>{upload.isPending ? 'Uploading…' : 'Upload'}</button>
        </div>
      )}
    </section>
  );
}
