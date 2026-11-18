import { api } from '../../../lib/api'

export const REPORTS = {
  person: 'tasks-by-person',
  items: 'items-expiry',
  clients: 'tasks-by-client',
}

export const reportsApi = {
  get: (kind, params) =>
    api.get(`/api/reports/${REPORTS[kind]}`, { params: { ...params, format: 'json' } }).then((r) => {
      const body = r.data
      const rows = body?.data ?? body?.rows ?? body
      return Array.isArray(rows) ? rows : []
    }),

  // Fetched as a blob through the Axios client so the session cookie is sent.
  async downloadCsv(kind, params) {
    const response = await api.get(`/api/reports/${REPORTS[kind]}`, {
      params: { ...params, format: 'csv' },
      responseType: 'blob',
    })
    const disposition = response.headers?.['content-disposition'] ?? ''
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
    const filename = match
      ? decodeURIComponent(match[1])
      : `${REPORTS[kind]}-${params.from}-to-${params.to}.csv`
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  },
}