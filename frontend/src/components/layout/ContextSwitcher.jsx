import { useWorkspace } from '../../context/useWorkspace.js'

export default function ContextSwitcher() {
  const { active, contexts, loading, setActive } = useWorkspace()
  const value = active ? `${active.type}:${active.id}` : ''

  return (
    <label className="context-switcher">
      <span>Active workspace</span>
      <select
        aria-label="Active workspace"
        value={value}
        disabled={loading}
        onChange={(event) => {
          const [type, id] = event.target.value.split(':')
          setActive(type, id)
        }}
      >
        {contexts.length === 0 ? <option value="">Personal workspace</option> : null}
        {contexts.map((context) => (
          <option key={`${context.type}:${context.id}`} value={`${context.type}:${context.id}`}>
            {context.type === 'farm' ? 'Farm' : 'Community'} · {context.name}
          </option>
        ))}
      </select>
    </label>
  )
}
