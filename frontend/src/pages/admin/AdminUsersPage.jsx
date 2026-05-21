import { startTransition, useDeferredValue, useState } from 'react'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { EmptyState, ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Button from '../../components/ui/Button.jsx'
import Card from '../../components/ui/Card.jsx'
import { api } from '../../lib/api.js'
import { formatDateTime, formatUserRole, USER_ROLES } from '../../lib/constants.js'
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
    return <LoadingState title="Įkeliami naudotojai..." />
  }

  if (usersState.error) {
    return <ErrorState error={usersState.error} onRetry={usersState.reload} />
  }

  return (
    <div className="page-stack">
      <PageHeader
        title="Naudotojų administravimas"
        description="Ieškokite, filtruokite, peržiūrėkite, keiskite roles ir šalinkite naudotojų paskyras."
      />

      <div className="search-row">
        <div className="field">
          <label htmlFor="admin-user-search">Ieškoti naudotojų</label>
          <input
            id="admin-user-search"
            value={filters.search}
            onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))}
            placeholder="El. paštas, vardas arba pavardė"
          />
        </div>
        <div className="field">
          <label htmlFor="admin-user-role">Rolės filtras</label>
          <select
            id="admin-user-role"
            value={filters.role}
            onChange={(event) => setFilters((current) => ({ ...current, role: event.target.value }))}
          >
            <option value="">Visos rolės</option>
            {USER_ROLES.map((role) => (
              <option key={role} value={role}>
                {formatUserRole(role)}
              </option>
            ))}
          </select>
        </div>
      </div>

      {usersState.data.length === 0 ? (
        <EmptyState title="Naudotojų nerasta" description="Pagal pasirinktus paieškos ir filtro kriterijus paskyrų nerasta." />
      ) : (
        <div className="detail-grid">
          <div className="panel table-stack">
            <div className="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>El. paštas</th>
                    <th>Vardas</th>
                    <th>Rolė</th>
                    <th>Paskutinis prisijungimas</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {usersState.data.map((user) => (
                    <tr key={user.id}>
                      <td>{user.email}</td>
                      <td>{[user.name, user.surname].filter(Boolean).join(' ') || 'Nenurodyta'}</td>
                      <td>{formatUserRole(user.role)}</td>
                      <td>{formatDateTime(user.profile?.last_login)}</td>
                      <td>
                        <Button variant="ghost" onClick={() => handleInspect(user.id)}>
                          Peržiūrėti
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <Card>
            <h3>Naudotojo informacija</h3>
            {selectedUser ? (
              <div className="stack">
                <strong>{selectedUser.email}</strong>
                <span className="muted">
                  {[selectedUser.name, selectedUser.surname].filter(Boolean).join(' ') || 'Profilio vardas nenurodytas'}
                </span>
                <span className="muted">
                  Sukurta: {formatDateTime(selectedUser.created_at)}
                </span>
                <div className="field">
                  <label htmlFor="user-role">Rolė</label>
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
                <Button variant="danger" onClick={() => handleDelete(selectedUser.id)} disabled={saving}>
                  Pašalinti naudotoją
                </Button>
              </div>
            ) : (
              <EmptyState
                title="Pasirinkite naudotoją"
                description="Paspauskite „Peržiūrėti“, kad įkeltumėte naudotojo informaciją."
              />
            )}
            {detailError ? <span className="field-error">{detailError}</span> : null}
          </Card>
        </div>
      )}
    </div>
  )
}
