import Button from './Button.jsx'

/** Consistent, labelled destructive action; callers provide confirmation before invoking onConfirm. */
export default function DestructiveButton({ label = 'Delete', loading = false, children, ...props }) {
  const visibleLabel = children ?? label

  return (
    <Button variant="danger" loading={loading} aria-label={label} {...props}>
      {visibleLabel}
    </Button>
  )
}
