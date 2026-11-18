import { api } from '../../../lib/api'

function createResourceApi(path) {
    const base = `/api/${path}`
    return {
        list: (params) => api.get(base, { params }).then((r) => r.data),
        options: () => api.get(`${base}/options`).then((r) => r.data),
        create: (data) => api.post(base, data).then((r) => r.data),
        update: (id, data) => api.put(`${base}/${id}`, data).then((r) => r.data),
        setActive: (id, active) =>
            api.post(`${base}/${id}/${active ? 'activate' : 'deactivate'}`).then((r) => r.data),
        remove: (id) => api.delete(`${base}/${id}`).then((r) => r.data),
        restore: (id) => api.post(`${base}/${id}/restore`).then((r) => r.data),
    }
}

export const clientsApi = createResourceApi('clients')
export const departmentsApi = createResourceApi('departments')
export const reminderPeriodsApi = createResourceApi('reminder-periods')

// Accepts Laravel's paginated resource shape ({data, meta}) or a plain paginator.
export function unwrapList(body) {
    const rows = Array.isArray(body) ? body : body?.data ?? []
    const meta = body?.meta ?? body ?? {}
    return {
        rows,
        lastPage: meta.last_page ?? 1,
        total: meta.total ?? rows.length,
    }
}

export function unwrapOptions(body) {
    return Array.isArray(body) ? body : body?.data ?? []
}