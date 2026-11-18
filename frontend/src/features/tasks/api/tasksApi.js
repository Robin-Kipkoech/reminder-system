import { api } from '../../../lib/api'

export const tasksApi = {
    list: (params) => api.get('/api/tasks', { params }).then((r) => r.data),
    show: (id) => api.get(`/api/tasks/${id}`).then((r) => r.data),
    create: (data) => api.post('/api/tasks', data).then((r) => r.data),
    update: (id, data) => api.put(`/api/tasks/${id}`, data).then((r) => r.data),
    remove: (id) => api.delete(`/api/tasks/${id}`).then((r) => r.data),
    setStatus: (id, status) => api.post(`/api/tasks/${id}/status`, { status }).then((r) => r.data),
    complete: (id) => api.post(`/api/tasks/${id}/complete`).then((r) => r.data),
    comments: (id) => api.get(`/api/tasks/${id}/comments`).then((r) => r.data),
    addComment: (id, body) => api.post(`/api/tasks/${id}/comments`, { body }).then((r) => r.data),
    removeComment: (id, commentId) => api.delete(`/api/tasks/${id}/comments/${commentId}`).then((r) => r.data),
    userOptions: () => api.get('/api/users/options').then((r) => r.data),
}

export const asArray = (body) => (Array.isArray(body) ? body : body?.data ?? [])