import axios from 'axios'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const body = error.response?.data
    const code = body?.code ?? body?.data?.code

    if (error.response?.status === 403 && code === 'PASSWORD_CHANGE_REQUIRED') {
      window.dispatchEvent(new CustomEvent('password-change-required'))
    }

    return Promise.reject(error)
  },
)

export function responsePayload(response) {
  const body = response?.data
  if (body && typeof body === 'object' && 'data' in body) {
    if (Array.isArray(body.data)) return body
    return { ...body, ...body.data }
  }
  return body
}

export function errorMessage(error, fallback = 'Something went wrong. Please try again.') {
  return (
    error.response?.data?.message ??
    error.response?.data?.error ??
    fallback
  )
}
