import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { ChevronDown, LifeBuoy, LogOut, Menu, User } from 'lucide-react'
import Avatar from './Avatar'
import NotificationBell from '../features/notifications/components/NotificationBell'
import { QuickAddButton, SearchButton } from '../features/quick/QuickActions'
import techronLogo from '../assets/techron-logo.png'

const SUPPORT_URL = 'https://support.techronltd.co.ke/'

function useDismiss(open, close, rootRef) {
  useEffect(() => {
    if (!open) return undefined
    const onDown = (event) => { if (rootRef.current && !rootRef.current.contains(event.target)) close() }
    const onKey = (event) => { if (event.key === 'Escape') close() }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open, close, rootRef])
}

function UserMenu({ user, onLogout, busy }) {
  const [open, setOpen] = useState(false)
  const ref = useRef(null)
  const { pathname } = useLocation()
  const close = useCallback(() => setOpen(false), [])
  useDismiss(open, close, ref)
  useEffect(() => { close() }, [pathname, close])

  return (
    <div className="th-menu-wrap" ref={ref}>
      <button
        type="button"
        className="th-user-button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Account menu for ${user?.name ?? 'you'}`}
        onClick={() => setOpen((value) => !value)}
      >
        <Avatar user={user} size="sm" />
        <span className="th-user-copy"><strong>{user?.name}</strong><small>{user?.role}</small></span>
        <ChevronDown size={15} aria-hidden="true" />
      </button>
      {open && (
        <div className="th-menu" role="menu">
          <div className="th-menu-head">
            <strong>{user?.name}</strong>
            <small>{user?.email}</small>
          </div>
          <Link to="/profile" role="menuitem"><User size={16} aria-hidden="true" /> My profile</Link>
          <button type="button" role="menuitem" onClick={onLogout} disabled={busy}>
            <LogOut size={16} aria-hidden="true" /> {busy ? 'Signing out…' : 'Sign out'}
          </button>
        </div>
      )}
    </div>
  )
}

export default function AppHeader({ user, onMenu, onLogout, logoutBusy }) {
  return (
    <header className="top-header">
      <div className="th-left">
        <button type="button" className="th-burger" aria-label="Open menu" onClick={onMenu}>
          <Menu size={22} />
        </button>
        <Link to="/" className="th-logo" aria-label="Reminder System home">
          <img src={techronLogo} alt="TechRon Ltd." />
        </Link>
        <SearchButton />
      </div>

      <div className="th-actions">
        <a className="th-support" href={SUPPORT_URL} target="_blank" rel="noopener noreferrer">
          <LifeBuoy size={16} aria-hidden="true" /> Support
        </a>
        <QuickAddButton />
        <NotificationBell />
        <UserMenu user={user} onLogout={onLogout} busy={logoutBusy} />
      </div>
    </header>
  )
}
