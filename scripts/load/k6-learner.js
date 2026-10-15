// Authenticated learner journey: sign in, dashboard, lesson progress heartbeats.
//   k6 run -e BASE_URL=https://staging.example.com -e EMAIL_PREFIX=load -e PASSWORD=... -e USERS=500 scripts/load/k6-learner.js
// Requires pre-seeded verified students <EMAIL_PREFIX>1@example.test .. <EMAIL_PREFIX>N@example.test enrolled in LESSON_ID's course
// (create them with a staging-only seeder; the login rate limiter must be relaxed on staging or the test spreads IPs).
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://localhost:8000';
const USERS = Number(__ENV.USERS || 100);

export const options = { vus: USERS, duration: '10m', thresholds: { http_req_failed: ['rate<0.01'], http_req_duration: ['p(95)<1000'] } };

function xsrf(jar) {
  const c = jar.cookiesForURL(BASE)['XSRF-TOKEN'];
  return c ? decodeURIComponent(c[0]) : '';
}

export default function () {
  const jar = http.cookieJar();
  http.get(`${BASE}/sanctum/csrf-cookie`);
  const email = `${__ENV.EMAIL_PREFIX || 'load'}${(__VU % USERS) + 1}@example.test`;
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(jar), Origin: BASE, Referer: `${BASE}/` };
  const login = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ email, password: __ENV.PASSWORD }), { headers });
  check(login, { 'login ok': (r) => r.status === 200 });
  for (let i = 0; i < 20; i++) {
    const r = http.get(`${BASE}/api/v1/student/dashboard`, { headers });
    check(r, { 'dashboard 200': (x) => x.status === 200 });
    sleep(5 + Math.random() * 10);
  }
}
