import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { del, post } from '../../api/client.js';
import { Alert, CheckField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { EmptyState, Loading, RichText, SectionHeading, StatusBadge, Stats } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { date, dateTime } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';

export function AnnouncementsTab({ course }) {
  const q = useGet(['learn', 'announcements', course.id], `/learn/courses/${course.id}/announcements`);
  const f = useForm({ title: '', body: '', notify: true, pinned: false });
  const [open, setOpen] = useState(false);
  const post_ = useAction(() => post(`/manage/courses/${course.id}/announcements`, f.values), { invalidate: [['learn', 'announcements']], success: 'Announcement posted.', onSuccess: () => { setOpen(false); f.setValues({ title: '', body: '', notify: true, pinned: false }); } });
  const remove = useAction((id) => del(`/manage/announcements/${id}`), { invalidate: [['learn', 'announcements']], success: 'Removed.' });
  return (
    <div className="stack">
      <div className="split"><SectionHeading title="Announcements" /><button className="btn btn-primary" onClick={() => setOpen(true)}>New announcement</button></div>
      {q.isLoading ? <Loading /> : q.data.data.length === 0 ? <EmptyState icon="bell" title="No announcements yet" /> : q.data.data.map((a) => (
        <div className="panel panel-pad" key={a.id}><div className="split"><h3 style={{ margin: 0 }}>{a.title}</h3><span className="small muted">{date(a.publish_at)}</span></div><RichText html={a.body} /><button className="link-btn small" onClick={() => remove.mutate(a.id)}>Remove</button></div>
      ))}
      {open && <Modal title="New announcement" onClose={() => setOpen(false)} footer={<><button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button><button className="btn btn-primary" disabled={!f.values.title || !f.values.body || post_.isPending} onClick={() => post_.mutate()}>Post</button></>}>
        <TextField label="Title" {...f.bind('title')} /><TextAreaField label="Message" rows={6} {...f.bind('body')} />
        <CheckField {...f.bind('notify', { checkbox: true })}>Also notify enrolled students by email and in the app</CheckField><CheckField {...f.bind('pinned', { checkbox: true })}>Pin to the top</CheckField></Modal>}
    </div>
  );
}

export function StudentsTab({ course }) {
  const [page, setPage] = useState(1);
  const q = useGet(['manage', 'students', course.id], `/manage/courses/${course.id}/completion-report`, { page });
  const pol = useGet(['manage', 'policy', course.id], `/manage/courses/${course.id}/completion-policy`);
  const [detail, setDetail] = useState(null);
  return (
    <div className="stack">
      {pol.data?.data?.problems?.length > 0 && <Alert type="warning" title="Check your grading weights">{pol.data.data.problems.join(' ')}</Alert>}
      <PolicyCard course={course} policy={pol.data?.data?.policy} />
      <section><SectionHeading title="Students and completion" />
        <DataTable caption="Students" loading={q.isLoading} error={q.error} onRetry={q.refetch} rows={q.data?.data} rowKey="user" meta={q.data?.meta} onPage={setPage} empty={<EmptyState icon="users" title="No students enrolled yet" />}
          columns={[
            { key: 'name', header: 'Student', sortable: true, sortValue: (r) => r.user.name, render: (r) => <button className="link-btn" onClick={() => setDetail(r.user)}>{r.user.name}</button> },
            { key: 'enrolment_status', header: 'Enrolment', render: (r) => <StatusBadge status={r.enrolment_status} /> },
            { key: 'eligible', header: 'Meets requirements', render: (r) => r.eligible == null ? <span className="muted">Not evaluated</span> : <StatusBadge status={r.eligible ? 'success' : 'pending'} label={r.eligible ? 'Yes' : 'Not yet'} /> },
            { key: 'grade_percent', header: 'Grade', numeric: true, sortable: true, render: (r) => (r.grade_percent != null ? `${r.grade_percent}%` : '-') },
            { key: 'reason_codes', header: 'Outstanding', render: (r) => (r.reason_codes?.length ? <span className="small">{r.reason_codes.map((c) => c.replace(/_/g, ' ')).join(', ')}</span> : '-') },
          ]} />
      </section>
      {detail && <StudentDetail course={course} student={detail} approvalNeeded={pol.data?.data?.policy?.lecturer_approval_required} onClose={() => setDetail(null)} />}
    </div>
  );
}

function PolicyCard({ course, policy }) {
  const f = useForm({ course_pass_percent: policy?.course_pass_percent ?? 50, attempt_selection: policy?.attempt_selection ?? 'best', min_attendance_percent: policy?.min_attendance_percent ?? '', lecturer_approval_required: policy?.lecturer_approval_required ?? false, show_grade_on_certificate: policy?.show_grade_on_certificate ?? false });
  const [open, setOpen] = useState(false);
  const save = useAction(() => import('../../api/client.js').then((m) => m.put(`/manage/courses/${course.id}/completion-policy`, { ...f.values, course_pass_percent: Number(f.values.course_pass_percent), min_attendance_percent: f.values.min_attendance_percent === '' ? null : Number(f.values.min_attendance_percent) })),
    { invalidate: [['manage', 'policy']], success: 'Completion rules saved.', onSuccess: () => setOpen(false) });
  if (!policy) return null;
  return (
    <div className="panel panel-pad split">
      <div><h2 style={{ fontSize: 'var(--fs-sub)', marginBottom: 4 }}>Completion rules</h2>
        <p className="small muted" style={{ margin: 0 }}>Overall pass mark {policy.course_pass_percent}% · uses each student&apos;s {policy.attempt_selection} attempt · attendance {policy.min_attendance_percent != null ? `${policy.min_attendance_percent}% required` : 'not required'}{policy.lecturer_approval_required ? ' · your approval required' : ''}</p></div>
      <button className="btn btn-secondary" onClick={() => setOpen(true)}>Edit rules</button>
      {open && <Modal title="Completion rules" onClose={() => setOpen(false)} footer={<><button className="btn btn-secondary" onClick={() => setOpen(false)}>Cancel</button><button className="btn btn-primary" onClick={() => save.mutate()}>Save</button></>}>
        <Alert type="info">Every required assignment and exam must also be passed on its own. A high average never makes up for a failed required component.</Alert>
        <TextField label="Overall course pass mark (%)" type="number" min="0" max="100" {...f.bind('course_pass_percent')} />
        <div className="field"><label className="label" htmlFor="sel">When a student has several attempts</label><select id="sel" className="select" {...f.bind('attempt_selection')}><option value="best">Use the best attempt</option><option value="latest">Use the latest attempt</option></select></div>
        <TextField label="Minimum live-class attendance (%)" type="number" min="0" max="100" help="Leave blank if attendance is not required." {...f.bind('min_attendance_percent')} />
        <CheckField {...f.bind('lecturer_approval_required', { checkbox: true })}>A lecturer must approve each student&apos;s completion</CheckField>
        <CheckField {...f.bind('show_grade_on_certificate', { checkbox: true })}>Show the final grade on the certificate</CheckField></Modal>}
    </div>
  );
}

function StudentDetail({ course, student, approvalNeeded, onClose }) {
  const q = useGet(['manage', 'student-completion', student.id], `/manage/courses/${course.id}/students/${student.id}/completion`);
  const approve = useAction(() => post(`/manage/courses/${course.id}/students/${student.id}/approve-completion`), { invalidate: [['manage', 'student-completion'], ['manage', 'students']], success: 'Approved.' });
  return (
    <Modal title={student.name} size="lg" onClose={onClose} footer={approvalNeeded && q.data && !q.data.data.eligible && q.data.data.reasons.some((r) => r.code === 'awaiting_lecturer_approval') ? <button className="btn btn-primary" onClick={() => approve.mutate()}>Approve completion</button> : null}>
      {q.isLoading ? <Loading /> : (
        <>
          {q.data.data.eligible ? <Alert type="success">Meets every requirement.</Alert> : <Alert type="warning" title="Outstanding"><ul style={{ margin: 0 }}>{q.data.data.reasons.map((r) => <li key={r.code + (r.item || '')}>{r.message}</li>)}</ul></Alert>}
          <DataTable caption="Components" rows={q.data.data.components.map((c, i) => ({ ...c, id: i }))} columns={[
            { key: 'title', header: 'Component', render: (c) => c.title || c.type }, { key: 'status', header: 'Status', render: (c) => (c.status ? <StatusBadge status={c.status} /> : c.type === 'lessons' ? `${c.completed}/${c.required}` : `${c.attended}/${c.held}`) },
            { key: 'weight', header: 'Weight', numeric: true, render: (c) => (c.weight ? `${c.weight}%` : '-') }, { key: 'percent', header: 'Result', numeric: true, render: (c) => (c.percent != null ? `${c.percent}%` : '-') },
          ]} />
        </>
      )}
    </Modal>
  );
}

export function AnalyticsTab({ course }) {
  const q = useGet(['manage', 'analytics', course.id], `/manage/courses/${course.id}/analytics`);
  if (q.isLoading) return <Loading />;
  const d = q.data.data;
  return (
    <div className="stack">
      <Stats items={[{ label: 'Enrolled', value: d.completion?.enrolled ?? 0 }, { label: 'Completed', value: d.completion?.completed ?? 0 }, { label: 'Completion rate', value: d.completion?.completion_rate != null ? `${d.completion.completion_rate}%` : '-' },
        { label: 'Awaiting grading', value: (d.grading_queue?.assignments_awaiting_grade ?? 0) + (d.grading_queue?.assessments_awaiting_marking ?? 0) }]} />
      <section><SectionHeading title="Assessment results" />
        <DataTable caption="Assessment pass rates" rows={d.assessments} columns={[{ key: 'title', header: 'Assessment' }, { key: 'attempts', header: 'Released attempts', numeric: true }, { key: 'passed', header: 'Passed', numeric: true }, { key: 'pass_rate', header: 'Pass rate', numeric: true, render: (r) => (r.pass_rate != null ? `${r.pass_rate}%` : '-') }, { key: 'average_percent', header: 'Average', numeric: true, render: (r) => (r.average_percent != null ? `${r.average_percent}%` : '-') }]} /></section>
      <section><SectionHeading title="Live class attendance" />
        <DataTable caption="Attendance" rows={d.attendance} columns={[{ key: 'title', header: 'Class' }, { key: 'starts_at', header: 'Held', render: (r) => dateTime(r.starts_at) }, { key: 'present', header: 'Present', numeric: true }, { key: 'enrolled', header: 'Enrolled', numeric: true }, { key: 'attendance_rate', header: 'Rate', numeric: true, render: (r) => (r.attendance_rate != null ? `${r.attendance_rate}%` : '-') }]} /></section>
    </div>
  );
}

export function VersionsTab({ course }) {
  const q = useGet(['manage', 'versions', course.id], `/manage/courses/${course.id}/versions`);
  return (
    <DataTable caption="Version history" loading={q.isLoading} error={q.error} rows={q.data?.data} rowKey="version" empty={<EmptyState title="No versions recorded yet">A snapshot is saved each time the course is edited, approved or published.</EmptyState>}
      columns={[{ key: 'version', header: 'Version', numeric: true }, { key: 'note', header: 'Event' }, { key: 'created_at', header: 'When', render: (v) => dateTime(v.created_at) },
        { key: 'title', header: 'Title at the time', render: (v) => v.snapshot.course.title }, { key: 'status', header: 'Status then', render: (v) => v.snapshot.course.status },
        { key: 'lessons', header: 'Lessons', numeric: true, render: (v) => v.snapshot.modules.reduce((n, m) => n + m.lessons.length, 0) }]} />
  );
}
