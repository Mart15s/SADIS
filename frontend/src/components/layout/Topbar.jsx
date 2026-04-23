import { useAuth } from '../../context/AuthContext.jsx'
import { Link } from 'react-router-dom'
import Button from '../ui/Button.jsx'
import StatusBadge from '../ui/StatusBadge.jsx'

export default function Topbar() {
  const { isAdmin, isAuthenticated } = useAuth()

  return (
    <header className="topbar">
      <div className="topbar-left">
        <div className="topbar-copy">
          <strong className="topbar-title">Personal Garden Information System</strong>
          <span className="topbar-subtitle">Clearer workflows, denser layout, and stronger planning context.</span>
        </div>
      </div>

      <div className="topbar-actions">
        <StatusBadge kind="connection" tone={isAuthenticated ? 'success' : 'warning'}>
          {isAuthenticated ? 'Connected' : 'Guest mode'}
        </StatusBadge>
        {isAdmin ? <StatusBadge kind="ownership" tone="warning">Admin</StatusBadge> : null}
        {isAuthenticated ? (
          <>
            <Link to="/account">
              <Button variant="ghost" size="sm">Account</Button>
            </Link>
          </>
        ) : (
          <div className="action-row action-row-end">
            <Link to="/login">
              <Button variant="ghost" size="sm">Sign in</Button>
            </Link>
            <Link to="/register">
              <Button size="sm">Create account</Button>
            </Link>
          </div>
        )}
      </div>
    </header>
  )
}
