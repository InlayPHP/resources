import { cleanup, render } from '@testing-library/vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import type { ActionExecutor, ActionResource } from '@inlayphp/actions'
import type { WidgetDashboardResource } from '@inlayphp/widgets-vue'
import ResourcePage from '../src/ResourcePage.vue'

afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

const widgets = (name: string, label: string, pollingInterval: number | null = null): WidgetDashboardResource => ({
  contract: 'inlay.widget-dashboard.v1',
  columns: 1,
  widgets: [{
    contract: 'inlay.widgets.v1',
    type: 'stats-overview',
    name,
    label: null,
    description: null,
    columnSpan: 'full',
    sort: 0,
    visible: true,
    pollingInterval,
    lazy: false,
    headerActions: [],
    footerActions: [],
    columns: 1,
    stats: [{ label, value: '42', description: null, icon: null, color: 'primary', url: null, trend: null, chart: [] }],
  }],
})

describe('Vue ResourcePage', () => {
  it('composes server-authored chrome around host slots', () => {
    const view = render(ResourcePage, {
      props: {
        breadcrumbs: [{ label: 'Users', url: '/users' }, { label: 'Ada', url: null }],
        heading: 'Ada Lovelace',
        subNavigation: [{ name: 'overview', label: 'Overview', url: '/users/1', active: true }],
        subheading: 'Account details',
      },
      slots: {
        actions: () => h('button', { type: 'button' }, 'Create'),
        before: () => h('p', 'Before'),
        default: () => h('p', 'Form content'),
        after: () => h('p', 'After'),
      },
    })

    expect(view.getByRole('heading', { name: 'Ada Lovelace' })).toBeTruthy()
    expect(view.getByRole('button', { name: 'Create' })).toBeTruthy()
    expect(view.getByText('Before')).toBeTruthy()
    expect(view.getByText('Form content')).toBeTruthy()
    expect(view.getByText('After')).toBeTruthy()
    expect(view.container.querySelector('[data-slot="resource-page"]')).toBeTruthy()
  })

  it('emits a server-driven tab selection', async () => {
    const view = render(ResourcePage, {
      props: {
        heading: 'Users',
        tabs: { active: 'all', items: [{ name: 'all', label: 'All' }, { name: 'admins', label: 'Admins', badge: 2 }] },
      },
    })

    await view.getByRole('tab', { name: 'Admins 2' }).click()
    expect(view.emitted('tabSelect')?.[0]).toEqual(['admins'])
  })

  it('renders PHP-resolved header and footer widget dashboards', () => {
    const view = render(ResourcePage, {
      props: {
        footerWidgets: widgets('footer-summary', 'Footer revenue'),
        headerWidgets: widgets('header-summary', 'Header revenue'),
        heading: 'Users',
        widgetProps: { classNames: { root: 'page-widgets' }, theme: { accent: '#123456' } },
      },
      slots: { default: () => h('p', 'Resource content') },
    })

    const header = view.container.querySelector('[data-slot="resource-page-header-widgets"]')
    const footer = view.container.querySelector('[data-slot="resource-page-footer-widgets"]')
    expect(header).toBeTruthy()
    expect(footer).toBeTruthy()
    expect(header?.querySelector('[data-slot="widget-dashboard"]')).toBeTruthy()
    expect(footer?.querySelector('[data-slot="widget-dashboard"]')).toBeTruthy()
    expect(header?.querySelector('[data-slot="widget-dashboard"]')).toHaveClass('page-widgets')
    expect(header?.querySelector('[data-slot="widget-dashboard"]')).toHaveStyle({ '--inlay-widget-accent': '#123456' })
    expect(view.getByText('Header revenue')).toBeTruthy()
    expect(view.getByText('Footer revenue')).toBeTruthy()
    expect(view.getByText('Resource content')).toBeTruthy()
  })

  it('forwards lazy and polling refresh events to widgetProps.onRefresh', () => {
    vi.useFakeTimers()
    const onRefresh = vi.fn()
    render(ResourcePage, {
      props: {
        footerWidgets: widgets('footer-summary', 'Footer revenue', 1),
        headerWidgets: widgets('header-summary', 'Header revenue', 1),
        heading: 'Users',
        widgetProps: { onRefresh },
      },
    })

    vi.advanceTimersByTime(1000)
    expect(onRefresh.mock.calls).toEqual([['header-summary'], ['footer-summary']])
  })

  it('does not add widget regions when PHP sends no widget dashboard', () => {
    const view = render(ResourcePage, { props: { heading: 'Users' } })

    expect(view.container.querySelector('[data-slot="resource-page-header-widgets"]')).toBeNull()
    expect(view.container.querySelector('[data-slot="resource-page-footer-widgets"]')).toBeNull()
  })

  it('renders PHP header actions and executes them through the shared action runtime', async () => {
    const action: ActionResource = {
      name: 'archive',
      label: 'Archive',
      url: '/users/archive',
      method: 'post',
      color: 'danger',
      requiresConfirmation: false,
      icon: null,
      modalHeading: null,
    }
    const executor = vi.fn(async (_context: Parameters<ActionExecutor>[0]) => ({ ok: true }))
    const view = render(ResourcePage, {
      props: {
        actionExecutor: executor,
        actionInput: { data: { source: 'resource-page' }, parameters: { tab: 'all' }, records: [{ id: 7 }] },
        headerActions: [action],
        heading: 'Users',
      },
      slots: { actions: () => h('button', { type: 'button' }, 'Custom') },
    })

    expect(view.getByRole('button', { name: 'Archive' })).toBeTruthy()
    expect(view.getByRole('button', { name: 'Custom' })).toBeTruthy()
    expect(view.container.querySelector('[data-slot="header-actions"]')).toBeTruthy()

    await view.getByRole('button', { name: 'Archive' }).click()
    await vi.waitFor(() => expect(executor).toHaveBeenCalledOnce())
    expect(executor.mock.calls[0]?.[0]).toMatchObject({
      action: { name: 'archive' },
      input: { data: { source: 'resource-page' }, parameters: { tab: 'all' }, records: [{ id: 7 }] },
      url: '/users/archive',
    })
  })
})
