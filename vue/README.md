# @inlayphp/resources-vue

[![npm](https://img.shields.io/npm/v/@inlayphp/resources-vue?style=flat-square)](https://www.npmjs.com/package/@inlayphp/resources-vue)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**Vue renderer for Inlay resources and relation managers**

Vue components for owner-scoped Inlay Relation Managers. They compose the standard Vue Table and Form renderers and keep authorization, validation, queries, and persistence in PHP.

## Install

```bash
pnpm add @inlayphp/resources @inlayphp/resources-vue @inlayphp/widgets-vue
```

## Render registered managers

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import {
  RelationManagers,
  type RelationManagerResource,
} from '@inlayphp/resources-vue'

defineProps<{ relations: RelationManagerResource[] }>()
</script>

<template>
  <RelationManagers
    :resources="relations"
    @changed="router.reload({ only: ['relations'] })"
  />
</template>
```

Use `RelationManager` for one contract. Props include `resource`, `mutate`, and
`empty`; events include `changed(operation, record)` and
`query-change(query)`. Changed operations include create, edit, delete, restore,
force-delete, attach, detach, associate, and dissociate. The default same-origin JSON transport sends Laravel CSRF headers
and maps 422 errors into the normal Form error contract.

Create, edit, attach, and associate use an accessible modal with close,
backdrop, and Escape behavior. Candidate selection uses the normal remote
searchable Select. Delete, detach, and dissociate execute through the standard
Table action confirmation flow. Additional Attach fields travel through the
same Form submission as validated pivot data. Edit dialogs use the dedicated
PHP `editForm` contract and hydrate declared pivot attributes even for
edit-only managers. Reload the
`relations` Inertia prop after mutations so policy decisions, query state, and
rendered values remain server-authoritative.

PHP `RelationGroup::make()` metadata is rendered automatically as semantic
tabs. The configured default is selected first, with Arrow Left/Right and
Home/End keyboard navigation. Vue pages keep passing the ordinary `relations`
prop and do not duplicate the grouping configuration.

## Compose a resource page

`ResourcePage` provides the same page chrome as the React renderer while leaving
the actual Form, Table, Infolist, and relation content to slots. Breadcrumbs,
record navigation, named views, and PHP `ResourcePage::headerActions()` remain
server-authored.

```vue
<script setup lang="ts">
import { ResourcePage } from '@inlayphp/resources-vue'
import { Form } from '@inlayphp/forms-vue'
import type { ResourcePageAction } from '@inlayphp/resources-vue'

defineProps<{
  form: unknown
  errors: Record<string, string>
  breadcrumbs: unknown[]
  heading: string
  headerActions?: ResourcePageAction[]
}>()
</script>

<template>
  <ResourcePage :breadcrumbs="breadcrumbs" :header-actions="headerActions" :heading="heading">
    <!-- Named #actions remains available for app-specific controls. It is
         rendered after server-declared headerActions. -->
    <template #actions><button type="button">Custom control</button></template>
    <Form :errors="errors" :resource="form" />
  </ResourcePage>
</template>
```

Each PHP action is rendered with `@inlayphp/actions-vue`'s `ActionButton` and
shared runtime. Confirmation modals, action forms, validation errors, keyboard
bindings, downloads, and lifecycle responses therefore behave like actions
inside Forms and Tables. Ordinary actions use an Inertia visit; actions with a
lifecycle handler use the JSON action endpoint. Pass an `action-executor` prop
when an application needs custom transport or reporting:

```vue
<ResourcePage
  :action-executor="context => myExecutor(context)"
  :action-input="{ parameters: { view: 'all' } }"
  :header-actions="headerActions"
  :heading="heading"
>
  <Form :errors="errors" :resource="form" />
</ResourcePage>
```

`actionInput` is optional and is forwarded to every declared header action. Use
it for route parameters, initial action data, or selected records when a custom
page renders the same action set in more than one context.

`ResourcePageAction` and `ResourcePageActionExecutor` are exported types for
page registries and wrapper components.

When `tabs` is present, listen for `tab-select` and visit the selected server
view. No client-side filtering is performed.

### Page widgets

Resource pages can render the PHP-resolved `headerWidgets` and `footerWidgets`
contracts through the same dashboard renderer used by an Inlay panel. Pass each
`inlay.widget-dashboard.v1` payload to the matching prop:

```vue
<script setup lang="ts">
import { ResourcePage } from '@inlayphp/resources-vue'
import type { WidgetDashboardResource } from '@inlayphp/widgets-vue'

defineProps<{
  heading: string
  headerWidgets?: WidgetDashboardResource | null
  footerWidgets?: WidgetDashboardResource | null
}>()
</script>

<template>
  <ResourcePage
    :footer-widgets="footerWidgets"
    :header-widgets="headerWidgets"
    :heading="heading"
  >
    <Form :resource="form" :errors="errors" />
  </ResourcePage>
</template>
```

`headerWidgets` is rendered after the page's tabs and before the `before` slot;
`footerWidgets` is rendered after the page content and the `after` slot. Both
props are optional, preserve PHP ordering and visibility, and render nothing
when the server does not publish a dashboard. `widgetProps` forwards theme,
class-name, icon, custom-renderer, and refresh options to both dashboards. The `WidgetDashboard` component
continues to own responsive spans, empty states, custom renderers, icon
registries, polling, and table widgets. Use its `@refresh` event directly when
the page needs to reload a lazy or polling widget:

```vue
<ResourcePage
  :header-widgets="headerWidgets"
  :heading="heading"
  :widget-props="{ classNames: { root: 'compact-widgets' }, onRefresh: refreshWidget }"
>
  <template #default><Form :resource="form" :errors="errors" /></template>
</ResourcePage>
```

`widgetProps` is applied to both dashboards and accepts `theme`, `className`,
`classNames`, `icons`, and `renderers` from `@inlayphp/widgets-vue`. Its
`onRefresh(name)` callback receives the widget name for lazy/polling refreshes;
the page decides whether to reload an Inertia prop, fetch JSON, or update its
own state. This keeps widget transport out of the renderer and makes theme and
component overrides symmetrical with the React resource page.

For custom widget themes or renderers, render `WidgetDashboard` directly in a
named page slot and keep the PHP payload unchanged. Resource-page defaults are
deliberately unopinionated so existing action, `before`, `after`, and default
content slots remain compatible.
