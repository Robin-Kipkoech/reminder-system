import { AtSign, BellRing, CheckCircle2, Info, MessageSquare, UserPlus } from 'lucide-react'
import { timeAgo } from '../../../lib/time'

const ICONS = {
  assigned: UserPlus,
  comment: MessageSquare,
  mention: AtSign,
  completed: CheckCircle2,
  reminder: BellRing,
  system: Info,
}

export default function NotificationItem({ notification, onOpen, children }) {
  const Icon = ICONS[notification.type] ?? Info
  const unread = !notification.read_at

  return (
    <div className={`nt-item${unread ? ' unread' : ''}`}>
      <button type="button" className="nt-main" onClick={() => onOpen(notification)}>
        <span className={`nt-icon nt-${notification.type}`}><Icon size={17} aria-hidden="true" /></span>
        <span className="nt-body">
          <strong>{notification.title}</strong>
          <span className="nt-message">{notification.message}</span>
          <small>{timeAgo(notification.created_at)}</small>
        </span>
        {unread && <span className="nt-dot" aria-label="Unread" />}
      </button>
      {children}
    </div>
  )
}
