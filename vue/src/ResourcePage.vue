<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { executeActionEndpoint } from '@inlayphp/actions'
import type { ActionExecutionContext, ActionExecutionInput, ActionExecutor } from '@inlayphp/actions'
import { ActionButton } from '@inlayphp/actions-vue'
import { ActionForm } from '@inlayphp/forms-vue'
import { WidgetDashboard } from '@inlayphp/widgets-vue'
import type { WidgetDashboardResource } from '@inlayphp/widgets-vue'
import type { Component } from 'vue'
import ResourceBreadcrumbs from './ResourceBreadcrumbs.vue'
import ResourcePageHeading from './ResourcePageHeading.vue'
import ResourceSubNavigation from './ResourceSubNavigation.vue'
import ResourceTabs from './ResourceTabs.vue'
import type { ResourceBreadcrumb, ResourcePageAction, ResourcePageWidgetProps, ResourceSubNavigationItem, ResourceTabsResource } from './types'

const props = withDefaults(defineProps<{
  heading: string
  subheading?: string | null
  breadcrumbs?: ResourceBreadcrumb[]
  subNavigation?: ResourceSubNavigationItem[]
  tabs?: ResourceTabsResource | null
  /** Actions declared by the PHP ResourcePage::headerActions() contract. */
  headerActions?: ResourcePageAction[]
  /** Replace the default Inertia/fetch transport for page actions. */
  actionExecutor?: ActionExecutor
  /** Parameters, data, and records forwarded to every declared header action. */
  actionInput?: ActionExecutionInput
  /** Replace the default action form renderer for custom field registries. */
  actionFormRenderer?: Component
  /** Widgets resolved by PHP for the top of this resource page. */
  headerWidgets?: WidgetDashboardResource | null
  /** Widgets resolved by PHP for the bottom of this resource page. */
  footerWidgets?: WidgetDashboardResource | null
  /** Shared Vue dashboard options and refresh callback for both widget regions. */
  widgetProps?: ResourcePageWidgetProps
  className?: string
}>(), {
  subheading: null,
  breadcrumbs: () => [],
  subNavigation: () => [],
  tabs: null,
  headerActions: () => [],
  headerWidgets: null,
  footerWidgets: null,
  widgetProps: undefined,
  className: '',
})

const emit = defineEmits<{ tabSelect: [tab: string] }>()

/**
 * Match Form and Infolist action semantics: lifecycle actions use the JSON
 * action endpoint, while ordinary page actions remain Inertia visits. Both
 * paths are still driven by the shared Actions Vue runtime (confirmation,
 * modal forms, validation, and lifecycle state).
 */
function executeAction({ action, input, url }: ActionExecutionContext) {
  if (!url) return
  if (action.lifecycle) return executeActionEndpoint({ action, input, url })

  return router.visit(url, {
    method: action.method,
    data: input.data as never,
    preserveScroll: true,
  })
}

const resolvedActionExecutor = (context: ActionExecutionContext) => (props.actionExecutor ?? executeAction)(context)

function refreshWidget(name: string) {
  props.widgetProps?.onRefresh?.(name)
}

/**
 * The renderer-neutral page composition emitted by a Resource page.
 *
 * PHP owns the breadcrumb, record sub-navigation, and named-view metadata.
 * This component only arranges those contracts and leaves the actual form,
 * table, infolist, or relation managers to the host through slots.
 */
</script>

<template>
  <div :class="`grid min-w-0 gap-6 ${className}`.trim()" data-slot="resource-page">
    <ResourceBreadcrumbs :breadcrumbs="breadcrumbs" />
    <ResourcePageHeading :heading="heading" :subheading="subheading">
      <template v-if="headerActions.length || $slots.actions" #actions>
        <ActionButton
          v-for="action in headerActions"
          :key="action.instanceKey ?? action.name"
          :action="action"
          :executor="resolvedActionExecutor"
          :form-renderer="actionFormRenderer ?? ActionForm"
          :input="actionInput"
        />
        <slot name="actions" />
      </template>
    </ResourcePageHeading>
    <ResourceSubNavigation :items="subNavigation" />
    <ResourceTabs v-if="tabs" :tabs="tabs" @select="tab => emit('tabSelect', tab)" />
    <div v-if="headerWidgets" data-slot="resource-page-header-widgets">
      <WidgetDashboard
        :class-name="widgetProps?.className"
        :class-names="widgetProps?.classNames"
        :icons="widgetProps?.icons"
        :renderers="widgetProps?.renderers"
        :resource="headerWidgets"
        :theme="widgetProps?.theme"
        @refresh="refreshWidget"
      />
    </div>
    <div v-if="$slots.before" data-slot="resource-page-before"><slot name="before" /></div>
    <main class="min-w-0" data-slot="resource-page-content"><slot /></main>
    <div v-if="$slots.after" data-slot="resource-page-after"><slot name="after" /></div>
    <div v-if="footerWidgets" data-slot="resource-page-footer-widgets">
      <WidgetDashboard
        :class-name="widgetProps?.className"
        :class-names="widgetProps?.classNames"
        :icons="widgetProps?.icons"
        :renderers="widgetProps?.renderers"
        :resource="footerWidgets"
        :theme="widgetProps?.theme"
        @refresh="refreshWidget"
      />
    </div>
  </div>
</template>
