import { api } from '../../../lib/api'

export const itemsApi = {
    list: (params) => api.get('/api/items', { params }).then((r) => r.data),
    show: (id) => api.get(`/api/items/${id}`).then((r) => r.data),
    create: (data) => api.post('/api/items', data).then((r) => r.data),
    update: (id, data) => api.put(`/api/items/${id}`, data).then((r) => r.data),
    remove: (id) => api.delete(`/api/items/${id}`).then((r) => r.data),
    renew: (id, dueDate) => api.post(`/api/items/${id}/renew`, { due_date: dueDate }).then((r) => r.data),
    setStatus: (id, status) => api.post(`/api/items/${id}/status`, { status }).then((r) => r.data),
}