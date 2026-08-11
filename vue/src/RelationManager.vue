<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { buttonSecondaryClass, iconButtonClass } from '@inlayphp/ui-vue'
import { executeActionEndpoint, interpolateActionUrl, normalizeAction } from '@inlayphp/actions'
import type { ActionExecutionContext } from '@inlayphp/actions'
import { mutateRelation, RelationMutationError, relationEndpoint } from '@inlayphp/resources'
import type { RelationMutationRequest, RelationMutationResponse } from '@inlayphp/resources'
import { Form } from '@inlayphp/forms-vue'
import type { FormErrors, FormResource } from '@inlayphp/forms-vue'
import { Table } from '@inlayphp/tables-vue'
import type { Action, QueryState, TableActionSelection, TableRow } from '@inlayphp/tables-vue'
import type { RelationManagerResource } from './types'

const props = withDefaults(defineProps<{
  resource: RelationManagerResource
  mutate?: (request: RelationMutationRequest) => Promise<RelationMutationResponse | null>
  className?: string
  grouped?: boolean
}>(), { mutate: mutateRelation, className: '', grouped: false })
const emit = defineEmits<{ changed: [operation: 'create' | 'edit' | 'delete' | 'restore' | 'force-delete' | 'attach' | 'detach' | 'associate' | 'dissociate', record: Record<string, unknown> | null]; queryChange: [query: QueryState] }>()
const mode = ref<'create' | 'edit' | 'attach' | 'associate' | null>(null)
const record = ref<TableRow | null>(null)
const errors = ref<FormErrors>({})
const processing = ref(false)
const closeButton = ref<HTMLButtonElement | null>(null)
const editName = '__inlay_relation_edit'
const deleteName = '__inlay_relation_delete'
const detachName = '__inlay_relation_detach'
const dissociateName = '__inlay_relation_dissociate'
const headerActionClass = `${buttonSecondaryClass} font-semibold`
const closeActionClass = `${iconButtonClass} border-transparent bg-transparent text-(--inlay-muted) shadow-none hover:bg-(--inlay-surface-muted) hover:text-(--inlay-text)`
const primaryKey = computed(() => props.resource.table.primaryKey)
const table = computed(() => ({
  ...props.resource.table,
  actions: [
    ...props.resource.table.actions,
    ...(props.resource.capabilities.edit && props.resource.endpoints ? [{ ...action(editName, 'Edit'), visibleWhen: { path: 'deleted_at', operator: 'blank' as const, value: null } }] : []),
    ...(!props.resource.capabilities.softDeletes && props.resource.capabilities.delete && props.resource.endpoints ? [{ ...action(deleteName, 'Delete'), method: 'delete' as const, color: 'danger', requiresConfirmation: true, modalHeading: 'Delete this related record?' }] : []),
    ...(props.resource.capabilities.detach && props.resource.endpoints ? [{ ...action(detachName, 'Detach'), method: 'delete' as const, color: 'danger', requiresConfirmation: true, modalHeading: 'Detach this related record?', visibleWhen: { path: 'deleted_at', operator: 'blank' as const, value: null } }] : []),
    ...(props.resource.capabilities.dissociate && props.resource.endpoints?.dissociate ? [{ ...action(dissociateName, 'Dissociate'), method: 'delete' as const, color: 'danger', requiresConfirmation: true, modalHeading: 'Dissociate this related record?', visibleWhen: { path: 'deleted_at', operator: 'blank' as const, value: null } }] : []),
  ],
}))
const dialogForm = computed<FormResource | null>(() => {
  if (!mode.value) return null
  if (mode.value === 'attach') {
    if (!props.resource.attachForm || !props.resource.endpoints) return null
    return {
      ...props.resource.attachForm,
      name: `${props.resource.name}.attach`,
      action: props.resource.endpoints.attach,
      method: 'post',
      submitLabel: `Attach ${singular(props.resource.title)}`,
      data: props.resource.attachForm.data,
    }
  }
  if (mode.value === 'associate') {
    if (!props.resource.associateForm || !props.resource.endpoints?.associate) return null
    return {
      ...props.resource.associateForm,
      name: `${props.resource.name}.associate`,
      action: props.resource.endpoints.associate,
      method: 'post',
      submitLabel: `Associate ${singular(props.resource.title)}`,
      data: props.resource.associateForm.data,
    }
  }
  const baseForm = mode.value === 'edit'
    ? props.resource.editForm ?? props.resource.createForm
    : props.resource.createForm
  if (!baseForm) return null
  const key = record.value?.[primaryKey.value]
  return {
    ...baseForm,
    name: `${props.resource.name}.${mode.value}`,
    action: mode.value === 'create'
      ? props.resource.endpoints?.create ?? null
      : (typeof key === 'string' || typeof key === 'number') && props.resource.endpoints
        ? relationEndpoint(props.resource.endpoints.update, key)
        : null,
    method: mode.value === 'create' ? 'post' : 'patch',
    submitLabel: mode.value === 'create' ? `Create ${singular(props.resource.title)}` : `Save ${singular(props.resource.title)}`,
    data: mode.value === 'edit' ? record.value ?? {} : baseForm.data,
  }
})
watch(mode, async value => { if (value) { await nextTick(); closeButton.value?.focus() } })
function escape(event: KeyboardEvent) { if (event.key === 'Escape' && !processing.value) mode.value = null }
if (typeof document !== 'undefined') document.addEventListener('keydown', escape)
onBeforeUnmount(() => { if (typeof document !== 'undefined') document.removeEventListener('keydown', escape) })
function open(next: 'create' | 'edit' | 'attach' | 'associate', row: TableRow | null = null) { record.value = row; errors.value = {}; mode.value = next }
async function execute(selectedAction: Action, rows: TableRow[], context?: ActionExecutionContext, selection?: TableActionSelection) {
  const selected = rows[0]
  if (!props.resource.endpoints) return
  if (selectedAction.name === editName) { if (selected) open('edit', selected); return }
  if (selectedAction.lifecycle && selectedAction.url) {
    const normalized = normalizeAction(selectedAction)
    const resolved = context ?? {
      action: normalized,
      url: interpolateActionUrl(selectedAction.url, selected ?? {}),
      input: { parameters: selected ?? {}, records: rows, data: selectedAction.data ?? {} },
    }
    const result = await executeActionEndpoint({
      ...resolved,
      action: normalized,
      url: interpolateActionUrl(selectedAction.url, selected ?? {}),
      input: {
        ...resolved.input,
        records: rows,
        data: selectedAction.bulk
          ? selection?.mode === 'query'
            ? { selection }
            : { records: rows.map(row => row[primaryKey.value] as string | number) }
          : selectedAction.data ?? {},
      },
    })
    emit('changed', selectedAction.name === 'force-delete' ? 'force-delete' : selectedAction.name === 'restore' ? 'restore' : 'delete', selected ?? null)
    return result
  }
  if (selectedAction.url) {
    const url = interpolateActionUrl(selectedAction.url, selected ?? {})
    if (!url) return
    router.visit(url, {
      method: selectedAction.method,
      data: (selectedAction.data ?? {}) as never,
    })
    return
  }
  if (!selected) return
  const key = selected[primaryKey.value]
  if (typeof key !== 'string' && typeof key !== 'number') return
  if (selectedAction.name === deleteName) {
    await props.mutate({ url: relationEndpoint(props.resource.endpoints.delete, key), method: 'delete' })
    emit('changed', 'delete', selected)
  }
  if (selectedAction.name === detachName) {
    await props.mutate({ url: relationEndpoint(props.resource.endpoints.detach, key), method: 'delete' })
    emit('changed', 'detach', selected)
  }
  if (selectedAction.name === dissociateName && props.resource.endpoints.dissociate) {
    await props.mutate({ url: relationEndpoint(props.resource.endpoints.dissociate, key), method: 'delete' })
    emit('changed', 'dissociate', selected)
  }
}
async function submit(data: Record<string, unknown>) {
  if (!dialogForm.value?.action || !mode.value) return
  processing.value = true
  errors.value = {}
  try {
    const currentMode = mode.value
    if (currentMode === 'attach' || currentMode === 'associate') {
      const key = data.record
      if ((typeof key !== 'string' && typeof key !== 'number') || !props.resource.endpoints) {
        errors.value = { record: `Choose a record to ${currentMode}.` }
        return
      }
      const endpoint = currentMode === 'attach' ? props.resource.endpoints.attach : props.resource.endpoints.associate
      if (!endpoint) return
      const result = await props.mutate({
        url: relationEndpoint(endpoint, key),
        method: 'post',
        data,
      })
      mode.value = null
      emit('changed', currentMode, result?.record ?? null)
      return
    }
    const result = await props.mutate({ url: dialogForm.value.action, method: currentMode === 'create' ? 'post' : 'patch', data })
    mode.value = null
    emit('changed', currentMode, result?.record ?? record.value)
  } catch (error) {
    if (error instanceof RelationMutationError) {
      errors.value = Object.fromEntries(
        Object.entries(error.errors).map(([field, messages]) => [field, messages[0] ?? 'The field is invalid.']),
      )
      return
    }
    throw error
  } finally {
    processing.value = false
  }
}
function action(name: string, label: string): Action {
  return { name, label, url: null, method: 'get', color: 'default', requiresConfirmation: false, icon: null, modalHeading: null }
}
function singular(title: string): string { return title.endsWith('s') ? title.slice(0, -1) : title }
</script>

<template>
  <section :aria-labelledby="`inlay-relation-${resource.name}`" :class="['text-(--inlay-text)', className]" :data-contract="resource.contract" data-slot="relation-manager">
    <header class="flex items-center justify-between gap-4 border-b border-(--inlay-border) pb-4">
      <div class="min-w-0">
        <component :is="grouped ? 'h3' : 'h2'" :id="`inlay-relation-${resource.name}`" :class="grouped ? 'text-lg font-semibold tracking-tight' : 'text-xl font-semibold tracking-tight'">{{ resource.title }}</component>
        <p class="text-base/7 text-(--inlay-muted) sm:text-sm/6">{{ resource.readOnly ? 'Related records are available for review.' : 'Manage records connected to this item.' }}</p>
      </div>
      <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
        <button v-if="resource.capabilities.associate && resource.associateForm && resource.endpoints?.associate" :class="headerActionClass" type="button" @click="open('associate')">Associate</button>
        <button v-if="resource.capabilities.attach && resource.attachForm && resource.endpoints" :class="headerActionClass" type="button" @click="open('attach')">Attach</button>
        <button v-if="resource.capabilities.create && resource.createForm && resource.endpoints" :class="headerActionClass" type="button" @click="open('create')">Create</button>
      </div>
    </header>
    <div class="pt-5"><Table :action-executor="execute" :resource="table" @query-change="query => emit('queryChange', query)" /></div>
    <div v-if="mode && dialogForm" class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-(--inlay-overlay) p-4" data-slot="relation-dialog-backdrop" @mousedown.self="!processing && (mode = null)">
      <section :aria-labelledby="`inlay-relation-dialog-${resource.name}`" aria-modal="true" class="w-full max-w-xl rounded-(--inlay-radius) bg-(--inlay-surface) p-5 text-(--inlay-text) shadow-xl ring-1 ring-(--inlay-border) sm:p-6" role="dialog">
        <header class="flex items-start justify-between gap-4 border-b border-(--inlay-border) pb-4">
          <div><h3 :id="`inlay-relation-dialog-${resource.name}`" class="text-xl font-semibold tracking-tight">{{ `${mode === 'create' ? 'Create' : mode === 'edit' ? 'Edit' : mode === 'attach' ? 'Attach' : 'Associate'} ${singular(resource.title)}` }}</h3><p class="text-base/7 text-(--inlay-muted) sm:text-sm/6">Changes are validated and saved through the owner relationship.</p></div>
          <button ref="closeButton" aria-label="Close" :class="closeActionClass" :disabled="processing" type="button" @click="mode = null"><span aria-hidden="true">×</span></button>
        </header>
        <div class="pt-5"><Form :errors="errors" manual :processing="processing" :resource="dialogForm" @submit="submit" /></div>
      </section>
    </div>
  </section>
</template>
