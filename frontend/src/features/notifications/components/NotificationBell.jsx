import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Bell } from 'lucide-react'
import { useNotificationList, useNotificationMutations, useUnreadCount } from '../hooks/useNotifications'
import NotificationItem from './NotificationItem'
import { notificationPath } from './notificationUtils'

export default function NotificationBell() {
  const [open, setOpen] = useState(false)
  const rootRef = useRef(null)
  const buttonRef = useRef(null)
  const navigate = useNavigate()
  const unread = useUnreadCount().data ?? 0
  const { data, isLoading, isError, refetch } = useNotificationList({ per_page: 8 }, open)
  const { markRead, markAll } = useNotificationMutations()
  const rows = Array.isArray(data) ? data : data?.data ?? []

  useEffect(() => {
    if (!open) return undefined

    function onPointerDown(event) {
      if (rootRef.current && !rootRef.current.contains(event.target)) setOpen(false)
    }

    function onKeyDown(event) {
      if (event.key === 'Escape') {
        setOpen(false)
        buttonRef.current?.focus()
      }
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  function openNotification(notification) {
    if (!notification.read_at) markRead.mutate(notification.id)
    setOpen(false)
    const path = notificationPath(notification)
    if (path) navigate(path)
  }

  const badge = unread > 99 ? '99+' : unread

  return (
    <div className="nt-bell" ref={rootRef}>
      <button
        ref={buttonRef}
        type="button"
        className="nt-bell-button"
        aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
        aria-haspopup="true"
        aria-expanded={open}
        aria-controls="notification-panel"
        onClick={() => setOpen((value) => !value)}
      >
        <Bell size={20} />
        {unread > 0 && <span className="nt-badge">{badge}</span>}
      </button>

      {open && (
        <div className="nt-panel" id="notification-panel" role="region" aria-label="Notifications">
          <header className="nt-panel-head">
            <h2>Notifications</h2>
            <button
              type="button"
              className="db-link nt-link-button"
              disabled={unread === 0 || markAll.isPending}
              onClick={() => markAll.mutate()}
            >
              Mark all as read
            </button>
          </header>

          <div className="nt-panel-list">
            {isError ? (
              <div className="nt-state">
                <p>We couldn’t load notifications.</p>
                <button type="button" className="db-link nt-link-button" onClick={() => refetch()}>Try again</button>
              </div>
            ) : isLoading ? (
              <div className="nt-state" role="status">Loading…</div>
            ) : rows.length === 0 ? (
              <div className="nt-state"><p>You’re all caught up.</p></div>
            ) : (
              rows.map((notification) => (
                <NotificationItem key={notification.id} notification={notification} onOpen={openNotification} />
              ))
            )}
          </div>

          <footer className="nt-panel-foot">
            <Link to="/notifications" className="db-link" onClick={() => setOpen(false)}>View all notifications</Link>
          </footer>
        </div>
      )}
    </div>
  )
}
