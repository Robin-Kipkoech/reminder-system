import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Plus, Repeat, Search } from 'lucide-react'
import Button from '../../../components/Button'
import ConfirmDialog from '../../../components/ConfirmDialog'
import Modal from '../../../components/Modal'
import { useToast } from '../../../components/Toast'
import { useAuth } from '../../../auth/useAuth'
import { errorMessage } from '../../../lib/api'
import { useClientOptions, useDepartmentOptions } from '../../master-data/hooks/useMasterOptions'
import { formatDate } from '../../tasks/utils'
import ItemDetail from '../components/ItemDetail'
import ItemForm from '../components/ItemForm'
import RenewDialog from '../components/RenewDialog'
import { useItemList, useItemMutations } from '../hooks/useItems'
import { ITEM_STATUS_LABELS, itemDueLabel } from '../utils'

export default function ItemsPage() {
  const toast = useToast()
  const { user } = useAuth()
  const canManage = user?.role === 'admin' || user?.role === 'manager'

  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [searchParams, setSearchParams] = useSearchParams()
  const [status, setStatus] = useState(searchParams.get('status') ?? '')
  const [clientId, setClientId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [mine, setMine] = useState(false)
  const [sort, setSort] = useState('due_date')
  const [direction, setDirection] = useState('asc')
  const [page, setPage] = useState(1)

  const [viewId, setViewId] = useState(null)
  const [form, setForm] = useState(null) // null | 'new' | item
  const [toDelete, setToDelete] = useState(null)
  const [toRenew, setToRenew] = useState(null)

  const clients = useClientOptions().data ?? []
  const departments = useDepartmentOptions().data ?? []
  const { remove } = useItemMutations()

  const openParam = searchParams.get('open')
  useEffect(() => {
    if (!openParam) return
    setViewId(Number(openParam))
    const next = new URLSearchParams(searchParams)
    next.delete('open')
    setSearchParams(next, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openParam])

  useEffect(() => {
    const timer = setTimeout(() => { setDebounced(search); setPage(1) }, 300)
    return () => clearTimeout(timer)
  }, [search])

  const { data, isLoading, isError, refetch } = useItemList({
    page, per_page: 15, sort, direction,
    search: debounced || undefined,
    status: status || undefined,
    client_id: clientId || undefined,
    department_id: departmentId || undefined,
    mine: mine ? 1 : undefined,
  })
  const rows = Array.isArray(data) ? data : data?.data ?? []
  const lastPage = data?.meta?.last_page ?? 1
  const total = data?.meta?.total ?? rows.length

  const filter = (setter) => (event) => { setter(event.target.value); setPage(1) }

  function toggleSort(key) {
    if (sort === key) setDirection(direction === 'asc' ? 'desc' : 'asc')
    else { setSort(key); setDirection('asc') }
  }

  async function confirmDelete() {
    try {
      await remove.mutateAsync(toDelete.id)
      toast.success('Item deleted.')
      setToDelete(null)
      setViewId(null)
    } catch (error) {
      toast.error(errorMessage(error))
    }
  }

  return (
    <div className="md-page">
      <header className="md-page-header">
        <div>
          <h1>Items</h1>
          <p>
            {canManage
              ? 'Licences, subscriptions and recurring services that expire or fall due.'
              : 'Licences and services you are in charge of.'}
          </p>
        </div>
        {canManage && <Button onClick={() => setForm('new')}><Plus size={18} /> New item</Button>}
      </header>

      <div className="md-toolbar">
        <label className="md-search">
          <Search size={17} aria-hidden="true" />
          <input type="search" placeholder="Search items…" aria-label="Search items"
            value={search} onChange={(event) => setSearch(event.target.value)} />
        </label>
        <select className="md-select" aria-label="Filter by status" value={status} onChange={filter(setStatus)}>
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="overdue">Overdue</option>
          <option value="expired">Expired</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <select className="md-select" aria-label="Filter by client" value={clientId} onChange={filter(setClientId)}>
          <option value="">All clients</option>
          {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        <select className="md-select" aria-label="Filter by department" value={departmentId} onChange={filter(setDepartmentId)}>
          <option value="">All departments</option>
          {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
        {canManage && (
          <label className="login-remember">
            <input type="checkbox" checked={mine} onChange={(event) => { setMine(event.target.checked); setPage(1) }} />
            <span>Items I’m in charge of</span>
          </label>
        )}
      </div>

      {isError ? (
        <div className="md-state">
          <p>We couldn’t load items.</p>
          <Button className="btn-secondary" onClick={() => refetch()}>Try again</Button>
        </div>
      ) : isLoading ? (
        <div className="md-skeletons" role="status" aria-label="Loading">
          {Array.from({ length: 5 }, (_, i) => <div key={i} className="md-skeleton" />)}
        </div>
      ) : rows.length === 0 ? (
        <div className="md-state"><p>No items match these filters.</p></div>
      ) : (
        <table className="md-table tk-table">
          <thead>
            <tr>
              <th aria-sort={sort === 'title' ? (direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                <button type="button" className="md-sort" onClick={() => toggleSort('title')}>Item</button>
              </th>
              <th aria-sort={sort === 'due_date' ? (direction === 'asc' ? 'ascending' : 'descending') : undefined}>
                <button type="button" className="md-sort" onClick={() => toggleSort('due_date')}>Due</button>
              </th>
              <th>In charge</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((item) => {
              const overdue = item.is_overdue && item.status === 'active'
              return (
                <tr key={item.id} className="tk-row" tabIndex={0}
                  onClick={() => setViewId(item.id)}
                  onKeyDown={(event) => { if (event.key === 'Enter') setViewId(item.id) }}>
                  <td data-label="Item">
                    <div className="tk-title">
                      {item.title}
                      {item.is_recurring && <Repeat size={14} aria-label="Renews regularly" />}
                    </div>
                    <small className="tk-sub">
                      {item.client?.name} · {item.departments?.map((d) => d.name).join(', ')}
                    </small>
                  </td>
                  <td data-label="Due">
                    <div>{formatDate(item.due_date)}</div>
                    <small className={`tk-sub${overdue ? ' is-overdue' : ''}`}>{itemDueLabel(item)}</small>
                  </td>
                  <td data-label="In charge">{item.owners?.map((o) => o.name).join(', ') || '—'}</td>
                  <td data-label="Status">
                    <span className={`md-badge it-status-${item.status}`}>{ITEM_STATUS_LABELS[item.status] ?? item.status}</span>
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
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

      <Modal open={viewId !== null} title="Item details" size="lg" onClose={() => setViewId(null)}>
        {viewId !== null && (
          <ItemDetail
            id={viewId}
            onEdit={(item) => { setViewId(null); setForm(item) }}
            onDelete={setToDelete}
            onRenew={(item) => { setViewId(null); setToRenew(item) }}
          />
        )}
      </Modal>

      <Modal open={form !== null} title={form === 'new' ? 'New item' : 'Edit item'} size="lg" onClose={() => setForm(null)}>
        {form !== null && (
          <ItemForm key={form === 'new' ? 'new' : form.id} item={form === 'new' ? null : form} onClose={() => setForm(null)} />
        )}
      </Modal>

      <RenewDialog item={toRenew} onClose={() => setToRenew(null)} />

      <ConfirmDialog
        open={toDelete !== null}
        title="Delete item?"
        confirmLabel="Delete"
        danger
        busy={remove.isPending}
        onConfirm={confirmDelete}
        onCancel={() => setToDelete(null)}
      >
        “{toDelete?.title}” will be removed from the item list.
      </ConfirmDialog>
    </div>
  )
}