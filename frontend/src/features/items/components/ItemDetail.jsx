import { Link } from 'react-router-dom'
import { Ban, CalendarClock, Pencil, RotateCcw, Trash2 } from 'lucide-react'
import Button from '../../../components/Button'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import { formatDate, offsetLabel } from '../../tasks/utils'
import { useItem, useItemMutations } from '../hooks/useItems'
import { ITEM_STATUS_LABELS, itemDueLabel, itemRecurrenceLabel } from '../utils'

export default function ItemDetail({ id, onEdit, onDelete, onRenew }) {
  const toast = useToast()
  const { data: item, isLoading, isError } = useItem(id)
  const { setStatus } = useItemMutations()

  if (isLoading) return <p className="tk-hint">Loading…</p>
  if (isError || !item) return <p className="field-error">We couldn’t load this item. It may have been removed.</p>

  async function changeStatus(status) {
    try {
      await setStatus.mutateAsync({ id: item.id, status })
      toast.success(status === 'cancelled' ? 'Item cancelled.' : 'Item reactivated.')
    } catch (error) {
      toast.error(errorMessage(error))
    }
  }

  const overdue = item.is_overdue && item.status === 'active'

  return (
    <div className="tk-detail">
      <div className="tk-detail-top">
        <span className={`md-badge it-status-${item.status}`}>{ITEM_STATUS_LABELS[item.status] ?? item.status}</span>
        <span className={`tk-due${overdue ? ' is-overdue' : ''}`}>{formatDate(item.due_date)} · {itemDueLabel(item)}</span>
      </div>

      {item.description && <p className="tk-description">{item.description}</p>}

      <dl className="tk-meta">
        <div><dt>Client</dt><dd>{item.client?.id ? <Link to={`/clients/${item.client.id}`}>{item.client.name}</Link> : '—'}</dd></div>
        <div><dt>Departments</dt><dd>{item.departments?.map((d) => d.name).join(', ') || '—'}</dd></div>
        <div><dt>In charge</dt><dd>{item.owners?.map((o) => o.name).join(', ') || '—'}</dd></div>
        <div><dt>Created by</dt><dd>{item.creator?.name ?? '—'}</dd></div>
        <div><dt>Renewal</dt><dd>{itemRecurrenceLabel(item)}</dd></div>
        <div><dt>Last renewed</dt><dd>{item.last_renewed_at ? formatDate(item.last_renewed_at) : 'Never'}</dd></div>
        <div className="tk-meta-wide">
          <dt>Reminders</dt>
          <dd>{item.reminder_periods?.length ? item.reminder_periods.map(offsetLabel).join(' · ') : 'None'}</dd>
        </div>
      </dl>

      {(item.can_renew || item.can_edit) && (
        <div className="tk-actions">
          {item.can_renew && item.status !== 'cancelled' && (
            <Button onClick={() => onRenew(item)}><CalendarClock size={17} /> Renew</Button>
          )}
          {item.can_edit && item.status !== 'cancelled' && (
            <Button className="btn-secondary" loading={setStatus.isPending} onClick={() => changeStatus('cancelled')}>
              <Ban size={16} /> Cancel item
            </Button>
          )}
          {item.can_edit && item.status === 'cancelled' && (
            <Button className="btn-secondary" loading={setStatus.isPending} onClick={() => changeStatus('active')}>
              <RotateCcw size={16} /> Reactivate
            </Button>
          )}
          {item.can_edit && <Button className="btn-secondary" onClick={() => onEdit(item)}><Pencil size={16} /> Edit</Button>}
          {item.can_delete && <Button className="btn-danger" onClick={() => onDelete(item)}><Trash2 size={16} /> Delete</Button>}
        </div>
      )}
    </div>
  )
}