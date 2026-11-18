import { useRef } from 'react'
import { MoreHorizontal } from 'lucide-react'

export default function UserRowActions({ user, currentUser, onEdit, onAction }) {
  const detailsRef = useRef(null)
  const isSelf = String(user.id) === String(currentUser?.id)
  const deleted = Boolean(user.deleted_at)

  function run(action) {
    detailsRef.current?.removeAttribute('open')
    onAction(action, user)
  }

  return (
    <details className="row-menu" ref={detailsRef}>
      <summary aria-label={`Actions for ${user.name}`} title="User actions">
        <MoreHorizontal size={19} />
      </summary>
      <div className="row-menu-popover" role="group" aria-label={`${user.name} actions`}>
        <button type="button" onClick={() => { detailsRef.current?.removeAttribute('open'); onEdit(user) }}>Edit user</button>
        {!deleted && user.status === 'active' ? (
          <button
            type="button"
            disabled={isSelf}
            title={isSelf ? 'You cannot suspend your own account.' : undefined}
            onClick={() => run('suspend')}
          >
            Suspend access
          </button>
        ) : !deleted && (
          <button type="button" onClick={() => run('activate')}>Activate access</button>
        )}
        {!deleted && <button type="button" onClick={() => run('reset-password')}>Reset password</button>}
        {deleted ? (
          <button type="button" onClick={() => run('restore')}>Restore user</button>
        ) : (
          <button
            type="button"
            className="menu-danger"
            disabled={isSelf}
            title={isSelf ? 'You cannot delete your own account.' : undefined}
            onClick={() => run('delete')}
          >
            Delete user
          </button>
        )}
      </div>
    </details>
  )
}
