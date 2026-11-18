import { useEffect, useState } from 'react'
import { Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from './useAuth'
import { api, errorMessage } from '../lib/api'
import LoginPage from '../pages/LoginPage'

export { LoginPage }

export function ProtectedRoute() {
  const { user, loading, authError } = useAuth()
  const location = useLocation()

  if (loading) return <div className="route-loading" role="status">Checking your session…</div>
  if (authError) {
    return (
      <div className="route-loading">
        <p>We couldn’t verify your session.</p>
        <button className="button button-secondary" onClick={() => window.location.reload()}>Try again</button>
      </div>
    )
  }
  if (!user) return <Navigate to="/login" replace state={{ from: location }} />
  return <Outlet />
}

export function RoleRoute({ roles }) {
  const { user } = useAuth()
  if (!roles.includes(user?.role)) return <Navigate to="/forbidden" replace />
  return <Outlet />
}

export function PasswordChangeGate() {
  const { user } = useAuth()
  const location = useLocation()
  const passwordPage = location.pathname === '/set-password'

  if (user?.must_change_password && !passwordPage) {
    return <Navigate to="/set-password" replace />
  }
  if (!user?.must_change_password && passwordPage) {
    return <Navigate to="/" replace />
  }
  return <Outlet />
}

export function SetPasswordPage() {
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const { refreshUser } = useAuth()
  const navigate = useNavigate()

  useEffect(() => {
    document.title = 'Set a new password · Reminder System'
  }, [])

  async function submit(event) {
    event.preventDefault()
    setError('')
    if (password.length < 8) {
      setError('Use at least 8 characters for your new password.')
      return
    }
    if (password !== confirmation) {
      setError('The password confirmation does not match.')
      return
    }
    setBusy(true)
    try {
      await api.put('/api/auth/password', { password, password_confirmation: confirmation })
      const user = await refreshUser()
      navigate(user?.role === 'admin' ? '/admin/users' : '/', { replace: true })
    } catch (requestError) {
      setError(errorMessage(requestError, 'Unable to update your password.'))
    } finally {
      setBusy(false)
      setPassword('')
      setConfirmation('')
    }
  }

  return (
    <main className="auth-page">
      <form className="auth-card" onSubmit={submit}>
        <span className="brand-mark">R</span>
        <p className="eyebrow">ACCOUNT SECURITY</p>
        <h1>Set a new password</h1>
        <p className="muted">Choose a new password to continue using Reminder System.</p>
        {error && <div className="inline-error" role="alert">{error}</div>}
        <label className="field">
          <span>New password</span>
          <input type="password" autoComplete="new-password" minLength={8} required value={password} onChange={(event) => setPassword(event.target.value)} />
        </label>
        <label className="field">
          <span>Confirm new password</span>
          <input type="password" autoComplete="new-password" minLength={8} required value={confirmation} onChange={(event) => setConfirmation(event.target.value)} />
        </label>
        <button className="button button-primary button-wide" disabled={busy}>
          {busy ? 'Updating…' : 'Update password'}
        </button>
      </form>
    </main>
  )
}
