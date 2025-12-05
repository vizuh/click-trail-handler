(function () {
    'use strict';

    /**
     * ClickTrail Events Tracking
     * Handles: Search, Downloads, Scroll, Time on Page
     */
    class ClickTrailEvents {
        constructor() {
            this.init();
        }

        init() {
            this.trackSearch();
            this.trackDownloads();
            this.trackScroll();
            this.trackTimeOnPage();
        }

        pushEvent(eventName, params = {}) {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                event: eventName,
                ...params
            });
        }

        /**
         * Track Site Search
         * Detects ?s= or ?q= or ?search= parameters
         */
        trackSearch() {
            const params = new URLSearchParams(window.location.search);
            const searchTerms = params.get('s') || params.get('q') || params.get('search');

            if (searchTerms) {
                this.pushEvent('view_search_results', {
                    search_term: searchTerms
                });
            }
        }

        /**
         * Track File Downloads
         */
        trackDownloads() {
            const fileExtensions = ['pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'mp3', 'mp4', 'txt', 'csv'];

            document.addEventListener('click', (e) => {
                const link = e.target.closest('a');
                if (!link || !link.href) return;

                const url = link.href;
                const extension = url.split('.').pop().toLowerCase();

                if (fileExtensions.includes(extension)) {
                    this.pushEvent('file_download', {
                        file_name: url.split('/').pop(),
                        file_extension: extension,
                        link_url: url
                    });
                }
            });
        }

        /**
         * Track Scroll Depth
         * Tracks 25, 50, 75, 90%
         */
        trackScroll() {
            let marks = { 25: false, 50: false, 75: false, 90: false };

            const calculateScroll = () => {
                const h = document.documentElement,
                    b = document.body,
                    st = 'scrollTop',
                    sh = 'scrollHeight';

                const percent = (h[st] || b[st]) / ((h[sh] || b[sh]) - h.clientHeight) * 100;

                Object.keys(marks).forEach(mark => {
                    if (!marks[mark] && percent >= mark) {
                        marks[mark] = true;
                        this.pushEvent('scroll', {
                            percent_scrolled: mark
                        });
                    }
                });
            };

            window.addEventListener('scroll', () => {
                // Simple throttle
                if (this.scrollTimeout) return;
                this.scrollTimeout = setTimeout(() => {
                    calculateScroll();
                    this.scrollTimeout = null;
                }, 100);
            });
        }

        /**
         * Track Time on Page
         * Tracks 10s, 30s, 60s, 120s, 300s
         */
        trackTimeOnPage() {
            const times = [10, 30, 60, 120, 300];

            times.forEach(seconds => {
                setTimeout(() => {
                    // Only track if tab is visible (optional, but good practice)
                    if (!document.hidden) {
                        this.pushEvent('time_on_page', {
                            seconds: seconds
                        });
                    }
                }, seconds * 1000);
            });
        }
    }

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new ClickTrailEvents());
    } else {
        new ClickTrailEvents();
    }

})();
