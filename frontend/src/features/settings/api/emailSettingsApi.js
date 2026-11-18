import { api } from '../../../lib/api'

const unwrap = (r) => r.data?.data ?? r.data

export const emailSettingsApi = {
  get: () => api.get('/api/admin/email-settings').then(unwrap),
  save: (data) => api.put('/api/admin/email-settings', data).then(unwrap),
  test: (to) => api.post('/api/admin/email-settings/test', to ? { to } : {}).then((r) => r.data),
  logs: (params) => api.get('/api/admin/email-settings/logs', { params }).then((r) => r.data),
}