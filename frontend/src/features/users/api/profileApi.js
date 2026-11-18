import { api } from '../../../lib/api'

const unwrap = (response) => response.data?.data ?? response.data

export const profileApi = {
  summary: () => api.get('/api/users/summary').then(unwrap),
  userProfile: (id) => api.get(`/api/users/${id}/profile`).then(unwrap),
  updateProfile: (data) => api.put('/api/auth/profile', data).then(unwrap),
  preferences: () => api.get('/api/auth/preferences').then(unwrap),
  updatePreferences: (data) => api.put('/api/auth/preferences', data).then(unwrap),
  changePassword: (data) => api.put('/api/auth/password', data).then((response) => response.data),
}
