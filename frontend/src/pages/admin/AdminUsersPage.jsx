import { startTransition, useDeferredValue, useState } from 'react'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import Card from '../../components/ui/Card.jsx'
import { api } from '../../lib/api.js'
import { formatDateTime, USER_ROLES } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

export default function AdminUsersPage() {
  const [filters, setFilters] = useState({
    search: '',
    role: '',
  })
  const [selectedUser, setSelectedUser] = useState(null)
  const [detailError, setDetailError] = useState('')
  const [saving, setSaving] = useState(false)
  const deferredSearch = useDeferredValue(filters.search)
  const usersState = useAsyncData(
    () => api.listAdminUsers({
      search: deferredSearch || undefined,
      role: filters.role || undefined,
    }),
    [deferredSearch, filters.role],
    [],
  )

  async function handleInspect(userId) {
    setDetailError('')

    try {
      const detail = await api.getAdminUser(userId)
      startTransition(() => {
        setSelectedUser(detail)
      })
    } catch (error) {
      setDetailError(error.message)
    }
  }

  async function handleRoleChange(userId, nextRole) {
    setSaving(true)

    try {
      const updated = await api.updateAdminUserRole(userId, nextRole)
      usersState.setData((current) => current.map((user) => (
        user.id === updated.id ? updated : user
      )))
      setSelectedUser(updated)
    } catch (error) {
      setDetailError(error.message)
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete(userId) {
    setSaving(true)

    try {
      await api.deleteAdminUser(userId)
      usersState.setData((current) => current.filter((user) => user.id !== userId))
      setSelectedUser((current) => (current?.id === userId ? null : current))
    } catch (error) {
      setDetailError(error.message)
    } finally {
      setSaving(false)
    }
  }

  if (usersState.loading) {
    return <LoadingState title="Loading admin users..." />
  }

  if (usersState.error) {
    return <ErrorState error={usersState.error} onRetry={usersState.reload} />
  }

  return (
    <div className="page-stack">
      <PageHeader
        title="Admin user management"
        description="Search, filter, inspect, change roles, and delete user accounts through the admin-only account management flow."
      />

      <div className="search-row">
        <div className="field">
          <label htmlFor="admin-user-search">Search users</label>
          <input
            id="admin-user-search"
            value={filters.search}
            onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
            placeholder="Search by email, name, or surname"
          />
        </div>
        <div className="field">
          <label htmlFor="admin-user-role">Role filter</label>
          <select
            id="admin-user-role"
            value={filters.role}
            onChange={(event) => setFilters((current) => ({ ...current, role: event.target.value }))}
          >
            <option value="">All roles</option>
            {USER_ROLES.map((role) => (
              <option key={role} value={role}>
                {role}
              </option>
            ))}
          </select>
        </div>
      </div>

      {usersState.data.length === 0 ? (
        <EmptyState title="No users found" description="No accounts matched the current admin search and filter criteria." />
      ) : (
        <div className="detail-grid">
          <div className="panel table-stack">
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Email</th>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Last login</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {usersState.data.map((user) => (
                    <tr key={user.id}>
                      <td>{user.email}</td>
                      <td>{[user.name, user.surname].filter(Boolean).join(' ') || 'Not set'}</td>
                      <td>{user.role}</td>
                      <td>{formatDateTime(user.profile?.last_login)}</td>
                      <td>
                        <Button variant="ghost" onClick={() => handleInspect(user.id)}>
                          Inspect
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <Card>
            <h3>User detail</h3>
            {selectedUser ? (
              <div className="stack">
                <strong>{selectedUser.email}</strong>
                <span className="muted">
                  {[selectedUser.name, selectedUser.surname].filter(Boolean).join(' ') || 'No profile name'}
                </span>
                <span className="muted">
                  Created: {formatDateTime(selectedUser.created_at)}
                </span>
                <div className="field">
                  <label htmlFor="user-role">Role</label>
                  <select
                    id="user-role"
                    value={selectedUser.role}
                    onChange={(event) => handleRoleChange(selectedUser.id, event.target.value)}
                    disabled={saving}
                  >
                    {USER_ROLES.map((role) => (
                      <option key={role} value={role}>
                        {role}
                      </option>
                    ))}
                  </select>
                </div>
                <Button variant="danger" onClick={() => handleDelete(selectedUser.id)} disabled={saving}>
                  Delete user
                </Button>
              </div>
            ) : (
              <EmptyState
                title="Choose a user"
                description="Click Inspect on the left to load the single-user admin endpoint."
              />
            )}
            {detailError ? <span className="field-error">{detailError}</span> : null}
          </Card>
        </div>
      )}
    </div>
  )
}
