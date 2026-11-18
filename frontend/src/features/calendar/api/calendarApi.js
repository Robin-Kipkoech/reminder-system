import { api } from '../../../lib/api'

export const calendarApi = {
  events: (params) =>
    api.get('/api/calendar', { params }).then((r) => {
      const body = r.data
      const list = body?.events ?? body?.data?.events ?? body?.data ?? body
      return Array.isArray(list) ? list : []
    }),
  moveDueDate: (type, id, dueDate) =>
    api.patch(`/api/${type === 'item' ? 'items' : 'tasks'}/${id}/due-date`, { due_date: dueDate }).then((r) => r.data),
}