<?php

namespace App\Http\Controllers;

use App\Models\PluginInstallation;
use App\Plugins\ExtensionRegistry;
use App\Plugins\PackageInstaller;
use App\Plugins\PluginManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class PluginController extends Controller
{
    public function index(PluginManager $manager)
    {
        Gate::authorize('manage-plugins');
        return response()->json(['plugins' => $manager->listing()]);
    }

    public function extensions(ExtensionRegistry $extensions) { return response()->json($extensions->descriptors()); }

    public function catalog(\App\Plugins\PluginCatalog $catalog)
    {
        Gate::authorize('manage-plugins');
        return response()->json($catalog->listing());
    }

    public function lifecycle(Request $request, string $vendor, string $name, string $action, PluginManager $manager)
    {
        Gate::authorize('manage-plugins');
        $id = $vendor.'/'.$name;
        $actor = $request->user()->id;
        match ($action) {
            'install' => $manager->installBundled($id, $actor),
            'activate' => $manager->activate($id, $actor),
            'deactivate' => $manager->deactivate($id, $actor),
            'upgrade' => $manager->upgradeBundled($id, $actor),
            'uninstall' => $manager->uninstall($id, $actor, (bool) ($request->validate(['purge' => ['sometimes', 'boolean']])['purge'] ?? false)),
            default => abort(404),
        };
        return response()->json(['plugin' => collect($manager->listing())->firstWhere('id', $id)]);
    }

    public function upload(Request $request, PackageInstaller $installer)
    {
        Gate::authorize('manage-plugins');
        $data = $request->validate(['file' => ['required', 'file', 'max:20480'], 'publisher' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{1,79}$/D'],
            'signature' => ['required', 'string', 'max:100'], 'sha256' => ['required', 'string', 'size:64']]);
        $record = $installer->install($data['file']->getRealPath(), $data['publisher'], $data['signature'], $data['sha256'], $request->user()->id);
        return response()->json(['plugin' => ['id' => $record->id, 'status' => $record->status, 'installed_version' => $record->installed_version]], 201);
    }

    public function settings(Request $request, string $vendor, string $name, ExtensionRegistry $extensions, PluginManager $manager)
    {
        Gate::authorize('manage-site');
        $id = $vendor.'/'.$name;
        $record = PluginInstallation::where('status', 'active')->findOrFail($id);
        $definition = $extensions->settingDefinition($id);
        abort_unless($definition, 404);
        if ($request->isMethod('PUT')) {
            $data = $request->validate(['settings' => ['required', 'array']]);
            $record->update(['settings' => ($definition['validator'])($data['settings'])]);
            $manager->audit($request->user()->id, 'plugin.settings_saved', $id);
        }
        return response()->json(['settings' => $record->settings ?? [], 'fields' => $definition['fields']]);
    }

    public function dispatch(Request $request, string $vendor, string $name, ExtensionRegistry $extensions, PluginManager $manager, string $path = '')
    {
        $id = $vendor.'/'.$name;
        $record = PluginInstallation::where('status', 'active')->findOrFail($id);
        $definition = $extensions->routeDefinition($id, $request->method(), $path);
        abort_unless($definition && in_array($definition['permission'], $record->manifest['permissions'], true), 404);
        $gate = ['pages.read' => 'view-content', 'pages.write' => 'edit-content', 'settings.manage' => 'manage-site'][$definition['permission']];
        Gate::authorize($gate);
        try { return ($definition['handler'])($request); }
        catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { throw $error; }
        catch (\Throwable $error) { $manager->report($id, $error, 'admin-route'); abort(503, 'This extension is temporarily unavailable.'); }
    }
}
