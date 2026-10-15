import { Link } from 'react-router-dom';
import { useGet } from '../../api/hooks.js';
import { Alert } from '../../components/ui/Fields.jsx';
import { Loading, PageHeader } from '../../components/ui/Display.jsx';
import { useAuth } from '../../auth/AuthContext.jsx';
import { PriceBlock } from './Courses.jsx';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

const Draft = ({ children }) => <div className="draft-banner" role="note"><strong>Draft for review.</strong> {children}</div>;
function Page({ title, subtitle, children }) {
  useDocumentTitle(title);
  return <div className="container band"><PageHeader title={title} subtitle={subtitle} /><div className="prose">{children}</div></div>;
}

export function About() {
  return (
    <Page title="About the academy">
      <Draft>The academy&apos;s own history, doctrinal statement, accreditation status and leadership must be supplied by the academy before launch. Nothing below describes the institution itself.</Draft>
      <h2>What this platform is</h2>
      <p>GOSPEL LMS is the online learning platform of an independent Christian academy. It offers courses in theology, Bible study, ministry training, Christian leadership, evangelism and discipleship, taught by lecturers the academy has approved.</p>
      <h2>How teaching and assessment work</h2>
      <p>Courses combine recorded and live lessons, study documents, assignments and examinations. Lecturers mark subjective work and publish results. Completion and certificates are decided by the platform from the recorded results, never by the student&apos;s browser.</p>
      <h2>Accreditation</h2>
      <p>Accreditation or recognition status is not stated here because it has not been supplied. Please read each course page and ask us directly if recognition matters for your plans.</p>
      <p><Link to="/contact">Contact the academy</Link></p>
    </Page>
  );
}

export function Faq() {
  const items = [
    ['How do I pay?', 'Paid courses are paid in naira (NGN) through Paystack using the methods Paystack offers, such as card or bank transfer. You are sent to Paystack to pay, and we never see your card details. Access starts when Paystack confirms the payment to us, which usually takes seconds.'],
    ['I paid but cannot see my course', 'Wait a minute and refresh. If it still does not appear, open Payments & receipts in your account and press “Check payment status”, or contact support with your payment reference. Do not pay a second time.'],
    ['Do I get a certificate?', 'Yes, when you meet every requirement set for the course: required lessons, assignments, examinations (each with its own pass mark), and any attendance rule. Your course page lists what is still outstanding.'],
    ['How can someone check my certificate?', 'Each certificate has a QR code and a verification code. Anyone can enter it on the Verify a certificate page. The page shows only the holder’s name, the course and the issue date.'],
    ['Can I skip through videos?', 'You can move around freely, but a lesson counts as complete only when the required share of the video (set per course, 90% by default) has genuinely been played. The next lesson unlocks only after that.'],
    ['What if my internet drops during an exam?', 'Your answers are saved as you go and the timer runs on our server, so you can reconnect and continue. Time that passes while you are offline still counts.'],
    ['Can I get a refund?', 'See the refund policy. Refund requests are reviewed by the finance team.'],
    ['How do I reset my password?', 'Use “Forgot password” on the sign-in page. We email you a link that expires. If you cannot access your email, contact support.'],
  ];
  return (
    <Page title="Frequently asked questions">
      {items.map(([q, a]) => <details key={q} className="panel" style={{ marginBottom: 8 }}><summary style={{ padding: '12px 16px', cursor: 'pointer', fontWeight: 700 }}>{q}</summary><div style={{ padding: '0 16px' }}><p>{a}</p></div></details>)}
    </Page>
  );
}

export function Pricing() {
  useDocumentTitle('Pricing');
  const q = useGet(['public', 'courses'], '/public/courses', { per_page: 50, sort: 'title' });
  return (
    <Page title="Pricing" subtitle="Each course sets its own price in naira.">
      <p>There is no subscription. You pay once per course and keep access to it. Discounts and coupons, when available, are shown at checkout before you pay.</p>
      {q.isLoading ? <Loading /> : (
        <div className="table-wrap"><table className="table stack-mobile"><thead><tr><th scope="col">Course</th><th scope="col">Level</th><th scope="col" className="num">Price</th></tr></thead>
          <tbody>{q.data.data.map((c) => <tr key={c.id}><td data-label="Course"><Link to={`/courses/${c.slug}`}>{c.title}</Link></td><td data-label="Level">{c.level}</td><td className="num" data-label="Price"><PriceBlock price={c.price} /></td></tr>)}
            {q.data.data.length === 0 && <tr><td colSpan={3} className="table-empty">Prices will be listed here when courses are published.</td></tr>}</tbody></table></div>
      )}
      <p style={{ marginTop: 16 }}>Payments are made through Paystack. See the <Link to="/refunds">refund policy</Link>.</p>
    </Page>
  );
}

export function Terms() {
  return (
    <Page title="Terms and conditions">
      <Draft>This is a working draft for the academy and its legal counsel to review and replace. It is not legal advice and must not be relied on as final.</Draft>
      <h2>1. The service</h2><p>The academy provides online courses through this platform. Course content is provided by the academy and its lecturers.</p>
      <h2>2. Your account</h2><p>Keep your password confidential. You are responsible for activity on your account. Share your certificate, not your login.</p>
      <h2>3. Payment and access</h2><p>Prices are shown in naira. Access to a paid course starts when payment is confirmed by our payment provider.</p>
      <h2>4. Academic integrity</h2><p>Submit your own work. Plagiarism or cheating may lead to loss of marks, withdrawal of a certificate or suspension.</p>
      <h2>5. Certificates</h2><p>Certificates are issued only when all course requirements are met and may be revoked if they were issued in error or obtained dishonestly.</p>
      <h2>6. Changes</h2><p>The academy may update these terms. Material changes will be notified.</p>
    </Page>
  );
}

export function Privacy() {
  return (
    <Page title="Privacy policy">
      <Draft>Working draft prepared for the academy&apos;s data-protection review under the Nigeria Data Protection Act 2023. It does not claim compliance or certification.</Draft>
      <h2>What we collect</h2><p>Account details (name, email, optional phone and profile), course activity (progress, submissions, results, attendance), payment records (we keep references and amounts; card details stay with Paystack), and security logs.</p>
      <h2>Why we use it</h2><p>To provide your courses, issue results and certificates, process payments, keep the service secure and meet legal and financial record-keeping duties.</p>
      <h2>Who sees it</h2><p>Your lecturers see your work in their courses. Finance staff see payments. Administrators see account records. Certificate verification shows only your name, the course and the issue date.</p>
      <h2>Your rights</h2><p>You can download a copy of your data and ask for your account to be anonymised from <Link to="/account">Profile &amp; security</Link>. Some academic and financial records must be kept.</p>
      <h2>Contact</h2><p>Questions about your data: <Link to="/contact">contact the academy</Link>.</p>
    </Page>
  );
}

export function Refunds() {
  const q = useGet(['public', 'site'], '/public/site');
  const days = q.data?.data?.refund_window_days;
  return (
    <Page title="Refund policy">
      <Draft>The refund window and conditions must be decided and supplied by the academy.</Draft>
      <p>{days != null ? `Refund requests are considered within ${days} days of purchase.` : 'The refund window has not been published yet.'} Requests are reviewed by the finance team; a refund returns the amount to the original payment method through Paystack. A full refund may also end your access to the course.</p>
      <p>To request a refund, <Link to="/support">contact support</Link> with your order number.</p>
    </Page>
  );
}

export function Support() {
  const { user } = useAuth();
  return (
    <Page title="Support" subtitle="We are here to help you study.">
      <ul>
        <li>Read the <Link to="/faq">frequently asked questions</Link>.</li>
        {user ? <li>Open or follow a request in <Link to="/student/support">your support requests</Link>.</li> : <li><Link to="/login">Sign in</Link> to open a support request and follow its replies.</li>}
        <li>No account? <Link to="/contact">Send us a message</Link>.</li>
      </ul>
      <Alert type="info" title="Paid but no access?">Do not pay again. Check your payment status under Payments &amp; receipts, then contact us with your payment reference.</Alert>
    </Page>
  );
}

export function NotFound() {
  useDocumentTitle('Page not found');
  return <div className="container band"><PageHeader title="Page not found" subtitle="The page you are looking for does not exist or has moved." /><Link className="btn btn-primary" to="/">Go to the home page</Link></div>;
}
