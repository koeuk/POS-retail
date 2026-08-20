import type { NavGroup, NavItem } from '@/types';
import {
    Boxes,
    ChartNoAxesColumn,
    LayoutGrid,
    MoreHorizontal,
    ScanBarcode,
    Shapes,
    Store,
    Users,
    UsersRound,
} from 'lucide-vue-next';

/**
 * One nav definition, two chromes. The desktop sidebar renders the groups and
 * the phone tab bar renders the first few items plus a "More" sheet — if the
 * two kept separate lists they would drift the first time a screen was added.
 */
export const navGroups: NavGroup[] = [
    {
        label: 'Selling',
        items: [
            { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
            { title: 'Point of Sale', href: '/pos', icon: ScanBarcode },
            { title: 'Reports', href: '/reports', icon: ChartNoAxesColumn, requires: 'manage' },
        ],
    },
    {
        label: 'Catalogue',
        items: [
            { title: 'Products', href: '/products', icon: Boxes, requires: 'manage' },
            { title: 'Categories', href: '/categories', icon: Shapes, requires: 'manage' },
        ],
    },
    {
        label: 'People',
        items: [
            { title: 'Customers', href: '/customers', icon: UsersRound, requires: 'manage' },
            { title: 'Staff', href: '/users', icon: Users, requires: 'isAdmin' },
            { title: 'Stores', href: '/stores', icon: Store, requires: 'manage' },
        ],
    },
];

type Can = { manage: boolean; isAdmin: boolean; accessAdmin: boolean };

const permitted = (item: NavItem, can: Can) => !item.requires || can[item.requires];

/** Groups the user can see something in, with the forbidden items removed. */
export function visibleGroups(can: Can): NavGroup[] {
    return navGroups
        .map((group) => ({ ...group, items: group.items.filter((item) => permitted(item, can)) }))
        .filter((group) => group.items.length > 0);
}

/** Flat list, group order preserved. */
export function visibleItems(can: Can): NavItem[] {
    return visibleGroups(can).flatMap((group) => group.items);
}

/**
 * The tab bar holds four slots. Three go to destinations and the fourth is
 * always "More" — a fixed last tab means the thumb learns one position, and
 * it is the only way the remaining screens stay reachable on a phone.
 *
 * A cashier sees only Dashboard and POS, so for them "More" is just the
 * account sheet and the bar is three tabs wide.
 */
export const TAB_SLOTS = 3;

export const moreTab = { title: 'More', href: '#more', icon: MoreHorizontal } as const;

export function tabItems(can: Can): NavItem[] {
    return visibleItems(can).slice(0, TAB_SLOTS);
}

/** Everything the tab bar could not fit — the contents of the More sheet. */
export function overflowItems(can: Can): NavItem[] {
    return visibleItems(can).slice(TAB_SLOTS);
}

/** `/products` must light up on `/products/4/edit`, but `/` must not swallow all. */
export function isActivePath(href: string, path: string): boolean {
    return path === href || path.startsWith(`${href}/`);
}
