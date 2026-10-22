import { forgetPluginDefinitions } from './builder/registry';
import { forgetPluginRenderers } from './builder/renderers';

export function createClientExtensionManager(modules) {
  const plugins = new Map();
  const registered = new Set();
  for (const module of Object.values(modules)) {
    if (module.pluginId) plugins.set(module.pluginId, module);
  }
  function forget(id) {
    forgetPluginDefinitions(id);
    forgetPluginRenderers(id);
    registered.delete(id);
  }
  return {
    activate(activeIds = []) {
      const active = new Set(activeIds);
      const failures = [];
      for (const id of registered) if (!active.has(id)) forget(id);
      for (const id of active) {
        const module = plugins.get(id);
        if (!module || registered.has(id)) continue;
        try {
          module.register?.();
          registered.add(id);
        } catch (error) {
          // A partial registration must not leave an unusable palette entry behind.
          forget(id);
          failures.push({ pluginId: id, message: error instanceof Error ? error.message : 'Client extension registration failed.' });
        }
      }
      return failures;
    },
    view(pluginId, viewId) { return registered.has(pluginId) ? plugins.get(pluginId)?.views?.[viewId] : undefined; },
  };
}
const manager = createClientExtensionManager(import.meta.glob('../../plugins/**/resources/admin.js', { eager: true }));
export const activateClientExtensions = activeIds => manager.activate(activeIds);
export const extensionView = (pluginId, viewId) => manager.view(pluginId, viewId);
