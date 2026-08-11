import { cleanup, render } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { ResourceTabs } from '../src'

afterEach(cleanup)

const tabs = { active: 'ada', items: [
  { name: 'all', label: 'Everyone', badge: 3 },
  { name: 'ada', label: 'Ada', badge: null },
] }

describe('Vue ResourceTabs', () => {
  it('reports the chosen tab and keeps roving focus', async () => {
    const view = render(ResourceTabs, { props: { tabs } })

    const everyone = view.getByRole('tab', { name: /Everyone/ })
    const ada = view.getByRole('tab', { name: 'Ada' })

    expect(ada.getAttribute('aria-selected')).toBe('true')
    expect(everyone.getAttribute('aria-selected')).toBe('false')
    // Only the active tab is reachable by Tab; the rest by arrow keys.
    expect(ada.getAttribute('tabindex')).toBe('0')
    expect(everyone.getAttribute('tabindex')).toBe('-1')
    expect(everyone.textContent).toContain('3')
    expect(ada.querySelector('[data-slot="resource-tab-badge"]')).toBeNull()

    await userEvent.click(everyone)
    expect(view.emitted('select')?.at(-1)).toEqual(['all'])

    // Arrow keys move along the list and wrap at both ends.
    ada.focus()
    await userEvent.keyboard('{ArrowRight}')
    expect(view.emitted('select')?.at(-1)).toEqual(['all'])

    cleanup()
    const wrapped = render(ResourceTabs, { props: { tabs: { ...tabs, active: 'all' } } })
    wrapped.getByRole('tab', { name: /Everyone/ }).focus()
    await userEvent.keyboard('{ArrowRight}')
    expect(wrapped.emitted('select')?.at(-1)).toEqual(['ada'])

    await userEvent.keyboard('{ArrowLeft}')
    expect(wrapped.emitted('select')?.at(-1)).toEqual(['ada'])
  })
})
