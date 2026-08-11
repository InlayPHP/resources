<script setup lang="ts">
import { ref } from 'vue'
import type { ResourceTabsResource } from './types'

/**
 * The named views a list page declared in PHP.
 *
 * The component only reports which tab was chosen; what a tab means, and which
 * records it shows, stays on the server.
 */
const props = withDefaults(defineProps<{ tabs: ResourceTabsResource; className?: string; label?: string }>(), {
  className: '',
  label: 'Views',
})
const emit = defineEmits<{ select: [tab: string] }>()
const buttons = ref<Record<string, HTMLButtonElement | null>>({})

function navigate(event: KeyboardEvent) {
  const offset = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0
  if (offset === 0) return
  event.preventDefault()
  const index = props.tabs.items.findIndex(item => item.name === props.tabs.active)
  const next = props.tabs.items[(index + offset + props.tabs.items.length) % props.tabs.items.length]
  if (!next) return
  emit('select', next.name)
  buttons.value[next.name]?.focus()
}
</script>

<template>
  <div
    :aria-label="label"
    :class="`flex gap-1 overflow-x-auto border-b border-(--inlay-border) ${className}`.trim()"
    data-slot="resource-tabs"
    role="tablist"
  >
    <button
      v-for="item in tabs.items"
      :key="item.name"
      :ref="(element) => { buttons[item.name] = element as HTMLButtonElement | null }"
      :aria-selected="item.name === tabs.active"
      class="relative min-h-10 shrink-0 px-3 py-2 text-sm font-semibold text-(--inlay-muted) transition-colors hover:text-(--inlay-text) aria-selected:text-(--inlay-accent)"
      data-slot="resource-tab"
      role="tab"
      :tabindex="item.name === tabs.active ? 0 : -1"
      type="button"
      @click="emit('select', item.name)"
      @keydown="navigate"
    >
      {{ item.label }}
      <span
        v-if="item.badge !== null && item.badge !== undefined"
        class="ml-2 rounded-full bg-(--inlay-surface-muted) px-2 py-0.5 text-xs"
        data-slot="resource-tab-badge"
      >{{ item.badge }}</span>
    </button>
  </div>
</template>
