import { useAuth } from '../../context/AuthContext.jsx'
import Badge from '../ui/Badge.jsx'
import { Link } from 'react-router-dom'
import Button from '../ui/Button.jsx'

function getInitials(name) {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/)
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}

export default function Topbar() {
  const { displayName, isAdmin, isAuthenticated, user } = useAuth()

  return (
    <header className="topbar">
      <div className="topbar-left">
        <div className="topbar-status">
          <span className={`status-dot ${isAuthenticated ? 'status-dot-success' : 'status-dot-warning'}`} />
          <span>{isAuthenticated ? 'Connected' : 'Guest mode'}</span>
        </div>
        {isAdmin ? <Badge tone="warning">Admin</Badge> : null}
      </div>

      <div className="topbar-user">
        {isAuthenticated ? (
          <>
            <div className="topbar-user-info">
              <strong>{displayName}</strong>
              <span>{user?.email}</span>
            </div>
            <span className="user-avatar user-avatar-sm" aria-hidden="true">{getInitials(displayName)}</span>
          </>
        ) : (
          <div style={{ display: 'flex', gap: '0.5rem' }}>
            <Link to="/login">
              <Button variant="ghost">Sign in</Button>
            </Link>
            <Link to="/register">
              <Button variant="primary">Create account</Button>
            </Link>
          </div>
        )}
      </div>
    </header>
  )
}
