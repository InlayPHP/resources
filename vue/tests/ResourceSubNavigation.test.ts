import { cleanup, render } from '@testing-library/vue'
import { afterEach, describe, expect, it } from 'vitest'
import { ResourceSubNavigation } from '../src'

afterEach(cleanup)

const items = [
  { name: 'view', label: 'Overview', url: '/admin/users/1', active: false },
  { name: 'edit', label: 'Edit', url: '/admin/users/1/edit', active: true },
]

describe('Vue ResourceSubNavigation', () => {
  it('links the sibling pages and marks the one being viewed', () => {
    const view = render(ResourceSubNavigation, { props: { items } })

    expect(view.getByRole('navigation', { name: 'Record pages' })).toBeTruthy()
    expect(view.getByRole('link', { name: 'Overview' }).getAttribute('href')).toBe('/admin/users/1')
    // The active page is where the visitor already is, so it is not a link.
    expect(view.queryByRole('link', { name: 'Edit' })).toBeNull()
    expect(view.getByText('Edit').getAttribute('aria-current')).toBe('page')
  })

  it('renders nothing when PHP offered no pages', () => {
    const view = render(ResourceSubNavigation, { props: { items: [] } })
    expect(view.container.querySelector('[data-slot="resource-sub-navigation"]')).toBeNull()
  })

  it('shows only what it was given, so a withheld page stays withheld', () => {
    const view = render(ResourceSubNavigation, { props: { items: [items[0]!], label: 'Note pages' } })

    expect(view.getByRole('navigation', { name: 'Note pages' })).toBeTruthy()
    expect(view.getAllByRole('listitem')).toHaveLength(1)
    expect(view.queryByText('Edit')).toBeNull()
  })
})
