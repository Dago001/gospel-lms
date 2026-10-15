// A tiny stand-in for Paystack used ONLY by the end-to-end tests. It implements just the endpoints the app calls,
// with the documented response shapes, so the full payment flow runs for real without any money or network access.
import http from 'node:http';

const txns = new Map();
let nextId = 910000000;
const json = (res, code, body) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)); };
const readBody = (req) => new Promise((r) => { let b = ''; req.on('data', (c) => (b += c)); req.on('end', () => r(b ? JSON.parse(b) : {})); });

http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost:9099');
  if (req.headers.authorization !== 'Bearer sk_test_e2e' && !url.pathname.startsWith('/pay')) return json(res, 401, { status: false, message: 'Invalid key' });

  if (req.method === 'POST' && url.pathname === '/transaction/initialize') {
    const b = await readBody(req);
    txns.set(b.reference, { ...b, id: nextId++, state: 'initialized' });
    return json(res, 200, { status: true, message: 'Authorization URL created', data: { authorization_url: `http://localhost:9099/pay/${b.reference}`, access_code: 'ac_e2e', reference: b.reference } });
  }
  if (req.method === 'GET' && url.pathname.startsWith('/transaction/verify/')) {
    const t = txns.get(decodeURIComponent(url.pathname.split('/').pop()));
    if (!t) return json(res, 404, { status: false, message: 'Transaction reference not found' });
    const status = t.state === 'paid' ? 'success' : t.state === 'failed' ? 'failed' : 'abandoned';
    return json(res, 200, { status: true, message: 'Verification successful', data: { id: t.id, status, reference: t.reference, amount: t.amount, currency: t.currency, channel: 'card', domain: 'test', gateway_response: status === 'success' ? 'Successful' : 'Declined', paid_at: new Date().toISOString(), metadata: t.metadata } });
  }
  if (url.pathname.startsWith('/pay/')) {
    const [, , ref, action] = url.pathname.split('/');
    const t = txns.get(ref);
    if (!t) { res.writeHead(404); return res.end('unknown'); }
    if (action === 'complete' || action === 'decline') {
      t.state = action === 'complete' ? 'paid' : 'failed';
      res.writeHead(302, { Location: `${t.callback_url}?reference=${ref}&trxref=${ref}` });
      return res.end();
    }
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end(`<!doctype html><title>Fake Paystack checkout</title><h1>Fake Paystack checkout</h1><p>Amount: NGN ${(t.amount / 100).toFixed(2)}</p><a id="pay" href="/pay/${ref}/complete">Pay now</a> <a id="decline" href="/pay/${ref}/decline">Decline</a>`);
  }
  json(res, 404, { status: false, message: 'not found' });
}).listen(9099, () => console.log('fake paystack on 9099'));
