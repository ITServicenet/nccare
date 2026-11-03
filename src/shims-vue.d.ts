/**
 * Lets plain tsc resolve single file components.
 *
 * Vite compiles the SFCs through its Vue plugin, so this declaration only
 * matters for type checking runs invoked directly with tsc.
 */
declare module '*.vue' {
	import type { DefineComponent } from 'vue'

	const component: DefineComponent<Record<string, unknown>, Record<string, unknown>, unknown>
	export default component
}
