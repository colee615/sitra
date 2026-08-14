<?php

namespace App\Http\Controllers;

use App\Models\TrackingEventRule;
use App\Services\TrackingEventRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrackingEventRuleController extends Controller
{
    public function __construct(
        private readonly TrackingEventRuleService $ruleService
    ) {
    }

    public function index(Request $request): View
    {
        $this->ensureAdmin($request);

        $query = TrackingEventRule::query()->orderBy('source_db')->orderBy('event_type_cd')->orderBy('raw_name');

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('raw_name', 'like', "%{$search}%")
                    ->orWhere('display_name', 'like', "%{$search}%")
                    ->orWhere('source_db', 'like', "%{$search}%");
            });
        }

        if ($source = trim((string) $request->query('source_db', ''))) {
            $query->where('source_db', $source);
        }

        $visibility = $request->query('visible');
        if (in_array((string) $visibility, ['0', '1'], true)) {
            $query->where('is_visible', $visibility === '1');
        }

        $rules = $query->paginate(25)->withQueryString();

        return view('tracking-event-rules.index', [
            'rules' => $rules,
            'sourceOptions' => $this->ruleService->commonSourceOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->ensureAdmin($request);

        return view('tracking-event-rules.create', [
            'rule' => new TrackingEventRule([
                'source_db' => '*',
                'is_visible' => true,
                'append_source_context' => false,
                'sort_order' => 0,
            ]),
            'sourceOptions' => $this->ruleService->commonSourceOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureAdmin($request);

        $data = $this->validatedData($request);

        TrackingEventRule::create($data);
        $this->ruleService->clearCache();

        return redirect()->route('tracking-event-rules.index')
            ->with('success', 'Regla de evento creada correctamente.');
    }

    public function edit(Request $request, TrackingEventRule $trackingEventRule): View
    {
        $this->ensureAdmin($request);

        return view('tracking-event-rules.edit', [
            'rule' => $trackingEventRule,
            'sourceOptions' => $this->ruleService->commonSourceOptions(),
        ]);
    }

    public function update(Request $request, TrackingEventRule $trackingEventRule): RedirectResponse
    {
        $this->ensureAdmin($request);

        $data = $this->validatedData($request);

        $trackingEventRule->update($data);
        $this->ruleService->clearCache();

        return redirect()->route('tracking-event-rules.index')
            ->with('success', 'Regla de evento actualizada correctamente.');
    }

    public function toggleVisibility(Request $request, TrackingEventRule $trackingEventRule): RedirectResponse
    {
        $this->ensureAdmin($request);

        $trackingEventRule->update([
            'is_visible' => !$trackingEventRule->is_visible,
        ]);

        $this->ruleService->clearCache();

        return redirect()->to($request->input('redirect_to', route('tracking-event-rules.index')))
            ->with('success', 'Visibilidad de la regla actualizada correctamente.');
    }

    public function destroy(Request $request, TrackingEventRule $trackingEventRule): RedirectResponse
    {
        $this->ensureAdmin($request);

        $trackingEventRule->delete();
        $this->ruleService->clearCache();

        return redirect()->route('tracking-event-rules.index')
            ->with('success', 'Regla de evento eliminada correctamente.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $this->ensureAdmin($request);

        $stats = $this->ruleService->syncFromSqlServerCatalog();

        return redirect()->route('tracking-event-rules.index')
            ->with('success', "Sincronización completada. Total: {$stats['total']}, creados: {$stats['created']}, actualizados: {$stats['updated']}.");
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'source_db' => ['required', 'string', 'max:80'],
            'event_type_cd' => ['nullable', 'integer', 'min:0'],
            'raw_name' => ['nullable', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'is_visible' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $data['raw_name'] = trim((string) ($data['raw_name'] ?? ''));
        $data['display_name'] = trim((string) ($data['display_name'] ?? '')) ?: null;
        $data['notes'] = trim((string) ($data['notes'] ?? '')) ?: null;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['append_source_context'] = false;

        if (($data['event_type_cd'] ?? null) === null && $data['raw_name'] === '') {
            abort(422, 'Debes indicar event_type_cd o raw_name.');
        }

        return $data;
    }

    private function ensureAdmin(Request $request): void
    {
        if (!$request->user() || !$request->user()->hasRole('admin')) {
            abort(403, 'Solo los administradores pueden administrar eventos.');
        }
    }
}
