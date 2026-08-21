import type { ReactNode } from 'react'

export type ResourcePageHeadingProps = {
  heading: string
  subheading?: string | null
  actions?: ReactNode
  className?: string
}

/**
 * The heading a resource page declared in PHP.
 *
 * A page about one record is named for that record, and PHP derives that from
 * the same `recordTitle()` the breadcrumb uses, so the two cannot disagree.
 * Actions are a slot rather than a prop the component resolves, because the
 * host already renders them and this should not become a second way to.
 */
export function ResourcePageHeading({ heading, subheading, actions, className }: ResourcePageHeadingProps) {
  return (
    <div className={`flex flex-wrap items-start justify-between gap-4 ${className ?? ''}`.trim()} data-slot="page-heading">
      <div className="min-w-0">
        <h1 className="text-[length:clamp(1.5rem,2vw,1.875rem)] font-semibold text-(--inlay-fg-strong)" data-slot="heading">{heading}</h1>
        {subheading ? <p className="mt-1 text-sm text-(--inlay-muted-strong)" data-slot="subheading">{subheading}</p> : null}
      </div>
      {actions ? <div className="flex flex-wrap items-center gap-2" data-slot="header-actions">{actions}</div> : null}
    </div>
  )
}
