import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useAuth } from '../../../auth/useAuth'
import Avatar from '../../../components/Avatar'
import Button from '../../../components/Button'
import Input from '../../../components/Input'
import { useToast } from '../../../components/Toast'
import { errorMessage } from '../../../lib/api'
import { profileApi } from '../../users/api/profileApi'
import NotificationSettingsCard from '../components/NotificationSettingsCard'

function mapServerErrors(error, setError, toast) {
  const serverErrors = error.response?.data?.errors
  if (error.response?.status === 422 && serverErrors) {
    Object.entries(serverErrors).forEach(([name, messages]) => setError(name, { message: messages[0] }))
  } else {
    toast.error(errorMessage(error))
  }
}

const detailsSchema = z.object({
  name: z.string().trim().min(1, 'Name is required.').max(255),
  phone: z.string().trim().max(50),
})

function DetailsCard({ user }) {
  const toast = useToast()
  const { refreshUser } = useAuth()
  const { register, handleSubmit, setError, formState: { errors, isSubmitting, isDirty } } = useForm({
    resolver: zodResolver(detailsSchema),
    defaultValues: { name: user.name ?? '', phone: user.phone ?? '' },
  })

  async function onSubmit(values) {
    try {
      await profileApi.updateProfile({ name: values.name, phone: values.phone || null })
      await refreshUser()
      toast.success('Profile updated.')
    } catch (error) {
      mapServerErrors(error, setError, toast)
    }
  }

  return (
    <section className="pf-card">
      <h2>Your details</h2>
      <form className="md-form" onSubmit={handleSubmit(onSubmit)} noValidate>
        <Input id="pf-name" label="Full name" error={errors.name?.message} {...register('name')} />
        <Input id="pf-phone" label="Phone" type="tel" error={errors.phone?.message} {...register('phone')} />
        <Input id="pf-email" label="Email" value={user.email ?? ''} readOnly disabled />
        <Input id="pf-role" label="Role" value={user.role ?? ''} readOnly disabled />
        <div className="md-actions">
          <Button type="submit" loading={isSubmitting} disabled={!isDirty}>Save details</Button>
        </div>
      </form>
    </section>
  )
}

const passwordSchema = z.object({
  current_password: z.string().min(1, 'Enter your current password.'),
  password: z.string().min(8, 'Use at least 8 characters.'),
  password_confirmation: z.string(),
}).refine((values) => values.password === values.password_confirmation, {
  path: ['password_confirmation'],
  message: 'The confirmation does not match.',
})

function PasswordCard() {
  const toast = useToast()
  const { register, handleSubmit, setError, reset, formState: { errors, isSubmitting } } = useForm({
    resolver: zodResolver(passwordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })

  async function onSubmit(values) {
    try {
      await profileApi.changePassword(values)
      reset()
      toast.success('Password changed.')
    } catch (error) {
      mapServerErrors(error, setError, toast)
    }
  }

  return (
    <section className="pf-card">
      <h2>Change password</h2>
      <form className="md-form" onSubmit={handleSubmit(onSubmit)} noValidate>
        <Input id="pf-current" label="Current password" type="password" autoComplete="current-password"
          error={errors.current_password?.message} {...register('current_password')} />
        <Input id="pf-new" label="New password" type="password" autoComplete="new-password"
          error={errors.password?.message} {...register('password')} />
        <Input id="pf-confirm" label="Confirm new password" type="password" autoComplete="new-password"
          error={errors.password_confirmation?.message} {...register('password_confirmation')} />
        <div className="md-actions">
          <Button type="submit" loading={isSubmitting}>Change password</Button>
        </div>
      </form>
    </section>
  )
}

export default function MyProfilePage() {
  const { user } = useAuth()
  if (!user) return null
  return (
    <div className="md-page">
      <header className="up-header">
        <Avatar user={user} size="lg" />
        <div className="up-identity">
          <h1>My profile</h1>
          <p>{user.name} · {user.role}</p>
        </div>
      </header>
      <div className="pf-grid">
        <DetailsCard user={user} />
        <NotificationSettingsCard />
        <PasswordCard />
      </div>
    </div>
  )
}
