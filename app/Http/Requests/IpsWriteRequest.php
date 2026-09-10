<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IpsWriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()?->hasRole('admin')) {
            return false;
        }
        if ($this->is('api/*') && $this->input('event') === 'EMI') {
            return $this->user()->tokenCan('ips.deliver');
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => strtoupper(trim((string) ($this->route('codigo') ?? $this->input('codigo')))),
            'event' => $this->route()->defaults['ips_event'] ?? strtoupper(trim((string) $this->input('event'))),
            'idempotency_key' => $this->header('Idempotency-Key') ?? $this->input('idempotency_key'),
        ]);
    }

    public function rules(): array
    {
        $creating = ($this->route()->defaults['ips_action'] ?? '') === 'create';

        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'codigo' => ['required', 'string', 'max:35', 'regex:/^[A-Z0-9][A-Z0-9-]*$/'],
            'event' => ['required', Rule::in($creating ? ['EMA', 'EMD'] : array_keys(config('ips.events')))],
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'office_cd' => ['required', 'integer', 'min:1', 'max:32767'],
            'expected_event_cd' => [$creating ? 'prohibited' : 'required', 'integer'],
            'expected_event_at' => [$creating ? 'prohibited' : 'required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP'],
            'signatory' => [Rule::requiredIf($this->input('event') === 'EMI'), 'nullable', 'string', 'max:64'],
            'delivery_location' => ['nullable', 'string', 'max:25'],
            'non_delivery_reason' => [Rule::requiredIf($this->input('event') === 'EMH'), 'nullable', 'integer', 'min:1'],
            'non_delivery_measure' => [Rule::requiredIf($this->input('event') === 'EMH'), 'nullable', 'string', 'size:1'],
            'mail_class' => [$creating ? 'required' : 'prohibited', 'string', 'size:1'],
            'origin_country' => [$creating ? 'required' : 'prohibited', 'string', 'regex:/^[A-Z]{2}$/'],
            'destination_country' => [$creating ? 'required' : 'prohibited', 'string', 'regex:/^[A-Z]{2}$/'],
            'weight_kg' => [$creating ? 'required' : 'prohibited', 'numeric', 'gt:0', 'max:999.999', 'decimal:0,3'],
            'sender' => [$creating ? 'required' : 'prohibited', 'array:name,address,city,country,postcode,phone,email'],
            'recipient' => [$creating ? 'required' : 'prohibited', 'array:name,address,city,country,postcode,phone,email'],
            'sender.name' => ['required_with:sender', 'string', 'max:64'],
            'recipient.name' => ['required_with:recipient', 'string', 'max:64'],
            'sender.address' => ['required_with:sender', 'string', 'max:105'],
            'recipient.address' => ['required_with:recipient', 'string', 'max:105'],
            'sender.city' => ['required_with:sender', 'string', 'max:32'],
            'recipient.city' => ['required_with:recipient', 'string', 'max:32'],
            'sender.country' => ['required_with:sender', 'regex:/^[A-Z]{2}$/'],
            'recipient.country' => ['required_with:recipient', 'regex:/^[A-Z]{2}$/'],
            'sender.postcode' => ['nullable', 'string', 'max:20'],
            'recipient.postcode' => ['nullable', 'string', 'max:20'],
            'sender.phone' => ['nullable', 'string', 'max:64'],
            'recipient.phone' => ['nullable', 'string', 'max:64'],
            'sender.email' => ['nullable', 'email', 'max:64'],
            'recipient.email' => ['nullable', 'email', 'max:64'],
        ];
    }
}
