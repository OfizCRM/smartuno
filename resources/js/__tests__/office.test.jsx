import { act, render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Office from '@/Pages/Documents/Office';

// The page is only ever rendered inside the client shell, and i18n is
// initialised by the app entry point, not by the test runner.
vi.mock('@/Layouts/ClientLayout', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key) => key }) }));

const DOC = { uuid: 'u1', name: 'Suport curs.docx' };

function open() {
    return render(<Office document={DOC} serverUrl="http://localhost:8080" config={{ editorConfig: { mode: 'edit' } }} />);
}

/**
 * Full screen is the one thing on this page the browser owns rather than React:
 * Escape and F11 leave without telling us, and the API is still prefixed in
 * Safari. Both are places the button and the page can silently disagree.
 */
describe('Office — full screen', () => {
    let requested;

    beforeEach(() => {
        requested = [];
        window.Element.prototype.requestFullscreen = function () {
            requested.push(this);

            return Promise.resolve();
        };
        window.document.exitFullscreen = vi.fn(() => Promise.resolve());
    });

    afterEach(() => {
        delete window.Element.prototype.requestFullscreen;
        delete window.document.exitFullscreen;
        Object.defineProperty(window.document, 'fullscreenElement', { value: null, configurable: true });
    });

    it('expands the whole panel, so the way out stays on the screen', () => {
        open();
        screen.getByRole('button', { name: 'office.fullscreen' }).click();

        expect(requested).toHaveLength(1);
        // Not just the editor: the header with the back arrow and the download
        // has to come along, or Escape is the only exit and nobody says so.
        expect(requested[0].querySelector('#onlyoffice-surface')).not.toBeNull();
        expect(requested[0].querySelector('a[href]')).not.toBeNull();
    });

    it('follows the browser when it leaves without the button', () => {
        const { container } = open();
        const shell = container.querySelector('#onlyoffice-surface').closest('div.flex-col');

        Object.defineProperty(window.document, 'fullscreenElement', { value: shell, configurable: true });
        // Not a React event, so the state update it causes is outside React's
        // own batching and has to be flushed by hand.
        act(() => window.document.dispatchEvent(new Event('fullscreenchange')));

        // Entered by F11 rather than by the button — the label still has to be
        // the one that gets you back out.
        expect(screen.getByRole('button', { name: 'office.exit_fullscreen' })).toBeInTheDocument();

        screen.getByRole('button', { name: 'office.exit_fullscreen' }).click();
        expect(window.document.exitFullscreen).toHaveBeenCalledOnce();
        expect(requested).toHaveLength(0);
    });

    it('leaves the page alone when the browser refuses', () => {
        window.Element.prototype.requestFullscreen = () => Promise.reject(new Error('refuzat'));
        open();

        expect(() => screen.getByRole('button', { name: 'office.fullscreen' }).click()).not.toThrow();
        expect(screen.getByRole('button', { name: 'office.fullscreen' })).toBeInTheDocument();
    });
});
