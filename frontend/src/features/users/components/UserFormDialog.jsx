import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import Dialog from './Dialog'

const userSchema = z.object({
  name: z.string().trim().min(1, 'Name is required.').max(255, 'Name is too long.'),
  email: z.string().trim().email('Enter a valid email address.').max(255),
  phone: z.string().trim().max(255, 'Phone number is too long.'),
  role: z.enum(['admin', 'manager', 'staff']),
  status: z.enum(['active', 'suspended']),
  password: z.string().optional().refine((value) => !value || value.length >= 8, 'Password must be at least 8 characters.'),
})

const emptyValues = { name: '', email: '', phone: '', role: 'staff', status: 'active', password: '' }

export default function UserFormDialog({ open, user, currentUser, busy, onClose, onSubmit }) {
  const editing = Boolean(user)
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm({ resolver: zodResolver(userSchema), defaultValues: emptyValues })
  const isCurrentUser = editing && String(user.id) === String(currentUser?.id)

  useEffect(() => {
    reset(user ? {
      name: user.name ?? '',
      email: user.email ?? '',
      phone: user.phone ?? '',
      role: user.role ?? 'staff',
      status: user.status ?? 'active',
      password: '',
    } : emptyValues)
  }, [user, reset, open])

  async function submit(values) {
    const result = await onSubmit({
      name: values.name.trim(),
      email: values.email.trim(),
      phone: values.phone.trim() || null,
      role: values.role,
      status: values.status,
      ...(!editing && values.password ? { password: values.password } : {}),
    })

    if (result?.errors) {
      for (const [field, messages] of Object.entries(result.errors)) {
        if (['name', 'email', 'phone', 'role', 'status', 'password'].includes(field)) {
          setError(field, { type: 'server', message: Array.isArray(messages) ? messages[0] : String(messages) })
        }
      }
    }
  }

  return (
    <Dialog open={open} title={editing ? 'Edit user' : 'Create a user'} onClose={onClose} size="regular">
      <form className="user-form" onSubmit={handleSubmit(submit)} noValidate>
        <label className="field">
          <span>Name</span>
          <input autoComplete="name" autoFocus {...register('name')} aria-invalid={Boolean(errors.name)} />
          {errors.name && <span className="field-error">{errors.name.message}</span>}
        </label>
        <label className="field">
          <span>Email address</span>
          <input type="email" autoComplete="email" {...register('email')} aria-invalid={Boolean(errors.email)} />
          {errors.email && <span className="field-error">{errors.email.message}</span>}
        </label>
        <label className="field">
          <span>Phone <small>Optional</small></span>
          <input type="tel" autoComplete="tel" {...register('phone')} aria-invalid={Boolean(errors.phone)} />
          {errors.phone && <span className="field-error">{errors.phone.message}</span>}
        </label>
        <div className="form-grid">
          <label className="field">
            <span>Role</span>
            <select {...register('role')}>
              {(!isCurrentUser || user.role === 'admin') && <option value="admin">Admin</option>}
              {(!isCurrentUser || user.role === 'manager') && <option value="manager">Manager</option>}
              {(!isCurrentUser || user.role === 'staff') && <option value="staff">Staff</option>}
            </select>
            {isCurrentUser && <small className="field-hint">Your account cannot be demoted.</small>}
            {errors.role && <span className="field-error">{errors.role.message}</span>}
          </label>
          <label className="field">
            <span>Status</span>
            <select {...register('status')}>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
            </select>
            {errors.status && <span className="field-error">{errors.status.message}</span>}
          </label>
        </div>
        {!editing && (
          <label className="field">
            <span>Password <small>Optional</small></span>
            <input type="password" autoComplete="new-password" {...register('password')} aria-invalid={Boolean(errors.password)} />
            <small className="field-hint">Leave blank to generate a temporary password for you.</small>
            {errors.password && <span className="field-error">{errors.password.message}</span>}
          </label>
        )}
        <div className="dialog-actions">
          <button type="button" className="button button-secondary" onClick={onClose}>Cancel</button>
          <button type="submit" className="button button-primary" disabled={busy}>
            {busy ? 'Saving…' : editing ? 'Save changes' : 'Create user'}
          </button>
        </div>
      </form>
    </Dialog>
  )
}
