import { useEffect, useMemo, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/auth-context.js'
import { PageChromeContext } from './PageChromeContext.jsx'
import Sidebar from './Sidebar.jsx'
import Topbar from './Topbar.jsx'
import BrandLogo from './BrandLogo.jsx'
import ContextSwitcher from './ContextSwitcher.jsx'
import ErrorBoundary from '../shared/ErrorBoundary.jsx'

export default function AppShell() {
  const { isAdmin, isAuthenticated } = useAuth()
  const location = useLocation()
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(() => {
    if (typeof window === 'undefined') return false
    return window.matchMedia('(max-width: 1100px)').matches
  })
  const isFieldEditorRoute = /^\/fields\/[^/]+\/editor$/.test(location.pathname)
  const isWorkspaceRoute =
    /^\/plots\/new$/.test(location.pathname) ||
    /^\/plots\/[^/]+(?:\/(?:calendar|history|harvests|analytics|sharing|rotation))?$/.test(
      location.pathname,
    ) ||
    isFieldEditorRoute
  const isPloteditorRoute =
    /^\/plots\/new$/.test(location.pathname) ||
    /^\/plots\/[^/]+$/.test(location.pathname) ||
    isFieldEditorRoute
  const [isMobileNavigationOpen, setIsMobileNavigationOpen] = useState(false)
  const [pageHeader, setPageHeader] = useState(null)
  const pageChromeContext = useMemo(
    () => ({
      registerPageHeader(id, header) {
        setPageHeader((current) => {
          if (
            current?.id === id &&
            current?.signature === header.signature &&
            current?.pathname === header.pathname
          ) {
            return current
          }

          return { ...header, id }
        })
      },
      clearPageHeader(id) {
        setPageHeader((current) => (current?.id === id ? null : current))
      },
    }),
    [],
  )
  const activePageHeader = pageHeader?.pathname === location.pathname ? pageHeader : null

  useEffect(() => {
    setIsMobileNavigationOpen(false)
  }, [location.pathname])

  useEffect(() => {
    if (!isMobileNavigationOpen) return undefined
    const previousActiveElement = document.activeElement
    const previousOverflow = document.body.style.overflow
    const drawer = document.getElementById('app-navigation-drawer')
    const focusableSelector =
      'a[href], button:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
    document.body.style.overflow = 'hidden'
    const focusable = () => Array.from(drawer?.querySelectorAll(focusableSelector) || [])
    focusable()[0]?.focus()

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        event.preventDefault()
        setIsMobileNavigationOpen(false)
        return
      }
      if (event.key !== 'Tab') return
      const elements = focusable()
      if (!elements.length) return
      const first = elements[0]
      const last = elements.at(-1)
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = previousOverflow
      previousActiveElement?.focus?.()
    }
  }, [isMobileNavigationOpen])

  return (
    <PageChromeContext.Provider value={pageChromeContext}>
      <div className={`app-shell ${isSidebarCollapsed ? 'is-sidebar-collapsed' : ''}`}>
        <Sidebar
          isAuthenticated={isAuthenticated}
          isAdmin={isAdmin}
          isCollapsed={isSidebarCollapsed}
          onToggleCollapse={() => setIsSidebarCollapsed((current) => !current)}
        />
        <main className={`shell-main ${isPloteditorRoute ? 'shell-main--plot-editor' : ''}`.trim()}>
          <div className="mobile-shell-bar" aria-label="Mobile navigation">
            <button
              type="button"
              className="mobile-shell-menu-button"
              aria-controls="app-navigation-drawer"
              aria-expanded={isMobileNavigationOpen}
              aria-label="Open navigation"
              onClick={() => setIsMobileNavigationOpen(true)}
            >
              <svg
                viewBox="0 0 20 20"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                aria-hidden="true"
              >
                <path d="M4 5.5h12M4 10h12M4 14.5h12" />
              </svg>
            </button>
            <span className="mobile-shell-title">
              <BrandLogo className="brand-logo--mobile" alt="Yava logo" />
              <span>Yava</span>
            </span>
            {isAuthenticated ? <ContextSwitcher /> : null}
          </div>
          {isWorkspaceRoute && !isFieldEditorRoute ? (
            <h1 className="sr-only">{activePageHeader?.title ?? 'Workspace'}</h1>
          ) : (
            <Topbar isWide={isWorkspaceRoute} pageHeader={activePageHeader} />
          )}
          <div
            className={[
              'page-container',
              isWorkspaceRoute ? 'page-container-wide page-container-workspace' : '',
              isPloteditorRoute ? 'page-container-plot-editor' : '',
            ]
              .filter(Boolean)
              .join(' ')}
          >
            <ErrorBoundary key={location.pathname}>
              <Outlet />
            </ErrorBoundary>
          </div>
        </main>
        <div className={`drawer-layer ${isMobileNavigationOpen ? 'is-open' : ''}`.trim()}>
          <button
            type="button"
            className="drawer-backdrop"
            aria-label="Close navigation"
            onClick={() => setIsMobileNavigationOpen(false)}
          />
          <Sidebar
            isAuthenticated={isAuthenticated}
            isAdmin={isAdmin}
            variant="drawer"
            onNavigate={() => setIsMobileNavigationOpen(false)}
          />
        </div>
      </div>
    </PageChromeContext.Provider>
  )
}
