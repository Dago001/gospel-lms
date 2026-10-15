import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { post } from '../../api/client.js';
import { Alert } from '../../components/ui/Fields.jsx';
import { Loading } from '../../components/ui/Display.jsx';
import { useQueryClient } from '@tanstack/react-query';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

/**
 * Paystack returns the browser here. The URL only tells us which payment to look up;
 * whether it succeeded is decided by the server asking Paystack.
 */
export function CheckoutCallback() {
  useDocumentTitle('Confirming payment');
  const [sp] = useSearchParams();
  const reference = sp.get('reference') || sp.get('trxref');
  const qc = useQueryClient();
  const [state, setState] = useState({ phase: 'checking' });
  const tries = useRef(0);

  useEffect(() => {
    if (!reference) { setState({ phase: 'missing' }); return undefined; }
    let stop = false;
    const run = async () => {
      try {
        const r = await post(`/payments/${encodeURIComponent(reference)}/verify`);
        if (stop) return;
        const s = r.data.status;
        if (s === 'success' || s === 'free') { qc.invalidateQueries(); setState({ phase: 'success', data: r.data }); return; }
        if (s === 'failed') { setState({ phase: 'failed' }); return; }
        if (s === 'mismatch') { setState({ phase: 'mismatch' }); return; }
        if (s === 'abandoned') { setState({ phase: 'abandoned' }); return; }
        throw new Error('pending');
      } catch (e) {
        if (stop) return;
        if (e.status === 404) { setState({ phase: 'missing' }); return; }
        if (++tries.current >= 8) { setState({ phase: 'slow' }); return; }
        setTimeout(run, Math.min(2000 * tries.current, 8000));        // payment may still be settling; ask again
      }
    };
    run();
    return () => { stop = true; };
  }, [reference, qc]);

  const { phase, data } = state;
  return (
    <div className="auth-wrap" style={{ maxWidth: 560 }}>
      <div className="panel panel-pad" aria-live="polite">
        {phase === 'checking' && <><h1 style={{ fontSize: 'var(--fs-section)' }}>Confirming your payment…</h1><Loading rows={2} label="Confirming payment" /><p className="small muted">Please do not close this page or pay again.</p></>}
        {phase === 'success' && <><h1 style={{ fontSize: 'var(--fs-section)' }}>Payment confirmed</h1><Alert type="success">Thank you. Your course is now available.</Alert><div className="btn-row"><Link className="btn btn-primary" to="/student/courses">Go to my courses</Link>{data.receipt_id && <a className="btn btn-secondary" href={`/api/v1/student/receipts/${data.receipt_id}/pdf`} download>Download receipt</a>}</div></>}
        {phase === 'failed' && <><h1 style={{ fontSize: 'var(--fs-section)' }}>Payment did not go through</h1><Alert type="error">Your bank or card was not charged for this order. You can try again.</Alert><Link className="btn btn-primary" to="/courses">Back to courses</Link></>}
        {phase === 'abandoned' && <><h1 style={{ fontSize: 'var(--fs-section)' }}>Checkout not completed</h1><Alert type="info">No payment was made. You can start again from the course page.</Alert><Link className="btn btn-primary" to="/courses">Back to courses</Link></>}
        {phase === 'mismatch' && <><h1 style={{ fontSize: 'var(--fs-section)' }}>We need to review this payment</h1><Alert type="warning">The payment details did not match your order, so access was not granted automatically. Our finance team has been flagged. Please contact support with reference <strong>{reference}</strong>.</Alert><Link className="btn btn-primary" to="/student/support">Contact support</Link></>}
        {phase === 'slow' && <><h1 style={{ fontSize: 'var(--fs-section)' }}>Still waiting for confirmation</h1><Alert type="info">If you completed the payment, it will be confirmed automatically within a few minutes. You will get an email. Do not pay again.</Alert><Link className="btn btn-secondary" to="/student/billing">Check in Payments &amp; receipts</Link></>}
        {phase === 'missing' && <><h1 style={{ fontSize: 'var(--fs-section)' }}>Payment not found</h1><Alert type="warning">We could not find that payment on your account.</Alert><Link className="btn btn-secondary" to="/student/billing">View my payments</Link></>}
      </div>
    </div>
  );
}
