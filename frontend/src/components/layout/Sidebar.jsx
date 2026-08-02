import { NavLink, useNavigate } from 'react-router-dom'
import BrandLogo from './BrandLogo.jsx'
import Badge from '../ui/Badge.jsx'
import Button from '../ui/Button.jsx'
import { useAuth } from '../../context/auth-context.js'
import { useI18n } from '../../i18n/i18n-context.js'
import { useWorkspace } from '../../context/useWorkspace.js'

const baseLinks = [{ to: '/', labelKey: 'nav.home', icon: 'dashboard' }]

const authLinks = [
  { to: '/communities', labelKey: 'nav.communities', icon: 'community' },
  { to: '/farms', labelKey: 'nav.farms', icon: 'plots' },
  { to: '/fields', labelKey: 'nav.fields', icon: 'plots' },
  { to: '/crops', labelKey: 'nav.crops', icon: 'plants' },
  { to: '/crop-seasons', labelKey: 'nav.seasons', icon: 'plants' },
  { to: '/tasks', labelKey: 'nav.tasks', icon: 'tasks' },
  { to: '/calendar', labelKey: 'nav.calendar', icon: 'tasks' },
  { to: '/inventory', labelKey: 'nav.inventory', icon: 'inventory' },
  { to: '/resources', labelKey: 'nav.resources', icon: 'resources' },
  { to: '/reservations', labelKey: 'nav.reservations', icon: 'resources' },
  { to: '/analytics', labelKey: 'nav.analytics', icon: 'analytics' },
  { to: '/account', labelKey: 'nav.account', icon: 'account' },
]

function SidebarIcon({ name }) {
  const paths = {
    dashboard: <path d="M3.5 4.5h5v4h-5zm6.5 0h4.5v3h-4.5zm0 4.5h4.5v5.5h-4.5zm-6.5 1h5v4.5h-5z" />,
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
    tasks: (
      <>
        <path d="M4 4h10v10H4z" />
        <path d="m6.5 9 1.5 1.5L11.8 7" />
      </>
    ),
    resources: (
      <>
        <path d="M3.5 6.2h11v7.2h-11z" />
        <path d="M6 6.2V4h6v2.2M6 9.5h6" />
      </>
    ),
    analytics: (
      <>
        <path d="M3.5 14.5V9h3v5.5zm4.5 0V5.5h3v9zm4.5 0V3h3v11.5z" />
      </>
    ),
    admin: (
      <>
        <path d="M9 2.8 4.2 4.7v3c0 3 1.7 5.6 4.8 6.9 3.1-1.3 4.8-3.9 4.8-6.9v-3Z" />
        <path d="M7 8.4 8.4 9.8l2.7-2.7" />
      </>
    ),
    auth: <path d="M6.3 4.2h5.2v9.6H6.3zm0 4.8h6.9m-1.8-1.8L13.2 9l-1.8 1.8" />,
  }

  return (
    <span className="sidebar-icon" aria-hidden="true">
      <svg
        viewBox="0 0 18 18"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
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

export default function Sidebar({
  isAuthenticated,
  isAdmin,
  variant = 'desktop',
  isCollapsed = false,
  onToggleCollapse,
  onNavigate,
}) {
  const { displayName, user, logout } = useAuth()
  const { active } = useWorkspace()
  const { t } = useI18n()
  const navigate = useNavigate()

  const links = [
    ...baseLinks,
    ...(isAuthenticated
      ? authLinks.filter(
          (link) =>
            link.to !== '/analytics' ||
            active?.type !== 'farm' ||
            active.permissions?.includes('view_analytics'),
        )
      : []),
    ...(isAdmin
      ? [
          {
            to: '/admin/users',
            labelKey: 'nav.admin',
            icon: 'admin',
            badge: { tone: 'warning', textKey: 'nav.administrator' },
          },
        ]
      : []),
    ...(!isAuthenticated
      ? [
          { to: '/login', labelKey: 'nav.signIn', icon: 'auth' },
          { to: '/register', labelKey: 'nav.register', icon: 'auth' },
        ]
      : []),
  ]

  async function handleLogout() {
    await logout()
    onNavigate?.()
    navigate('/login')
  }

  return (
    <aside
      id={variant === 'drawer' ? 'app-navigation-drawer' : undefined}
      className={`shell-sidebar shell-sidebar--${variant} ${isCollapsed ? 'is-collapsed' : ''}`.trim()}
      role={variant === 'drawer' ? 'dialog' : undefined}
      aria-label={t('nav.application')}
      aria-modal={variant === 'drawer' ? 'true' : undefined}
    >
      <div className="brand-lockup">
        <BrandLogo className="brand-logo--sidebar" alt="Yava logo" />
        <span className="brand-copy">
          <span className="brand-title">{t('app.name')}</span>
          <span className="brand-subtitle">{t('app.platform')}</span>
        </span>
        {variant === 'desktop' ? (
          <button
            type="button"
            className="sidebar-collapse-button"
            onClick={onToggleCollapse}
            aria-label={isCollapsed ? t('nav.expand') : t('nav.collapse')}
            aria-pressed={isCollapsed}
            title={isCollapsed ? t('nav.expand') : t('nav.collapse')}
          >
            <svg
              viewBox="0 0 20 20"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
              strokeLinejoin="round"
              aria-hidden="true"
            >
              <path d={isCollapsed ? 'M8 5l5 5-5 5' : 'M12 5l-5 5 5 5'} />
            </svg>
          </button>
        ) : null}
        {variant === 'drawer' ? (
          <button
            type="button"
            className="drawer-close-button"
            onClick={onNavigate}
            aria-label={t('nav.close')}
          >
            <svg
              viewBox="0 0 20 20"
              fill="none"
              stroke="currentColor"
              strokeWidth="1.8"
              strokeLinecap="round"
              aria-hidden="true"
            >
              <path d="M5 5l10 10M15 5 5 15" />
            </svg>
          </button>
        ) : null}
      </div>

      <nav className="sidebar-nav" aria-label={t('nav.primary')}>
        {links.map((link) => {
          const label = t(link.labelKey)
          return (
            <NavLink
              key={link.to}
              to={link.to}
              end={link.to === '/'}
              onClick={onNavigate}
              className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
              title={isCollapsed ? label : undefined}
            >
              <SidebarIcon name={link.icon} />
              <span className="sidebar-link-main">
                <span className="sidebar-link-label">{label}</span>
              </span>
              {link.badge ? (
                <span className="sidebar-link-trailing">
                  <Badge tone={link.badge.tone} size="sm" className="sidebar-nav-badge">
                    {t(link.badge.textKey)}
                  </Badge>
                </span>
              ) : null}
            </NavLink>
          )
        })}
      </nav>

      <div className="sidebar-user-card">
        {isAuthenticated ? (
          <>
            <div className="sidebar-user-row">
              <span className="user-avatar" aria-hidden="true">
                {getInitials(displayName)}
              </span>
              <div className="sidebar-user-info">
                <span className="sidebar-user-name">{displayName}</span>
                <span className="sidebar-user-email">{user?.email}</span>
              </div>
            </div>
            <Button variant="ghost" onClick={handleLogout} fullWidth>
              {t('nav.signOut')}
            </Button>
          </>
        ) : (
          <div className="sidebar-user-info">
            <span className="sidebar-user-name">{t('nav.guest')}</span>
            <span className="sidebar-user-email">{t('nav.guestHint')}</span>
          </div>
        )}
      </div>
    </aside>
  )
}
