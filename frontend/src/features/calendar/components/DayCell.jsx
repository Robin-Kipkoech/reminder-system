import { useState } from 'react'
import EventChip from './EventChip'
import { longDay, toISO } from '../dateUtils'

export default function DayCell({
  date, events, inMonth, isToday, limit, canDrag, onOpen, onMore, onDropEvent, tall,
}) {
  const [over, setOver] = useState(false)
  const shown = events.slice(0, limit)
  const hidden = events.length - shown.length

  return (
    <div
      role="group"
      aria-label={`${longDay(date)}, ${events.length} ${events.length === 1 ? 'event' : 'events'}`}
      className={`cal-cell${inMonth ? '' : ' dim'}${isToday ? ' today' : ''}${over ? ' drop' : ''}${tall ? ' tall' : ''}`}
      onDragOver={(e) => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; if (!over) setOver(true) }}
      onDragLeave={() => setOver(false)}
      onDrop={(e) => {
        e.preventDefault()
        setOver(false)
        onDropEvent(e.dataTransfer.getData('text/plain'), toISO(date))
      }}
    >
      <span className="cal-daynum">{date.getDate()}</span>
      <div className="cal-chips">
        {shown.map((event) => (
          <EventChip key={`${event.type}-${event.id}`} event={event} draggable={canDrag(event)} onOpen={onOpen} />
        ))}
        {hidden > 0 && (
          <button type="button" className="cal-more" onClick={() => onMore(date, events)}>
            +{hidden} more
          </button>
        )}
      </div>
    </div>
  )
}