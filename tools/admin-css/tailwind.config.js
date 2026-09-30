/**
 * Tailwind build for the ClickTrail dashboard refresh (Settings, Logs, Diagnostics).
 *
 * Design tokens follow the Bankco admin visual language (Urbanist type, soft gray
 * canvas, white rounded cards, green primary). Only tokens are reused; no template
 * files are copied. Text and button colors are adjusted within the palette for WCAG AA.
 *
 * The source CSS uses @apply only and `content` points at it alone, so Tailwind emits
 * no standalone utility classes into wp-admin. Preflight is disabled.
 */
module.exports = {
	content: [ './tools/admin-css/admin-refresh.src.css' ],
	corePlugins: { preflight: false },
	theme: {
		extend: {
			fontFamily: {
				urbanist: [ 'Urbanist', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', 'sans-serif' ],
			},
			colors: {
				ink: '#04091E',
				// 600/700 are not in the template: darker greens for AA. 600 (#15803D) is ~5:1 on white for
				// focus rings and borders; 700 (#166534) is ~7:1 and also clears AA on the #F0F0F1 wp-admin canvas.
				success: { 50: '#D9FBE6', 100: '#B7FFD1', 200: '#4ADE80', 300: '#22C55E', 400: '#16A34A', 600: '#15803D', 700: '#166534' },
				warning: { 50: '#FEF9E6', 100: '#FDE047', 200: '#FACC15', 300: '#EAB308', 700: '#854D0E' },
				error: { 50: '#FCDEDE', 100: '#FF7171', 200: '#FF4747', 300: '#DD3333' },
				bgray: {
					50: '#FAFAFA', 100: '#F7FAFC', 200: '#EDF2F7', 300: '#E2E8F0', 400: '#CBD5E0',
					500: '#A0AEC0', 600: '#718096', 700: '#4A5568', 800: '#2D3748', 900: '#1A202C',
				},
				secondary: { 100: '#F2F6FF', 200: '#D8E3F8' },
				portage: '#936DFF',
			},
			borderRadius: { '2.5xl': '20px' },
			boxShadow: {
				card: '0 1px 2px rgba(26, 32, 44, 0.04), 0 4px 16px rgba(26, 32, 44, 0.04)',
				bar: '0 -4px 24px rgba(26, 32, 44, 0.08)',
			},
		},
	},
	plugins: [],
};
