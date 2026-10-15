import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useGet } from '../../api/hooks.js';
import { Alert, TextField } from '../../components/ui/Fields.jsx';
import { Loading, PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { date } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export default function VerifyCertificate() {
  useDocumentTitle('Verify a certificate');
  const { code } = useParams();
  const nav = useNavigate();
  const [input, setInput] = useState('');
  const q = useGet(['public', 'verify', code], `/public/certificates/verify/${code}`, undefined, { enabled: !!code, retry: false });
  const r = q.data?.data;
  return (
    <div className="container band">
      <PageHeader title="Verify a certificate" subtitle="Enter the verification code printed on the certificate, or scan its QR code." />
      <form onSubmit={(e) => { e.preventDefault(); input.trim() && nav(`/verify/${encodeURIComponent(input.trim())}`); }} style={{ maxWidth: 560, display: 'flex', gap: 8, alignItems: 'flex-end' }}>
        <div style={{ flex: 1 }}><TextField label="Verification code" value={input} onChange={(e) => setInput(e.target.value)} autoComplete="off" spellCheck="false" /></div>
        <button className="btn btn-primary" style={{ marginBottom: 16 }}>Verify</button>
      </form>
      {code && (
        <section aria-live="polite" style={{ maxWidth: 640, marginTop: 24 }}>
          {q.isLoading ? <Loading rows={2} label="Checking" /> : q.error?.status === 404 ? (
            <Alert type="error" title="No certificate found">We could not find a certificate with that code. Check it and try again.</Alert>
          ) : q.error ? <Alert type="error" title="Could not verify right now">Please try again in a moment.</Alert> : (
            <div className="panel panel-pad">
              <div className="split"><h2 style={{ margin: 0 }}>{r.status === 'valid' ? 'Valid certificate' : 'This certificate has been revoked'}</h2><StatusBadge status={r.status} /></div>
              {r.status === 'revoked' && <p className="muted">The academy has withdrawn this certificate. It should not be relied upon.</p>}
              <dl className="kv" style={{ marginTop: 16 }}>
                <dt>Holder</dt><dd>{r.holder_name}</dd><dt>Course</dt><dd>{r.course_title}</dd><dt>Issued on</dt><dd>{date(r.issued_on)}</dd>
                <dt>Certificate no.</dt><dd>{r.certificate_number}</dd><dt>Issued by</dt><dd>{r.issuer}</dd>
              </dl>
            </div>
          )}
        </section>
      )}
    </div>
  );
}
