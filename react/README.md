# @inlayphp/resources-react

[![npm](https://img.shields.io/npm/v/@inlayphp/resources-react?style=flat-square)](https://www.npmjs.com/package/@inlayphp/resources-react)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](../../../LICENSE)

**React renderer for Inlay resources and relation managers**

React components for owner-scoped Inlay Relation Managers. The package contains no Laravel business rules: it renders the serialized PHP contract by composing `@inlayphp/tables-react` and `@inlayphp/forms-react`.

## Install

```bash
pnpm add @inlayphp/resources @inlayphp/resources-react
```

## Render registered managers

```tsx
import { router } from '@inertiajs/react'
import {
  RelationManagers,
  type RelationManagerResource,
} from '@inlayphp/resources-react'

export function Relations({
  relations,
}: {
  relations: RelationManagerResource[]
}) {
  return <RelationManagers
    resources={relations}
    onChanged={() => router.reload({ only: ['relations'] })}
  />
}
```

Use `RelationManager` when a page renders one manager. Both components accept:

- `onChanged(operation, record)` after create, edit, delete, restore,
  force-delete, attach, detach, associate, or dissociate.
- `onQueryChange(query)` for server-side Table navigation.
- `empty` to replace the default empty Table.
- `mutate(request)` to replace the built-in `fetch` transport.
- `className` for page-level layout without overriding component internals.

The built-in transport sends same-origin JSON with Laravel CSRF and
XMLHttpRequest headers. Validation responses are mapped to the standard Form
error contract. Create, edit, attach, and associate use an accessible modal
with close, backdrop, and Escape behavior. Attach and associate dialogs render
PHP-provided remote searchable Selects and resolve chosen identifiers into
record-specific endpoints. Additional Attach fields are submitted as validated
pivot data without any React-specific business logic. Edit dialogs use the
dedicated PHP `editForm` contract, including hydrated `withPivot()` attributes,
even when creation is not authorized. Delete, detach, and
dissociate use the Table action confirmation flow.

When PHP registers managers inside `RelationGroup::make()`,
`RelationManagers` automatically renders the group as accessible tabs. The
configured default tab is selected first, and Arrow Left/Right plus Home/End
move both selection and keyboard focus. No React group definition is needed.

The package deliberately does not cache relation rows after a mutation. Reload the `relations` Inertia prop so authorization, computed columns, filters, and pagination remain server-authoritative.

## Compose a resource page

Use `ResourcePage` when a custom page wants the same familiar resource chrome
without reimplementing breadcrumbs, record navigation, or named views. The server
still owns the metadata; the page owns the actual Form, Table, Infolist, and
RelationManagers content.

```tsx
import { ResourcePage } from '@inlayphp/resources-react'
import { Form } from '@inlayphp/forms-react'

export function EditUser({ form, breadcrumbs, heading, subheading, subNavigation, errors }) {
  return (
    <ResourcePage
      actions={<button type="button">Delete</button>}
      breadcrumbs={breadcrumbs}
      heading={heading}
      subheading={subheading}
      subNavigation={subNavigation}
    >
      <Form errors={errors} resource={form} />
    </ResourcePage>
  )
}
```

### Page widgets

`ResourcePage` consumes the `headerWidgets` and `footerWidgets` props published by
PHP `ResourcePage`. Both props use the same `inlay.widget-dashboard.v1` contract as
the panel dashboard and are rendered by `@inlayphp/widgets-react`, so stats,
charts, nested tables, lazy loading, polling, and custom renderers behave the same
on a resource page and a dashboard.

```tsx
import { ResourcePage } from '@inlayphp/resources-react'
import type { WidgetDashboardResource } from '@inlayphp/widgets-react'

export function ListOrders({
  footerWidgets,
  headerWidgets,
  ...props
}: {
  headerWidgets?: WidgetDashboardResource | null
  footerWidgets?: WidgetDashboardResource | null
  // ...the remaining ResourcePage props
}) {
  return (
    <ResourcePage
      {...props}
      footerWidgets={footerWidgets}
      headerWidgets={headerWidgets}
      widgetProps={{
        theme: { accent: '#4f46e5' },
        classNames: { grid: 'gap-8' },
      }}
    >
      {/* Table, Form, Infolist, or RelationManagers */}
    </ResourcePage>
  )
}
```

`widgetProps` is forwarded to both dashboards. Use it for theme tokens, custom
icons/renderers, an empty state, or an `onRefresh` callback. The page keeps widget
placement server-authored: header dashboards render before `beforeContent` and
the page content, while footer dashboards render after `afterContent`. Omitted or
`null` widget props render no dashboard region.

PHP page actions are available as the `headerActions` prop. They are rendered by
`@inlayphp/actions-react`, so confirmation dialogs, dynamic action forms,
validation errors, keyboard bindings, and focus return use the same runtime as
table and form actions. The default executor visits ordinary action URLs with
Inertia and sends lifecycle actions to the JSON action endpoint:

```tsx
import type { ActionResource } from '@inlayphp/actions'
import { ResourcePage } from '@inlayphp/resources-react'

export function EditUser({
  headerActions,
  recordId,
  ...props
}: {
  headerActions?: ActionResource[]
  recordId: number
  // ...the remaining server props
}) {
  return (
    <ResourcePage
      {...props}
      headerActions={headerActions}
      actionInput={{ parameters: { id: recordId } }}
    >
      {/* Form, Table, Infolist, or RelationManagers */}
    </ResourcePage>
  )
}
```

`actionExecutor` replaces that transport when an application uses a custom
request client. `actionInput` is merged into each action invocation and is
useful for page-level parameters or records. The existing `actions` prop remains
an ordinary `ReactNode` slot; when both props are supplied, custom slot content
and server-declared actions are rendered together.

`tabs` accepts the PHP `ListRecords` named-view contract. Handle `onTabSelect`
by visiting the same page with the selected `tab`; the component deliberately
does not filter records in the browser.
