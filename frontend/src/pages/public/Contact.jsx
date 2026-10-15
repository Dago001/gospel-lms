import { useState } from 'react';
import { post } from '../../api/client.js';
import { Alert, SelectField, TextAreaField, TextField } from '../../components/ui/Fields.jsx';
import { PageHeader } from '../../components/ui/Display.jsx';
import { useForm } from '../../lib/useForm.js';
import { useDocumentTitle } from '../../lib/useDocumentTitle.js';

export default function Contact() {
  useDocumentTitle('Contact');
  const [done, setDone] = useState(null);
  const f = useForm({ name: '', email: '', category: 'other', subject: '', message: '', website: '' });
  const submit = f.handle(async (v) => { const r = await post('/public/contact', v); setDone(r.data.number); });
  return (
    <div className="container band">
      <PageHeader title="Contact the academy" subtitle="Ask about admissions, courses, payments or teaching with us." />
      <div style={{ maxWidth: 640 }}>
        {done ? <Alert type="success" title="Message received">Thank you. Your reference is <strong>{done}</strong>. We will reply by email.</Alert> : (
          <form onSubmit={submit} noValidate>
            {f.formError && <Alert type="error">{f.formError}</Alert>}
            <div className="form-grid">
              <TextField label="Your name" required autoComplete="name" {...f.bind('name')} />
              <TextField label="Email" type="email" required autoComplete="email" {...f.bind('email')} />
            </div>
            <SelectField label="Topic" {...f.bind('category')} options={[['other', 'General'], ['course', 'A course'], ['payment', 'Payments'], ['account', 'My account'], ['technical', 'Technical problem']].map(([value, label]) => ({ value, label }))} />
            <TextField label="Subject" required {...f.bind('subject')} />
            <TextAreaField label="Message" required rows={6} help="At least 10 characters. Never include your password." {...f.bind('message')} />
            {/* Honeypot: invisible to people, tempting to bots. */}
            <div aria-hidden="true" style={{ position: 'absolute', left: -9999 }}><label>Website<input tabIndex={-1} autoComplete="off" value={f.values.website} onChange={(e) => f.set('website', e.target.value)} /></label></div>
            <button className="btn btn-primary" disabled={f.submitting}>{f.submitting ? 'Sending…' : 'Send message'}</button>
          </form>
        )}
      </div>
    </div>
  );
}
