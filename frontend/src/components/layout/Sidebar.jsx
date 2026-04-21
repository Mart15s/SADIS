import { NavLink, useNavigate } from 'react-router-dom'
import Badge from '../ui/Badge.jsx'
import Button from '../ui/Button.jsx'
import { useAuth } from '../../context/AuthContext.jsx'

const baseLinks = [
  { to: '/', label: 'Dashboard', icon: 'dashboard' },
]

const authLinks = [
  { to: '/account', label: 'Account', icon: 'account' },
  { to: '/community', label: 'Community', icon: 'community' },
  { to: '/plots', label: 'Plots', icon: 'plots' },
  { to: '/plants', label: 'Plants', icon: 'plants' },
  { to: '/inventory', label: 'Inventory', icon: 'inventory' },
]

function SidebarIcon({ name }) {
  const paths = {
    dashboard: (
      <path d="M3.5 4.5h5v4h-5zm6.5 0h4.5v3h-4.5zm0 4.5h4.5v5.5h-4.5zm-6.5 1h5v4.5h-5z" />
    ),
    account: (
      <>
        <circle cx="9" cy="6.5" r="2.5" />
        <path d="M4.2 14.5c1.1-2.4 3-3.6 4.8-3.6s3.7 1.2 4.8 3.6" />
      </>
    ),
    community: (
      <>
        <circle cx="6" cy="6.5" r="2" />
        <circle cx="12.3" cy="7.3" r="1.7" />
        <path d="M2.8 14.5c.8-1.9 2.1-3 3.8-3 1.4 0 2.6.8 3.5 2.3" />
        <path d="M10 13.8c.6-1.5 1.6-2.3 2.9-2.3 1.1 0 2 .6 2.7 1.9" />
      </>
    ),
    plots: (
      <>
        <path d="M3.5 12.8V5.2L9 2.8l5.5 2.4v7.6L9 15.2z" />
        <path d="M9 2.8v12.4" />
      </>
    ),
    plants: (
      <>
        <path d="M8.9 14.8V8.4" />
        <path d="M8.9 8.9c-2-.1-3.7-1.4-4.4-3.6 2.8-.1 4.2 1 4.4 3.6Z" />
        <path d="M9.1 7.6c.3-2.4 1.8-3.8 4.5-4.1.1 2.7-1.4 4.3-4.5 4.1Z" />
      </>
    ),
    inventory: (
      <>
        <path d="M4 5.2h10v8.9H4z" />
        <path d="M6 5.2V3.8h6v1.4" />
        <path d="M7.2 8.3h3.6" />
      </>
    ),
    admin: (
      <>
        <path d="M9 2.8 4.2 4.7v3c0 3 1.7 5.6 4.8 6.9 3.1-1.3 4.8-3.9 4.8-6.9v-3Z" />
        <path d="M7 8.4 8.4 9.8l2.7-2.7" />
      </>
    ),
    auth: (
      <path d="M6.3 4.2h5.2v9.6H6.3zm0 4.8h6.9m-1.8-1.8L13.2 9l-1.8 1.8" />
    ),
  }

  return (
    <span className="sidebar-icon" aria-hidden="true">
      <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
        {paths[name] ?? paths.dashboard}
      </svg>
    </span>
  )
}

function getInitials(name) {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/)
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}

export default function Sidebar({ isAuthenticated, isAdmin }) {
  const { displayName, user, logout } = useAuth()
  const navigate = useNavigate()

  const links = [
    ...baseLinks,
    ...(isAuthenticated ? authLinks : []),
    ...(isAdmin ? [{ to: '/admin/users', label: 'Admin', icon: 'admin' }] : []),
    ...(!isAuthenticated
      ? [
          { to: '/login', label: 'Sign in', icon: 'auth' },
          { to: '/register', label: 'Register', icon: 'auth' },
        ]
      : []),
  ]

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  return (
    <aside className="shell-sidebar">
      <div className="brand-lockup">
        <span className="brand-leaf" aria-hidden="true">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
            <path d="M10 17V9" />
            <path d="M10 9.5c-2.2-.1-4.1-1.6-4.8-4 3-.1 4.6 1.1 4.8 4Z" />
            <path d="M10.2 8c.3-2.7 2-4.2 5-4.5.1 3-1.6 4.7-5 4.5Z" />
          </svg>
        </span>
        <span className="brand-title">SAD<em>iS</em></span>
      </div>

      <nav className="sidebar-nav" aria-label="Primary">
        {links.map((link) => (
          <NavLink
            key={link.to}
            to={link.to}
            end={link.to === '/'}
            className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
          >
            <span className="sidebar-link-main">
              <SidebarIcon name={link.icon} />
              <span>{link.label}</span>
            </span>
            {link.to === '/community' ? <Badge tone="soft">Shared</Badge> : null}
            {link.to === '/admin/users' ? <Badge tone="warning">Admin</Badge> : null}
          </NavLink>
        ))}
      </nav>

      <div className="sidebar-user-card">
        {isAuthenticated ? (
          <>
            <div className="sidebar-user-row">
              <span className="user-avatar" aria-hidden="true">{getInitials(displayName)}</span>
              <div className="sidebar-user-info">
                <span className="sidebar-user-name">{displayName}</span>
                <span className="sidebar-user-email">{user?.email}</span>
              </div>
            </div>
            <Button variant="ghost" onClick={handleLogout} style={{ width: '100%' }}>
              Log out
            </Button>
          </>
        ) : (
          <div className="sidebar-user-info">
            <span className="sidebar-user-name">Guest</span>
            <span className="sidebar-user-email">Sign in to access all features</span>
          </div>
        )}
      </div>
    </aside>
  )
}
