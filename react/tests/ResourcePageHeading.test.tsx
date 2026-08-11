import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { ResourcePageHeading } from '../src'

afterEach(cleanup)

describe('ResourcePageHeading', () => {
  it('names the page and carries a subheading only when there is one', () => {
    const { container, rerender } = render(<ResourcePageHeading heading="People" />)

    expect(screen.getByRole('heading', { name: 'People', level: 1 })).toBeInTheDocument()
    expect(container.querySelector('[data-slot="subheading"]')).toBeNull()

    rerender(<ResourcePageHeading heading="Ada Lovelace" subheading="Created 3 days ago" />)
    expect(container.querySelector('[data-slot="subheading"]')?.textContent).toBe('Created 3 days ago')
  })

  it('renders actions in their own region, and none when the host gives none', () => {
    const { container, rerender } = render(<ResourcePageHeading heading="People" />)
    expect(container.querySelector('[data-slot="header-actions"]')).toBeNull()

    rerender(<ResourcePageHeading actions={<button type="button">New</button>} heading="People" />)
    expect(screen.getByRole('button', { name: 'New' })).toBeInTheDocument()
  })
})
