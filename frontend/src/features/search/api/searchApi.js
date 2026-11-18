import { api } from '../../../lib/api'

export const searchApi = {
  search: (q) =>
    api.get('/api/search', { params: { q } }).then((r) => {
      const body = r.data?.data ?? r.data ?? {}
      return {
        tasks: Array.isArray(body.tasks) ? body.tasks : [],
        items: Array.isArray(body.items) ? body.items : [],
        clients: Array.isArray(body.clients) ? body.clients : [],
        users: Array.isArray(body.users) ? body.users : [],
      }
    }),
}