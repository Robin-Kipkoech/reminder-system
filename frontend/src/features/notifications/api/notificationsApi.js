import { api } from '../../../lib/api'

export const notificationsApi = {
  list: (params) => api.get('/api/notifications', { params }).then((response) => response.data),
  unreadCount: () => api.get('/api/notifications/unread-count').then((response) => {
    const body = response.data
    return Number(body?.data?.count ?? body?.count ?? body?.unread ?? body?.data ?? 0) || 0
  }),
  markRead: (id) => api.post(`/api/notifications/${id}/read`).then((response) => response.data),
  markAllRead: () => api.post('/api/notifications/read-all').then((response) => response.data),
  remove: (id) => api.delete(`/api/notifications/${id}`).then((response) => response.data),
}
