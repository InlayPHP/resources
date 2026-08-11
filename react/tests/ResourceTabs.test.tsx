import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ResourceTabs } from '../src'

afterEach(cleanup)

const tabs = { active: 'ada', items: [
  { name: 'all', label: 'Everyone', badge: 3 },
  { name: 'ada', label: 'Ada', badge: null },
] }

describe('ResourceTabs', () => {
  it('reports the chosen tab and keeps roving focus', async () => {
    const onSelect = vi.fn()
    render(<ResourceTabs onSelect={onSelect} tabs={tabs} />)

    const everyone = screen.getByRole('tab', { name: /Everyone/ })
    const ada = screen.getByRole('tab', { name: 'Ada' })

    expect(ada).toHaveAttribute('aria-selected', 'true')
    expect(everyone).toHaveAttribute('aria-selected', 'false')
    // Only the active tab is reachable by Tab; the rest by arrow keys.
    expect(ada).toHaveAttribute('tabindex', '0')
    expect(everyone).toHaveAttribute('tabindex', '-1')
    expect(everyone).toHaveTextContent('3')
    expect(screen.getByRole('tab', { name: 'Ada' }).querySelector('[data-slot="resource-tab-badge"]')).toBeNull()

    await userEvent.click(everyone)
    expect(onSelect).toHaveBeenLastCalledWith('all')

    // Arrow keys move along the list and wrap at both ends.
    ada.focus()
    await userEvent.keyboard('{ArrowRight}')
    expect(onSelect).toHaveBeenLastCalledWith('all')

    cleanup()
    render(<ResourceTabs onSelect={onSelect} tabs={{ ...tabs, active: 'all' }} />)
    screen.getByRole('tab', { name: /Everyone/ }).focus()
    await userEvent.keyboard('{ArrowRight}')
    expect(onSelect).toHaveBeenLastCalledWith('ada')

    await userEvent.keyboard('{ArrowLeft}')
    expect(onSelect).toHaveBeenLastCalledWith('ada')
  })
})
