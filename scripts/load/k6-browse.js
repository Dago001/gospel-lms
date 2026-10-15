// Repeatable load profile for the read-heavy public and learner paths.
//   k6 run -e BASE_URL=https://staging.example.com -e VUS=500 scripts/load/k6-browse.js
// Run against STAGING with production-like data and infrastructure, never against production.
// No result from this script is recorded in the repository: publish measured numbers only
// together with the environment (instance sizes, DB size, PHP-FPM workers, cache) they came from.
import http from 'k6/http';
import { check, sleep } from 'k6';

const VUS = Number(__ENV.VUS || 500);
const BASE = __ENV.BASE_URL || 'http://localhost:8000';

export const options = {
  scenarios: {
    ramp: {
      executor: 'ramping-vus',
      stages: [
        { duration: '2m', target: VUS },
        { duration: '10m', target: VUS },
        { duration: '1m', target: 0 },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    'http_req_duration{kind:api}': ['p(95)<800'],
  },
};

export default function () {
  const list = http.get(`${BASE}/api/v1/public/courses?per_page=12`, { tags: { kind: 'api' } });
  check(list, { 'catalogue 200': (r) => r.status === 200 });
  const rows = list.status === 200 ? list.json('data') : [];
  if (rows && rows.length) {
    const slug = rows[Math.floor(Math.random() * rows.length)].slug;
    const detail = http.get(`${BASE}/api/v1/public/courses/${slug}`, { tags: { kind: 'api' } });
    check(detail, { 'course 200': (r) => r.status === 200 });
  }
  http.get(`${BASE}/api/v1/public/site`, { tags: { kind: 'api' } });
  sleep(1 + Math.random() * 3);
}
