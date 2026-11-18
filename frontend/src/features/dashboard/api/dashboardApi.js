import { api } from '../../../lib/api'

export const dashboardApi = {
    get: () => api.get('/api/dashboard').then((r) => r.data?.data ?? r.data),
}
