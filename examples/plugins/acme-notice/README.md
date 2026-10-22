# Acme Notice SDK example

This independent example adds a server-validated `acme/notice/notice` block and a Vue renderer through the same public APIs as FastFind SEO. It is source code for developers, not an installed marketplace listing.

## Try the example locally

1. Copy this directory to `plugins/acme-notice` in your FastFind source checkout.
2. Add `base_path('plugins/acme-notice')` to the `bundled_paths` array in `config/plugins.php`, preserving the existing entries. This explicitly trusts the reviewed source.
3. Run `npm run build` to compile its `resources/admin.js` module. Restart any running development/build processes as needed.
4. Sign in as an owner, open Plugins, and install then activate Acme Notice.
5. Open a page and add the Acme notice block to a standard section. Edit its Notice field, save, publish and reload the public page.

The server rejects empty text, text longer than 200 characters and undeclared properties. Vue renders the text as escaped content. Deactivating the plugin removes the block from the palette and renderer registry while preserving saved content. Re-enable it to restore rendering and validation of that block.

## Files and contracts

- `plugin.json`: identity, compatibility and the `content.extend` capability.
- `plugin.php`: anonymous `AbstractPlugin` implementation registering the block with `PluginContext::block` and a server validator.
- `block.json`: namespaced ID, defaults and typed field schema shared by server and client.
- `resources/admin.js`: client registration using `registerBlock` and `registerBlockRenderer`. Its relative imports assume the installation path above.

Do not render user text with `v-html`, omit server validation or treat declared capabilities as a PHP sandbox. Publishing, signature verification, lifecycle behavior and further extension points are documented in [the plugin SDK](../../../docs/PLUGIN_DEVELOPMENT.md). From the installed `plugins/acme-notice` directory, that guide is at `../../docs/PLUGIN_DEVELOPMENT.md`.
