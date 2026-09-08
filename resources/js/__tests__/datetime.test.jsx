import { describe, it, expect } from 'vitest';
import { msUntilMidnightTz } from '@/Utils/datetime';

/**
 * The day divider in the inbox decides "today" once, when the page mounts. This
 * is what tells it when to ask again — and it has to ask on the user's own
 * midnight, which is not the browser's.
 */
describe('msUntilMidnightTz', () => {
    // 2026-09-08 21:04 UTC. Bucharest is +03 in September, so it is already
    // 00:04 on the 9th there while London still says 22:04 on the 8th.
    const at = Date.parse('2026-09-08T21:04:00Z');
    const HOUR = 3600 * 1000;

    it('measures to midnight in the given zone, not the browser one', () => {
        const bucharest = msUntilMidnightTz('Europe/Bucharest', at);
        const london = msUntilMidnightTz('Europe/London', at);

        // Bucharest has just rolled over, so it has nearly a full day to run.
        expect(bucharest / HOUR).toBeGreaterThan(23.9);
        // London is two hours short of its own midnight.
        expect(london / HOUR).toBeGreaterThan(1.9);
        expect(london / HOUR).toBeLessThan(2.1);
    });

    it('never returns zero, so a timer cannot fire in a loop on the boundary', () => {
        const exactly = msUntilMidnightTz('UTC', Date.parse('2026-09-09T00:00:00Z'));

        expect(exactly).toBeGreaterThan(0);
        expect(exactly / HOUR).toBeGreaterThan(23.9);
    });

    it('lands after the boundary rather than on it', () => {
        const oneSecondBefore = msUntilMidnightTz('UTC', Date.parse('2026-09-08T23:59:59Z'));

        // A timer that fires a hair early must still see the new day.
        expect(oneSecondBefore).toBeGreaterThan(1000);
        expect(oneSecondBefore).toBeLessThan(4000);
    });

    it('falls back to UTC when no zone is given', () => {
        expect(msUntilMidnightTz(null, Date.parse('2026-09-08T22:00:00Z')) / HOUR).toBeCloseTo(2, 1);
    });
});
