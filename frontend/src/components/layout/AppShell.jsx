import { useEffect, useMemo, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext.jsx'
import { PageChromeContext } from './PageChromeContext.jsx'
import Sidebar from './Sidebar.jsx'
import Topbar from './Topbar.jsx'
import BrandLogo from './BrandLogo.jsx'

export default function AppShell() {
  const { isAdmin, isAuthenticated } = useAuth()
  const location = useLocation()
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(() => {
    if (typeof window === 'undefined') return false
    return window.matchMedia('(max-width: 1100px)').matches
  })
  const isWorkspaceRoute = (
    /^\/plots\/new$/.test(location.pathname)
    || /^\/plots\/[^/]+(?:\/(?:calendar|history|harvests|analytics|sharing|rotation))?$/.test(location.pathname)
  )
  const isPloteditorRoute = /^\/plots\/new$/.test(location.pathname) || /^\/plots\/[^/]+$/.test(location.pathname)
  const [isMobileNavigationOpen, setIsMobileNavigationOpen] = useState(false)
  const [pageHeader, setPageHeader] = useState(null)
  const pageChromeContext = useMemo(() => ({
    registerPageHeader(id, header) {
      setPageHeader((current) => {
        if (current?.id === id && current?.signature === header.signature && current?.pathname === header.pathname) {
          return current
        }

        return { ...header, id }
      })
    },
    clearPageHeader(id) {
      setPageHeader((current) => (current?.id === id ? null : current))
    },
  }), [])
  const activePageHeader = pageHeader?.pathname === location.pathname ? pageHeader : null

  useEffect(() => {
    setIsMobileNavigationOpen(false)
  }, [location.pathname])

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
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden="true">
                <path d="M4 5.5h12M4 10h12M4 14.5h12" />
              </svg>
            </button>
            <span className="mobile-shell-title"><BrandLogo className="brand-logo--mobile" alt="Yava logo" /><span>Yava</span></span>
          </div>
          {isWorkspaceRoute ? null : <Topbar isWide={isWorkspaceRoute} pageHeader={activePageHeader} />}
          <div
            className={[
              'page-container',
              isWorkspaceRoute ? 'page-container-wide page-container-workspace' : '',
              isPloteditorRoute ? 'page-container-plot-editor' : '',
            ].filter(Boolean).join(' ')}
          >
            <Outlet />
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
