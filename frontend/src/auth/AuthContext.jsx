import { useCallback, useEffect, useMemo, useState } from 'react'
import { AuthContext } from './authStore'
import { api, responsePayload } from '../lib/api'

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)
  const [authError, setAuthError] = useState(null)

  const refreshUser = useCallback(async () => {
    try {
      const response = await api.get('/api/auth/me')
      const payload = responsePayload(response)
      setUser(payload?.user ?? payload)
      return payload?.user ?? payload
    } catch (error) {
      if (error.response?.status === 401) {
        setUser(null)
        return null
      }
      setAuthError(error)
      throw error
    } finally {
      setLoading(false)
    }
  }, [])

  const login = useCallback(async (email, password, remember) => {
    const base = import.meta.env.VITE_API_BASE_URL || ''
    await api.get(`${base}/sanctum/csrf-cookie`)
    await api.post('/api/auth/login', { email, password, remember })
    return refreshUser()
  }, [refreshUser])

  useEffect(() => {
    void Promise.resolve().then(() => refreshUser()).catch(() => {})
  }, [refreshUser])

  useEffect(() => {
    const requirePasswordChange = () => {
      setUser((current) => current ? { ...current, must_change_password: true } : current)
    }
    window.addEventListener('password-change-required', requirePasswordChange)
    return () => window.removeEventListener('password-change-required', requirePasswordChange)
  }, [])

  const value = useMemo(
    () => ({ user, loading, authError, refreshUser, login, setUser }),
    [user, loading, authError, refreshUser, login],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
