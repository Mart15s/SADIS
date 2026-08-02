import { startTransition, useDeferredValue, useState } from 'react'
import PageHeader from '../../components/layout/PageHeader.jsx'
import {
  EmptyState,
  ErrorState,
  LoadingState,
  SuccessToast,
} from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import Card from '../../components/ui/Card.jsx'
import { api } from '../../lib/api.js'
import { formatUserRole, USER_ROLES } from '../../lib/constants.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'
import { useI18n } from '../../i18n/i18n-context.js'

export default function AdminUsersPage() {
  const [filters, setFilters] = useState({
    search: '',
    role: '',
  })
  const [selectedUser, setSelectedUser] = useState(null)
  const [detailError, setDetailError] = useState('')
  const [saving, setSaving] = useState(false)
  const [toast, setToast] = useState('')
  const { formatDateTime } = useI18n()
  const deferredSearch = useDeferredValue(filters.search)
  const usersState = useAsyncData(
    () =>
      api.listAdminUsers({
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
    if (saving) return
    setSaving(true)

    try {
      const updated = await api.updateAdminUserRole(userId, nextRole)
      usersState.setData((current) =>
        current.map((user) => (user.id === updated.id ? updated : user)),
      )
      setSelectedUser(updated)
      setToast('User role updated.')
    } catch (error) {
      setDetailError(error.message)
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete(userId) {
    if (saving || !window.confirm('Deactivate this user account?')) return
    setSaving(true)

    try {
      await api.deleteAdminUser(userId)
      usersState.setData((current) => current.filter((user) => user.id !== userId))
      setSelectedUser((current) => (current?.id === userId ? null : current))
      setToast('User account deactivated.')
    } catch (error) {
      setDetailError(error.message)
    } finally {
      setSaving(false)
    }
  }

  if (usersState.loading) {
    return <LoadingState title="Loading users…" />
  }

  if (usersState.error) {
    return <ErrorState error={usersState.error} onRetry={usersState.reload} />
  }

  return (
    <div className="page-stack">
      <PageHeader
        title="User administration"
        description="Search, filter, inspect, and manage system user accounts."
      />
      <SuccessToast message={toast} onDismiss={() => setToast('')} />

      <div className="search-row">
        <div className="field">
          <label htmlFor="admin-user-search">Search users</label>
          <input
            id="admin-user-search"
            value={filters.search}
            onChange={(event) =>
              setFilters((current) => ({ ...current, search: event.target.value }))
            }
            placeholder="Email address, first name, or last name"
          />
        </div>
        <div className="field">
          <label htmlFor="admin-user-role">Role filter</label>
          <select
            id="admin-user-role"
            value={filters.role}
            onChange={(event) =>
              setFilters((current) => ({ ...current, role: event.target.value }))
            }
          >
            <option value="">All roles</option>
            {USER_ROLES.map((role) => (
              <option key={role} value={role}>
                {formatUserRole(role)}
              </option>
            ))}
          </select>
        </div>
      </div>

      {usersState.data.length === 0 ? (
        <EmptyState
          title="No users found"
          description="No accounts match the selected search and role filters."
        />
      ) : (
        <div className="detail-grid">
          <div className="panel table-stack">
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Email address</th>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Last sign-in</th>
                    <th>
                      <span className="sr-only">Actions</span>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {usersState.data.map((user) => (
                    <tr key={user.id}>
                      <td>{user.email}</td>
                      <td>
                        {[user.name, user.surname].filter(Boolean).join(' ') || 'Not specified'}
                      </td>
                      <td>{formatUserRole(user.role)}</td>
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
            <h3>User details</h3>
            {selectedUser ? (
              <div className="stack">
                <strong>{selectedUser.email}</strong>
                <span className="muted">
                  {[selectedUser.name, selectedUser.surname].filter(Boolean).join(' ') ||
                    'Profile name not specified'}
                </span>
                <span className="muted">Created: {formatDateTime(selectedUser.created_at)}</span>
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
                        {formatUserRole(role)}
                      </option>
                    ))}
                  </select>
                </div>
                <Button
                  variant="danger"
                  onClick={() => handleDelete(selectedUser.id)}
                  disabled={saving}
                >
                  Deactivate user
                </Button>
              </div>
            ) : (
              <EmptyState
                title="Select a user"
                description="Choose Inspect to load account details."
              />
            )}
            {detailError ? <span className="field-error">{detailError}</span> : null}
          </Card>
        </div>
      )}
    </div>
  )
}
