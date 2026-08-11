import { cleanup, render } from '@testing-library/vue'
import { afterEach, describe, expect, it } from 'vitest'
import { h } from 'vue'
import { ResourcePageHeading } from '../src'

afterEach(cleanup)

describe('Vue ResourcePageHeading', () => {
  it('names the page and carries a subheading only when there is one', () => {
    const plain = render(ResourcePageHeading, { props: { heading: 'People' } })
    expect(plain.getByRole('heading', { name: 'People', level: 1 })).toBeTruthy()
    expect(plain.container.querySelector('[data-slot="subheading"]')).toBeNull()
    plain.unmount()

    const withSub = render(ResourcePageHeading, { props: { heading: 'Ada Lovelace', subheading: 'Created 3 days ago' } })
    expect(withSub.container.querySelector('[data-slot="subheading"]')?.textContent).toBe('Created 3 days ago')
  })

  it('renders actions in their own region, and none when the host gives none', () => {
    const plain = render(ResourcePageHeading, { props: { heading: 'People' } })
    expect(plain.container.querySelector('[data-slot="header-actions"]')).toBeNull()
    plain.unmount()

    const view = render(ResourcePageHeading, { props: { heading: 'People' }, slots: { actions: () => h('button', { type: 'button' }, 'New') } })
    expect(view.getByRole('button', { name: 'New' })).toBeTruthy()
  })
})
