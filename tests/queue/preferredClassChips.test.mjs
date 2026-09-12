import assert from 'node:assert/strict';
import { test } from 'node:test';
import { preferredClassChips } from '../../resources/js/utils/preferredClassChips.ts';

const roles = ['tank', 'healer', 'melee dps', 'physical ranged dps', 'magic ranged dps'];
const items = roles.map((role, i) => ({ label: `Class ${i}`, role, flat_icon_url: `/class-${i}.png` }));

test('sorts classes by role without mutating the selection', () => {
    const selection = [...items].reverse();
    assert.deepEqual(preferredClassChips(selection).map(item => item.label), items.map(item => item.label));
    assert.equal(selection[0].role, 'magic ranged dps');
});

test('uses the requested five border colors', () => {
    assert.deepEqual(preferredClassChips(items).map(item => item.border), [
        'ring-blue-500/70', 'ring-green-500/70', 'ring-red-500/70', 'ring-yellow-500/70', 'ring-purple-500/70',
    ]);
});

test('collapses complete roles into a single chip with the role icon and original names', () => {
    const tanks = ['Paladin', 'Warrior', 'Dark Knight', 'Gunbreaker'].map(label => ({ label, role: 'tank' }));
    const chips = preferredClassChips([...items.slice(1), ...tanks], ['tank']);
    assert.equal(chips.length, 5);
    assert.equal(chips[0].omniKey, 'tank');
    assert.equal(chips[0].icon, '/role-icons/tank.png');
    assert.deepEqual(chips[0].classNames, tanks.map(item => item.label));
});

test('uses the individual role icons for all five Omni roles', () => {
    assert.deepEqual(preferredClassChips(items, roles).map(item => item.icon), [
        '/role-icons/tank.png', '/role-icons/healer.png', '/role-icons/melee_dps.png',
        '/role-icons/physrange_dps.png', '/role-icons/magic_range_dps.png',
    ]);
});

test('does not infer completeness from selected counts and preserves unknown options', () => {
    const selection = [...items, { label: 'Any', role: null }, { label: 'Custom', role: 'custom' }];
    const chips = preferredClassChips(selection);
    assert.equal(chips.length, 7);
    assert.ok(chips.every(chip => chip.omniKey === null));
    assert.equal(chips.at(-2).label, 'Any');
    assert.deepEqual(preferredClassChips([], roles), []);
});
