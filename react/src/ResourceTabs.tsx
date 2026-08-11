import { useRef } from 'react'
import type { KeyboardEvent } from 'react'

export type ResourceTab = {
  name: string
  label: string
  badge?: string | number | null
}

export type ResourceTabsResource = {
  active: string
  items: ResourceTab[]
}

export type ResourceTabsProps = {
  tabs: ResourceTabsResource
  onSelect: (tab: string) => void
  className?: string
  label?: string
}

/**
 * The named views a list page declared in PHP.
 *
 * The component only reports which tab was chosen; what a tab means, and which
 * records it shows, stays on the server.
 */
export function ResourceTabs({ tabs, onSelect, className, label = 'Views' }: ResourceTabsProps) {
  const refs = useRef<Record<string, HTMLButtonElement | null>>({})
  const navigate = (event: KeyboardEvent<HTMLButtonElement>) => {
    const offset = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0
    if (offset === 0) return
    event.preventDefault()
    const index = tabs.items.findIndex(item => item.name === tabs.active)
    const next = tabs.items[(index + offset + tabs.items.length) % tabs.items.length]
    if (!next) return
    onSelect(next.name)
    refs.current[next.name]?.focus()
  }

  return (
    <div
      aria-label={label}
      className={`flex gap-1 overflow-x-auto border-b border-(--inlay-border) ${className ?? ''}`.trim()}
      data-slot="resource-tabs"
      role="tablist"
    >
      {tabs.items.map(item => (
        <button
          aria-selected={item.name === tabs.active}
          className="relative min-h-10 shrink-0 px-3 py-2 text-sm font-semibold text-(--inlay-muted) transition-colors hover:text-(--inlay-text) aria-selected:text-(--inlay-accent)"
          data-slot="resource-tab"
          key={item.name}
          onClick={() => onSelect(item.name)}
          onKeyDown={navigate}
          ref={(element) => { refs.current[item.name] = element }}
          role="tab"
          tabIndex={item.name === tabs.active ? 0 : -1}
          type="button"
        >
          {item.label}
          {item.badge !== null && item.badge !== undefined ? (
            <span className="ml-2 rounded-full bg-(--inlay-surface-muted) px-2 py-0.5 text-xs" data-slot="resource-tab-badge">
              {item.badge}
            </span>
          ) : null}
        </button>
      ))}
    </div>
  )
}
