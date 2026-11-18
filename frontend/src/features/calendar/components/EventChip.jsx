import { CalendarClock, ClipboardList } from 'lucide-react'

function chipClass(event) {
  const kind = event.type === 'item' ? 'cal-item' : 'cal-task'
  const tone = event.is_overdue && event.status !== 'done' ? 'cal-overdue' : `cal-${event.status}`
  return `cal-chip ${kind} ${tone}`
}

export default function EventChip({ event, draggable, onOpen }) {
  const Icon = event.type === 'item' ? CalendarClock : ClipboardList
  return (
    <button
      type="button"
      className={chipClass(event)}
      draggable={draggable}
      onDragStart={(e) => {
        e.dataTransfer.setData('text/plain', `${event.type}:${event.id}`)
        e.dataTransfer.effectAllowed = 'move'
      }}
      onClick={() => onOpen(event)}
      aria-label={`${event.type === 'item' ? 'Item' : 'Task'}: ${event.title}${event.client_name ? `, ${event.client_name}` : ''}`}
      title={`${event.title}${event.client_name ? ` · ${event.client_name}` : ''}`}
    >
      <Icon size={12} aria-hidden="true" />
      <span>{event.title}</span>
    </button>
  )
}