import assert from 'node:assert/strict';
import { test } from 'node:test';
import { memberNotePresentation, memberNoteIndicator, memberNoteSeverityDots } from '../../resources/js/utils/memberNotePresentation.ts';

test('commendations have a star and positive green styling', () => {
    const style = memberNotePresentation('commendation');
    assert.equal(style.icon, 'i-lucide-star');
    assert.equal(style.color, 'success');
    assert.equal(style.borderClass, 'border-r-2 border-r-success');
});

test('dots represent distinct severities in a stable order, including info', () => {
    assert.deepEqual(memberNoteSeverityDots(['warning', 'info', 'critical', 'info', 'warning', 'warning']), [
        { severity: 'info', color: 'info' },
        { severity: 'warning', color: 'warning' },
        { severity: 'critical', color: 'error' },
    ]);
    assert.deepEqual(memberNoteSeverityDots(['commendation', 'commendation']), [{ severity: 'commendation', color: 'success' }]);
    assert.deepEqual(memberNoteSeverityDots([]), []);
});

test('existing note types retain their colors and icons', () => {
    assert.equal(memberNotePresentation('info').color, 'info');
    assert.equal(memberNotePresentation('warning').icon, 'i-lucide-triangle-alert');
    assert.equal(memberNotePresentation('critical').color, 'error');
});

test('button indicators use matching colors and dots without added borders, with no indicator for info', () => {
    assert.deepEqual(memberNoteIndicator('commendation'), { color: 'success', dot: 'bg-success' });
    assert.deepEqual(memberNoteIndicator('warning'), { color: 'warning', dot: 'bg-warning' });
    assert.deepEqual(memberNoteIndicator('critical'), { color: 'error', dot: 'bg-error' });
    for (const severity of ['info', null, undefined]) assert.equal(memberNoteIndicator(severity), null);
});
