/**
 * PteroMods – DayZ Manager navigation tab.
 *
 * Adds a "DayZ Manager" entry to the panel navigation of DayZ servers:
 *   - the client area sub navigation on /server/{id}
 *   - the admin server tabs on /admin/servers/view/{id}
 *
 * The client area is a single page application, so the link is re-injected
 * whenever the navigation is re-rendered. The existing navigation entries are
 * cloned so the injected link always matches the active panel theme.
 */
(function () {
    'use strict';

    var LINK_ID = 'pteromods-dayz-tab';
    var LABEL = 'DayZ Manager';
    var support = {};

    function context() {
        var path = window.location.pathname;
        var admin = path.match(/^\/admin\/servers\/view\/(\d+)/);

        if (admin) {
            return { id: admin[1], admin: true, url: '/admin/servers/view/' + admin[1] + '/dayz' };
        }

        var client = path.match(/^\/server\/([^/]+)/);

        if (client) {
            return { id: client[1], admin: false, url: '/server/' + client[1] + '/dayz' };
        }

        return null;
    }

    function isSupported(id) {
        if (Object.prototype.hasOwnProperty.call(support, id)) {
            return Promise.resolve(support[id]);
        }

        return fetch('/api/server/' + encodeURIComponent(id) + '/dayz/tab', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(function (response) {
                return response.ok ? response.json() : { supported: false };
            })
            .then(function (data) {
                support[id] = !!(data && data.supported);
                return support[id];
            })
            .catch(function () {
                support[id] = false;
                return false;
            });
    }

    function buildLink(template, url) {
        var link = template.cloneNode(true);
        link.id = LINK_ID;
        link.setAttribute('href', url);
        link.removeAttribute('aria-current');
        link.className = template.className.replace(/\bactive\b/g, '');

        var labelled = link.querySelector('span, p, div');
        if (labelled) {
            labelled.textContent = LABEL;
            Array.prototype.slice.call(link.childNodes).forEach(function (node) {
                if (node.nodeType === Node.TEXT_NODE) {
                    node.textContent = '';
                }
            });
        } else {
            link.textContent = LABEL;
        }

        return link;
    }

    function injectClient(url) {
        if (document.getElementById(LINK_ID)) {
            return;
        }

        var template = document.querySelector('a[href$="/files"], a[href$="/settings"], a[href$="/network"]');

        if (!template || !template.parentNode) {
            return;
        }

        template.parentNode.insertBefore(buildLink(template, url), template.nextSibling);
    }

    function injectAdmin(url) {
        if (document.getElementById(LINK_ID)) {
            return;
        }

        var tabs = document.querySelector('ul.nav-tabs');

        if (!tabs) {
            return;
        }

        var items = tabs.querySelectorAll('li');

        if (!items.length) {
            return;
        }

        var item = items[items.length - 1].cloneNode(true);
        item.className = items[items.length - 1].className.replace(/\bactive\b/g, '');

        var anchor = item.querySelector('a');

        if (!anchor) {
            return;
        }

        anchor.id = LINK_ID;
        anchor.setAttribute('href', url);
        anchor.textContent = LABEL;
        tabs.appendChild(item);
    }

    function apply() {
        var current = context();

        if (!current) {
            return;
        }

        isSupported(current.id).then(function (supported) {
            if (!supported) {
                return;
            }

            if (current.admin) {
                injectAdmin(current.url);
            } else {
                injectClient(current.url);
            }
        });
    }

    function boot() {
        apply();

        var observer = new MutationObserver(function () {
            if (!document.getElementById(LINK_ID)) {
                apply();
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
