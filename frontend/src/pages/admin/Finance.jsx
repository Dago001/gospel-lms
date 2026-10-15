import { useState } from 'react';
import { Link, Route, Routes, useParams } from 'react-router-dom';
import { useAction, useGet } from '../../api/hooks.js';
import { post, put } from '../../api/client.js';
import { useAuth } from '../../auth/AuthContext.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Alert, CheckField, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { Breadcrumbs, EmptyState, ErrorState, Loading, Money, PageHeader, StatusBadge, Stats } from '../../components/ui/Display.jsx';
import { BarChart } from '../../components/ui/BarChart.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { date, dateTime, money } from '../../lib/format.js';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export function Finance() {
  return (
    <Routes>
      <Route index element={<Payments />} />
      <Route path="payments/:id" element={<PaymentDetail />} />
      <Route path="orders" element={<Orders />} />
      <Route path="refunds" element={<Refunds />} />
      <Route path="coupons" element={<Coupons />} />
      <Route path="exceptions" element={<Exceptions />} />
      <Route path="webhooks" element={<Webhooks />} />
      <Route path="reports" element={<RevenueReports />} />
    </Routes>
  );
}

function Payments() {
  useDocumentTitle('Payments');
  const [f, setF] = useState({ q: '', status: '', from: '', to: '', page: 1 });
  const set = (k, v) => setF({ ...f, [k]: v, page: 1 });
  const q = useGet(['admin', 'payments'], '/admin/finance/payments', f);
  const csv = `/api/v1/admin/finance/export?${new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([k, v]) => v && k !== 'page')))}`;
  const { can } = useAuth();
  return (
    <div>
      <PageHeader title="Payments" subtitle="Every payment attempt and its outcome." actions={can('reports.finance') && <a className="btn btn-secondary" href={csv} download>Export CSV</a>} />
      <div className="filters">
        <TextField label="Reference or order" type="search" value={f.q} onChange={(e) => set('q', e.target.value)} />
        <SelectField label="Status" value={f.status} onChange={(e) => set('status', e.target.value)} placeholder="Any" options={['success', 'free', 'initialized', 'failed', 'abandoned', 'mismatch']} />
        <TextField label="From" type="date" value={f.from} onChange={(e) => set('from', e.target.value)} /><TextField label="To" type="date" value={f.to} onChange={(e) => set('to', e.target.value)} />
      </div>
      <DataTable caption="Payments" loading={q.isLoading} error={q.error} onRetry={q.refetch} rows={q.data?.data} meta={q.data?.meta} onPage={(p) => setF({ ...f, page: p })}
        columns={[
          { key: 'reference', header: 'Reference', render: (p) => <Link to={`/admin/finance/payments/${p.id}`}>{p.reference}</Link> },
          { key: 'customer', header: 'Customer', render: (p) => p.order?.customer ? <>{p.order.customer.name}<div className="micro muted">{p.order.number}</div></> : '-' },
          { key: 'amount_minor', header: 'Amount', numeric: true, sortable: true, render: (p) => <Money minor={p.amount_minor} currency={p.currency} /> },
          { key: 'status', header: 'Status', render: (p) => <><StatusBadge status={p.status} />{p.failure_reason && <div className="micro muted">{p.failure_reason}</div>}</> },
          { key: 'channel', header: 'Channel' }, { key: 'created_at', header: 'Created', sortable: true, render: (p) => dateTime(p.created_at) },
        ]} />
    </div>
  );
}

function PaymentDetail() {
  const { id } = useParams();
  const { can } = useAuth();
  const q = useGet(['admin', 'payment', id], `/admin/finance/payments/${id}`);
  const [refund, setRefund] = useState(false);
  const recon = useAction(() => post(`/admin/finance/payments/${id}/reconcile`), { invalidate: [['admin', 'payment'], ['admin', 'payments']], success: 'Re-checked with the gateway.' });
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorState error={q.error} onRetry={q.refetch} />;
  const p = q.data.data;
  return (
    <div className="stack">
      <Breadcrumbs items={[{ label: 'Payments', to: '/admin/finance' }, { label: p.reference }]} />
      <div className="split"><h1 style={{ margin: 0 }}>{p.reference}</h1><StatusBadge status={p.status} /></div>
      {p.status === 'mismatch' && <Alert type="warning" title="Needs review">{p.failure_reason}. Access was <strong>not</strong> granted automatically. If the customer was charged, issue a refund or resolve with the gateway.</Alert>}
      <div className="panel panel-pad"><dl className="kv">
        <dt>Amount</dt><dd><Money minor={p.amount_minor} currency={p.currency} /></dd><dt>Order</dt><dd>{p.order?.number}</dd><dt>Customer</dt><dd>{p.order?.customer ? `${p.order.customer.name} (${p.order.customer.email})` : '-'}</dd>
        <dt>Channel</dt><dd>{p.channel || '-'}</dd><dt>Paid at</dt><dd>{dateTime(p.paid_at)}</dd><dt>Items</dt><dd>{p.items?.map((i) => i.title).join(', ')}</dd><dt>Refundable</dt><dd><Money minor={p.refundable_minor} currency={p.currency} /></dd>
      </dl></div>
      <div className="btn-row">
        {can('finance.reconcile') && <button className="btn btn-secondary" onClick={() => recon.mutate()} disabled={recon.isPending}>Re-check with Paystack</button>}
        {can('finance.refund') && (p.status === 'success' || p.status === 'mismatch') && <button className="btn btn-primary" onClick={() => setRefund(true)}>Issue refund</button>}
        {p.receipt_id && <a className="btn btn-secondary" href={`/api/v1/student/receipts/${p.receipt_id}/pdf`} download>Receipt PDF</a>}
      </div>
      <section><h2>Refunds</h2><DataTable caption="Refunds on this payment" rows={p.refunds} columns={[{ key: 'created_at', header: 'Requested', render: (r) => dateTime(r.created_at) }, { key: 'amount_minor', header: 'Amount', numeric: true, render: (r) => money(r.amount_minor, p.currency) }, { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> }, { key: 'reason', header: 'Reason' }]} empty={<p className="muted">No refunds.</p>} /></section>
      {refund && <RefundModal payment={p} onClose={() => setRefund(false)} />}
    </div>
  );
}

function RefundModal({ payment: p, onClose }) {
  const [partial, setPartial] = useState(false);
  const f = useForm({ amount: '', reason: '', revoke_access: false });
  const inv = useAction(() => Promise.resolve(), { invalidate: [['admin', 'payment'], ['admin', 'refunds']] });
  const save = f.handle(async (v) => { const body = { reason: v.reason, revoke_access: !partial && v.revoke_access }; if (partial) body.amount_minor = Math.round(Number(v.amount) * 100); const r = await post(`/admin/finance/payments/${p.id}/refunds`, body); inv.mutate(); alert_(r); onClose(); });
  return (
    <Modal title="Issue a refund" onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-danger" form="rf" disabled={f.submitting}>Refund</button></>}>
      <form id="rf" onSubmit={save} noValidate>
        {f.formError && <Alert type="error">{f.formError}</Alert>}
        <p>Refundable balance: <strong>{money(p.refundable_minor, p.currency)}</strong>. The refund is sent to Paystack and confirmed when Paystack reports it processed.</p>
        <CheckField checked={partial} onChange={(e) => setPartial(e.target.checked)}>Refund only part of the amount</CheckField>
        {partial && <TextField label="Amount (₦)" type="number" min="0.01" step="0.01" {...f.bind('amount')} />}
        {!partial && <CheckField {...f.bind('revoke_access', { checkbox: true })}>Also end the student&apos;s access to the course(s)</CheckField>}
        <TextAreaField label="Reason" rows={2} required help="At least 5 characters. Recorded in the audit log." {...f.bind('reason')} />
      </form>
    </Modal>
  );
}
const alert_ = () => {};

function Orders() {
  useDocumentTitle('Orders');
  const [f, setF] = useState({ status: '', page: 1 });
  const q = useGet(['admin', 'orders'], '/admin/finance/orders', f);
  return (
    <div><PageHeader title="Orders" />
      <div className="filters"><SelectField label="Status" value={f.status} onChange={(e) => setF({ status: e.target.value, page: 1 })} placeholder="Any" options={['pending', 'paid', 'failed', 'expired', 'refunded', 'partially_refunded']} /></div>
      <DataTable caption="Orders" loading={q.isLoading} error={q.error} rows={q.data?.data} meta={q.data?.meta} onPage={(p) => setF({ ...f, page: p })}
        columns={[{ key: 'number', header: 'Order' }, { key: 'customer', header: 'Customer', render: (o) => <>{o.customer.name}<div className="micro muted">{o.customer.email}</div></> }, { key: 'items', header: 'Items', render: (o) => o.items.join(', ') },
          { key: 'total_minor', header: 'Total', numeric: true, render: (o) => <Money minor={o.total_minor} currency={o.currency} /> }, { key: 'status', header: 'Status', render: (o) => <StatusBadge status={o.status} /> }, { key: 'created_at', header: 'Created', render: (o) => dateTime(o.created_at) }]} /></div>
  );
}

function Refunds() {
  useDocumentTitle('Refunds');
  const [page, setPage] = useState(1);
  const q = useGet(['admin', 'refunds'], '/admin/finance/refunds', { page });
  return (
    <div><PageHeader title="Refunds" />
      <DataTable caption="Refunds" loading={q.isLoading} error={q.error} rows={q.data?.data} meta={q.data?.meta} onPage={setPage} empty={<EmptyState icon="card" title="No refunds yet" />}
        columns={[{ key: 'payment_reference', header: 'Payment' }, { key: 'amount_minor', header: 'Amount', numeric: true, render: (r) => money(r.amount_minor, r.currency) }, { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> }, { key: 'reason', header: 'Reason' }, { key: 'created_at', header: 'Requested', render: (r) => dateTime(r.created_at) }]} /></div>
  );
}

function Coupons() {
  useDocumentTitle('Coupons');
  const q = useGet(['admin', 'coupons'], '/admin/finance/coupons');
  const [open, setOpen] = useState(false);
  const toggle = useAction((c) => put(`/admin/finance/coupons/${c.id}`, { active: !c.active }), { invalidate: [['admin', 'coupons']], success: 'Updated.' });
  return (
    <div><PageHeader title="Coupons" actions={<button className="btn btn-primary" onClick={() => setOpen(true)}>New coupon</button>} />
      <DataTable caption="Coupons" loading={q.isLoading} error={q.error} rows={q.data?.data} empty={<EmptyState icon="gift" title="No coupons yet" />}
        columns={[{ key: 'code', header: 'Code' }, { key: 'value', header: 'Discount', render: (c) => (c.type === 'percent' ? `${c.value}%` : money(c.value)) }, { key: 'redeemed_count', header: 'Redeemed', numeric: true, render: (c) => `${c.redeemed_count}${c.max_redemptions ? ` / ${c.max_redemptions}` : ''}` },
          { key: 'ends_at', header: 'Expires', render: (c) => (c.ends_at ? date(c.ends_at) : 'Never') }, { key: 'active', header: 'Status', render: (c) => <StatusBadge status={c.active ? 'active' : 'suspended'} label={c.active ? 'Active' : 'Disabled'} /> },
          { key: 'a', header: '', render: (c) => <button className="btn btn-secondary btn-sm" onClick={() => toggle.mutate(c)}>{c.active ? 'Disable' : 'Enable'}</button> }]} />
      {open && <CouponModal onClose={() => setOpen(false)} />}</div>
  );
}
function CouponModal({ onClose }) {
  const f = useForm({ code: '', type: 'percent', value: 10, max_redemptions: '', per_user_limit: 1, ends_at: '' });
  const act = useAction(() => Promise.resolve(), { invalidate: [['admin', 'coupons']], success: 'Coupon created.' });
  const save = f.handle(async (v) => { await post('/admin/finance/coupons', { code: v.code, type: v.type, value: v.type === 'percent' ? Number(v.value) : Math.round(Number(v.value) * 100), max_redemptions: v.max_redemptions ? Number(v.max_redemptions) : null, per_user_limit: Number(v.per_user_limit), ends_at: v.ends_at ? new Date(v.ends_at).toISOString() : null }); act.mutate(); onClose(); });
  return (
    <Modal title="New coupon" onClose={onClose} footer={<><button className="btn btn-secondary" onClick={onClose}>Cancel</button><button className="btn btn-primary" form="cp" disabled={f.submitting}>Create</button></>}>
      <form id="cp" onSubmit={save} noValidate>{f.formError && <Alert type="error">{f.formError}</Alert>}
        <TextField label="Code" required help="Letters, numbers, dashes. Not case sensitive." {...f.bind('code')} />
        <div className="form-grid"><SelectField label="Type" {...f.bind('type')} options={[{ value: 'percent', label: 'Percentage off' }, { value: 'fixed', label: 'Fixed amount off (₦)' }]} /><TextField label={f.values.type === 'percent' ? 'Percent' : 'Amount (₦)'} type="number" min="1" step={f.values.type === 'percent' ? '1' : '0.01'} {...f.bind('value')} /></div>
        <div className="form-grid"><TextField label="Total uses (blank = unlimited)" type="number" min="1" {...f.bind('max_redemptions')} /><TextField label="Uses per student" type="number" min="1" {...f.bind('per_user_limit')} /><TextField label="Expires" type="datetime-local" {...f.bind('ends_at')} /></div></form>
    </Modal>
  );
}

function Exceptions() {
  useDocumentTitle('Payment exceptions');
  const q = useGet(['admin', 'exceptions'], '/admin/finance/exceptions');
  const L = { payment_mismatch: 'Amount/currency/reference mismatch', stuck_initialized: 'No outcome after 1 hour', paid_without_enrolment: 'Paid but not enrolled', webhook_error: 'Webhook could not be processed', invalid_signature: 'Webhook with a bad signature', refund_review: 'Refund outcome unknown', refund_failed: 'Refund failed' };
  return (
    <div><PageHeader title="Payment exceptions" subtitle="Anything finance should look at. The list is empty when everything reconciles." />
      <DataTable caption="Exceptions" loading={q.isLoading} error={q.error} rows={q.data?.data?.map((e, i) => ({ ...e, id: i }))} empty={<Alert type="success" title="No exceptions">All payments, webhooks and refunds reconcile.</Alert>}
        columns={[{ key: 'kind', header: 'Issue', render: (e) => L[e.kind] || e.kind }, { key: 'ref', header: 'Reference', render: (e) => (e.payment_id ? <Link to={`/admin/finance/payments/${e.payment_id}`}>{e.reference || 'Open'}</Link> : e.reference || e.order || '-') }, { key: 'detail', header: 'Detail' }, { key: 'at', header: 'When', render: (e) => dateTime(e.at) }]} /></div>
  );
}

function Webhooks() {
  useDocumentTitle('Webhook history');
  const [page, setPage] = useState(1);
  const q = useGet(['admin', 'webhooks'], '/admin/finance/webhooks', { page });
  return (
    <div><PageHeader title="Webhook history" subtitle="Everything Paystack has sent us, including rejected deliveries." />
      <DataTable caption="Webhook events" loading={q.isLoading} error={q.error} rows={q.data?.data} meta={q.data?.meta} onPage={setPage}
        columns={[{ key: 'received_at', header: 'Received', render: (e) => dateTime(e.received_at) }, { key: 'event_type', header: 'Event' }, { key: 'reference', header: 'Reference' },
          { key: 'signature_valid', header: 'Signature', render: (e) => <StatusBadge status={e.signature_valid ? 'valid' : 'rejected'} /> }, { key: 'processed_at', header: 'Processed', render: (e) => (e.processed_at ? dateTime(e.processed_at) : 'Pending') }, { key: 'attempts', header: 'Tries', numeric: true }, { key: 'processing_error', header: 'Note' }]} /></div>
  );
}

function RevenueReports() {
  useDocumentTitle('Revenue reports');
  const [range, setRange] = useState(() => ({ from: new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10), to: new Date().toISOString().slice(0, 10) }));
  const q = useGet(['admin', 'finance-summary'], '/admin/finance/summary', range);
  const d = q.data?.data;
  return (
    <div className="stack"><PageHeader title="Revenue reports" />
      <div className="filters"><TextField label="From" type="date" value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /><TextField label="To" type="date" value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></div>
      {q.isLoading ? <Loading /> : q.error ? <ErrorState error={q.error} /> : (
        <>
          <Stats items={d.payments_by_status.map((r) => ({ label: `${r.status} (${r.currency})`, value: money(Number(r.total_minor), r.currency), hint: `${r.count}` }))} />
          <div className="panel panel-pad"><h3>Revenue by day (₦)</h3><BarChart label="Revenue by day" valueLabel="Revenue (naira)" data={d.daily_revenue.map((r) => ({ label: String(r.day).slice(5), value: Number(r.total_minor) / 100 }))} format={(v) => Math.round(v).toLocaleString('en-NG')} /></div>
          <section><h2>Processed refunds</h2><DataTable caption="Refunds processed" rows={d.refunds_processed.map((r) => ({ ...r, id: r.currency }))} columns={[{ key: 'currency', header: 'Currency' }, { key: 'count', header: 'Refunds', numeric: true }, { key: 'total_minor', header: 'Total', numeric: true, render: (r) => money(Number(r.total_minor), r.currency) }]} empty={<p className="muted">No refunds processed in this period.</p>} /></section>
        </>
      )}
    </div>
  );
}
