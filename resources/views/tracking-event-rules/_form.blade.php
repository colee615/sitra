@csrf
@if(isset($method))
    @method($method)
@endif

<div class="card-body">
    <div class="row">
        <div class="col-md-4">
            <div class="form-group">
                <label for="source_db">Origen</label>
                <select name="source_db" id="source_db" class="form-control" required>
                    @foreach($sourceOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('source_db', $rule->source_db) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label for="event_type_cd">Codigo de evento</label>
                <input type="number" name="event_type_cd" id="event_type_cd" class="form-control" value="{{ old('event_type_cd', $rule->event_type_cd) }}" min="0">
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label for="sort_order">Orden</label>
                <input type="number" name="sort_order" id="sort_order" class="form-control" value="{{ old('sort_order', $rule->sort_order ?? 0) }}" min="0">
            </div>
        </div>
    </div>

    <div class="form-group">
        <label for="raw_name">Nombre original BD</label>
        <input type="text" name="raw_name" id="raw_name" class="form-control" value="{{ old('raw_name', $rule->raw_name) }}" maxlength="255">
        <small class="form-text text-muted">Es el nombre del evento tal como llega desde la base de datos. Puedes dejarlo vacio si la regla se resolvera solo por codigo de evento.</small>
    </div>

    <div class="form-group">
        <label for="display_name">Nombre visible en la API</label>
        <input type="text" name="display_name" id="display_name" class="form-control" value="{{ old('display_name', $rule->display_name) }}" maxlength="255">
        <small class="form-text text-muted">Si lo dejas vacio, la API mostrara el nombre original BD.</small>
    </div>

    <div class="form-group">
        <label for="is_visible">Mostrar en API</label>
        <select name="is_visible" id="is_visible" class="form-control">
            <option value="1" @selected((string) old('is_visible', (int) $rule->is_visible) === '1')>Si</option>
            <option value="0" @selected((string) old('is_visible', (int) $rule->is_visible) === '0')>No</option>
        </select>
    </div>

    <div class="form-group">
        <label for="notes">Notas</label>
        <textarea name="notes" id="notes" rows="3" class="form-control">{{ old('notes', $rule->notes) }}</textarea>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 pl-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

<div class="card-footer d-flex justify-content-between">
    <a href="{{ route('tracking-event-rules.index') }}" class="btn btn-default">Volver</a>
    <button type="submit" class="btn btn-primary">Guardar</button>
</div>
