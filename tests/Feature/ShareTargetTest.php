<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ShareTargetTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_share_chooser_keeps_the_return_path_and_offers_login(): void
    {
        $this->get(route('share-target.show', ['shared' => 1]))
            ->assertOk()
            ->assertSee('У яку подію їх додати?')
            ->assertSee('Увійти через Сільпо')
            ->assertSee('data-pwa-install-banner', escape: false)
            ->assertSee('data-share-target', escape: false)
            ->assertSessionHas('share_target.pending', true)
            ->assertSessionHas('share_target.return_after_auth', true);
    }

    public function test_authenticated_chooser_lists_only_the_owners_events(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $picnic = Event::factory()->for($owner)->create(['title' => 'Пікнік біля води']);
        Event::factory()->for($owner)->create(['title' => 'День народження Олі']);
        Event::factory()->for($stranger)->create(['title' => 'Чужа секретна подія']);

        $this->actingAs($owner)
            ->withSession(['share_target.pending' => true])
            ->get(route('share-target.show'))
            ->assertOk()
            ->assertSee('Пікнік біля води')
            ->assertSee('День народження Олі')
            ->assertSee(route('events.sources.store', $picnic), escape: false)
            ->assertDontSee('Чужа секретна подія')
            ->assertSessionMissing('share_target.return_after_create');
    }

    public function test_empty_chooser_returns_after_creation_and_discard_clears_share_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['share_target.pending' => true])
            ->get(route('share-target.show'))
            ->assertOk()
            ->assertSee('Спершу створимо подію')
            ->assertSee(route('events.create'), escape: false)
            ->assertSessionHas('share_target.return_after_create', true);

        $this->postJson(route('share-target.discard'))
            ->assertNoContent()
            ->assertSessionMissing('share_target.pending')
            ->assertSessionMissing('share_target.return_after_auth')
            ->assertSessionMissing('share_target.return_after_create');
    }

    public function test_manifest_publishes_install_share_icon_and_screenshot_contracts(): void
    {
        $manifest = json_decode(
            file_get_contents(public_path('manifest.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('/', $manifest['id']);
        $this->assertSame('/events', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/share-target', $manifest['share_target']['action']);
        $this->assertSame('POST', $manifest['share_target']['method']);
        $this->assertSame('images', $manifest['share_target']['params']['files'][0]['name']);
        $this->assertCount(4, $manifest['screenshots']);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path($icon['src']));
        }

        foreach ($manifest['screenshots'] as $screenshot) {
            $path = public_path($screenshot['src']);
            $this->assertFileExists($path);
            [$width, $height] = getimagesize($path);
            $this->assertSame($screenshot['sizes'], "{$width}x{$height}");
        }

        $serviceWorker = file_get_contents(public_path('service-worker.js'));
        $this->assertStringContainsString("event.request.method === 'POST'", $serviceWorker);
        $this->assertStringContainsString('url.pathname === SHARE_TARGET_PATH', $serviceWorker);
        $this->assertStringNotContainsString('caches.', $serviceWorker);

        $this->get(route('landing'))
            ->assertOk()
            ->assertSee(asset('manifest.json'), escape: false)
            ->assertSee(asset('images/pwa/icon-512.png'), escape: false)
            ->assertSee('data-pwa-install-banner', escape: false)
            ->assertSee('data-pwa-install-button', escape: false)
            ->assertSee('Встановити застосунок')
            ->assertSee(asset('images/brand/goose-sho.png'), escape: false);

        $progressiveWebAppJavascript = file_get_contents(resource_path('js/pwa.js'));
        $this->assertStringContainsString("window.addEventListener('beforeinstallprompt'", $progressiveWebAppJavascript);
        $this->assertStringContainsString("window.addEventListener('appinstalled'", $progressiveWebAppJavascript);
        $this->assertStringContainsString('await installPrompt.prompt()', $progressiveWebAppJavascript);
    }
}
