module.exports = {
	extends: [
		'@nextcloud',
	],
	// The shared config parses single file components with the plain JavaScript
	// parser, which chokes on TypeScript syntax such as "import type". Pointing
	// vue-eslint-parser at the TypeScript parser fixes it for <script lang="ts">.
	parser: 'vue-eslint-parser',
	parserOptions: {
		parser: '@typescript-eslint/parser',
		ecmaVersion: 2022,
		sourceType: 'module',
	},
	settings: {
		// Imports are written without an extension, so the resolvers need to be
		// told that .ts and .vue are candidates.
		'import/resolver': {
			node: {
				extensions: ['.js', '.mjs', '.ts', '.vue'],
			},
		},
		// eslint-plugin-n reads its settings from the "n" key; the older
		// eslint-plugin-node used "node". Both are provided so the config keeps
		// working whichever version the shared preset pulls in.
		n: {
			tryExtensions: ['.js', '.mjs', '.ts', '.vue', '.json'],
		},
		node: {
			tryExtensions: ['.js', '.mjs', '.ts', '.vue', '.json'],
		},
	},
	rules: {
		'jsdoc/require-jsdoc': 'off',
		'jsdoc/require-param': 'off',
		'vue/first-attribute-linebreak': 'off',
		// Extensions are resolved by Vite, and TypeScript forbids importing a
		// .ts path explicitly, so requiring them here is counterproductive.
		'import/extensions': 'off',
		// Keying a <template v-for> is the correct Vue 3 pattern when each
		// iteration emits several sibling elements, as in a definition list.
		'vue/no-v-for-template-key': 'off',
		// This rule resolves imports the way Node would at runtime, which does
		// not apply to browser code bundled by Vite and cannot see .ts sources.
		// Module resolution is verified by "tsc --noEmit" in the same CI job.
		'n/no-missing-import': 'off',
	},
}
