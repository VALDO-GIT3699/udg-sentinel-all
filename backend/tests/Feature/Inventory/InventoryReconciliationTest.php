<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class InventoryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_forbids_a_user_without_manage_settings_permission(): void
    {
        // Antes bastaba con tener sesion iniciada: cualquier cuenta podia
        // subir un archivo arbitrario y disparar un analisis de
        // conciliacion contra el inventario oficial.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/inventory-reconciliation')
            ->assertForbidden();
    }

    #[Test]
    public function it_rejects_an_unsupported_file_type(): void
    {
        Storage::fake('local');
        $user = $this->authorizedUser();

        $file = UploadedFile::fake()->create('inventario.pdf', 10);

        $response = $this->actingAs($user)->post('/inventory-reconciliation', [
            'source_file' => $file,
        ]);

        $response->assertSessionHasErrors('source_file');
    }

    #[Test]
    public function it_analyzes_an_uploaded_csv_and_creates_a_batch(): void
    {
        Storage::fake('local');
        $user = $this->authorizedUser();

        $csv = "Nombre,Dominio\nSitio de Prueba,prueba.udg.mx\n";
        $file = UploadedFile::fake()->createWithContent('inventario.csv', $csv);

        $response = $this->actingAs($user)->post('/inventory-reconciliation', [
            'source_file' => $file,
        ]);

        $response->assertRedirect(route('inventory.reconciliation.index'));
        $response->assertSessionHas('batch_id');

        $this->assertDatabaseHas('inventory_reconciliation_batches', [
            'source_name' => 'inventario.csv',
            'status' => 'analyzed',
            'uploaded_by' => $user->id,
        ]);
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();

        Permission::findOrCreate('monitoring.manage_settings', 'web');
        $user->givePermissionTo('monitoring.manage_settings');

        return $user;
    }
}
