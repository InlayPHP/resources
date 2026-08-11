import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { ResourceBreadcrumbs } from '../src'

afterEach(cleanup)

describe('ResourceBreadcrumbs', () => {
  it('links the steps a visitor can reach and marks the current page', () => {
    render(<ResourceBreadcrumbs breadcrumbs={[
      { label: 'Users', url: '/admin/users' },
      { label: 'Ada', url: '/admin/users/1/edit' },
      { label: 'Edit', url: null },
    ]} />)

    expect(screen.getByRole('link', { name: 'Users' })).toHaveAttribute('href', '/admin/users')
    expect(screen.getByRole('link', { name: 'Ada' })).toBeInTheDocument()
    // The last step is where the visitor already is, so it is not a link.
    expect(screen.queryByRole('link', { name: 'Edit' })).not.toBeInTheDocument()
    expect(screen.getByText('Edit')).toHaveAttribute('aria-current', 'page')
  })

  it('renders nothing without a trail and never links a step with no URL', () => {
    const { container, rerender } = render(<ResourceBreadcrumbs breadcrumbs={[]} />)
    expect(container).toBeEmptyDOMElement()

    rerender(<ResourceBreadcrumbs breadcrumbs={[{ label: 'Users', url: null }, { label: 'Create', url: null }]} />)
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })
})
