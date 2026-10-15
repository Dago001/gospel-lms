import { Link } from 'react-router-dom';
import { useGet } from '../../api/hooks.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { EmptyState, PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { date, titleCase } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Results() {
  useDocumentTitle('Results');
  const q = useGet(['student', 'results'], '/student/results');
  return (
    <div>
      <PageHeader title="Results" subtitle="Assignments and assessments that have been graded and released to you." />
      <DataTable caption="Released results" loading={q.isLoading} error={q.error} onRetry={q.refetch} rows={q.data?.data} rowKey="title"
        empty={<EmptyState icon="clipboard" title="No results yet">Results appear here once your lecturer releases them.</EmptyState>}
        columns={[
          { key: 'course', header: 'Course', sortable: true },
          { key: 'title', header: 'Work', sortable: true, render: (r) => <>{r.title}<div className="micro muted">{titleCase(r.kind === 'assessment' ? r.assessment_kind : 'assignment')}{r.attempt > 1 ? ` · attempt ${r.attempt}` : ''}</div></> },
          { key: 'score', header: 'Score', numeric: true, render: (r) => `${r.score} / ${r.max}` },
          { key: 'percent', header: 'Percent', numeric: true, sortable: true, render: (r) => `${r.percent}%` },
          { key: 'passed', header: 'Outcome', render: (r) => r.passed == null ? '-' : <StatusBadge status={r.passed ? 'success' : 'failed'} label={r.passed ? 'Passed' : 'Not passed'} /> },
          { key: 'at', header: 'Released', sortable: true, render: (r) => date(r.at) },
          { key: 'view', header: '', render: (r) => r.kind === 'assessment' ? <Link to={`/student/attempts/${r.attempt_id}`}>Review</Link> : <Link to={`/student/courses/${r.course_id}?tab=assignments`}>Feedback</Link> },
        ]} />
    </div>
  );
}
