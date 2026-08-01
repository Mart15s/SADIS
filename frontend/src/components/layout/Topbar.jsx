import { Link, useLocation } from 'react-router-dom'
import BrandLogo from './BrandLogo.jsx'

const routeLabels = [
  { pattern: /^\/$/, label: 'Home' },
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
  { pattern: /^\/plots\/[^/]+$/, label: 'Plot plan' },
  { pattern: /^\/plots$/, label: 'Plots' },
  { pattern: /^\/plants\/new$/, label: 'New plant' },
  { pattern: /^\/plants\/[^/]+\/edit$/, label: 'Edit plant' },
  { pattern: /^\/plants\/[^/]+$/, label: 'Plant information' },
  { pattern: /^\/plants$/, label: 'Plants' },
  { pattern: /^\/catalog-plants/, label: 'Plant catalog' },
  { pattern: /^\/inventory$/, label: 'Inventory' },
  { pattern: /^\/admin\/users$/, label: 'User management' },
]

function getRouteLabel(pathname) {
  return routeLabels.find((route) => route.pattern.test(pathname))?.label ?? 'Workspace'
}

export default function Topbar({ isWide = false, pageHeader = null }) {
  const location = useLocation()
  const currentLabel = getRouteLabel(location.pathname)
  const title = pageHeader?.title ?? currentLabel
  const kicker = pageHeader?.eyebrow ?? 'Yava'
  const hasPageChrome = Boolean(pageHeader?.meta || pageHeader?.actions)

  return (
    <header
      className={[
        'topbar',
        isWide ? 'topbar-wide' : '',
        pageHeader ? 'topbar--page-header' : '',
      ].filter(Boolean).join(' ')}
      aria-label="Workspace bar"
    >
      <div className="topbar-left">
        <Link to="/" className="topbar-mark" aria-label="Go to home">
          <BrandLogo className="brand-logo--topbar" alt="Yava logo" />
        </Link>
        <div className="topbar-copy">
          <span className="topbar-kicker">{kicker}</span>
          <h1 className="topbar-title">{title}</h1>
        </div>
      </div>

      {hasPageChrome ? (
        <div className="topbar-page-chrome">
          {pageHeader.meta ? <div className="topbar-page-meta">{pageHeader.meta}</div> : null}
          {pageHeader.actions ? <div className="topbar-page-actions">{pageHeader.actions}</div> : null}
        </div>
      ) : null}

      {!hasPageChrome ? <div className="topbar-actions" aria-hidden="true" /> : null}
    </header>
  )
}
