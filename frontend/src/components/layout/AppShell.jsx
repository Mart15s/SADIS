import { Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../../context/AuthContext.jsx'
import Sidebar from './Sidebar.jsx'
import Topbar from './Topbar.jsx'

export default function AppShell() {
  const { isAdmin, isAuthenticated } = useAuth()
  const location = useLocation()
  const isWorkspaceRoute = /^\/plots\/[^/]+(\/calendar)?$/.test(location.pathname)

  return (
    <div className="app-shell">
      <Sidebar isAuthenticated={isAuthenticated} isAdmin={isAdmin} />
      <main className="shell-main">
        <Topbar />
        <div className={`page-container ${isWorkspaceRoute ? 'page-container-wide page-container-workspace' : ''}`.trim()}>
          <Outlet />
        </div>
      </main>
    </div>
  )
}
