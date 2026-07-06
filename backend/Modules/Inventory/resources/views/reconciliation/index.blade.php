<x-inventory::layouts.master>
    <div style="max-width: 1200px; margin: 0 auto; padding: 32px; font-family: Figtree, sans-serif; color: #0f172a;">
        <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 16px; align-items: end; margin-bottom: 24px;">
            <div>
                <p style="text-transform: uppercase; letter-spacing: 0.12em; font-size: 12px; color: #64748b; margin: 0 0 6px;">Inventario institucional</p>
                <h1 style="margin: 0; font-size: 36px; line-height: 1.1;">Reconciliación inteligente</h1>
                <p style="margin: 8px 0 0; color: #475569; max-width: 760px;">Carga una fuente institucional y genera una conciliación no destructiva. El sistema detecta coincidencias exactas, probables, nuevos activos y obsolescencias sin sobrescribir el inventario existente.</p>
            </div>

            @if (session('status'))
                <div style="background: #ecfeff; color: #155e75; border: 1px solid #a5f3fc; padding: 12px 16px; border-radius: 14px;">{{ session('status') }}</div>
            @endif
        </div>

        <div style="display: grid; grid-template-columns: 1.1fr .9fr; gap: 24px; margin-bottom: 24px;">
            <section style="background: white; border: 1px solid #e2e8f0; border-radius: 20px; padding: 24px; box-shadow: 0 10px 35px rgba(15, 23, 42, 0.05);">
                <h2 style="margin-top: 0; font-size: 18px;">Importar fuente</h2>
                <form method="POST" action="{{ route('inventory.reconciliation.store') }}" enctype="multipart/form-data" style="display: grid; gap: 16px;">
                    @csrf
                    <label style="display: grid; gap: 8px;">
                        <span style="font-weight: 600;">Archivo institucional</span>
                        <input type="file" name="source_file" accept=".xlsx,.xls,.csv,.md,.markdown,.txt" style="padding: 14px; border: 1px dashed #94a3b8; border-radius: 14px; background: #f8fafc;" required>
                    </label>

                    @error('source_file')
                        <div style="color: #b91c1c;">{{ $message }}</div>
                    @enderror

                    <button type="submit" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: #0f172a; color: white; border: none; border-radius: 999px; padding: 14px 18px; font-weight: 600; cursor: pointer;">Analizar y reconciliar</button>
                </form>
            </section>

            <section style="background: linear-gradient(180deg, #0f172a, #1e293b); color: white; border-radius: 20px; padding: 24px;">
                <h2 style="margin-top: 0; font-size: 18px;">Criterios de reconciliación</h2>
                <ul style="margin: 0; padding-left: 18px; line-height: 1.8; color: #e2e8f0;">
                    <li>Dominio, URL y nombre normalizados.</li>
                    <li>Señales secundarias: CMS, IP, activo y comentarios.</li>
                    <li>Resultado no destructivo con trazabilidad por lote.</li>
                    <li>Sin duplicar ni eliminar activos existentes.</li>
                </ul>
            </section>
        </div>

        <section style="background: white; border: 1px solid #e2e8f0; border-radius: 20px; padding: 24px; box-shadow: 0 10px 35px rgba(15, 23, 42, 0.05);">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;">
                <h2 style="margin: 0; font-size: 18px;">Lotes recientes</h2>
                <span style="color: #64748b;">{{ count($batches) }} lote(s)</span>
            </div>

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; min-width: 900px;">
                    <thead>
                        <tr style="text-align: left; color: #475569; border-bottom: 1px solid #e2e8f0;">
                            <th style="padding: 12px 8px;">Lote</th>
                            <th style="padding: 12px 8px;">Fuente</th>
                            <th style="padding: 12px 8px;">Estado</th>
                            <th style="padding: 12px 8px;">Coincidencias</th>
                            <th style="padding: 12px 8px;">Nuevos</th>
                            <th style="padding: 12px 8px;">Obsoletos</th>
                            <th style="padding: 12px 8px;">Analizado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batches as $batch)
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px 8px; font-weight: 600;">#{{ $batch->id }}</td>
                                <td style="padding: 12px 8px;">{{ $batch->source_name }}</td>
                                <td style="padding: 12px 8px; text-transform: uppercase; font-size: 12px; letter-spacing: .06em;">{{ $batch->status }}</td>
                                <td style="padding: 12px 8px;">{{ $batch->exact_matches + $batch->probable_matches }}</td>
                                <td style="padding: 12px 8px;">{{ $batch->new_rows }}</td>
                                <td style="padding: 12px 8px;">{{ $batch->obsolete_sites }}</td>
                                <td style="padding: 12px 8px;">{{ optional($batch->analyzed_at)->format('Y-m-d H:i') ?? 'Pendiente' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" style="padding: 18px 8px; color: #64748b;">Todavía no hay lotes de reconciliación.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-inventory::layouts.master>
