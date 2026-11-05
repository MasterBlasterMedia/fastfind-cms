# FastFind page builder

The builder edits the existing FastFind page document. It uses Vue 3, the shared content registry, and the normal versioned page API. The same `Block.vue` and `SectionLayout.vue` render a draft preview and a published page.

FastFind provides its own visual editing experience inspired by the section-and-module workflow of visual site builders. It does not embed Divi, run WordPress, or import Divi layouts or shortcodes.

## Visual editing workflow

- **Visual view** renders the modules inside editable sections. Select a module on the canvas or use its named Edit button to open its properties. Headings and paragraphs also have an **Edit text on canvas** button that opens a labeled text field in place; changes immediately update the inspector and draft document.
- **Wireframe view** shows compact section/module outlines and text summaries for arranging long pages. It edits the same document as Visual view, with the same move, duplicate, delete, and insertion controls.
- **Add content** includes a searchable module palette and section layout choices. Palette additions append to the selected section. The contextual **+ Module** buttons insert at the indicated position inside a section; **+ Section** opens a layout picker and inserts after that section. Searchable pickers disable modules incompatible with the target layout.
- **Layers** lists sections and their modules, with independently collapsible sections. Selecting an entry selects the corresponding canvas content and opens its inspector. Collapse state affects navigation only; it does not hide published content.
- **Content / Section design** separates module fields from the containing section's layout, background, width, spacing, and alignment. Design changes apply to the whole section and appear on the editing canvas. Image modules retain the media-library chooser.
- **Desktop**, **Tablet**, and **Mobile** controls change the editing canvas width. Desktop uses the available workspace width; Tablet uses 768px and Mobile uses 375px. **Canvas viewport** also offers fixed 1280px and 1920px widths. Wider canvases scroll within the editor, keeping the inspector and palette accessible. The chosen width carries into Draft preview and back.

View mode, canvas width, search text, and collapsed layers are temporary workspace preferences. They do not change saved page content or add undo entries. The editor's module and section buttons can be used with a keyboard; arrow controls and the destination selector provide movement without dragging. Insertion dialogs support Escape, keyboard focus containment, and return focus to the prior control when it remains on the page.

The canvas shows all modules so they remain selectable, including the contents of tabbed and carousel sections. Use **Preview** to exercise the actual tab, accordion, and carousel behavior. Canvas frames and editing controls are not part of the public page. Responsive grids follow the registry's column counts and canvas container width; typography and third-party media queries still require testing at real browser viewport widths.

This implementation retains the section → ordered blocks document model. "Module" is the editor-facing name for a block. It does not add nested row/column containers, freeform positioning, per-device design overrides, arbitrary module CSS, global reusable modules, or a rich-text formatting toolbar. Inline text editing is available for built-in headings and paragraphs; all registered modules use their schema-driven inspector fields.

## Document contract

```json
{
  "sections": [{
    "id": "unique-section-id",
    "layout": "stack",
    "settings": {
      "background": "plain",
      "width": "normal",
      "spacing": "normal",
      "align": "left"
    },
    "blocks": [{
      "id": "unique-block-id",
      "type": "heading",
      "props": {"text": "Welcome", "level": 2}
    }]
  }]
}
```

Section and block IDs must be unique across a document. The server accepts at most 40 sections, 80 blocks per section, and 300 blocks per page; individual layouts can impose a lower block limit. The server validates every save, including requests that did not originate from the editor.

Section settings support `background` (`plain`, `soft`, `dark`, `accent`), `width` (`normal`, `wide`), `spacing` (`compact`, `normal`, `relaxed`), and `align` (`left`, `center`, `right`). Arbitrary HTML, JavaScript, CSS strings and unknown properties are not accepted as block content.

## Registered layouts

`resources/content/registry.json` is the canonical definition of built-in layouts and blocks. It supplies editor labels, defaults, field constraints, slots, restrictions, block limits and responsive column counts. PHP `ContentRegistry` and the JavaScript registry both load this file.

| ID | Layout | Placement restrictions |
| --- | --- | --- |
| `stack` | Vertical stack | Any registered block |
| `hero` | Full-width hero | Any registered block; 12 maximum |
| `two-columns` | Two columns | Any registered block |
| `three-columns` | Three columns | Any registered block |
| `four-columns` | Four columns | Any registered block |
| `sidebar` | Sidebar and content | Any registered block; alternating narrow/wide grid cells |
| `split` | Split layout | Any registered block |
| `responsive-grid` | Responsive grid | Any registered block |
| `grid` | Card grid | Any registered block |
| `feature` | Feature section | Any registered block |
| `accordion` | Accordion | `faq` and `accordion-item`; 30 maximum |
| `tabs` | Tabbed content | `tab-content`; 12 maximum |
| `carousel` | Manual carousel | `image` and `card`; 20 maximum |
| `gallery` | Image gallery | `image`; 40 maximum |
| `cta` | Call to action | Any registered block; 12 maximum |
| `banner` | Full-width banner | Any registered block; 8 maximum |

Every current layout has one ordered `default` slot. Column layouts distribute blocks across a grid; they are not independently editable column containers. Empty sections are valid. Nested sections and arbitrary named slot composition are not implemented.

Tabs support Left/Right Arrow, Home and End keys, with selected state and linked tab panels. Accordions use native `details`/`summary`. Carousels have previous/next buttons, announce the slide position, and do not autoplay. Responsive columns reduce at smaller viewport and container widths. The preview width selector helps inspect fixed widths; real browser viewport tests remain necessary because the surrounding editor can affect CSS media queries.

## Registered blocks

The 22 built-in types are `heading`, `text`, `image`, `gallery`, `video`, `button`, `button-group`, `card`, `card-collection`, `divider`, `spacer`, `quote`, `list`, `table`, `icon`, `callout`, `cta`, `code`, `accordion-item`, `tab-content`, `stat`, and `faq`.

Text and code render through Vue text nodes. The paragraph block is plain multiline text, not a WYSIWYG rich-text editor. Images have explicit alternative text and captions. Videos accept supported HTTPS YouTube and Vimeo URLs and render a restricted provider embed; arbitrary iframe URLs are not supported.

Collections use bounded scalar text fields:

| Block | Entry format, one per line |
| --- | --- |
| Image gallery | `URL | alternative text | caption` |
| Button group | `Label | URL` |
| Card collection | `Title | description | optional URL` |
| List | One text item |
| Table | Cells separated by `|`; column headings are a separate field |

Image, link and card collections are limited to 40 rows. Their URL columns are validated on the server. Pipe characters are delimiters; an escaping syntax is not implemented. URL validation rejects executable schemes, protocol-relative destinations, backslashes, credentials, control characters and unsafe syntax. Client validation is an additional rendering guard, not the authorization or persistence boundary.

There are no simulated form submissions, reusable global block references or nested section controls. Those features require corresponding persisted models and server contracts before registration.

## Editing and persistence

The editor supports adding, selecting, duplicating, deleting and ordering sections and blocks. Native drag handles move sections and compatible blocks. Explicit arrow buttons provide ordering without dragging, and a block's destination select moves it between compatible sections. Incompatible drops and incompatible layout changes preserve the original content and display an error.

Undo and redo retain up to 50 in-memory editing states. Deletion is recoverable through undo while the editor remains open. This history is not a substitute for saved revisions and is not persisted across reloads. Unsaved changes trigger navigation and browser-close prompts. Ctrl/Command+S saves a draft.

`builder/operations.js` creates an independent save payload. When the user keeps typing during a request, the response advances the server version while preserving newer local edits. A publish operation does not proceed when a save has left newer local changes unsaved. Network and validation failures keep the current editor content. Stale revisions are rejected by the server; the editor offers an explicit reload of the current server version instead of silently overwriting it.

Saving a draft does not replace the published snapshot. Publishing promotes saved content. Republish, unpublish and revision restoration use the versioned API. Plugin page fields persist under `page.extensions[plugin_id]`, follow the same draft/revision/published snapshot workflow, and are generated from the active plugin's field descriptors.

## Extending the builder

Add built-in definitions to `resources/content/registry.json`, then register the corresponding Vue renderer in `resources/js/builder/renderers.js`. `SchemaFields.vue` generates the property controls from field descriptors. Supported field types are `text`, `textarea`, `number`, `select`, `boolean`, `url` and `image`. `min`, `max`, `required`, options and supported collection/video formats constrain input.

Third-party plugins use `PluginContext` on the server to register namespaced blocks/layouts with a server validator. Their reviewed, built JavaScript module registers the matching definition with `registerBlock` or `registerLayout` and the Vue component with `registerBlockRenderer`. The compiled administration module exports `pluginId`, a `views` map, and an optional `register()` function. Consult `PLUGIN_DEVELOPMENT.md` and the sample plugin for the complete contract. Uploaded Vue source is not evaluated dynamically; application assets must be rebuilt after adding trusted frontend modules.

The visual canvas, palette, layer navigator, and insertion pickers consume these same block/layout registries. Plugin definitions therefore receive the same selection, insertion, compatibility, inspector, and persistence behavior as built-in definitions, without a separate official-plugin path. `components/builder/BuilderCanvas.vue` emits document-edit actions to `views/Editor.vue`; `InsertPicker.vue` and `LayerNavigator.vue` provide the shared picker and navigation UI. Save/version reconciliation remains in `builder/operations.js`.

The current layout extension API supports declarative grids and existing built-in interaction patterns; it does not expose an arbitrary third-party layout-renderer registration API. Custom block rendering is available. A missing block renderer produces an explicit unavailable-content message.

## Verification

Run `npm test` for frontend behavior and registry rendering checks, `npm run build` for a production asset build, and `npm run test:e2e` for the configured real-browser suite. Backend registry and workflow tests run with `php artisan test`. The browser harness uses an isolated disposable database; do not reset an existing site to run tests.

`npm test -- tests/frontend/visual-builder.test.js` runs focused editor integration checks for inline editing, undo/redo, contextual insertion order and compatibility, layer selection, section design, responsive workspace state, palette filtering, viewer restrictions, and preservation of edits made during a save request. It also registers a third-party block, renderer, and layout through the public APIs and exercises insertion, editing, rendering, layers, and saving through the same UI.

Tests should discover definitions from the registry and exercise defaults, empty content, restrictions, cloning, reordering, safe URL rendering, interactive semantics and persistence. Required target viewport widths are 375, 768, 1280 and 1920 pixels. Current test results and any remaining coverage gaps belong in `TESTING.md` and the verification report; registration or a successful build alone does not prove the complete layout matrix passes.
