import { useWorkspace } from '../../context/useWorkspace.js'
import { useI18n } from '../../i18n/i18n-context.js'

export default function ContextSwitcher() {
  const { active, contexts, error, loading, setActive } = useWorkspace()
  const { t } = useI18n()
  const value = active ? `${active.type}:${active.id}` : ''

  return (
    <label className="context-switcher" title={error ? t('context.refreshError') : undefined}>
      <span>{t('context.label')}</span>
      <select
        aria-label={t('context.label')}
        value={value}
        disabled={loading}
        onChange={(event) => {
          const [type, id] = event.target.value.split(':')
          setActive(type, id)
        }}
      >
        {contexts.length === 0 ? <option value="">{t('context.personal')}</option> : null}
        {contexts.map((context) => (
          <option key={`${context.type}:${context.id}`} value={`${context.type}:${context.id}`}>
            {t('context.option', {
              type: t(context.type === 'farm' ? 'context.farm' : 'context.community'),
              name: context.name,
            })}
          </option>
        ))}
      </select>
    </label>
  )
}
