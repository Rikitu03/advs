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
    public string $government_id_type = '';
    public string $government_id_number = '';
    public string $home_address = '';

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
            'government_id_type' => ['required', Rule::in(array_keys(VendorRepresentative::GOVERNMENT_ID_TYPES))],
            'government_id_number' => ['required', 'string', 'max:60'],
            'home_address' => ['required', 'string', 'max:500'],
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

    {{-- Step indicator --}}
    <ol class="flex items-center gap-2 text-xs font-medium">
        <li class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
            <flux:icon icon="check-circle" variant="micro" class="size-4" /> {{ __('Account') }}
        </li>
        <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
        <li class="flex items-center gap-1.5 text-cu-purple">
            <span class="flex size-4 items-center justify-center rounded-full bg-cu-purple text-[10px] text-white">2</span>
            {{ __('Details') }}
        </li>
        <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
        <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
            <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">3</span>
            {{ __('Signature') }}
        </li>
        <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
        <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
            <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">4</span>
            {{ __('Verify') }}
        </li>
    </ol>

    <form wire:submit="save" class="flex flex-col gap-6">
        {{-- Business information --}}
        <section class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Business information') }}</flux:heading>

            <flux:input wire:model="company_name" :label="__('Registered business name')" required />
            <flux:input wire:model="trade_name" :label="__('Trade name / DBA (optional)')" />

            <flux:select wire:model.live="business_entity_type" :label="__('Type of business entity')" :placeholder="__('Select entity type')" required>
                @foreach (\App\Models\Vendor::BUSINESS_ENTITY_TYPES as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="tin" :label="__('TIN (Tax Identification Number)')" placeholder="123-456-789-000" required />

            @if ($this->requiresDti())
                <flux:input wire:model="dti_registration_number" :label="__('DTI Registration Number')" required />
            @endif

            @if ($this->requiresSec())
                <flux:input wire:model="sec_registration_number" :label="__('SEC Registration Number')" required />
            @endif

            <flux:input wire:model="business_permit_number" :label="__('Business Permit Number')" required />
            <flux:input wire:model="nature_of_business" :label="__('Nature / line of business')" required />

            <flux:heading size="sm" class="mt-2">{{ __('Business address') }}</flux:heading>
            <flux:input wire:model="business_street" :label="__('Street')" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="business_barangay" :label="__('Barangay')" required />
                <flux:input wire:model="business_city" :label="__('City / Municipality')" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="business_province" :label="__('Province')" required />
                <flux:input wire:model="business_postal_code" :label="__('ZIP / Postal code')" required />
            </div>
        </section>

        {{-- Owner / representative --}}
        <section class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Owner / authorized representative') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="first_name" :label="__('First name')" required />
                <flux:input wire:model="middle_name" :label="__('Middle name (optional)')" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="last_name" :label="__('Last name')" required />
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

            <flux:input wire:model="contact_number" :label="__('Contact number')" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="government_id_type" :label="__('Government ID type')" :placeholder="__('Select ID type')" required>
                    @foreach (\App\Models\VendorRepresentative::GOVERNMENT_ID_TYPES as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="government_id_number" :label="__('Government ID number')" required />
            </div>

            <flux:textarea wire:model="home_address" :label="__('Home address (complete)')" rows="2" required />
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
