<script setup lang="ts">
import type { ResourceSubNavigationItem } from './types'

/**
 * The sibling pages one record moves between.
 *
 * PHP decides which pages belong here and leaves out any the visitor is not
 * allowed to open, so the list is already the list they may follow. The page
 * being viewed is marked rather than linked back to itself.
 */
withDefaults(defineProps<{ items: ResourceSubNavigationItem[]; className?: string; label?: string }>(), {
  className: '',
  label: 'Record pages',
})
</script>

<template>
  <nav v-if="items.length" :aria-label="label" :class="className" data-slot="resource-sub-navigation">
    <ul class="flex flex-wrap gap-1 text-sm">
      <li v-for="item in items" :key="item.name" data-slot="resource-sub-navigation-item">
        <span
          v-if="item.active"
          aria-current="page"
          class="block rounded-md bg-(--inlay-surface-muted) px-3 py-2 font-semibold text-(--inlay-text)"
          data-active="true"
        >{{ item.label }}</span>
        <a
          v-else
          class="block rounded-md px-3 py-2 font-medium text-(--inlay-muted) transition-colors hover:bg-(--inlay-surface-muted) hover:text-(--inlay-text)"
          :href="item.url"
        >{{ item.label }}</a>
      </li>
    </ul>
  </nav>
</template>
