import http from 'k6/http'
import { check, sleep } from 'k6'

export const options = {
  vus: 50,
  duration: '10m',
  thresholds: {
    http_req_failed: ['rate<0.02'],
    http_req_duration: ['p(95)<1200'],
  },
}

const baseUrl = __ENV.SENTINEL_BASE_URL || 'http://127.0.0.1:8080'
const token = __ENV.SENTINEL_BEARER || ''

function authHeaders() {
  return token
    ? {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      }
    : {
        Accept: 'application/json',
      }
}

export default function () {
  const dashboardRes = http.get(`${baseUrl}/monitoring/dashboard`, {
    headers: authHeaders(),
  })

  check(dashboardRes, {
    'dashboard responde 200/302/401/403': (r) => [200, 302, 401, 403].includes(r.status),
  })

  const apiRes = http.get(`${baseUrl}/api/v1/monitoring-sites?per_page=50`, {
    headers: authHeaders(),
  })

  check(apiRes, {
    'api monitoreo responde': (r) => [200, 401, 403].includes(r.status),
  })

  sleep(1)
}
