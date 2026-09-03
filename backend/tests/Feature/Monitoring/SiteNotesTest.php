<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\SiteGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class SiteNotesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_toggles_is_hidden_without_requiring_a_status(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $note = SiteEvent::record(
            siteId: (int) $site->id,
            eventType: 'monitoring.note',
            title: 'Nota operativa',
            description: 'Contenido de la nota',
            metadata: ['note_status' => 'en_curso'],
        );

        $response = $this->actingAs($user)->patch(
            "/monitoring/sites/{$site->id}/notes/{$note->id}",
            ['is_hidden' => true],
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $note->refresh();
        $this->assertTrue((bool) $note->metadata['is_hidden']);
        // El status previo no debe perderse al solo actualizar is_hidden.
        $this->assertSame('en_curso', $note->metadata['note_status']);
    }

    #[Test]
    public function it_updates_the_status_without_requiring_is_hidden(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $note = SiteEvent::record(
            siteId: (int) $site->id,
            eventType: 'monitoring.note',
            title: 'Nota operativa',
            description: 'Contenido de la nota',
            metadata: ['note_status' => 'en_curso'],
        );

        $response = $this->actingAs($user)->patch(
            "/monitoring/sites/{$site->id}/notes/{$note->id}",
            ['status' => 'resuelta'],
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $note->refresh();
        $this->assertSame('resuelta', $note->metadata['note_status']);
        $this->assertArrayHasKey('resolved_at', $note->metadata);
    }

    #[Test]
    public function it_rejects_a_patch_with_neither_status_nor_is_hidden(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $note = SiteEvent::record(
            siteId: (int) $site->id,
            eventType: 'monitoring.note',
            title: 'Nota operativa',
            description: 'Contenido de la nota',
            metadata: ['note_status' => 'en_curso'],
        );

        $response = $this->actingAs($user)->patch(
            "/monitoring/sites/{$site->id}/notes/{$note->id}",
            [],
        );

        $response->assertStatus(422);
    }

    #[Test]
    public function it_deletes_a_note(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $note = SiteEvent::record(
            siteId: (int) $site->id,
            eventType: 'monitoring.note',
            title: 'Nota operativa',
            description: 'Contenido de la nota',
            metadata: ['note_status' => 'en_curso'],
        );

        $response = $this->actingAs($user)->delete("/monitoring/sites/{$site->id}/notes/{$note->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('site_events', ['id' => $note->id]);
    }

    #[Test]
    public function the_notes_timeline_exposes_the_authors_name(): void
    {
        $user = $this->authorizedUser();
        $site = $this->createSite();

        $note = SiteEvent::record(
            siteId: (int) $site->id,
            eventType: 'monitoring.note',
            title: 'Nota operativa',
            description: 'Contenido de la nota',
            metadata: ['note_status' => 'en_curso'],
            createdBy: $user->id,
        );

        $response = $this->actingAs($user)->get("/monitoring/sites/{$site->id}/detail");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('notesTimeline.0.created_by_name', $user->name));
    }

    #[Test]
    public function it_forbids_a_view_only_user_from_managing_comments(): void
    {
        // Regresion: view_site_detail (solo lectura) ya NO basta para
        // agregar/editar/borrar comentarios -antes cualquier rol con acceso
        // de solo lectura al detalle del sitio podia comentar libremente.
        $user = User::factory()->create();
        Permission::findOrCreate('monitoring.view_site_detail', 'web');
        $user->givePermissionTo('monitoring.view_site_detail');

        $site = $this->createSite();

        $note = SiteEvent::record(
            siteId: (int) $site->id,
            eventType: 'monitoring.note',
            title: 'Nota operativa',
            description: 'Contenido de la nota',
            metadata: ['note_status' => 'en_curso'],
        );

        $this->actingAs($user)
            ->post("/monitoring/sites/{$site->id}/notes", ['note' => 'Intento'])
            ->assertForbidden();

        $this->actingAs($user)
            ->patch("/monitoring/sites/{$site->id}/notes/{$note->id}", ['status' => 'resuelta'])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete("/monitoring/sites/{$site->id}/notes/{$note->id}")
            ->assertForbidden();
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.view_site_detail', 'web');
        Permission::findOrCreate('monitoring.manage_comments', 'web');
        $user->givePermissionTo(['monitoring.view_site_detail', 'monitoring.manage_comments']);

        return $user;
    }

    private function createSite(): Site
    {
        $group = SiteGroup::query()->create([
            'name' => 'Portales UDG',
            'slug' => 'portales-udg-notes',
            'color' => '#0EA5E9',
        ]);

        return Site::query()->create([
            'site_group_id' => $group->id,
            'name' => 'Sitio de pruebas',
            'slug' => 'sitio-de-pruebas-notes',
            'domain' => 'notas.udg.mx',
            'url' => 'https://notas.udg.mx',
        ]);
    }
}
