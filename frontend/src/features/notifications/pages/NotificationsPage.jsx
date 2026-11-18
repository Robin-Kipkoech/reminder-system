import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Check, Trash2 } from 'lucide-react'
import Button from '../../../components/Button'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import NotificationItem from '../components/NotificationItem'
import { notificationPath } from '../components/notificationUtils'
import { useNotificationList, useNotificationMutations } from '../hooks/useNotifications'

export default function NotificationsPage() {
  const toast = useToast()
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
  const [unreadOnly, setUnreadOnly] = useState(false)
  const { data, isLoading, isError, refetch } = useNotificationList({
    page,
    per_page: 15,
    unread: unreadOnly ? 1 : undefined,
  })
  const { markRead, markAll, remove } = useNotificationMutations()
  const rows = Array.isArray(data) ? data : data?.data ?? []
  const lastPage = data?.meta?.last_page ?? 1
  const total = data?.meta?.total ?? rows.length

  function open(notification) {
    if (!notification.read_at) markRead.mutate(notification.id)
    const path = notificationPath(notification)
    if (path) navigate(path)
  }

  async function run(mutation, id, message) {
    try {
      await mutation.mutateAsync(id)
      if (message) toast.success(message)
    } catch (error) {
      toast.error(errorMessage(error))
    }
  }

  return (
    <div className="md-page">
      <header className="md-page-header">
        <div>
          <h1>Notifications</h1>
          <p>Assignments, comments, mentions and reminders.</p>
        </div>
        <Button className="btn-secondary" loading={markAll.isPending}
          onClick={() => run(markAll, undefined, 'All marked as read.')}>
          <Check size={17} /> Mark all as read
        </Button>
      </header>

      <div className="md-toolbar">
        <label className="login-remember">
          <input type="checkbox" checked={unreadOnly}
            onChange={(event) => { setUnreadOnly(event.target.checked); setPage(1) }} />
          <span>Unread only</span>
        </label>
      </div>

      {isError ? (
        <div className="md-state">
          <p>We couldn’t load notifications.</p>
          <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button>
        </div>
      ) : isLoading ? (
        <div className="md-skeletons" role="status" aria-label="Loading">
          {Array.from({ length: 5 }, (_, index) => <div key={index} className="md-skeleton" />)}
        </div>
      ) : rows.length === 0 ? (
        <div className="md-state"><p>{unreadOnly ? 'No unread notifications.' : 'No notifications yet.'}</p></div>
      ) : (
        <div className="nt-page-list">
          {rows.map((notification) => (
            <NotificationItem key={notification.id} notification={notification} onOpen={open}>
              <div className="nt-actions">
                {!notification.read_at && (
                  <button type="button" className="md-icon-btn" title="Mark as read" aria-label="Mark as read"
                    onClick={() => run(markRead, notification.id)}>
                    <Check size={17} />
                  </button>
                )}
                <button type="button" className="md-icon-btn md-icon-danger" title="Delete" aria-label="Delete notification"
                  onClick={() => run(remove, notification.id, 'Notification deleted.')}>
                  <Trash2 size={17} />
                </button>
              </div>
            </NotificationItem>
          ))}
        </div>
      )}

      {!isLoading && !isError && rows.length > 0 && (
        <nav className="md-pagination" aria-label="Pagination">
          <span>Page {page} of {lastPage} · {total} total</span>
          <div>
            <Button className="btn-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Previous</Button>
            <Button className="btn-secondary" disabled={page >= lastPage} onClick={() => setPage(page + 1)}>Next</Button>
          </div>
        </nav>
      )}
    </div>
  )
}
