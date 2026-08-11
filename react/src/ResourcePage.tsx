import { router } from '@inertiajs/react'
import { executeActionEndpoint } from '@inlayphp/actions'
import { ActionButton, ActionDialog, useActionRuntime } from '@inlayphp/actions-react'
import type { ActionExecutionInput, ActionExecutor, ActionResource } from '@inlayphp/actions'
import { ActionForm } from '@inlayphp/forms-react'
import { WidgetDashboard } from '@inlayphp/widgets-react'
import type { WidgetDashboardProps, WidgetDashboardResource } from '@inlayphp/widgets-react'
import type { ReactNode } from 'react'
import { ResourceBreadcrumbs } from './ResourceBreadcrumbs'
import type { ResourceBreadcrumb } from './ResourceBreadcrumbs'
import { ResourcePageHeading } from './ResourcePageHeading'
import { ResourceSubNavigation } from './ResourceSubNavigation'
import type { ResourceSubNavigationItem } from './ResourceSubNavigation'
import { ResourceTabs } from './ResourceTabs'
import type { ResourceTabsResource } from './ResourceTabs'

export type ResourcePageProps = {
  heading: string
  subheading?: string | null
  breadcrumbs?: ResourceBreadcrumb[]
  subNavigation?: ResourceSubNavigationItem[]
  tabs?: ResourceTabsResource | null
  /** Dashboard widgets serialized by PHP for the top of the page. */
  headerWidgets?: WidgetDashboardResource | null
  /** Dashboard widgets serialized by PHP for the bottom of the page. */
  footerWidgets?: WidgetDashboardResource | null
  /** Shared widget renderer options applied to both page widget regions. */
  widgetProps?: Omit<WidgetDashboardProps, 'resource'>
  /**
   * Actions declared by the PHP page's `headerActions()` method.
   *
   * These are rendered with the shared action runtime. `actions` remains a
   * ReactNode slot for callers that need a custom control or composition.
   */
  headerActions?: ActionResource[]
  /** Replace the default Inertia/action-endpoint transport for header actions. */
  actionExecutor?: ActionExecutor
  /** Parameters and records forwarded to every declared header action. */
  actionInput?: ActionExecutionInput
  actions?: ReactNode
  beforeContent?: ReactNode
  afterContent?: ReactNode
  children?: ReactNode
  onTabSelect?: (tab: string) => void
  className?: string
}

/**
 * The renderer-neutral page composition emitted by a Resource page.
 *
 * PHP owns the breadcrumb, record sub-navigation, and named-view metadata.
 * This component only arranges those contracts and leaves the actual form,
 * table, infolist, or relation managers to the host through children/slots.
 * Keeping the shell small means a custom page can replace any content region
 * without having to fork the resource chrome.
 */
export function ResourcePage({
  heading,
  subheading,
  breadcrumbs = [],
  subNavigation = [],
  tabs = null,
  headerWidgets = null,
  footerWidgets = null,
  widgetProps,
  headerActions = [],
  actionExecutor,
  actionInput,
  actions,
  beforeContent,
  afterContent,
  children,
  onTabSelect,
  className,
}: ResourcePageProps) {
  const actionRuntime = useActionRuntime(actionExecutor ?? defaultActionExecutor)
  const declaredActions = headerActions.length > 0 ? (
    <>
      {headerActions.map(action => (
        <ActionButton action={action} input={actionInput} key={action.instanceKey ?? action.name} runtime={actionRuntime} />
      ))}
      <ActionDialog runtime={actionRuntime}>
        {dialogRuntime => <ActionForm runtime={dialogRuntime} />}
      </ActionDialog>
    </>
  ) : null
  const headingActions = declaredActions ? <>{declaredActions}{actions}</> : actions

  return (
    <div className={`grid min-w-0 gap-6 ${className ?? ''}`.trim()} data-slot="resource-page">
      <ResourceBreadcrumbs breadcrumbs={breadcrumbs} />
      <ResourcePageHeading actions={headingActions} heading={heading} subheading={subheading} />
      <ResourceSubNavigation items={subNavigation} />
      {tabs ? <ResourceTabs onSelect={onTabSelect ?? (() => undefined)} tabs={tabs} /> : null}
      {headerWidgets ? <div data-slot="resource-page-header-widgets"><WidgetDashboard {...widgetProps} resource={headerWidgets} /></div> : null}
      {beforeContent ? <div data-slot="resource-page-before">{beforeContent}</div> : null}
      <main className="min-w-0" data-slot="resource-page-content">{children}</main>
      {afterContent ? <div data-slot="resource-page-after">{afterContent}</div> : null}
      {footerWidgets ? <div data-slot="resource-page-footer-widgets"><WidgetDashboard {...widgetProps} resource={footerWidgets} /></div> : null}
    </div>
  )
}

/**
 * The default page-action transport follows the same rule as Forms and
 * Infolists: lifecycle actions use the JSON action contract, while ordinary
 * actions visit their URL through Inertia so redirects and page props remain
 * server-authoritative.
 */
const defaultActionExecutor: ActionExecutor = ({ action, input, url }) => {
  if (!url) return
  if (action.lifecycle) return executeActionEndpoint({ action, input, url })

  return router.visit(url, {
    data: input.data as never,
    method: action.method,
    preserveScroll: true,
  })
}
