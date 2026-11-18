import { lazy, Suspense, useState } from 'react'
import { BrowserRouter, Link, Navigate, NavLink, Outlet, Route, Routes, useNavigate } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import {
  BellRing, Building2, CalendarClock, CalendarDays, ClipboardList, LayoutDashboard,
  LogOut, Mail, Network, Shield, TrendingUp, Users, X,
} from 'lucide-react'
import { AuthProvider } from './auth/AuthContext'
import { useAuth } from './auth/useAuth'
import { LoginPage, PasswordChangeGate, ProtectedRoute, RoleRoute, SetPasswordPage } from './auth/AuthRoutes'
import Avatar from './components/Avatar'
import AppHeader from './components/AppHeader'
import { ToastProvider } from './components/Toast'
import { QuickActionsProvider } from './features/quick/QuickActions'
import { api } from './lib/api'
import techronLogo from './assets/techron-logo.png'
import './App.css'
import './master-data.css'
import './tasks.css'
import './items.css'
import './dashboard.css'
import './users.css'
import './notifications.css'
import './calendar.css'
import './quick.css'
import './reports.css'
import './forgot.css'
import './header.css'
import './settings.css'

const DashboardPage = lazy(() => import('./features/dashboard/pages/DashboardPage'))
const TasksPage = lazy(() => import('./features/tasks/pages/TasksPage'))
const ItemsPage = lazy(() => import('./features/items/pages/ItemsPage'))
const CalendarPage = lazy(() => import('./features/calendar/pages/CalendarPage'))
const NotificationsPage = lazy(() => import('./features/notifications/pages/NotificationsPage'))
const MyProfilePage = lazy(() => import('./features/profile/pages/MyProfilePage'))
const ClientOverviewPage = lazy(() => import('./features/clients/pages/ClientOverviewPage'))
const ReportsPage = lazy(() => import('./features/reports/pages/ReportsPage'))
const UserManagementPage = lazy(() => import('./features/users/pages/UserManagementPage'))
const UserProfilePage = lazy(() => import('./features/users/pages/UserProfilePage'))
const ClientsPage = lazy(() => import('./features/master-data/pages/ClientsPage'))
const DepartmentsPage = lazy(() => import('./features/master-data/pages/DepartmentsPage'))
const ReminderPeriodsPage = lazy(() => import('./features/master-data/pages/ReminderPeriodsPage'))
const ForgotPasswordPage = lazy(() => import('./features/auth/pages/ForgotPasswordPage'))
const EmailSettingsPage = lazy(() => import('./features/settings/pages/EmailSettingsPage'))

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false },
  },
})

const adminLinks = [
  { to: '/admin/users', label: 'Users', icon: Users },
  { to: '/admin/clients', label: 'Clients', icon: Building2 },
  { to: '/admin/departments', label: 'Departments', icon: Network },
  { to: '/admin/reminder-periods', label: 'Reminder periods', icon: BellRing },
  { to: '/admin/email-settings', label: 'Email settings', icon: Mail },
]

const navClass = ({ isActive }) => `nav-link${isActive ? ' active' : ''}`

function AppShell() {
  const { user, setUser } = useAuth()
  const [logoutBusy, setLogoutBusy] = useState(false)
  const [menuOpen, setMenuOpen] = useState(false)
  const navigate = useNavigate()
  const canSeeReports = user?.role === 'admin' || user?.role === 'manager'

  async function logout() {
    setLogoutBusy(true)
    try {
      await api.post('/api/auth/logout')
    } finally {
      setUser(null)
      setLogoutBusy(false)
      navigate('/login', { replace: true })
    }
  }

  const closeMenu = () => setMenuOpen(false)

  return (
    <QuickActionsProvider>
      <div className="app-shell">
        {menuOpen && <div className="drawer-backdrop" onClick={closeMenu} aria-hidden="true" />}

        <aside className={`sidebar${menuOpen ? ' open' : ''}`}>
          <div className="sidebar-head">
            <Link className="brand" to="/" aria-label="Reminder System home" onClick={closeMenu}>
              <span className="sidebar-logo-frame"><img src={techronLogo} alt="TechRon Ltd." /></span>
            </Link>
            <button type="button" className="drawer-close" aria-label="Close menu" onClick={closeMenu}>
              <X size={20} />
            </button>
          </div>

          <div className="sidebar-label">WORKSPACE</div>
          <nav className="main-nav" aria-label="Main navigation" onClick={closeMenu}>
            <NavLink className={navClass} to="/" end><LayoutDashboard size={18} /> Overview</NavLink>
            <NavLink className={navClass} to="/tasks"><ClipboardList size={18} /> Tasks</NavLink>
            <NavLink className={navClass} to="/items"><CalendarClock size={18} /> Items</NavLink>
            <NavLink className={navClass} to="/calendar"><CalendarDays size={18} /> Calendar</NavLink>
            {canSeeReports && (
              <NavLink className={navClass} to="/reports"><TrendingUp size={18} /> Reports</NavLink>
            )}
          </nav>

          {user?.role === 'admin' && (
            <>
              <div className="sidebar-label">ADMINISTRATION</div>
              <nav className="main-nav" aria-label="Administration" onClick={closeMenu}>
                {adminLinks.map(({ to, label, icon: Icon }) => (
                  <NavLink key={to} to={to} className={navClass}><Icon size={18} /> {label}</NavLink>
                ))}
              </nav>
            </>
          )}

          <div className="sidebar-footer">
            <Link to="/profile" className="sidebar-user" onClick={closeMenu} title="My profile">
              <Avatar user={user} size="sm" />
              <span className="sidebar-user-copy"><strong>{user?.name}</strong><small>{user?.role}</small></span>
            </Link>
            <button type="button" className="logout-button" onClick={logout} disabled={logoutBusy}>
              <LogOut size={17} /> {logoutBusy ? 'Signing out…' : 'Sign out'}
            </button>
          </div>
        </aside>

        <main className="app-main">
          <AppHeader
            user={user}
            onMenu={() => setMenuOpen(true)}
            onLogout={logout}
            logoutBusy={logoutBusy}
          />
          <Outlet />
        </main>
      </div>
    </QuickActionsProvider>
  )
}

function ForbiddenPage() {
  return (
    <div className="home-placeholder">
      <div className="heading-icon"><Shield size={22} /></div>
      <p className="eyebrow">ACCESS RESTRICTED</p>
      <h1>Permission required</h1>
      <p>Your account does not have permission to access this page.</p>
      <Link to="/" className="button button-primary">Return to workspace</Link>
    </div>
  )
}

const pageFallback = <div className="route-loading" role="status">Loading…</div>
const lazyPage = (Page) => <Suspense fallback={pageFallback}><Page /></Suspense>

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/forgot-password" element={lazyPage(ForgotPasswordPage)} />
      <Route element={<ProtectedRoute />}>
        <Route element={<PasswordChangeGate />}>
          <Route path="/set-password" element={<SetPasswordPage />} />
          <Route element={<AppShell />}>
            <Route path="/" element={lazyPage(DashboardPage)} />
            <Route path="/tasks" element={lazyPage(TasksPage)} />
            <Route path="/items" element={lazyPage(ItemsPage)} />
            <Route path="/calendar" element={lazyPage(CalendarPage)} />
            <Route path="/notifications" element={lazyPage(NotificationsPage)} />
            <Route path="/profile" element={lazyPage(MyProfilePage)} />
            <Route path="/clients/:id" element={lazyPage(ClientOverviewPage)} />

            <Route element={<RoleRoute roles={['admin', 'manager']} />}>
              <Route path="/reports" element={lazyPage(ReportsPage)} />
            </Route>

            <Route element={<RoleRoute roles={['admin']} />}>
              <Route path="/admin/users" element={lazyPage(UserManagementPage)} />
              <Route path="/admin/users/:id" element={lazyPage(UserProfilePage)} />
              <Route path="/admin/clients" element={lazyPage(ClientsPage)} />
              <Route path="/admin/departments" element={lazyPage(DepartmentsPage)} />
              <Route path="/admin/reminder-periods" element={lazyPage(ReminderPeriodsPage)} />
              <Route path="/admin/email-settings" element={lazyPage(EmailSettingsPage)} />
            </Route>

            <Route path="/forbidden" element={<ForbiddenPage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <BrowserRouter>
          <AuthProvider>
            <AppRoutes />
          </AuthProvider>
        </BrowserRouter>
      </ToastProvider>
    </QueryClientProvider>
  )
}
