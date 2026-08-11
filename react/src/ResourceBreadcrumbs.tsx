export type ResourceBreadcrumb = {
  label: string
  url: string | null
}

export type ResourceBreadcrumbsProps = {
  breadcrumbs: ResourceBreadcrumb[]
  className?: string
  label?: string
}

/**
 * The trail a resource page published.
 *
 * PHP decides which steps exist and which of them are reachable; a step with
 * no URL renders as plain text rather than a link that leads nowhere.
 */
export function ResourceBreadcrumbs({ breadcrumbs, className, label = 'Breadcrumb' }: ResourceBreadcrumbsProps) {
  if (!breadcrumbs.length) return null

  return (
    <nav aria-label={label} className={className} data-slot="resource-breadcrumbs">
      <ol className="flex flex-wrap items-center gap-2 text-sm text-(--inlay-muted)">
        {breadcrumbs.map((crumb, index) => {
          const last = index === breadcrumbs.length - 1
          return (
            <li className="flex items-center gap-2" data-slot="resource-breadcrumb" key={`${crumb.label}:${index}`}>
              {crumb.url && !last
                ? <a className="hover:text-(--inlay-text)" href={crumb.url}>{crumb.label}</a>
                : <span aria-current={last ? 'page' : undefined}>{crumb.label}</span>}
              {last ? null : <span aria-hidden="true">/</span>}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
