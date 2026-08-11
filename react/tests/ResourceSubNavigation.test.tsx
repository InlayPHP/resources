import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { ResourceSubNavigation } from '../src'

afterEach(cleanup)

const items = [
  { name: 'view', label: 'Overview', url: '/admin/users/1', active: false },
  { name: 'edit', label: 'Edit', url: '/admin/users/1/edit', active: true },
]

describe('ResourceSubNavigation', () => {
  it('links the sibling pages and marks the one being viewed', () => {
    render(<ResourceSubNavigation items={items} />)

    expect(screen.getByRole('navigation', { name: 'Record pages' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Overview' })).toHaveAttribute('href', '/admin/users/1')
    // The active page is where the visitor already is, so it is not a link.
    expect(screen.queryByRole('link', { name: 'Edit' })).not.toBeInTheDocument()
    expect(screen.getByText('Edit')).toHaveAttribute('aria-current', 'page')
  })

  it('renders nothing when PHP offered no pages', () => {
    const { container } = render(<ResourceSubNavigation items={[]} />)
    expect(container).toBeEmptyDOMElement()
  })

  it('shows only what it was given, so a withheld page stays withheld', () => {
    render(<ResourceSubNavigation items={[items[0]!]} label="Note pages" />)

    expect(screen.getByRole('navigation', { name: 'Note pages' })).toBeInTheDocument()
    expect(screen.getAllByRole('listitem')).toHaveLength(1)
    expect(screen.queryByText('Edit')).not.toBeInTheDocument()
  })
})
