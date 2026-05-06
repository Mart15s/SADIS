import { Link, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext.jsx'
import StatusBadge from '../ui/StatusBadge.jsx'

const routeLabels = [
  { pattern: /^\/$/, label: 'Dashboard' },
  { pattern: /^\/account$/, label: 'Account' },
  { pattern: /^\/community$/, label: 'Community' },
  { pattern: /^\/plots\/new$/, label: 'New plot' },
  { pattern: /^\/plots\/[^/]+\/edit$/, label: 'Edit plot' },
  { pattern: /^\/plots\/[^/]+\/calendar$/, label: 'Calendar' },
  { pattern: /^\/plots\/[^/]+\/history$/, label: 'Planning history' },
  { pattern: /^\/plots\/[^/]+\/harvests$/, label: 'Harvests' },
  { pattern: /^\/plots\/[^/]+\/analytics$/, label: 'Analytics' },
  { pattern: /^\/plots\/[^/]+\/sharing$/, label: 'Sharing' },
  { pattern: /^\/plots\/[^/]+\/rotation$/, label: 'Rotation' },
  { pattern: /^\/plots\/[^/]+$/, label: 'Plot workspace' },
  { pattern: /^\/plots$/, label: 'Plots' },
  { pattern: /^\/plants\/new$/, label: 'New plant' },
  { pattern: /^\/plants\/[^/]+\/edit$/, label: 'Edit plant' },
  { pattern: /^\/plants\/[^/]+$/, label: 'Plant details' },
  { pattern: /^\/plants$/, label: 'Plants' },
  { pattern: /^\/catalog-plants/, label: 'Plant catalog' },
  { pattern: /^\/inventory$/, label: 'Inventory' },
  { pattern: /^\/admin\/users$/, label: 'User management' },
]

function getRouteLabel(pathname) {
  return routeLabels.find((route) => route.pattern.test(pathname))?.label ?? 'Workspace'
}

export default function Topbar({ isWide = false }) {
  const { isAdmin, isAuthenticated, displayName } = useAuth()
  const location = useLocation()
  const currentLabel = getRouteLabel(location.pathname)

  return (
    <header className={`topbar ${isWide ? 'topbar-wide' : ''}`.trim()} aria-label="Workspace bar">
      <div className="topbar-left">
        <Link to="/" className="topbar-mark" aria-label="Go to dashboard">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M10 17V9" />
            <path d="M10 9.5c-2.2-.1-4.1-1.6-4.8-4 3-.1 4.6 1.1 4.8 4Z" />
            <path d="M10.2 8c.3-2.7 2-4.2 5-4.5.1 3-1.6 4.7-5 4.5Z" />
          </svg>
        </Link>
        <div className="topbar-copy">
          <span className="topbar-kicker">SADiS</span>
          <strong className="topbar-title">{currentLabel}</strong>
        </div>
      </div>

      <div className="topbar-actions">
        <StatusBadge kind="connection" tone={isAuthenticated ? 'success' : 'warning'}>
          {isAuthenticated ? 'Connected' : 'Guest'}
        </StatusBadge>
        {isAdmin ? <StatusBadge kind="ownership" tone="warning">Admin</StatusBadge> : null}
        {isAuthenticated ? (
          <Link to="/account" className="topbar-user-link">
            {displayName}
          </Link>
        ) : null}
      </div>
    </header>
  )
}
