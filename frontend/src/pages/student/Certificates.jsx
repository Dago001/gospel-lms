import { useState } from 'react';
import { useGet } from '../../api/hooks.js';
import { EmptyState, ErrorState, Loading, PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { date } from '../../lib/format.js';
import { useAuth } from '../../auth/AuthContext.jsx';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Certificates() {
  useDocumentTitle('Certificates');
  const { user } = useAuth();
  const q = useGet(['student', 'certificates'], '/learn/certificates');
  const [view, setView] = useState(null);
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  return (
    <div>
      <PageHeader title="Certificates" subtitle="Issued automatically when you complete a course." />
      {q.data.data.length === 0 ? <EmptyState icon="award" title="No certificates yet">Complete all the requirements of a course and your certificate will appear here.</EmptyState> : (
        <ul className="list-plain panel list-rule" style={{ padding: '0 20px' }}>
          {q.data.data.map((c) => (
            <li key={c.id} className="split">
              <div><h3 style={{ margin: 0 }}>{c.course_title}</h3><div className="small muted">No. {c.number} · Issued {date(c.issued_at)}</div></div>
              <div className="btn-row"><StatusBadge status={c.status} />{c.status === 'active' && <><button className="btn btn-secondary btn-sm" onClick={() => setView(c)}>Preview</button><a className="btn btn-primary btn-sm" href={`/api/v1/learn/certificates/${c.id}/pdf`} download>Download PDF</a></>}</div>
            </li>
          ))}
        </ul>
      )}
      {view && (
        <Modal title="Certificate preview" size="lg" onClose={() => setView(null)} footer={<><a className="btn btn-secondary" href={`/verify/${view.verification_code}`} target="_blank" rel="noreferrer">Open public verification page</a><a className="btn btn-primary" href={`/api/v1/learn/certificates/${view.id}/pdf`} download>Download PDF</a></>}>
          <div className="cert-preview"><div>
            <div className="section-label">GOSPEL LMS</div><h2 className="serif" style={{ color: 'var(--green-dark)', fontWeight: 400 }}>Certificate of Completion</h2>
            <p className="small muted" style={{ margin: '0 auto' }}>This is to certify that</p><div className="cert-name">{user.name}</div>
            <p className="small muted" style={{ margin: '0 auto' }}>has successfully completed</p><h3 className="serif" style={{ color: 'var(--green)' }}>{view.course_title}</h3>
            <p className="small muted" style={{ margin: '0 auto' }}>Issued {date(view.issued_at)} · No. {view.number}</p>
          </div></div>
          <p className="small muted">This is a preview. The downloadable PDF includes the QR code, signatory and verification link.</p>
        </Modal>
      )}
    </div>
  );
}
