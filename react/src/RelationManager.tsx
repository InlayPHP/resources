import { useMemo, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent, ReactNode } from 'react'
import { router } from '@inertiajs/react'
import { buttonSecondaryClass } from '@inlayphp/ui-react'
import { executeActionEndpoint, interpolateActionUrl, normalizeAction } from '@inlayphp/actions'
import { mutateRelation, RelationMutationError, relationEndpoint } from '@inlayphp/resources'
import type { RelationManagerResource as BaseRelationManagerResource, RelationMutationRequest, RelationMutationResponse } from '@inlayphp/resources'
import type { FormErrors, FormResource } from '@inlayphp/forms-react'
import { Table } from '@inlayphp/tables-react'
import type { Action, BulkSelectionState, QueryState, TableRenderers, TableResource, TableRow } from '@inlayphp/tables-react'
import { RelationDialog } from './RelationDialog'

export type RelationManagerResource = BaseRelationManagerResource<TableResource, FormResource>

export type ResourceIconRegistry = NonNullable<TableRenderers['icon']>

type Mutation = (request: RelationMutationRequest) => Promise<RelationMutationResponse | null>

export type RelationManagerProps = {
  resource: RelationManagerResource
  className?: string
  mutate?: Mutation
  icons?: ResourceIconRegistry
  onChanged?: (operation: 'create' | 'edit' | 'delete' | 'restore' | 'force-delete' | 'attach' | 'detach' | 'associate' | 'dissociate', record: Record<string, unknown> | null) => void
  onQueryChange?: (query: QueryState) => void
  empty?: ReactNode
  grouped?: boolean
}

export type RelationManagersProps = Omit<RelationManagerProps, 'resource'> & {
  resources: RelationManagerResource[]
}

const editAction: Action = {
  name: '__inlay_relation_edit',
  label: 'Edit',
  url: null,
  method: 'get',
  color: 'default',
  requiresConfirmation: false,
  icon: null,
  modalHeading: null,
  visibleWhen: { path: 'deleted_at', operator: 'blank', value: null },
}

const deleteAction: Action = {
  name: '__inlay_relation_delete',
  label: 'Delete',
  url: null,
  method: 'delete',
  color: 'danger',
  requiresConfirmation: true,
  icon: null,
  modalHeading: 'Delete this related record?',
}

const detachAction: Action = {
  name: '__inlay_relation_detach',
  label: 'Detach',
  url: null,
  method: 'delete',
  color: 'danger',
  requiresConfirmation: true,
  icon: null,
  modalHeading: 'Detach this related record?',
  visibleWhen: { path: 'deleted_at', operator: 'blank', value: null },
}

const dissociateAction: Action = {
  name: '__inlay_relation_dissociate',
  label: 'Dissociate',
  url: null,
  method: 'delete',
  color: 'danger',
  requiresConfirmation: true,
  icon: null,
  modalHeading: 'Dissociate this related record?',
  visibleWhen: { path: 'deleted_at', operator: 'blank', value: null },
}

const headerActionClass = `${buttonSecondaryClass} font-semibold`

export function RelationManagers({ resources, ...props }: RelationManagersProps) {
  const entries = relationEntries(resources)

  return <div className="grid gap-10" data-slot="relation-managers">
    {entries.map(entry => entry.type === 'relation'
      ? <RelationManager {...props} key={entry.resource.name} resource={entry.resource} />
      : <RelationManagerGroup {...props} group={entry.group} key={entry.group.id} resources={entry.resources} />)}
  </div>
}

type RelationEntry =
  | { type: 'relation'; resource: RelationManagerResource }
  | { type: 'group'; group: NonNullable<RelationManagerResource['group']>; resources: RelationManagerResource[] }

function relationEntries(resources: RelationManagerResource[]): RelationEntry[] {
  const entries: RelationEntry[] = []
  const grouped = new Set<string>()
  for (const resource of resources) {
    if (!resource.group) {
      entries.push({ type: 'relation', resource })
      continue
    }
    if (grouped.has(resource.group.id)) continue
    grouped.add(resource.group.id)
    entries.push({
      type: 'group',
      group: resource.group,
      resources: resources.filter(candidate => candidate.group?.id === resource.group?.id),
    })
  }

  return entries
}

function RelationManagerGroup({
  group,
  resources,
  ...props
}: Omit<RelationManagersProps, 'resources'> & {
  group: NonNullable<RelationManagerResource['group']>
  resources: RelationManagerResource[]
}) {
  const initial = resources.some(resource => resource.name === group.defaultRelation)
    ? group.defaultRelation
    : resources[0]?.name ?? ''
  const [active, setActive] = useState(initial)
  const current = resources.find(resource => resource.name === active) ?? resources[0]
  if (!current) return null
  const headingId = `inlay-relation-group-${group.id}`
  const tabId = (name: string) => `${headingId}-tab-${name}`
  const panelId = `${headingId}-panel`
  const selectTab = (name: string, focus = false) => {
    setActive(name)
    if (focus) requestAnimationFrame(() => document.getElementById(tabId(name))?.focus())
  }
  const navigateTabs = (event: ReactKeyboardEvent<HTMLButtonElement>) => {
    const index = resources.findIndex(resource => resource.name === current.name)
    const next = event.key === 'Home'
      ? 0
      : event.key === 'End'
        ? resources.length - 1
        : event.key === 'ArrowRight'
          ? (index + 1) % resources.length
          : event.key === 'ArrowLeft'
            ? (index - 1 + resources.length) % resources.length
            : null
    if (next === null) return
    event.preventDefault()
    selectTab(resources[next].name, true)
  }

  return <section
    aria-labelledby={headingId}
    className={group.contained ? 'rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-(--inlay-space-card)' : ''}
    data-icon={group.icon ?? undefined}
    data-slot="relation-group"
  >
    <header className="mb-5">
      <h2 className="text-xl font-semibold tracking-tight text-(--inlay-text)" id={headingId}>{group.label}</h2>
      {group.description ? <p className="mt-1 text-base/7 text-(--inlay-muted) sm:text-sm/6">{group.description}</p> : null}
    </header>
    <div
      aria-label={`${group.label} relations`}
      className="mb-5 flex gap-1 overflow-x-auto border-b border-(--inlay-border)"
      role="tablist"
    >
      {resources.map(resource => <button
        aria-controls={panelId}
        aria-selected={resource.name === current.name}
        className="relative min-h-10 shrink-0 px-3 py-2 text-sm font-semibold text-(--inlay-muted) outline-none transition-colors hover:text-(--inlay-text) aria-selected:text-(--inlay-accent) aria-selected:after:absolute aria-selected:after:inset-x-2 aria-selected:after:bottom-0 aria-selected:after:h-0.5 aria-selected:after:rounded-full aria-selected:after:bg-(--inlay-accent) focus-visible:ring-(length:--inlay-focus-ring-width) focus-visible:ring-(--inlay-focus-ring-color)"
        id={tabId(resource.name)}
        key={resource.name}
        onClick={() => selectTab(resource.name)}
        onKeyDown={navigateTabs}
        role="tab"
        tabIndex={resource.name === current.name ? 0 : -1}
        type="button"
      >
        {resource.title}
      </button>)}
    </div>
    <div aria-labelledby={tabId(current.name)} id={panelId} role="tabpanel">
      <RelationManager {...props} grouped resource={current} />
    </div>
  </section>
}

export function RelationManager({
  resource,
  className = '',
  mutate = mutateRelation,
  icons,
  onChanged,
  onQueryChange,
  empty,
  grouped = false,
}: RelationManagerProps) {
  const [mode, setMode] = useState<'create' | 'edit' | 'attach' | 'associate' | null>(null)
  const [record, setRecord] = useState<TableRow | null>(null)
  const [errors, setErrors] = useState<FormErrors>({})
  const [processing, setProcessing] = useState(false)
  const primaryKey = resource.table.primaryKey
  const actions = useMemo(() => [
    ...resource.table.actions,
    ...(resource.capabilities.edit && resource.endpoints ? [editAction] : []),
    ...(!resource.capabilities.softDeletes && resource.capabilities.delete && resource.endpoints ? [deleteAction] : []),
    ...(resource.capabilities.detach && resource.endpoints ? [detachAction] : []),
    ...(resource.capabilities.dissociate && resource.endpoints?.dissociate ? [dissociateAction] : []),
  ], [resource])
  const table = useMemo(() => ({ ...resource.table, actions }), [actions, resource.table])
  const dialogForm = useMemo(() => {
    if (!mode) return null
    if (mode === 'attach') {
      if (!resource.attachForm || !resource.endpoints) return null
      return {
        ...resource.attachForm,
        name: `${resource.name}.attach`,
        action: resource.endpoints.attach,
        method: 'post' as const,
        submitLabel: `Attach ${singular(resource.title)}`,
        data: resource.attachForm.data,
      }
    }
    if (mode === 'associate') {
      if (!resource.associateForm || !resource.endpoints?.associate) return null
      return {
        ...resource.associateForm,
        name: `${resource.name}.associate`,
        action: resource.endpoints.associate,
        method: 'post' as const,
        submitLabel: `Associate ${singular(resource.title)}`,
        data: resource.associateForm.data,
      }
    }
    const baseForm = mode === 'edit'
      ? resource.editForm ?? resource.createForm
      : resource.createForm
    if (!baseForm) return null
    const key = record?.[primaryKey]
    return {
      ...baseForm,
      name: `${resource.name}.${mode}`,
      action: mode === 'create'
        ? resource.endpoints?.create ?? null
        : key !== undefined && resource.endpoints
          ? relationEndpoint(resource.endpoints.update, key as string | number)
          : null,
      method: mode === 'create' ? 'post' as const : 'patch' as const,
      submitLabel: mode === 'create' ? `Create ${singular(resource.title)}` : `Save ${singular(resource.title)}`,
      data: mode === 'edit' ? record ?? {} : baseForm.data,
    }
  }, [mode, primaryKey, record, resource])

  const open = (nextMode: 'create' | 'edit' | 'attach' | 'associate', nextRecord: TableRow | null = null) => {
    setRecord(nextRecord)
    setErrors({})
    setMode(nextMode)
  }

  const execute = async (action: Action, rows: TableRow[], selection?: BulkSelectionState) => {
    const selected = rows[0]
    if (!resource.endpoints) return
    if (action.name === editAction.name) {
      if (!selected) return
      open('edit', selected)
      return
    }
    if (action.lifecycle && action.url) {
      const normalized = normalizeAction(action)
      const result = await executeActionEndpoint({
        action: normalized,
        url: interpolateActionUrl(action.url, selected ?? {}),
        input: {
          parameters: selected ?? {},
          records: rows,
          data: action.bulk
            ? selection?.mode === 'query'
              ? { selection }
              : { records: rows.map(row => row[primaryKey] as string | number) }
            : action.data ?? {},
        },
      })
      onChanged?.(
        action.name === 'force-delete' ? 'force-delete' : action.name === 'restore' ? 'restore' : 'delete',
        selected ?? null,
      )
      return result
    }
    if (action.url) {
      const url = interpolateActionUrl(action.url, selected ?? {})
      if (!url) return
      router.visit(url, {
        method: action.method,
        data: (action.data ?? {}) as never,
      })
      return
    }
    if (!selected) return
    const key = selected[primaryKey]
    if (typeof key !== 'string' && typeof key !== 'number') return
    if (action.name === deleteAction.name) {
      await mutate({ url: relationEndpoint(resource.endpoints.delete, key), method: 'delete' })
      onChanged?.('delete', selected)
    }
    if (action.name === detachAction.name) {
      await mutate({ url: relationEndpoint(resource.endpoints.detach, key), method: 'delete' })
      onChanged?.('detach', selected)
    }
    if (action.name === dissociateAction.name && resource.endpoints.dissociate) {
      await mutate({ url: relationEndpoint(resource.endpoints.dissociate, key), method: 'delete' })
      onChanged?.('dissociate', selected)
    }
  }

  const submit = async (data: Record<string, unknown>) => {
    if (!dialogForm?.action || !mode) return
    setProcessing(true)
    setErrors({})
    try {
      if (mode === 'attach' || mode === 'associate') {
        const key = data.record
        if ((typeof key !== 'string' && typeof key !== 'number') || !resource.endpoints) {
          setErrors({ record: `Choose a record to ${mode}.` })
          return
        }
        const endpoint = mode === 'attach' ? resource.endpoints.attach : resource.endpoints.associate
        if (!endpoint) return
        const result = await mutate({
          url: relationEndpoint(endpoint, key),
          method: 'post',
          data,
        })
        setMode(null)
        onChanged?.(mode, result?.record ?? null)
        return
      }
      const result = await mutate({
        url: dialogForm.action,
        method: mode === 'create' ? 'post' : 'patch',
        data,
      })
      setMode(null)
      onChanged?.(mode, result?.record ?? record)
    } catch (error) {
      if (error instanceof RelationMutationError) {
        setErrors(Object.fromEntries(
          Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? 'The field is invalid.']),
        ))
        return
      }
      throw error
    } finally {
      setProcessing(false)
    }
  }

  return <section
    aria-labelledby={`inlay-relation-${resource.name}`}
    className={`text-(--inlay-text) ${className}`.trim()}
    data-contract={resource.contract}
    data-slot="relation-manager"
  >
    <header className="flex items-center justify-between gap-4 border-b border-(--inlay-border) pb-4">
      <div className="min-w-0">
        {grouped
          ? <h3 className="text-lg font-semibold tracking-tight" id={`inlay-relation-${resource.name}`}>{resource.title}</h3>
          : <h2 className="text-xl font-semibold tracking-tight" id={`inlay-relation-${resource.name}`}>{resource.title}</h2>}
        <p className="text-base/7 text-(--inlay-muted) sm:text-sm/6">
          {resource.readOnly ? 'Related records are available for review.' : 'Manage records connected to this item.'}
        </p>
      </div>
      <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
        {resource.capabilities.associate && resource.associateForm && resource.endpoints?.associate ? <button
          className={headerActionClass}
          onClick={() => open('associate')}
          type="button"
        >
          Associate
        </button> : null}
        {resource.capabilities.attach && resource.attachForm && resource.endpoints ? <button
          className={headerActionClass}
          onClick={() => open('attach')}
          type="button"
        >
          Attach
        </button> : null}
        {resource.capabilities.create && resource.createForm && resource.endpoints ? <button
          className={headerActionClass}
          onClick={() => open('create')}
          type="button"
        >
          Create
        </button> : null}
      </div>
    </header>
    <div className="pt-5">
      {resource.table.rows.length === 0 && empty ? empty : <Table onAction={execute} onQueryChange={onQueryChange} renderers={icons ? { icon: icons } : undefined} resource={table} />}
    </div>
    {mode && dialogForm ? <RelationDialog
      errors={errors}
      form={dialogForm}
      heading={`${mode === 'create' ? 'Create' : mode === 'edit' ? 'Edit' : mode === 'attach' ? 'Attach' : 'Associate'} ${singular(resource.title)}`}
      icons={icons}
      name={resource.name}
      onClose={() => setMode(null)}
      onSubmit={submit}
      processing={processing}
    /> : null}
  </section>
}

function singular(title: string): string {
  return title.endsWith('s') ? title.slice(0, -1) : title
}
