<?php

namespace Hibira\Statamic;

use Hibira\Statamic\Comments\CommentStore;
use Hibira\Statamic\Http\Controllers\ScriptController;
use Hibira\Statamic\Http\Middleware\CaptureLivePreview;
use Hibira\Statamic\Http\Middleware\InjectPreviewReload;
use Hibira\Statamic\Http\Middleware\PointControlPanelEchoAtHibira;
use Hibira\Statamic\Listeners\AnnounceEntrySaved;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Statamic\Events\EntryDeleted;
use Statamic\Events\EntrySaved;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    // The package is named "statamic", so Statamic's automatic config and
    // translation namespaces would collide with its own. Both are loaded
    // under "hibira-statamic" instead.
    protected $config = false;

    protected $translations = false;

    protected $listen = [
        EntrySaved::class => [AnnounceEntrySaved::class],
    ];

    protected $middlewareGroups = [
        'web' => [InjectPreviewReload::class],
        'statamic.cp.authenticated' => [CaptureLivePreview::class, PointControlPanelEchoAtHibira::class],
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/hibira-statamic.php', 'hibira-statamic');

        $this->app->singleton(Realtime::class);
        $this->app->singleton(CommentStore::class, fn ($app) => new CommentStore(
            new Filesystem,
            (string) $app['config']->get('hibira-statamic.comments.path'),
        ));
    }

    public function bootAddon(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'hibira-statamic');

        $this->publishes([
            __DIR__.'/../config/hibira-statamic.php' => config_path('hibira-statamic.php'),
        ], 'hibira-statamic-config');

        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/hibira-statamic'),
        ], 'hibira-statamic-translations');

        $this->registerCpRoutes(fn () => Routes::register());

        $cp = trim((string) config('statamic.cp.route', 'cp'), '/');

        if (! $this->app->routesAreCached()) {
            Route::get($cp.'/hibira/cp.js', ScriptController::class)->name('statamic.cp.hibira.script');
        }

        Statamic::externalScript(url($cp.'/hibira/cp.js').'?v='.ScriptController::version());

        $this->app['events']->listen(EntryDeleted::class, function (EntryDeleted $event) {
            $id = (string) $event->entry->id();
            if (Channels::validId($id)) {
                $this->app->make(CommentStore::class)->forget($id);
            }
        });
    }
}
