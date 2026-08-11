import type { RelationManagerResource as BaseRelationManagerResource } from '@inlayphp/resources'
import type { ActionExecutor, ActionResource } from '@inlayphp/actions'
import type { FormResource } from '@inlayphp/forms-vue'
import type { TableResource } from '@inlayphp/tables-vue'
import type { WidgetClassNames, WidgetDashboardResource, WidgetIconRegistry, WidgetRenderers, WidgetTheme } from '@inlayphp/widgets-vue'

export type RelationManagerResource = BaseRelationManagerResource<TableResource, FormResource>

export type ResourceTab = { name: string; label: string; badge?: string | number | null }
export type ResourceTabsResource = { active: string; items: ResourceTab[] }

export type ResourceBreadcrumb = { label: string; url: string | null }

export type ResourceSubNavigationItem = {
  name: string
  label: string
  url: string
  active: boolean
}

/** A page header action serialized by a PHP ResourcePage. */
export type ResourcePageAction = ActionResource

/** Override the default Inertia/fetch transport for resource page actions. */
export type ResourcePageActionExecutor = ActionExecutor

/**
 * Renderer options shared by both resource-page widget regions.
 *
 * `onRefresh` is a page-level callback for lazy and polling widgets. The Vue
 * dashboard emits `refresh`; ResourcePage forwards that event without making
 * callers reach into either dashboard instance.
 */
export type ResourcePageWidgetProps = {
  theme?: WidgetTheme
  className?: string
  classNames?: WidgetClassNames
  icons?: WidgetIconRegistry
  renderers?: WidgetRenderers
  onRefresh?: (name: string) => void
}
