import { Children, isValidElement, useEffectEvent, useId, useLayoutEffect, useMemo } from 'react'
import { useLocation } from 'react-router-dom'
import { usePageChrome } from './PageChromeContext.jsx'

function nodeSignature(node) {
  if (node === null || node === undefined || typeof node === 'boolean') return ''
  if (typeof node === 'string' || typeof node === 'number') return String(node)
  if (Array.isArray(node)) return node.map(nodeSignature).join('|')

  if (isValidElement(node)) {
    const typeName = typeof node.type === 'string' ? node.type : node.type?.displayName ?? node.type?.name ?? 'component'
    const propSignature = ['to', 'href', 'tone', 'kind', 'variant']
      .map((propName) => node.props?.[propName])
      .filter((value) => value !== undefined && value !== null)
      .join(':')
    const childrenSignature = Children.toArray(node.props?.children).map(nodeSignature).join('|')

    return `${typeName}:${propSignature}:${childrenSignature}`
  }

  return ''
}

export default function PageHeader({
  title,
  description,
  eyebrow,
  meta,
  actions,
  className = '',
}) {
  const pageChrome = usePageChrome()
  const id = useId()
  const location = useLocation()
  const signature = useMemo(
    () => [
      title,
      description,
      eyebrow,
      className,
      location.pathname,
      nodeSignature(meta),
      nodeSignature(actions),
    ].filter(Boolean).join('::'),
    [title, description, eyebrow, className, location.pathname, meta, actions],
  )
  const getLatestHeader = useEffectEvent(() => ({
    title,
    description,
    eyebrow,
    meta,
    actions,
    className,
    pathname: location.pathname,
    signature,
  }))

  useLayoutEffect(() => {
    if (!pageChrome) return undefined

    pageChrome.registerPageHeader(id, getLatestHeader())

    return () => pageChrome.clearPageHeader(id)
  }, [pageChrome, id, signature])

  if (pageChrome) {
    return null
  }

  return (
    <header className={`page-header ${className}`.trim()}>
      <div className="page-header-copy">
        {eyebrow ? <span className="page-header-eyebrow">{eyebrow}</span> : null}
        <h1 className="page-header-title">{title}</h1>
        {description ? <p>{description}</p> : null}
        {meta ? <div className="page-header-meta">{meta}</div> : null}
      </div>
      {actions ? <div className="page-header-actions">{actions}</div> : null}
    </header>
  )
}
