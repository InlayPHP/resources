<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import RelationManager from './RelationManager.vue'
import type { RelationManagerResource } from './types'

const props = defineProps<{ resources: RelationManagerResource[] }>()
const emit = defineEmits<{ changed: [operation: 'create' | 'edit' | 'delete' | 'restore' | 'force-delete' | 'attach' | 'detach' | 'associate' | 'dissociate', record: Record<string, unknown> | null]; queryChange: [name: string, query: unknown] }>()
type Group = NonNullable<RelationManagerResource['group']>
type Entry =
  | { type: 'relation'; resource: RelationManagerResource }
  | { type: 'group'; group: Group; resources: RelationManagerResource[] }
const active = ref<Record<string, string>>({})
const entries = computed<Entry[]>(() => {
  const resolved: Entry[] = []
  const grouped = new Set<string>()
  for (const resource of props.resources) {
    if (!resource.group) {
      resolved.push({ type: 'relation', resource })
      continue
    }
    if (grouped.has(resource.group.id)) continue
    grouped.add(resource.group.id)
    resolved.push({
      type: 'group',
      group: resource.group,
      resources: props.resources.filter(candidate => candidate.group?.id === resource.group?.id),
    })
  }
  return resolved
})
function current(entry: Extract<Entry, { type: 'group' }>): RelationManagerResource {
  const requested = active.value[entry.group.id] ?? entry.group.defaultRelation
  return entry.resources.find(resource => resource.name === requested) ?? entry.resources[0]
}
async function navigateTabs(event: KeyboardEvent, entry: Extract<Entry, { type: 'group' }>) {
  const selected = current(entry)
  const index = entry.resources.findIndex(resource => resource.name === selected.name)
  const target = event.key === 'Home'
    ? 0
    : event.key === 'End'
      ? entry.resources.length - 1
      : event.key === 'ArrowRight'
        ? (index + 1) % entry.resources.length
        : event.key === 'ArrowLeft'
          ? (index - 1 + entry.resources.length) % entry.resources.length
          : null
  if (target === null) return
  event.preventDefault()
  const name = entry.resources[target].name
  active.value[entry.group.id] = name
  await nextTick()
  document.getElementById(`inlay-relation-group-${entry.group.id}-tab-${name}`)?.focus()
}
</script>

<template>
  <div class="grid gap-10" data-slot="relation-managers">
    <template v-for="entry in entries" :key="entry.type === 'relation' ? entry.resource.name : entry.group.id">
      <RelationManager v-if="entry.type === 'relation'" :resource="entry.resource" @changed="(operation, record) => emit('changed', operation, record)" @query-change="query => emit('queryChange', entry.resource.name, query)" />
      <section
        v-else
        :aria-labelledby="`inlay-relation-group-${entry.group.id}`"
        :class="entry.group.contained ? 'rounded-(--inlay-radius) border border-(--inlay-border) bg-(--inlay-surface) p-4 sm:p-6' : ''"
        :data-icon="entry.group.icon || undefined"
        data-slot="relation-group"
      >
        <header class="mb-5">
          <h2 :id="`inlay-relation-group-${entry.group.id}`" class="text-xl font-semibold tracking-tight text-(--inlay-text)">{{ entry.group.label }}</h2>
          <p v-if="entry.group.description" class="mt-1 text-base/7 text-(--inlay-muted) sm:text-sm/6">{{ entry.group.description }}</p>
        </header>
        <div :aria-label="`${entry.group.label} relations`" class="mb-5 flex gap-1 overflow-x-auto border-b border-(--inlay-border)" role="tablist">
          <button
            v-for="resource in entry.resources"
            :id="`inlay-relation-group-${entry.group.id}-tab-${resource.name}`"
            :key="resource.name"
            :aria-controls="`inlay-relation-group-${entry.group.id}-panel`"
            :aria-selected="resource.name === current(entry).name"
            class="relative min-h-10 shrink-0 px-3 py-2 text-sm font-semibold text-(--inlay-muted) outline-none transition-colors hover:text-(--inlay-text) aria-selected:text-(--inlay-accent) aria-selected:after:absolute aria-selected:after:inset-x-2 aria-selected:after:bottom-0 aria-selected:after:h-0.5 aria-selected:after:rounded-full aria-selected:after:bg-(--inlay-accent) focus-visible:ring-2 focus-visible:ring-(--inlay-accent)"
            role="tab"
            :tabindex="resource.name === current(entry).name ? 0 : -1"
            type="button"
            @click="active[entry.group.id] = resource.name"
            @keydown="navigateTabs($event, entry)"
          >{{ resource.title }}</button>
        </div>
        <div
          :id="`inlay-relation-group-${entry.group.id}-panel`"
          :aria-labelledby="`inlay-relation-group-${entry.group.id}-tab-${current(entry).name}`"
          role="tabpanel"
        >
          <RelationManager
            grouped
            :resource="current(entry)"
            @changed="(operation, record) => emit('changed', operation, record)"
            @query-change="query => emit('queryChange', current(entry).name, query)"
          />
        </div>
      </section>
    </template>
  </div>
</template>
