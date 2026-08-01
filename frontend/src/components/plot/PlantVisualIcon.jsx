import { plantVisual } from '../../lib/plantVisual.js'

const paths = {
  tree: <><path d="M12 21v-7" /><path d="M12 3c-3.9 0-6 3-6 6.2 0 1.8.8 3.5 2.1 4.6h7.8A6.1 6.1 0 0 0 18 9.2C18 6 15.9 3 12 3Z" /></>,
  berry: <><circle cx="9" cy="13" r="3.4" /><circle cx="14.3" cy="12.2" r="3.4" /><circle cx="12" cy="16.4" r="3.4" /><path d="M12 7c.4-2 1.7-3 3.7-3" /></>,
  herb: <><path d="M12 21V9" /><path d="M12 14c-4.3-.2-6.2-2.6-6.5-6 4.2-.1 6.3 2.1 6.5 6Z" /><path d="M12 11c.4-3.8 2.7-5.5 6.5-5.5-.1 3.8-2.4 5.8-6.5 5.5Z" /></>,
  fruit: <><path d="M12 8c-3.5 0-5.5 2.7-5.5 6.1 0 4 2.2 6.4 5.5 6.4s5.5-2.4 5.5-6.4C17.5 10.7 15.5 8 12 8Z" /><path d="M12 8c.2-2 1.1-3 3.3-3.5M12 8c-1.4-1.8-3-2.2-4.7-1.8" /></>,
  leafy: <path d="M12 21V12c-4.8 0-7.2-3.3-7.6-7.8C9.3 4 12 7.3 12 12Zm0 0c0-4.7 2.8-7.7 7.6-7.8C19.2 8.7 16.8 12 12 12Z" />,
  root: <><path d="M12 10c-3 1.3-4.3 4.8-2.8 7.8 1.2 2.5 4.4 3.4 6.7 1.7 2.8-2 2.3-6.5-1.1-8.6Z" /><path d="M12 10c-2.9-1.3-4.3-3.5-4.3-6.2M12 10c1.3-3 3.2-4.4 5.8-4.5" /></>,
  legume: <><path d="M7.2 5.5c3.1 0 5.4 2.8 5.4 6.3s-2.3 6.3-5.4 6.3c-1.4 0-2.7-.6-3.7-1.6 3.2-.7 4.4-2.4 4.4-4.7S6.7 7.8 3.5 7.1c1-1 2.3-1.6 3.7-1.6Z" /><circle cx="16.8" cy="11.8" r="2.3" /></>,
  grain: <><path d="M12 21V4" /><path d="m12 7-3-2m3 5-3-2m3 5-3-2m3 5-3-2m3-9 3-2m-3 5 3-2m-3 5 3-2m-3 5 3-2" /></>,
  flower: <><circle cx="12" cy="12" r="2.2" /><path d="M12 9.8C8 5.2 5.2 6.6 5.2 9.6S8 14.1 12 14.1m0-4.3c4-4.6 6.8-3.2 6.8-.2S16 14.1 12 14.1M12 14.1V21" /></>,
  shrub: <><path d="M12 21v-6" /><path d="M5 15c-2.1-4.3.9-8.6 4.8-7.2C11.2 3.7 16.6 4 18 7.8c3.9-1.4 6.9 2.9 4.8 7.2Z" transform="translate(-1 0) scale(.96)" /></>,
}

export default function PlantVisualIcon({ plant, className = '' }) {
  const visual = plantVisual(plant)
  if (visual.key === 'explicit' && /^https?:|^\//.test(String(visual.explicit))) return <img className={className} src={visual.explicit} alt="" />
  if (visual.key === 'generic' || visual.key === 'explicit') return <span className={className} aria-hidden="true">{visual.monogram}</span>
  return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">{paths[visual.key] ?? paths.leafy}</svg>
}
