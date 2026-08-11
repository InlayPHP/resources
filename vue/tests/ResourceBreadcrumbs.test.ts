import { cleanup, render } from '@testing-library/vue'
import { afterEach, describe, expect, it } from 'vitest'
import { ResourceBreadcrumbs } from '../src'

afterEach(cleanup)

describe('Vue ResourceBreadcrumbs', () => {
  it('links the steps a visitor can reach and marks the current page', () => {
    const view = render(ResourceBreadcrumbs, { props: { breadcrumbs: [
      { label: 'Users', url: '/admin/users' },
      { label: 'Ada', url: '/admin/users/1/edit' },
      { label: 'Edit', url: null },
    ] } })

    expect(view.getByRole('link', { name: 'Users' }).getAttribute('href')).toBe('/admin/users')
    expect(view.getByRole('link', { name: 'Ada' })).toBeTruthy()
    // The last step is where the visitor already is, so it is not a link.
    expect(view.queryByRole('link', { name: 'Edit' })).toBeNull()
    expect(view.getByText('Edit').getAttribute('aria-current')).toBe('page')
  })

  it('renders nothing without a trail and never links a step with no URL', () => {
    const empty = render(ResourceBreadcrumbs, { props: { breadcrumbs: [] } })
    expect(empty.container.querySelector('[data-slot="resource-breadcrumbs"]')).toBeNull()
    empty.unmount()

    const view = render(ResourceBreadcrumbs, { props: { breadcrumbs: [{ label: 'Users', url: null }, { label: 'Create', url: null }] } })
    expect(view.queryByRole('link')).toBeNull()
  })
})
