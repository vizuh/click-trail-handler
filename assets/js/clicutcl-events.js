(function () {
    'use strict';

    /**
     * ClickTrail Events Tracking
     * Handles: Search, Downloads, Scroll, Time on Page
     */
    class ClickTrailEvents {
        constructor() {
            this.debugEnabled = !!(window.clicutclEventsConfig && window.clicutclEventsConfig.debug);
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
            const eventData = {
                event: eventName,
                ...params
            };

            this.debugLog('ClickTrail Event:', eventName, eventData);

            window.dataLayer.push(eventData);
        }

        debugLog(...args) {
            if (!this.debugEnabled) return;
            console.log('[ClickTrail]', ...args);
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
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
                const scrollHeight = document.documentElement.scrollHeight || document.body.scrollHeight;
                const clientHeight = document.documentElement.clientHeight || window.innerHeight;

                // Calculate scroll percentage
                const percent = (scrollTop / (scrollHeight - clientHeight)) * 100;

                Object.keys(marks).forEach(mark => {
                    if (!marks[mark] && percent >= mark) {
                        marks[mark] = true;
                        this.pushEvent('scroll', {
                            // GTM standard built-in variables
                            'gtm.scrollThreshold': parseInt(mark),
                            'gtm.scrollUnits': 'percent',
                            'gtm.scrollDirection': 'vertical',
                            // GA4 Enhanced Measurement compatibility
                            'percent_scrolled': parseInt(mark)
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
            const timeThresholds = [
                { seconds: 10, label: '10_seconds', engagement: 'quick_view' },
                { seconds: 30, label: '30_seconds', engagement: 'browsing' },
                { seconds: 60, label: '1_minute', engagement: 'engaged' },
                { seconds: 120, label: '2_minutes', engagement: 'interested' },
                { seconds: 300, label: '5_minutes', engagement: 'highly_engaged' }
            ];

            timeThresholds.forEach(threshold => {
                setTimeout(() => {
                    // Only track if tab is visible
                    if (!document.hidden) {
                        this.pushEvent('user_engagement', {
                            // GTM friendly parameters
                            'engagement_time_msec': threshold.seconds * 1000,
                            'time_threshold': threshold.seconds,
                            'time_label': threshold.label,
                            'engagement_level': threshold.engagement,
                            // GA4 compatibility
                            'value': threshold.seconds
                        });
                    }
                }, threshold.seconds * 1000);
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
