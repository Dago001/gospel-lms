import { useState } from 'react';
import { useGet } from '../../api/hooks.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { SelectField, TextField } from '../../components/ui/Fields.jsx';
import { PageHeader } from '../../components/ui/Display.jsx';
import { BarChart } from '../../components/ui/BarChart.jsx';
import { Tabs } from '../../components/ui/Tabs.jsx';
import { dateTime } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

const REPORTS = [['enrolments', 'Enrolments'], ['completion', 'Completion'], ['assessments', 'Assessment pass rates'], ['grading-queue', 'Grading queue'], ['attendance', 'Attendance'], ['certificates', 'Certificates']];

export function Reports() {
  useDocumentTitle('Reports');
  const [tab, setTab] = useState('enrolments');
  const today = new Date().toISOString().slice(0, 10);
  const [range, setRange] = useState({ from: new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10), to: today });
  const q = useGet(['admin', 'report', tab], `/admin/reports/${tab}`, range);
  const csv = `/api/v1/admin/reports/${tab}?${new URLSearchParams({ ...range, format: 'csv' })}`;
  const d = q.data?.data;
  return (
    <div className="stack">
      <PageHeader title="Reports" subtitle="Computed from the live records. Export any report as CSV." actions={<a className="btn btn-secondary" href={csv} download>Export CSV</a>} />
      <div className="filters">{['enrolments', 'certificates'].includes(tab) && <><TextField label="From" type="date" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /><TextField label="To" type="date" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></>}</div>
      <Tabs tabs={REPORTS.map(([id, label]) => ({ id, label }))} active={tab} onChange={setTab}>
        {tab === 'enrolments' && <>
          {d && <div className="panel panel-pad" style={{ marginBottom: 16 }}><h3>Enrolments per day</h3><BarChart label="Enrolments per day" valueLabel="Enrolments" data={Object.values(d.daily.reduce((m, r) => { m[r.day] = m[r.day] || { label: String(r.day).slice(5), value: 0 }; m[r.day].value += Number(r.count); return m; }, {}))} /></div>}
          <DataTable caption="Enrolments by course" loading={q.isLoading} error={q.error} rows={d?.by_course?.map((r) => ({ ...r, id: r.title }))} columns={[{ key: 'title', header: 'Course' }, { key: 'count', header: 'Enrolments', numeric: true }]} /></>}
        {tab === 'completion' && <DataTable caption="Completion by course" loading={q.isLoading} error={q.error} rows={d} columns={[{ key: 'title', header: 'Course' }, { key: 'enrolled', header: 'Enrolled', numeric: true }, { key: 'completed', header: 'Completed', numeric: true }, { key: 'completion_rate', header: 'Rate', numeric: true, render: (r) => (r.completion_rate != null ? `${r.completion_rate}%` : '-') }]} />}
        {tab === 'assessments' && <DataTable caption="Assessment pass rates" loading={q.isLoading} error={q.error} rows={d} columns={[{ key: 'course', header: 'Course' }, { key: 'title', header: 'Assessment' }, { key: 'attempts', header: 'Attempts', numeric: true }, { key: 'passed', header: 'Passed', numeric: true }, { key: 'pass_rate', header: 'Pass rate', numeric: true, render: (r) => (r.pass_rate != null ? `${r.pass_rate}%` : '-') }, { key: 'average_percent', header: 'Average', numeric: true, render: (r) => (r.average_percent != null ? `${r.average_percent}%` : '-') }]} />}
        {tab === 'grading-queue' && <DataTable caption="Work awaiting grading" loading={q.isLoading} error={q.error} rows={d} columns={[{ key: 'title', header: 'Course' }, { key: 'assignments_awaiting_grade', header: 'Assignments to grade', numeric: true }, { key: 'assessments_awaiting_marking', header: 'Exams to mark', numeric: true }]} />}
        {tab === 'attendance' && <DataTable caption="Attendance by class" loading={q.isLoading} error={q.error} rows={d} columns={[{ key: 'course', header: 'Course' }, { key: 'title', header: 'Class' }, { key: 'starts_at', header: 'Held', render: (r) => dateTime(r.starts_at) }, { key: 'present', header: 'Present', numeric: true }, { key: 'enrolled', header: 'Enrolled', numeric: true }, { key: 'attendance_rate', header: 'Rate', numeric: true, render: (r) => (r.attendance_rate != null ? `${r.attendance_rate}%` : '-') }]} />}
        {tab === 'certificates' && <DataTable caption="Certificates issued by month" loading={q.isLoading} error={q.error} rows={d?.map((r) => ({ ...r, id: r.month }))} columns={[{ key: 'month', header: 'Month' }, { key: 'active', header: 'Active', numeric: true }, { key: 'revoked', header: 'Revoked', numeric: true }]} />}
      </Tabs>
    </div>
  );
}
