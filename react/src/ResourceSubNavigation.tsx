export type ResourceSubNavigationItem = {
  name: string
  label: string
  url: string
  active: boolean
}

export type ResourceSubNavigationProps = {
  items: ResourceSubNavigationItem[]
  className?: string
  label?: string
}

/**
 * The sibling pages one record moves between.
 *
 * PHP decides which pages belong here and leaves out any the visitor is not
 * allowed to open, so the list is already the list they may follow. The page
 * being viewed is marked rather than linked back to itself.
 */
export function ResourceSubNavigation({ items, className, label = 'Record pages' }: ResourceSubNavigationProps) {
  if (!items.length) return null

  return (
    <nav aria-label={label} className={className} data-slot="resource-sub-navigation">
      <ul className="flex flex-wrap gap-1 text-sm">
        {items.map((item) => (
          <li data-slot="resource-sub-navigation-item" key={item.name}>
            {item.active
              ? (
                  <span
                    aria-current="page"
                    className="block rounded-md bg-(--inlay-surface-muted) px-3 py-2 font-semibold text-(--inlay-text)"
                    data-active="true"
                  >
                    {item.label}
                  </span>
                )
              : (
                  <a
                    className="block rounded-md px-3 py-2 font-medium text-(--inlay-muted) transition-colors hover:bg-(--inlay-surface-muted) hover:text-(--inlay-text)"
                    href={item.url}
                  >
                    {item.label}
                  </a>
                )}
          </li>
        ))}
      </ul>
    </nav>
  )
}
