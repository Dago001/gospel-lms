import { Link } from 'react-router-dom';
import { useGet } from '../../api/hooks.js';
import { useAuth } from '../../auth/AuthContext.jsx';
import { ErrorState, Loading, Money, PageHeader, SectionHeading, Stats } from '../../components/ui/Display.jsx';
import { BarChart } from '../../components/ui/BarChart.jsx';
import { money } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function AdminDashboard() {
  useDocumentTitle('Administration');
  const { can } = useAuth();
  const q = useGet(['admin', 'dashboard'], '/admin/dashboard');
  const rev = useGet(['admin', 'finance-summary'], '/admin/finance/summary', undefined, { enabled: can('reports.finance') });
  if (q.isLoading) return <Loading rows={6} />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  const { academic: a, finance: f } = q.data.data;
  const days = (rev.data?.data?.daily_revenue || []).map((d) => ({ label: String(d.day).slice(5), value: Number(d.total_minor) / 100 }));
  return (
    <div className="stack">
      <PageHeader title="Administration" subtitle="Live figures from the platform's records." />
      {a && (
        <>
          <Stats items={[
            { label: 'Users', value: a.users.total, hint: `${a.users.suspended} suspended` },
            { label: 'Published courses', value: a.courses.published ?? 0 }, { label: 'Courses awaiting review', value: a.courses.pending_review ?? 0 },
            { label: 'Active enrolments', value: a.enrolments.active }, { label: 'Completed enrolments', value: a.enrolments.completed },
            { label: 'Active students (7 days)', value: a.active_students.last_7_days }, { label: 'Active students (30 days)', value: a.active_students.last_30_days },
          ]} />
          <Stats items={[
            { label: 'Assessment pass rate', value: a.assessments.pass_rate != null ? `${a.assessments.pass_rate}%` : '-', hint: `${a.assessments.results_released} results` },
            { label: 'Assignments to grade', value: a.pending_grading.assignments }, { label: 'Exams to mark', value: a.pending_grading.assessments },
            { label: 'Certificates issued', value: a.certificates.active }, { label: 'Certificates revoked', value: a.certificates.revoked }, { label: 'Open support requests', value: a.support.open },
          ]} />
        </>
      )}
      {f && (
        <section>
          <SectionHeading title="Finance, last 30 days" action={<Link to="/admin/finance">Payments</Link>} />
          <Stats items={[
            { label: 'Revenue', value: f.revenue_30_days.length ? f.revenue_30_days.map((r) => money(Number(r.total_minor), r.currency)).join(' + ') : money(0), hint: `${f.revenue_30_days.reduce((n, r) => n + Number(r.count), 0)} payments` },
            { label: 'Failed payments', value: f.failed_30_days }, { label: 'Refunded', value: f.refunds_30_days.length ? f.refunds_30_days.map((r) => money(Number(r.total_minor), r.currency)).join(' + ') : money(0) },
            { label: 'Open exceptions', value: f.open_exceptions },
          ]} />
          {days.length > 0 && <div className="panel panel-pad" style={{ marginTop: 16 }}><h3>Revenue by day (₦)</h3><BarChart data={days} label="Revenue by day in naira" valueLabel="Revenue (naira)" format={(v) => Math.round(v).toLocaleString('en-NG')} /></div>}
        </section>
      )}
    </div>
  );
}
