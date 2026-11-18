import { useCallback, useEffect, useMemo, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import {
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  Check,
  ChevronLeft,
  ChevronRight,
  Copy,
  LoaderCircle,
  Plus,
  ShieldCheck,
  Users,
} from 'lucide-react'
import { errorMessage } from '../../../lib/api'
import Avatar from '../../../components/Avatar'
import { timeAgo } from '../../../lib/time'
import { useAuth } from '../../../auth/useAuth'
import Dialog from '../components/Dialog'
import UserFormDialog from '../components/UserFormDialog'
import UserRowActions from '../components/UserRowActions'
import SearchBox from '../components/SearchBox'
import { createUser, getUser, performUserAction, updateUser } from '../api/usersApi'
import { useUsers } from '../hooks/useUsers'
import UserSummaryChips from '../components/UserSummaryChips'
import { useUserSummary } from '../hooks/useProfile'

function useToasts() {
  const [toasts, setToasts] = useState([])
  const notify = useCallback((message, type = 'success') => {
    const id = `${Date.now()}-${Math.random()}`
    setToasts((items) => [...items, { id, message, type }])
    window.setTimeout(() => setToasts((items) => items.filter((toast) => toast.id !== id)), 4500)
  }, [])
  const dismiss = useCallback((id) => setToasts((items) => items.filter((toast) => toast.id !== id)), [])
  return { toasts, notify, dismiss }
}

function roleLabel(role) {
  return role ? `${role[0].toUpperCase()}${role.slice(1)}` : '—'
}

function UserBadges({ user }) {
  const deleted = Boolean(user.deleted_at)
  return (
    <div className="badge-stack">
      <span className={`badge role-badge role-${user.role}`}>{roleLabel(user.role)}</span>
      <span className={`badge status-badge ${deleted ? 'status-deleted' : `status-${user.status}`}`}>
        {deleted ? 'Deleted' : roleLabel(user.status)}
      </span>
    </div>
  )
}

function SkeletonRows() {
  return (
    <div className="skeleton-list" aria-label="Loading users" role="status">
      {Array.from({ length: 6 }, (_, index) => (
        <div className="skeleton-row" key={index}>
          <span /><span /><span /><span /><span />
        </div>
      ))}
    </div>
  )
}

function ToastStack({ toasts, dismiss }) {
  return (
    <div className="toast-stack" aria-live="polite" aria-relevant="additions">
      {toasts.map((toast) => (
        <div className={`toast toast-${toast.type}`} key={toast.id} role={toast.type === 'error' ? 'alert' : 'status'}>
          <span className="toast-mark">{toast.type === 'error' ? '!' : <Check size={16} />}</span>
          <span>{toast.message}</span>
          <button type="button" className="toast-close" aria-label="Dismiss notification" onClick={() => dismiss(toast.id)}>×</button>
        </div>
      ))}
    </div>
  )
}

function ActionDescription({ action, user }) {
  if (action === 'suspend') return <p>They will lose access immediately, and their active sessions and API tokens will be revoked.</p>
  if (action === 'delete') return <p>Their account will be soft-deleted. Their history is retained, and you can restore the account later.</p>
  if (action === 'reset-password') return <p>A new temporary password will replace their current password. They must change it at their next sign-in.</p>
  return <p>Continue with this action for <strong>{user?.name}</strong>?</p>
}

export default function UserManagementPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const queryClient = useQueryClient()
  const { user: currentUser } = useAuth()
  const { toasts, notify, dismiss } = useToasts()
  const [formUser, setFormUser] = useState(null)
  const [formOpen, setFormOpen] = useState(false)
  const [confirmation, setConfirmation] = useState(null)
  const [busy, setBusy] = useState(false)
  const [temporaryPassword, setTemporaryPassword] = useState(null)
  const [copied, setCopied] = useState(false)

  const page = Math.max(1, Number(searchParams.get('page') || 1))
  const perPage = Math.min(100, Math.max(1, Number(searchParams.get('per_page') || 15)))
  const search = searchParams.get('search') ?? ''
  const role = searchParams.get('role') ?? ''
  const status = searchParams.get('status') ?? ''
  const withTrashed = searchParams.get('with_trashed') === '1' || searchParams.get('with_trashed') === 'true'
  const sort = searchParams.get('sort') || 'created_at'
  const direction = searchParams.get('direction') || 'desc'

  const params = useMemo(() => ({
    page,
    per_page: perPage,
    ...(search ? { search } : {}),
    ...(role ? { role } : {}),
    ...(status ? { status } : {}),
    ...(withTrashed ? { with_trashed: true } : {}),
    sort,
    direction,
  }), [page, perPage, search, role, status, withTrashed, sort, direction])

  const usersQuery = useUsers(params)
  const summaryQuery = useUserSummary()
  const users = usersQuery.data?.users ?? []
  const meta = usersQuery.data?.meta ?? {}
  const currentPage = Number(meta.current_page ?? page)
  const lastPage = Number(meta.last_page ?? 1)
  const total = Number(meta.total ?? users.length)
  const firstItem = total === 0 ? 0 : (currentPage - 1) * perPage + 1
  const lastItem = Math.min(currentPage * perPage, total)

  useEffect(() => {
    if (usersQuery.error && !usersQuery.data) {
      notify(errorMessage(usersQuery.error, 'We could not load users.'), 'error')
    }
  }, [usersQuery.error, usersQuery.data, notify])

  function updateParams(values) {
    const next = new URLSearchParams(searchParams)
    for (const [key, value] of Object.entries(values)) {
      if (value === '' || value === null || value === undefined || value === false) next.delete(key)
      else next.set(key, String(value))
    }
    setSearchParams(next, { replace: true })
  }

  const updateSearch = useCallback((value) => {
    const next = new URLSearchParams(searchParams)
    if (value) next.set('search', value)
    else next.delete('search')
    next.set('page', '1')
    setSearchParams(next, { replace: true })
  }, [searchParams, setSearchParams])

  function setFilter(key, value) {
    updateParams({ [key]: value, page: 1 })
  }

  function toggleSort(key) {
    const nextDirection = sort === key && direction === 'asc' ? 'desc' : 'asc'
    updateParams({ sort: key, direction: nextDirection, page: 1 })
  }

  async function refreshLists() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['users'] }),
      queryClient.invalidateQueries({ queryKey: ['user-options'] }),
    ])
  }

  function openCreate() {
    setFormUser(null)
    setFormOpen(true)
  }

  async function openEdit(user) {
    setBusy(true)
    try {
      const details = await getUser(user.id)
      setFormUser(details)
      setFormOpen(true)
    } catch (error) {
      notify(errorMessage(error, 'Could not load this user.'), 'error')
    } finally {
      setBusy(false)
    }
  }

  async function saveUser(attributes) {
    setBusy(true)
    try {
      if (formUser) {
        await updateUser({ id: formUser.id, attributes })
        notify('User details updated.')
      } else {
        const result = await createUser(attributes)
        if (result.temporaryPassword) {
          setCopied(false)
          setTemporaryPassword(result.temporaryPassword)
        }
        notify('User created.')
      }
      setFormOpen(false)
      await refreshLists()
      return null
    } catch (error) {
      if (error.response?.status === 422 && error.response.data?.errors) {
        return { errors: error.response.data.errors }
      }
      notify(errorMessage(error, 'Could not save this user.'), 'error')
      return null
    } finally {
      setBusy(false)
    }
  }

  function requestAction(action, target) {
    if (['suspend', 'delete', 'reset-password'].includes(action)) {
      setConfirmation({ action, user: target })
      return
    }
    runAction(action, target)
  }

  async function runAction(action, target) {
    setBusy(true)
    try {
      const response = await performUserAction({ id: target.id, action })
      if (action === 'reset-password') {
        const password = response.data?.temporary_password ?? response.data?.data?.temporary_password
        if (password) {
          setCopied(false)
          setTemporaryPassword(password)
        }
      }
      const messages = {
        suspend: 'User suspended. Their access has been revoked.',
        activate: 'User activated.',
        'reset-password': 'Temporary password generated.',
        delete: 'User deleted.',
        restore: 'User restored.',
      }
      notify(messages[action] ?? 'User updated.')
      setConfirmation(null)
      await refreshLists()
    } catch (error) {
      notify(errorMessage(error, 'The action could not be completed.'), 'error')
    } finally {
      setBusy(false)
    }
  }

  async function copyTemporaryPassword() {
    try {
      await navigator.clipboard.writeText(temporaryPassword)
      setCopied(true)
      notify('Temporary password copied.')
    } catch {
      notify('Could not access the clipboard. Select and copy the password manually.', 'error')
    }
  }

  function closeTemporaryPassword() {
    setTemporaryPassword(null)
    setCopied(false)
  }

  const confirmationTitles = {
    suspend: 'Suspend this user?',
    delete: 'Delete this user?',
    'reset-password': 'Reset this password?',
  }
  const confirmationButtons = {
    suspend: 'Suspend user',
    delete: 'Delete user',
    'reset-password': 'Reset password',
  }

  return (
    <div className="users-page">
      <ToastStack toasts={toasts} dismiss={dismiss} />
      <header className="page-header">
        <div>
          <div className="breadcrumb"><span>Workspace</span><span className="breadcrumb-separator">/</span><strong>Users</strong></div>
          <div className="heading-row">
            <div className="heading-icon"><Users size={23} strokeWidth={1.8} /></div>
            <div>
              <h1>User management</h1>
              <p>Manage your team’s access, roles, and account status.</p>
            </div>
          </div>
        </div>
        <button type="button" className="button button-primary new-user-button" onClick={openCreate}>
          <Plus size={18} /> New user
        </button>
      </header>

      <section className="users-panel" aria-label="Users">
        <div className="panel-topline">
          <div>
            <h2>Team members</h2>
            <p>{total} {total === 1 ? 'user' : 'users'} in your workspace</p>
          </div>
          {usersQuery.isFetching && usersQuery.data && (
            <span className="refreshing"><LoaderCircle size={15} className="spin" /> Updating</span>
          )}
        </div>

        <div className="toolbar">
          <SearchBox key={search} value={search} onSearch={updateSearch} />
          <label className="filter-field">
            <span className="sr-only">Filter by role</span>
            <select value={role} onChange={(event) => setFilter('role', event.target.value)}>
              <option value="">All roles</option>
              <option value="admin">Admin</option>
              <option value="manager">Manager</option>
              <option value="staff">Staff</option>
            </select>
          </label>
          <label className="filter-field">
            <span className="sr-only">Filter by status</span>
            <select value={status} onChange={(event) => setFilter('status', event.target.value)}>
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
            </select>
          </label>
          <label className="deleted-toggle">
            <input type="checkbox" checked={withTrashed} onChange={(event) => setFilter('with_trashed', event.target.checked ? '1' : '')} />
            <span className="toggle-track"><span /></span>
            <span>Show deleted</span>
          </label>
        </div>

        <UserSummaryChips
          summary={summaryQuery.data}
          filters={{ role, status }}
          onSelect={({ role: nextRole, status: nextStatus }) => updateParams({ role: nextRole, status: nextStatus, page: 1 })}
        />

        {usersQuery.isPending ? (
          <SkeletonRows />
        ) : usersQuery.isError && !usersQuery.data ? (
          <div className="state-card error-state">
            <div className="state-icon state-icon-error">!</div>
            <h3>Couldn’t load users</h3>
            <p>{errorMessage(usersQuery.error, 'Check your connection and try again.')}</p>
            <button type="button" className="button button-secondary" onClick={() => usersQuery.refetch()}>Retry</button>
          </div>
        ) : users.length === 0 ? (
          <div className="state-card">
            <div className="state-icon"><Users size={21} /></div>
            <h3>{search || role || status ? 'No matching users' : 'No users yet'}</h3>
            <p>{search || role || status ? 'Try adjusting your filters or search.' : 'Add a team member to get started.'}</p>
            {!search && !role && !status && <button type="button" className="button button-primary" onClick={openCreate}><Plus size={17} /> New user</button>}
          </div>
        ) : (
          <>
            <div className="table-scroll">
              <table className="users-table">
                <thead>
                  <tr>
                    <th aria-sort={sort === 'name' ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}>
                      <button type="button" className="sort-button" onClick={() => toggleSort('name')}>
                        Name {sort === 'name' ? (direction === 'asc' ? <ArrowUp size={14} /> : <ArrowDown size={14} />) : <ArrowUpDown size={14} className="sort-muted" />}
                      </button>
                    </th>
                    <th>Phone</th>
                    <th aria-sort={sort === 'role' ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}>
                      <button type="button" className="sort-button" onClick={() => toggleSort('role')}>
                        Role {sort === 'role' ? (direction === 'asc' ? <ArrowUp size={14} /> : <ArrowDown size={14} />) : <ArrowUpDown size={14} className="sort-muted" />}
                      </button>
                    </th>
                    <th aria-sort={sort === 'status' ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}>
                      <button type="button" className="sort-button" onClick={() => toggleSort('status')}>
                        Status {sort === 'status' ? (direction === 'asc' ? <ArrowUp size={14} /> : <ArrowDown size={14} />) : <ArrowUpDown size={14} className="sort-muted" />}
                      </button>
                    </th>
                    <th className="num-cell">Open tasks</th>
                    <th className="num-cell">Overdue</th>
                    <th className="num-cell">Items in charge</th>
                    <th aria-sort={sort === 'last_login_at' ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}>
                      <button type="button" className="sort-button" onClick={() => toggleSort('last_login_at')}>
                        Last active {sort === 'last_login_at' ? (direction === 'asc' ? <ArrowUp size={14} /> : <ArrowDown size={14} />) : <ArrowUpDown size={14} className="sort-muted" />}
                      </button>
                    </th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {users.map((row) => (
                    <tr key={row.id} className={row.deleted_at ? 'row-deleted' : ''}>
                      <td className="user-cell">
                        <Link className="name-link" to={`/admin/users/${row.id}`}>
                          <Avatar user={row} size="sm" />
                          <span className="user-info"><strong>{row.name}</strong><span>{row.email}</span></span>
                        </Link>
                      </td>
                      <td className="phone-cell">{row.phone || '—'}</td>
                      <td><span className="role-pill role-pill-desktop">{roleLabel(row.role)}</span></td>
                      <td><span className={`status-pill status-${row.deleted_at ? 'deleted' : row.status}`}>{row.deleted_at ? 'Deleted' : roleLabel(row.status)}</span></td>
                      <td className="num-cell" data-label="Open tasks">{row.open_tasks_count ?? 0}</td>
                      <td className={`num-cell${row.overdue_tasks_count > 0 ? ' is-overdue' : ''}`} data-label="Overdue">{row.overdue_tasks_count ?? 0}</td>
                      <td className="num-cell" data-label="Items in charge">{row.items_in_charge_count ?? 0}</td>
                      <td className="last-login">{row.last_login_at ? timeAgo(row.last_login_at) : 'Never'}</td>
                      <td className="actions-cell"><UserRowActions user={row} currentUser={currentUser} onEdit={openEdit} onAction={requestAction} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="user-cards">
              {users.map((row) => (
                <article className={`user-card ${row.deleted_at ? 'user-card-deleted' : ''}`} key={row.id}>
                  <div className="user-card-top">
                    <Link className="name-link" to={`/admin/users/${row.id}`}>
                      <Avatar user={row} size="sm" />
                      <span className="user-info"><strong>{row.name}</strong><span>{row.email}</span></span>
                    </Link>
                    <UserRowActions user={row} currentUser={currentUser} onEdit={openEdit} onAction={requestAction} />
                  </div>
                  <div className="user-card-meta">
                    <UserBadges user={row} />
                    <span>{row.phone || 'No phone'}</span>
                    <span>Open tasks · {row.open_tasks_count ?? 0}</span>
                    <span>Overdue · {row.overdue_tasks_count ?? 0}</span>
                    <span>Items in charge · {row.items_in_charge_count ?? 0}</span>
                    <span>Last active · {row.last_login_at ? timeAgo(row.last_login_at) : 'Never'}</span>
                  </div>
                </article>
              ))}
            </div>
          </>
        )}

        {!usersQuery.isPending && users.length > 0 && (
          <footer className="pagination">
            <span>Showing <strong>{firstItem}–{lastItem}</strong> of <strong>{total}</strong></span>
            <div className="pagination-controls">
              <button type="button" className="button button-secondary button-small" disabled={currentPage <= 1} aria-label="Previous page" onClick={() => updateParams({ page: currentPage - 1 })}>
                <ChevronLeft size={16} /> Previous
              </button>
              <span className="page-count">Page {currentPage} of {lastPage}</span>
              <button type="button" className="button button-secondary button-small" disabled={currentPage >= lastPage} aria-label="Next page" onClick={() => updateParams({ page: currentPage + 1 })}>
                Next <ChevronRight size={16} />
              </button>
            </div>
          </footer>
        )}
      </section>

      <div className="security-note"><ShieldCheck size={16} /> User actions are recorded for security and accountability.</div>

      <UserFormDialog
        open={formOpen}
        user={formUser}
        currentUser={currentUser}
        busy={busy}
        onClose={() => setFormOpen(false)}
        onSubmit={saveUser}
      />

      <Dialog
        open={Boolean(confirmation)}
        title={confirmation ? confirmationTitles[confirmation.action] : 'Confirm action'}
        onClose={() => setConfirmation(null)}
        size="small"
      >
        {confirmation && (
          <div className="confirm-content">
            <div className={`confirm-icon confirm-${confirmation.action}`}>
              {confirmation.action === 'suspend' ? '!' : confirmation.action === 'delete' ? '×' : '↻'}
            </div>
            <p className="confirm-user">{confirmation.user.name}</p>
            <ActionDescription action={confirmation.action} user={confirmation.user} />
            {confirmation.action === 'delete' && String(confirmation.user.id) === String(currentUser?.id) && (
              <p className="confirm-warning">You cannot delete your own account.</p>
            )}
            <div className="dialog-actions">
              <button type="button" className="button button-secondary" onClick={() => setConfirmation(null)}>Cancel</button>
              <button
                type="button"
                className={`button ${confirmation.action === 'reset-password' ? 'button-primary' : 'button-danger'}`}
                disabled={busy || (confirmation.action === 'delete' && String(confirmation.user.id) === String(currentUser?.id))}
                onClick={() => runAction(confirmation.action, confirmation.user)}
              >
                {busy ? 'Please wait…' : confirmationButtons[confirmation.action]}
              </button>
            </div>
          </div>
        )}
      </Dialog>

      <Dialog
        open={Boolean(temporaryPassword)}
        title="Temporary password"
        onClose={closeTemporaryPassword}
        size="small"
      >
        {temporaryPassword && (
          <div className="temporary-password">
            <div className="password-once-icon"><ShieldCheck size={22} /></div>
            <p className="password-warning">Shown only once. Share it securely; the user must change it at first login.</p>
            <div className="password-display">
              <code>{temporaryPassword}</code>
              <button type="button" className="button button-secondary button-copy" onClick={copyTemporaryPassword}>
                {copied ? <Check size={16} /> : <Copy size={16} />}
                {copied ? 'Copied' : 'Copy'}
              </button>
            </div>
            <button type="button" className="button button-primary button-wide" onClick={closeTemporaryPassword}>Done</button>
          </div>
        )}
      </Dialog>
    </div>
  )
}
