<?php

namespace Hibira\Statamic\Tests;

use Hibira\Laravel\HibiraServiceProvider;
use Hibira\Statamic\Realtime;
use Hibira\Statamic\ServiceProvider;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected FakeRealtime $realtime;

    protected string $commentsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realtime = new FakeRealtime($this->app->make(\Hibira\Laravel\Hibira::class));
        $this->app->instance(Realtime::class, $this->realtime);
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->commentsPath);

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return array_merge([HibiraServiceProvider::class], parent::getPackageProviders($app));
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('hibira.app_id', '1');
        $app['config']->set('hibira.key', 'test-key');
        $app['config']->set('hibira.secret', 'test-secret');
        $app['config']->set('statamic.editions.pro', true);

        $this->commentsPath = sys_get_temp_dir().'/hibira-statamic-tests-'.getmypid();
        $app['config']->set('hibira-statamic.comments.path', $this->commentsPath);
    }

    protected function entry(string $id = 'post-1'): EntryContract
    {
        if (! Collection::find('blog')) {
            Collection::make('blog')->title('Blog')->save();
        }

        $entry = Entry::make()->collection('blog')->id($id)->slug($id)->data(['title' => 'Hello']);
        $entry->save();

        return $entry;
    }

    protected function superUser(string $id = 'super', string $name = 'Ada Admin'): UserContract
    {
        return tap(User::make()->id($id)->email($id.'@example.com')->set('name', $name)->makeSuper())->save();
    }

    /** A user who may use the control panel but not see the blog. */
    protected function plainUser(string $id = 'plain', string $name = 'Pat Plain'): UserContract
    {
        return tap(User::make()->id($id)->email($id.'@example.com')->set('name', $name))->save();
    }
}
