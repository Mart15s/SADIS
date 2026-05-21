import { Link, useLocation } from 'react-router-dom'

const routeLabels = [
  { pattern: /^\/$/, label: 'Pradžia' },
  { pattern: /^\/account$/, label: 'Paskyra' },
  { pattern: /^\/community$/, label: 'Bendruomenė' },
  { pattern: /^\/plots\/new$/, label: 'Naujas sklypas' },
  { pattern: /^\/plots\/[^/]+\/edit$/, label: 'Sklypo redagavimas' },
  { pattern: /^\/plots\/[^/]+\/calendar$/, label: 'Kalendorius' },
  { pattern: /^\/plots\/[^/]+\/history$/, label: 'Planavimo istorija' },
  { pattern: /^\/plots\/[^/]+\/harvests$/, label: 'Derlius' },
  { pattern: /^\/plots\/[^/]+\/analytics$/, label: 'Analitika' },
  { pattern: /^\/plots\/[^/]+\/sharing$/, label: 'Bendrinimas' },
  { pattern: /^\/plots\/[^/]+\/rotation$/, label: 'Rotacija' },
  { pattern: /^\/plots\/[^/]+$/, label: 'Sklypo planas' },
  { pattern: /^\/plots$/, label: 'Sklypai' },
  { pattern: /^\/plants\/new$/, label: 'Naujas augalas' },
  { pattern: /^\/plants\/[^/]+\/edit$/, label: 'Augalo redagavimas' },
  { pattern: /^\/plants\/[^/]+$/, label: 'Augalo informacija' },
  { pattern: /^\/plants$/, label: 'Augalai' },
  { pattern: /^\/catalog-plants/, label: 'Augalų katalogas' },
  { pattern: /^\/inventory$/, label: 'Inventorius' },
  { pattern: /^\/admin\/users$/, label: 'Naudotojų valdymas' },
]

function getRouteLabel(pathname) {
  return routeLabels.find((route) => route.pattern.test(pathname))?.label ?? 'Darbo sritis'
}

export default function Topbar({ isWide = false, pageHeader = null }) {
  const location = useLocation()
  const currentLabel = getRouteLabel(location.pathname)
  const title = pageHeader?.title ?? currentLabel
  const kicker = pageHeader?.eyebrow ?? 'SADiS'
  const hasPageChrome = Boolean(pageHeader?.meta || pageHeader?.actions)

  return (
    <header
      className={[
        'topbar',
        isWide ? 'topbar-wide' : '',
        pageHeader ? 'topbar--page-header' : '',
      ].filter(Boolean).join(' ')}
      aria-label="Darbo srities juosta"
    >
      <div className="topbar-left">
        <Link to="/" className="topbar-mark" aria-label="Eiti į pradžią">
          <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M10 17V9" />
            <path d="M10 9.5c-2.2-.1-4.1-1.6-4.8-4 3-.1 4.6 1.1 4.8 4Z" />
            <path d="M10.2 8c.3-2.7 2-4.2 5-4.5.1 3-1.6 4.7-5 4.5Z" />
          </svg>
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
