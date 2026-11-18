import { api } from '../../../lib/api'

export async function listUsers(params) {
  const response = await api.get('/api/users', { params })
  const body = response.data
  const users = Array.isArray(body?.data)
    ? body.data
    : Array.isArray(body?.users)
      ? body.users
      : []

  return {
    users,
    meta: body?.meta ?? {
      current_page: body?.current_page ?? Number(params.page ?? 1),
      last_page: body?.last_page ?? 1,
      per_page: body?.per_page ?? Number(params.per_page ?? 15),
      total: body?.total ?? users.length,
    },
  }
}

export async function getUser(id) {
  const response = await api.get(`/api/users/${id}`)
  return response.data?.data ?? response.data?.user ?? response.data
}

export async function createUser(attributes) {
  const response = await api.post('/api/users', attributes)
  const body = response.data
  return {
    temporaryPassword: body?.temporary_password ?? body?.data?.temporary_password ?? null,
  }
}

export async function updateUser({ id, attributes }) {
  return api.put(`/api/users/${id}`, attributes)
}

export async function performUserAction({ id, action }) {
  if (action === 'delete') return api.delete(`/api/users/${id}`)
  return api.post(`/api/users/${id}/${action}`)
}

export async function fetchUserOptions(search = '') {
  const response = await api.get('/api/users/options', {
    params: search.trim() ? { search: search.trim() } : {},
  })
  const body = response.data
  const users = Array.isArray(body?.data) ? body.data : Array.isArray(body) ? body : []
  return users.map(({ id, name, role }) => ({ id, name, role }))
}
