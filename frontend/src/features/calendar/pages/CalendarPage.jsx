import { useMemo, useState } from 'react'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useAuth } from '../../../auth/useAuth'
import Button from '../../../components/Button'
import ConfirmDialog from '../../../components/ConfirmDialog'
import Modal from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import { useMediaQuery } from '../../../hooks/useMediaQuery'
import ItemDetail from '../../items/components/ItemDetail'
import ItemForm from '../../items/components/ItemForm'
import RenewDialog from '../../items/components/RenewDialog'
import { useItemMutations } from '../../items/hooks/useItems'
import { useClientOptions, useDepartmentOptions } from '../../master-data/hooks/useMasterOptions'
import TaskDetail from '../../tasks/components/TaskDetail'
import TaskForm from '../../tasks/componenets/TaskForm'
import { useTaskMutations } from '../../tasks/hooks/useTasks'
import { formatDate } from '../../tasks/utils'
import { calendarApi } from '../api/calendarApi'
import ChangeDate from '../components/ChangeDate'
import DayCell from '../components/DayCell'
import EventChip from '../components/EventChip'
import {
  fromISO, longDay, monthGrid, monthTitle, todayISO, toISO, weekGrid, weekTitle, addDays,
} from '../dateUtils'

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

export default function CalendarPage() {
  const toast = useToast()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const canManage = user?.role === 'admin' || user?.role === 'manager'
  const isPhone = useMediaQuery('(max-width: 760px)')

  const [view, setView] = useState('month')
  const [cursor, setCursor] = useState(() => fromISO(todayISO()))
  const [type, setType] = useState('all')
  const [clientId, setClientId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [mine, setMine] = useState(false)

  const [detail, setDetail] = useState(null)   // { type, id }
  const [more, setMore] = useState(null)       // { date, events }
  const [form, setForm] = useState(null)       // { type, record }
  const [toDelete, setToDelete] = useState(null) // { type, record }
  const [toRenew, setToRenew] = useState(null)

  const clients = useClientOptions().data ?? []
  const departments = useDepartmentOptions().data ?? []
  const taskMutations = useTaskMutations()
  const itemMutations = useItemMutations()

  const days = useMemo(() => (view === 'month' ? monthGrid(cursor) : weekGrid(cursor)), [view, cursor])
  const from = toISO(days[0])
  const to = toISO(days[days.length - 1])
  const params = {
    from, to,
    type: type === 'all' ? undefined : type,
    client_id: clientId || undefined,
    department_id: departmentId || undefined,
    mine: mine ? 1 : undefined,
  }
  const queryKey = ['calendar', params]

  const { data: events = [], isLoading, isError, refetch } = useQuery({
    queryKey,
    queryFn: () => calendarApi.events(params),
    placeholderData: keepPreviousData,
  })

  const byDate = useMemo(() => {
    const map = new Map()
    for (const event of events) {
      const key = String(event.due_date).slice(0, 10)
      if (!map.has(key)) map.set(key, [])
      map.get(key).push(event)
    }
    for (const list of map.values()) {
      list.sort((a, b) => (a.type === b.type ? a.title.localeCompare(b.title) : a.type === 'task' ? -1 : 1))
    }
    return map
  }, [events])

  const today = todayISO()

  function canDrag(event) {
    if (typeof event.can_edit === 'boolean') return event.can_edit
    return event.type === 'item' ? canManage : true // the server still enforces permissions
  }

  function refreshAll() {
    for (const key of ['calendar', 'tasks', 'items', 'dashboard']) {
      queryClient.invalidateQueries({ queryKey: [key] })
    }
  }

  async function moveEvent(event, newDate) {
    if (String(event.due_date).slice(0, 10) === newDate) return
    const previous = queryClient.getQueryData(queryKey)
    queryClient.setQueryData(queryKey, (old = []) =>
      old.map((e) => (e.type === event.type && e.id === event.id ? { ...e, due_date: newDate } : e)))
    try {
      await calendarApi.moveDueDate(event.type, event.id, newDate)
      toast.success(`“${event.title}” moved to ${formatDate(newDate)}.`)
      refreshAll()
    } catch (error) {
      queryClient.setQueryData(queryKey, previous)
      toast.error(errorMessage(error, 'Could not change the date.'))
    }
  }

  function handleDrop(payload, newDate) {
    const [kind, rawId] = String(payload).split(':')
    const event = events.find((e) => e.type === kind && String(e.id) === rawId)
    if (event && canDrag(event)) moveEvent(event, newDate)
  }

  function openEvent(event) {
    setMore(null)
    setDetail({ type: event.type, id: event.id })
  }

  function closeDetail() {
    setDetail(null)
    refreshAll()
  }

  function shift(direction) {
    if (view === 'month') setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + direction, 1))
    else setCursor(addDays(cursor, direction * 7))
  }

  async function confirmDelete() {
    try {
      const mutation = toDelete.type === 'item' ? itemMutations.remove : taskMutations.remove
      await mutation.mutateAsync(toDelete.record.id)
      toast.success(`${toDelete.type === 'item' ? 'Item' : 'Task'} deleted.`)
      setToDelete(null)
      setDetail(null)
      refreshAll()
    } catch (error) {
      toast.error(errorMessage(error))
    }
  }

  const title = view === 'month' ? monthTitle(cursor) : weekTitle(days)
  const limit = view === 'month' ? 3 : 50
  const agendaDays = days.filter((d) => byDate.has(toISO(d)))

  return (
    <div className="md-page">
      <header className="md-page-header">
        <div>
          <h1>Calendar</h1>
          <p>Every task and item deadline in one place. Drag an entry to another day to reschedule it.</p>
        </div>
      </header>

      <div className="cal-toolbar">
        <div className="cal-nav">
          <button type="button" className="md-icon-btn" aria-label="Previous" onClick={() => shift(-1)}><ChevronLeft size={20} /></button>
          <button type="button" className="md-icon-btn" aria-label="Next" onClick={() => shift(1)}><ChevronRight size={20} /></button>
          <Button className="btn-secondary" onClick={() => setCursor(fromISO(todayISO()))}>Today</Button>
          <h2 className="cal-title" aria-live="polite">{title}</h2>
        </div>
        <div className="cal-view-toggle" role="group" aria-label="Calendar view">
          {['month', 'week'].map((v) => (
            <button key={v} type="button" className={view === v ? 'active' : ''} aria-pressed={view === v}
              onClick={() => setView(v)}>
              {v === 'month' ? 'Month' : 'Week'}
            </button>
          ))}
        </div>
      </div>

      <div className="md-toolbar">
        <select className="md-select" aria-label="Show" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="all">Tasks and items</option>
          <option value="tasks">Tasks only</option>
          <option value="items">Items only</option>
        </select>
        <select className="md-select" aria-label="Client" value={clientId} onChange={(e) => setClientId(e.target.value)}>
          <option value="">All clients</option>
          {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select className="md-select" aria-label="Department" value={departmentId} onChange={(e) => setDepartmentId(e.target.value)}>
          <option value="">All departments</option>
          {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
        <label className="login-remember">
          <input type="checkbox" checked={mine} onChange={(e) => setMine(e.target.checked)} />
          <span>Mine only</span>
        </label>
      </div>

      <ul className="cal-legend" aria-label="Legend">
        <li><i className="cal-dot cal-open" /> Open task</li>
        <li><i className="cal-dot cal-in_progress" /> In progress</li>
        <li><i className="cal-dot cal-done" /> Done</li>
        <li><i className="cal-dot cal-active" /> Item</li>
        <li><i className="cal-dot cal-overdue" /> Overdue</li>
      </ul>

      {isError ? (
        <div className="md-state">
          <p>We couldn’t load the calendar.</p>
          <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button>
        </div>
      ) : isLoading ? (
        <div className="md-skeleton" style={{ height: 420 }} role="status" aria-label="Loading" />
      ) : isPhone ? (
        <div className="cal-agenda">
          {agendaDays.length === 0 ? (
            <div className="md-state"><p>Nothing due in this period.</p></div>
          ) : agendaDays.map((day) => {
            const iso = toISO(day)
            return (
              <section key={iso} className="cal-agenda-day">
                <h3 className={iso === today ? 'today' : ''}>{longDay(day)}</h3>
                <div className="cal-agenda-list">
                  {byDate.get(iso).map((event) => (
                    <EventChip key={`${event.type}-${event.id}`} event={event} draggable={false} onOpen={openEvent} />
                  ))}
                </div>
              </section>
            )
          })}
        </div>
      ) : (
        <div className="cal-board">
          <div className="cal-grid cal-weekdays" aria-hidden="true">
            {WEEKDAYS.map((d) => <div key={d}>{d}</div>)}
          </div>
          <div className="cal-grid">
            {days.map((day) => {
              const iso = toISO(day)
              return (
                <DayCell
                  key={iso}
                  date={day}
                  events={byDate.get(iso) ?? []}
                  inMonth={view === 'week' || day.getMonth() === cursor.getMonth()}
                  isToday={iso === today}
                  limit={limit}
                  tall={view === 'week'}
                  canDrag={canDrag}
                  onOpen={openEvent}
                  onMore={(date, list) => setMore({ date, events: list })}
                  onDropEvent={handleDrop}
                />
              )
            })}
          </div>
        </div>
      )}

      {/* Day popover for "+N more" */}
      <Modal open={more !== null} title={more ? longDay(more.date) : ''} size="sm" onClose={() => setMore(null)}>
        {more && (
          <div className="cal-more-list">
            {more.events.map((event) => (
              <EventChip key={`${event.type}-${event.id}`} event={event} draggable={false} onOpen={openEvent} />
            ))}
          </div>
        )}
      </Modal>

      {/* Detail */}
      <Modal open={detail !== null} title={detail?.type === 'item' ? 'Item details' : 'Task details'} size="lg" onClose={closeDetail}>
        {detail && (
          <>
            {detail.type === 'item' ? (
              <ItemDetail
                id={detail.id}
                onEdit={(record) => { setDetail(null); setForm({ type: 'item', record }) }}
                onDelete={(record) => setToDelete({ type: 'item', record })}
                onRenew={(record) => { setDetail(null); setToRenew(record) }}
              />
            ) : (
              <TaskDetail
                id={detail.id}
                onEdit={(record) => { setDetail(null); setForm({ type: 'task', record }) }}
                onDelete={(record) => setToDelete({ type: 'task', record })}
              />
            )}
            <ChangeDate type={detail.type} id={detail.id} />
          </>
        )}
      </Modal>

      {/* Edit forms */}
      <Modal open={form !== null} title={form?.type === 'item' ? 'Edit item' : 'Edit task'} size="lg"
        onClose={() => { setForm(null); refreshAll() }}>
        {form?.type === 'task' && (
          <TaskForm key={form.record.id} task={form.record} onClose={() => { setForm(null); refreshAll() }} />
        )}
        {form?.type === 'item' && (
          <ItemForm key={form.record.id} item={form.record} onClose={() => { setForm(null); refreshAll() }} />
        )}
      </Modal>

      <RenewDialog item={toRenew} onClose={() => { setToRenew(null); refreshAll() }} />

      <ConfirmDialog
        open={toDelete !== null}
        title={`Delete ${toDelete?.type === 'item' ? 'item' : 'task'}?`}
        confirmLabel="Delete"
        danger
        busy={taskMutations.remove.isPending || itemMutations.remove.isPending}
        onConfirm={confirmDelete}
        onCancel={() => setToDelete(null)}
      >
        “{toDelete?.record?.title}” will be removed.
      </ConfirmDialog>
    </div>
  )
}