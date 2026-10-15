import { useState } from 'react';
import { useAction, useGet } from '../../api/hooks.js';
import { post } from '../../api/client.js';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Alert } from '../../components/ui/Fields.jsx';
import { EmptyState, Money, PageHeader, StatusBadge } from '../../components/ui/Display.jsx';
import { dateTime } from '../../lib/format.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Billing() {
  useDocumentTitle('Payments & receipts');
  const [page, setPage] = useState(1);
  const q = useGet(['student', 'orders'], '/student/orders', { page });
  const [msg, setMsg] = useState(null);
  const check = useAction((ref) => post(`/payments/${ref}/verify`), { quietError: true, invalidate: [['student'], ['learn']],
    onSuccess: (r) => setMsg(r.data.status === 'success' || r.data.status === 'free' ? { t: 'success', m: 'Payment confirmed. Your course is ready.' } : { t: 'info', m: `Payment status: ${r.data.status}. If you completed the payment, wait a minute and check again.` }) });
  return (
    <div className="stack">
      <PageHeader title="Payments & receipts" subtitle="Your orders and the receipts for completed payments." />
      {msg && <Alert type={msg.t}>{msg.m}</Alert>}
      <DataTable caption="Your orders" loading={q.isLoading} error={q.error} onRetry={q.refetch} rows={q.data?.data} rowKey="order_id" meta={q.data?.meta} onPage={setPage}
        empty={<EmptyState icon="card" title="No orders yet">When you buy a course, the order and its receipt appear here.</EmptyState>}
        columns={[
          { key: 'number', header: 'Order', render: (o) => <>{o.number}<div className="micro muted">{o.items.map((i) => i.title).join(', ')}</div></> },
          { key: 'created', header: 'Date', render: (o) => dateTime(o.paid_at || o.expires_at) },
          { key: 'total_minor', header: 'Amount', numeric: true, render: (o) => <Money minor={o.total_minor} currency={o.currency} /> },
          { key: 'status', header: 'Status', render: (o) => <StatusBadge status={o.status} /> },
          { key: 'act', header: '', render: (o) => {
            const p = o.payments?.[0];
            if (o.status === 'paid' && p?.receipt_id) return <a href={`/api/v1/student/receipts/${p.receipt_id}/pdf`} download>Receipt (PDF)</a>;
            if (o.status === 'pending' && p) return <button type="button" className="link-btn" onClick={() => check.mutate(p.reference, { onError: (e) => setMsg({ t: 'error', m: e.message }) })}>Check payment status</button>;
            return null;
          } },
        ]} />
    </div>
  );
}
