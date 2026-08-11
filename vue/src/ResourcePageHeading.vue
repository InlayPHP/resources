<script setup lang="ts">
/**
 * The heading a resource page declared in PHP.
 *
 * A page about one record is named for that record, and PHP derives that from
 * the same `recordTitle()` the breadcrumb uses, so the two cannot disagree.
 * Actions are a slot rather than a prop the component resolves, because the
 * host already renders them and this should not become a second way to.
 */
withDefaults(defineProps<{ heading: string; subheading?: string | null; className?: string }>(), {
  subheading: null,
  className: '',
})
</script>

<template>
  <div :class="`flex flex-wrap items-start justify-between gap-4 ${className}`.trim()" data-slot="page-heading">
    <div class="min-w-0">
      <h1 class="text-xl font-semibold text-(--inlay-text)" data-slot="heading">{{ heading }}</h1>
      <p v-if="subheading" class="mt-1 text-sm text-(--inlay-muted)" data-slot="subheading">{{ subheading }}</p>
    </div>
    <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2" data-slot="header-actions"><slot name="actions" /></div>
  </div>
</template>
