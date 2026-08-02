import { Link } from 'react-router-dom'
import PageHeader from '../../components/layout/PageHeader.jsx'
import { ErrorState, LoadingState } from '../../components/shared/StatusView.jsx'
import Badge from '../../components/ui/Badge.jsx'
import { useWorkspace } from '../../context/useWorkspace.js'
import { useI18n } from '../../i18n/i18n-context.js'
import { api } from '../../lib/api.js'
import { useAsyncData } from '../../lib/hooks/useAsyncData.js'

function taskTimestamp(task) {
  return task.starts_at || task.due_at || null
}

export default function CalendarWorkspacePage() {
  const { active } = useWorkspace()
  const { formatDateTime } = useI18n()
  const pageState = useAsyncData(
    () =>
      active ? api.listV1('tasks', { [`${active.type}_id`]: active.id }) : Promise.resolve([]),
    [active?.type, active?.id],
    [],
  )
  const tasks = [...(pageState.data || [])]
    .filter(taskTimestamp)
    .sort((first, second) => new Date(taskTimestamp(first)) - new Date(taskTimestamp(second)))

  return (
    <div className="page-stack stage1-page">
      <PageHeader
        eyebrow={active ? `${active.type} workspace` : 'Yava workspace'}
        title="Calendar"
        description="Review planned farm and community work in the active workspace time zone."
        actions={
          <Link className="button button-primary button-md" to="/tasks">
            Manage tasks
          </Link>
        }
      />
      {!active ? (
        <section className="stage1-empty">
          <h2>Select a workspace</h2>
          <p>Choose a farm or community to review planned work.</p>
        </section>
      ) : null}
      {pageState.loading ? <LoadingState title="Loading planned work…" /> : null}
      {pageState.error ? <ErrorState error={pageState.error} onRetry={pageState.reload} /> : null}
      {active && !pageState.loading && !pageState.error && tasks.length === 0 ? (
        <section className="stage1-empty">
          <h2>No planned tasks</h2>
          <p>Add a planned start or due date to a task and it will appear here.</p>
        </section>
      ) : null}
      {active && !pageState.loading && !pageState.error && tasks.length ? (
        <ol className="stage1-calendar" aria-label="Planned tasks">
          {tasks.map((task) => (
            <li className="stage1-calendar-item" key={task.id}>
              <time dateTime={taskTimestamp(task)}>
                {formatDateTime(taskTimestamp(task), {}, active.timezone)}
              </time>
              <div>
                <h2>{task.title}</h2>
                <p>{task.description || task.materials || 'No additional notes.'}</p>
              </div>
              <div className="stage1-calendar-badges">
                <Badge tone={task.priority === 'urgent' ? 'warning' : 'neutral'}>
                  {task.priority || 'medium'}
                </Badge>
                <Badge tone={task.status === 'completed' ? 'success' : 'neutral'}>
                  {task.status || 'pending'}
                </Badge>
              </div>
            </li>
          ))}
        </ol>
      ) : null}
    </div>
  )
}
