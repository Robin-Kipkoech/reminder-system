import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import Button from '../../../components/Button'
import Input from '../../../components/Input'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import { useItem } from '../../items/hooks/useItems'
import { useTask } from '../../tasks/hooks/useTasks'
import { formatDate } from '../../tasks/utils'
import { calendarApi } from '../api/calendarApi'

export default function ChangeDate({ type, id }) {
  const toast = useToast()
  const queryClient = useQueryClient()
  const task = useTask(type === 'task' ? id : null)
  const item = useItem(type === 'item' ? id : null)
  const record = type === 'task' ? task.data : item.data
  const [value, setValue] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  if (!record?.can_edit) return null

  const current = String(record.due_date).slice(0, 10)
  const date = value ?? current

  async function save(event) {
    event.preventDefault()
    if (!date) { setError('Choose a date.'); return }
    setBusy(true)
    setError('')
    try {
      await calendarApi.moveDueDate(type, id, date)
      toast.success(`Due date changed to ${formatDate(date)}.`)
      for (const key of ['calendar', 'tasks', 'items', 'dashboard']) {
        queryClient.invalidateQueries({ queryKey: [key] })
      }
      setValue(null)
    } catch (e) {
      setError(e.response?.data?.errors?.due_date?.[0] ?? errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="cal-change-date" onSubmit={save} noValidate>
      <Input id={`change-date-${type}-${id}`} label="Change due date" type="date" value={date} error={error}
        onChange={(event) => { setValue(event.target.value); setError('') }} />
      <Button type="submit" loading={busy} disabled={date === current}>Save date</Button>
    </form>
  )
}