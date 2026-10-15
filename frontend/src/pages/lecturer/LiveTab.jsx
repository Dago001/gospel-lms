import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { del, post, put } from '../../api/client.js';
import { Alert, CheckField, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { EmptyState, Loading, SectionHeading, StatusBadge } from '../../components/ui/Display.jsx';
import { ConfirmDialog, Modal } from '../../components/ui/Modal.jsx';
import { dateTime } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';

export function LiveTab({ course }) {
  const q = useGet(['manage', 'live', course.id], `/manage/courses/${course.id}/live-sessions`);
  const [creating, setCreating] = useState(false);
  const [att, setAtt] = useState(null);
  const [rec, setRec] = useState(null);
  const [cancel, setCancel] = useState(null);
  const remove = useAction((id) => del(`/manage/live-sessions/${id}`), { invalidate: [['manage', 'live']], success: 'Session cancelled.', onSuccess: () => setCancel(null), onError: () => setCancel(null) });
  return (
    <div className="stack">
      <div className="split"><SectionHeading title="Live classes" /><button className="btn btn-primary" onClick={() => setCreating(true)}>Schedule a class</button></div>
      <DataTable caption="Live classes" loading={q.isLoading} error={q.error} rows={q.data?.data} empty={<EmptyState icon="calendar" title="No live classes scheduled" />}
        columns={[
          { key: 'title', header: 'Class' }, { key: 'starts_at', header: 'Starts', sortable: true, render: (s) => dateTime(s.starts_at) }, { key: 'duration_minutes', header: 'Minutes', numeric: true },
          { key: 'provider', header: 'Via', render: (s) => (s.provider === 'zoom' ? 'Zoom' : 'Link') }, { key: 'status', header: 'Status', render: (s) => <StatusBadge status={s.status} /> },
          { key: 'a', header: '', render: (s) => <span className="btn-row">
            <button className="btn btn-secondary btn-sm" onClick={() => setAtt(s)}>Attendance</button>
            {s.provider === 'zoom' && <button className="btn btn-quiet btn-sm" onClick={() => setRec(s)}>Recordings</button>}
            {!['ended', 'cancelled'].includes(s.status) && <button className="btn btn-quiet btn-sm" onClick={() => setCancel(s)}>Cancel</button>}</span> },
        ]} />
      {creating && <CreateSession course={course} onClose={() => setCreating(false)} />}
      {att && <Attendance session={att} onClose={() => setAtt(null)} />}
      {rec && <Recordings session={rec} onClose={() => setRec(null)} />}
      {cancel && <ConfirmDialog title={`Cancel “${cancel.title}”?`} danger confirmLabel="Cancel class" message="The Zoom meeting is deleted. Students can no longer join." busy={remove.isPending} onCancel={() => setCancel(null)} onConfirm={() => remove.mutate(cancel.id)} />}
    </div>
  );
}

function CreateSession({ course, onClose }) {
  const f = useForm({ title: '', description: '', starts_at: '', duration_minutes: 60, provider: 'zoom', join_url: '', counts_for_attendance: true });
  const act = useAction(() => Promise.resolve(), { invalidate: [['manage', 'live']] });
  const save = f.handle(async (v) => { await post(`/manage/courses/${course.id}/live-sessions`, { ...v, starts_at: new Date(v.starts_at).toISOString(), duration_minutes: Number(v.duration_minutes), join_url: v.provider === 'manual' ? v.join_url : undefined }); act.mutate(); onClose(); });
  return (
    <Modal title="Schedule a live class" onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" form="ls" disabled={f.submitting}>Schedule</button></>}>
      <form id="ls" onSubmit={save} noValidate>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        <TextField label="Title" required {...f.bind('title')} /><TextAreaField label="Description (optional)" rows={2} {...f.bind('description')} />
        <div className="form-grid"><TextField label="Starts (your local time)" type="datetime-local" required {...f.bind('starts_at')} /><TextField label="Length (minutes)" type="number" min="5" max="480" {...f.bind('duration_minutes')} /></div>
        <SelectField label="How will it run?" {...f.bind('provider')} options={[{ value: 'zoom', label: 'Zoom (created for you; attendance recorded automatically)' }, { value: 'manual', label: 'Another meeting link (you record attendance)' }]} />
        {f.values.provider === 'manual' && <TextField label="Meeting link" type="url" placeholder="https://…" required {...f.bind('join_url')} />}
        <CheckField {...f.bind('counts_for_attendance', { checkbox: true })}>Counts towards attendance</CheckField>
      </form>
    </Modal>
  );
}

function Attendance({ session, onClose }) {
  const q = useGet(['manage', 'attendance', session.id], `/manage/live-sessions/${session.id}/attendance`);
  const [edit, setEdit] = useState(null);
  const f = useForm({ present: true, note: '' });
  const set = useAction(() => put(`/manage/live-sessions/${session.id}/attendance/${edit.user.id}`, f.values), { invalidate: [['manage', 'attendance']], success: 'Attendance updated.', onSuccess: () => setEdit(null) });
  const sync = useAction(() => post(`/manage/live-sessions/${session.id}/sync-attendance`), { success: 'Attendance sync queued. Refresh in a few minutes.' });
  return (
    <Modal title={`Attendance: ${session.title}`} size="lg" onClose={onClose} footer={session.provider === 'zoom' ? <button className="btn btn-secondary" onClick={() => sync.mutate()}>Re-fetch from Zoom</button> : null}>
      {q.isLoading ? <Loading /> : (
        <>
          <p className="small muted">Present means at least {q.data.required_minutes} minutes. Manual changes always take precedence over Zoom&apos;s report and are recorded in the audit log.</p>
          <DataTable caption="Attendance" rows={q.data.data} rowKey="user" columns={[
            { key: 'name', header: 'Student', render: (r) => r.user.name }, { key: 'minutes', header: 'Minutes', numeric: true },
            { key: 'present', header: 'Status', render: (r) => <StatusBadge status={r.present ? 'present' : 'absent'} /> }, { key: 'source', header: 'Source', render: (r) => r.source || '-' },
            { key: 'a', header: '', render: (r) => <button className="link-btn" onClick={() => { f.setValues({ present: !r.present, note: '' }); setEdit(r); }}>Mark {r.present ? 'absent' : 'present'}</button> },
          ]} />
          {edit && <Modal title={`Mark ${edit.user.name} ${f.values.present ? 'present' : 'absent'}`} onClose={() => setEdit(null)} footer={<><button className="btn btn-secondary" onClick={() => setEdit(null)}>Cancel</button><button className="btn btn-primary" disabled={f.values.note.length < 3 || set.isPending} onClick={() => set.mutate()}>Save</button></>}><TextField label="Reason" help="Recorded in the audit log." {...f.bind('note')} /></Modal>}
        </>
      )}
    </Modal>
  );
}

function Recordings({ session, onClose }) {
  const q = useGet(['manage', 'recordings', session.id], `/manage/live-sessions/${session.id}/recordings`);
  const toggle = useAction(({ id, published }) => put(`/manage/recordings/${id}`, { published }), { invalidate: [['manage', 'recordings']], success: 'Updated.' });
  return (
    <Modal title={`Recordings: ${session.title}`} onClose={onClose}>
      {q.isLoading ? <Loading /> : q.data.data.length === 0 ? <p className="muted">No recordings yet. Zoom sends them here when processing finishes.</p> : (
        <ul className="list-plain list-rule">{q.data.data.map((r) => <li key={r.id} className="split"><span>Recording {r.duration_seconds ? `(${Math.round(r.duration_seconds / 60)} min)` : ''} <StatusBadge status={r.published ? 'published' : 'draft'} label={r.published ? 'Visible to students' : 'Hidden'} /></span><button className="btn btn-secondary btn-sm" onClick={() => toggle.mutate({ id: r.id, published: !r.published })}>{r.published ? 'Hide' : 'Release to students'}</button></li>)}</ul>
      )}
      <p className="small muted">Recordings stay hidden until you release them. The recording link itself is held by Zoom; release only what you are comfortable sharing with every enrolled student.</p>
    </Modal>
  );
}
