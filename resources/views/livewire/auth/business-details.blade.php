<?php

use App\Actions\Vendor\CreateVendorProfile;
use App\Models\Vendor;
use App\Models\VendorRepresentative;
use App\Support\RegistrationNumberValidator;
use App\Support\TinValidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    // Business
    public string $company_name = '';
    public string $trade_name = '';
    public string $business_entity_type = '';
    public string $tin = '';
    public string $dti_registration_number = '';
    public string $sec_registration_number = '';
    public string $business_permit_number = '';
    public string $nature_of_business = '';
    public string $business_street = '';
    public string $business_barangay = '';
    public string $business_city = '';
    public string $business_province = '';
    public string $business_postal_code = '';

    // Owner / representative
    public string $first_name = '';
    public string $middle_name = '';
    public string $last_name = '';
    public string $suffix = '';
    public string $date_of_birth = '';
    public string $gender = '';
    public string $contact_number = '';

    public function requiresDti(): bool
    {
        return Vendor::entityRequiresDti($this->business_entity_type);
    }

    public function requiresSec(): bool
    {
        return Vendor::entityRequiresSec($this->business_entity_type);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:150'],
            'trade_name' => ['nullable', 'string', 'max:150'],
            'business_entity_type' => ['required', Rule::in(array_keys(Vendor::BUSINESS_ENTITY_TYPES))],
            'tin' => ['required', 'string', 'max:20', $this->tinRule()],
            'dti_registration_number' => ['nullable', 'required_if:business_entity_type,sole_proprietorship', 'string', 'max:50', $this->registrationNumberRule()],
            'sec_registration_number' => ['nullable', 'required_if:business_entity_type,partnership,corporation', 'string', 'max:50', $this->registrationNumberRule()],
            'business_permit_number' => ['required', 'string', 'max:50'],
            'nature_of_business' => ['required', 'string', 'max:150'],
            'business_street' => ['required', 'string', 'max:255'],
            'business_barangay' => ['required', 'string', 'max:120'],
            'business_city' => ['required', 'string', 'max:120'],
            'business_province' => ['required', 'string', 'max:120'],
            'business_postal_code' => ['required', 'string', 'max:10'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(array_keys(VendorRepresentative::GENDERS))],
            'contact_number' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * Closure rule: a declared TIN must be a well-formed Philippine TIN.
     */
    protected function tinRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! TinValidator::isValid((string) $value)) {
                $fail(__('Enter a valid TIN (9–14 digits, e.g. 123-456-789-000).'));
            }
        };
    }

    /**
     * Closure rule: validate a DTI/SEC certificate number's format, but only when
     * a value was supplied (the field is conditional on entity type).
     */
    protected function registrationNumberRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (filled($value) && ! RegistrationNumberValidator::isValid((string) $value)) {
                $fail(__('Enter a valid registration number (at least 5 digits).'));
            }
        };
    }

    public function save(CreateVendorProfile $action): void
    {
        $validated = $this->validate();

        $action->execute(Auth::user(), $validated);

        $this->redirectRoute('signature.create', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Business & owner details')"
        :description="__('Step 2 of 4 — declare your business and representative information. This is what we cross-check your uploaded documents against.')"
    />

    <x-auth.steps current="business" />

    <form wire:submit="save" class="flex flex-col gap-6">
        {{-- Business information --}}
        <section class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Business information') }}</flux:heading>

            <flux:input wire:model="company_name" :label="__('Registered business name')" placeholder="e.g. Acme Logistics, Inc." required />
            <flux:input wire:model="trade_name" :label="__('Trade name / DBA (optional)')" placeholder="e.g. Acme Express (optional)" />

            <flux:select wire:model.live="business_entity_type" :label="__('Type of business entity')" :placeholder="__('Select entity type')" required>
                @foreach (\App\Models\Vendor::BUSINESS_ENTITY_TYPES as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="tin" :label="__('TIN (Tax Identification Number)')" placeholder="123-456-789-000" required />

            @if ($this->requiresDti())
                <flux:input wire:model="dti_registration_number" :label="__('DTI Registration Number')" placeholder="e.g. 12345678" required />
            @endif

            @if ($this->requiresSec())
                <flux:input wire:model="sec_registration_number" :label="__('SEC Registration Number')" placeholder="e.g. CS2001234567" required />
            @endif

            <flux:input wire:model="business_permit_number" :label="__('Business Permit Number')" placeholder="e.g. BP-2024-000123" required />
            <flux:input wire:model="nature_of_business" :label="__('Nature / line of business')" placeholder="e.g. Freight forwarding and logistics" required />

            <flux:heading size="sm" class="mt-2">{{ __('Business address') }}</flux:heading>
            <flux:input wire:model="business_street" :label="__('Street')" placeholder="e.g. 123 Main Street, Makati Commercial Center" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="business_barangay" :label="__('Barangay')" placeholder="e.g. Barangay Bel-Air" required />
                <flux:input wire:model="business_city" :label="__('City / Municipality')" placeholder="e.g. Makati City" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="business_province" :label="__('Province')" placeholder="e.g. Metro Manila" required />
                <flux:input wire:model="business_postal_code" :label="__('ZIP / Postal code')" placeholder="e.g. 1209" required />
            </div>
        </section>

        {{-- Owner / representative --}}
        <section class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Owner / authorized representative') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="first_name" :label="__('First name')" placeholder="e.g. Juan" required />
                <flux:input wire:model="middle_name" :label="__('Middle name (optional)')" placeholder="e.g. Santos (optional)" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="last_name" :label="__('Last name')" placeholder="e.g. dela Cruz" required />
                <flux:input wire:model="suffix" :label="__('Suffix (optional)')" placeholder="Jr., Sr., III" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="date_of_birth" type="date" :label="__('Date of birth')" required />
                <flux:select wire:model="gender" :label="__('Gender')" :placeholder="__('Select')" required>
                    @foreach (\App\Models\VendorRepresentative::GENDERS as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input wire:model="contact_number" :label="__('Contact number')" placeholder="e.g. +63 912 345 6789" required />
        </section>

        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">{{ __('Save & continue') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </flux:button>
    </form>

    <div class="text-center">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:link as="button" type="submit" class="cursor-pointer text-sm">{{ __('Log out') }}</flux:link>
        </form>
    </div>
</div>
