<script setup lang="ts">
import type { ResourceBreadcrumb } from './types'

/**
 * The trail a resource page published.
 *
 * PHP decides which steps exist and which of them are reachable; a step with
 * no URL renders as plain text rather than a link that leads nowhere.
 */
withDefaults(defineProps<{ breadcrumbs: ResourceBreadcrumb[]; className?: string; label?: string }>(), {
  className: '',
  label: 'Breadcrumb',
})
</script>

<template>
  <nav v-if="breadcrumbs.length" :aria-label="label" :class="className" data-slot="resource-breadcrumbs">
    <ol class="flex flex-wrap items-center gap-2 text-sm text-(--inlay-muted)">
      <li
        v-for="(crumb, index) in breadcrumbs"
        :key="`${crumb.label}:${index}`"
        class="flex items-center gap-2"
        data-slot="resource-breadcrumb"
      >
        <a v-if="crumb.url && index !== breadcrumbs.length - 1" class="hover:text-(--inlay-text)" :href="crumb.url">{{ crumb.label }}</a>
        <span v-else :aria-current="index === breadcrumbs.length - 1 ? 'page' : undefined">{{ crumb.label }}</span>
        <span v-if="index !== breadcrumbs.length - 1" aria-hidden="true">/</span>
      </li>
    </ol>
  </nav>
</template>
