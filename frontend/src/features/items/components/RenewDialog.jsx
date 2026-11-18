import { useState } from 'react'
import Button from '../../../components/Button'
import Input from '../../../components/Input'
import Modal from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import { formatDate } from '../../tasks/utils'
import { useItemMutations } from '../hooks/useItems'

function Body({ item, onClose }) {
  const toast = useToast()
  const { renew } = useItemMutations()
  const [date, setDate] = useState(item.suggested_renewal_date ?? '')
  const [error, setError] = useState('')

  async function submit(event) {
    event.preventDefault()
    if (!date) { setError('Choose the new due date.'); return }
    if (date <= String(item.due_date).slice(0, 10)) {
      setError('The new date must be after the current due date.')
      return
    }
    try {
      await renew.mutateAsync({ id: item.id, dueDate: date })
      toast.success(`Renewed. New due date: ${formatDate(date)}.`)
      onClose()
    } catch (e) {
      setError(e.response?.data?.errors?.due_date?.[0] ?? errorMessage(e))
    }
  }

  return (
    <form className="md-form" onSubmit={submit} noValidate>
      <p className="md-confirm-text">
        “{item.title}” is currently due on {formatDate(item.due_date)}. Choose its new due date.
        Reminders will start again for the new date.
      </p>
      <Input id="renew-date" label="New due date" type="date" value={date} error={error}
        onChange={(event) => { setDate(event.target.value); setError('') }} />
      <div className="md-actions">
        <Button className="btn-secondary" onClick={onClose}>Cancel</Button>
        <Button type="submit" loading={renew.isPending}>Renew</Button>
      </div>
    </form>
  )
}

export default function RenewDialog({ item, onClose }) {
  return (
    <Modal open={item !== null} title="Renew item" size="sm" onClose={onClose}>
      {item && <Body key={item.id} item={item} onClose={onClose} />}
    </Modal>
  )
}