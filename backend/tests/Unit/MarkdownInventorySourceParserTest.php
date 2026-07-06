<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Inventory\Services\Reconciliation\Parsing\MarkdownInventorySourceParser;
use Tests\TestCase;

final class MarkdownInventorySourceParserTest extends TestCase
{
    public function test_it_parses_plain_text_row_with_no_tiene_marker(): void
    {
        $parser = new MarkdownInventorySourceParser();
        $file = $this->createTempMarkdownFile(<<<'MD'
ABC CGTI Sitio Demo https://demo.udg.mx Si WordPress No tiene Pendiente Observacion inicial
MD);

        $rows = $parser->parse($file);

        $this->assertCount(1, $rows);
        $this->assertSame('https://demo.udg.mx', $rows[0]['dominio']);
        $this->assertSame('No tiene', $rows[0]['ip_servidor']);
        $this->assertSame('Pendiente', $rows[0]['estatus_proyecto']);
        $this->assertSame('Observacion inicial', $rows[0]['comentarios']);
    }

    public function test_it_preserves_url_path_and_ip_when_present(): void
    {
        $parser = new MarkdownInventorySourceParser();
        $file = $this->createTempMarkdownFile(<<<'MD'
ABC CGTI Portal Servicios https://portal.udg.mx/apps/ingreso Si Joomla 148.202.10.20 Activo Monitoreo normal
MD);

        $rows = $parser->parse($file);

        $this->assertCount(1, $rows);
        $this->assertSame('https://portal.udg.mx/apps/ingreso', $rows[0]['dominio']);
        $this->assertSame('148.202.10.20', $rows[0]['ip_servidor']);
        $this->assertSame('Activo', $rows[0]['estatus_proyecto']);
    }

    public function test_it_ignores_non_inventory_pipe_tables(): void
    {
        $parser = new MarkdownInventorySourceParser();
        $file = $this->createTempMarkdownFile(<<<'MD'
| Etiqueta | Valor |
| --- | --- |
| COUNTA | 127 |
MD);

        $rows = $parser->parse($file);

        $this->assertSame([], $rows);
    }

    public function test_it_does_not_force_unknown_status_token(): void
    {
        $parser = new MarkdownInventorySourceParser();
        $file = $this->createTempMarkdownFile(<<<'MD'
ABC CGTI Portal Transparencia https://transparencia.udg.mx Si Drupal 148.202.10.21 RevisarPorArea Pendiente revision manual
MD);

        $rows = $parser->parse($file);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['estatus_proyecto']);
        $this->assertSame('RevisarPorArea Pendiente revision manual', $rows[0]['comentarios']);
    }

    private function createTempMarkdownFile(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'inv-parser-');
        $this->assertNotFalse($file);

        file_put_contents($file, $content);

        return $file;
    }
}
