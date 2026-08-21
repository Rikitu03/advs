<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        return [
            'vendor' => Auth::user()->vendor?->load('representative'),
        ];
    }
}; ?>

<x-page>
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 text-cu-text">
        @if ($vendor)
            <div class="rounded-2xl border border-cu-border bg-cu-surface p-5">
                <flux:heading size="lg">{{ __('Declared information') }}</flux:heading>
                <p class="text-xs text-cu-muted">{{ __('What your uploaded documents are cross-checked against. Contact a compliance officer to correct any of these.') }}</p>

                <h3 class="mt-5 text-sm font-semibold text-cu-text">{{ __('Business') }}</h3>
                <dl class="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Registered business name') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->company_name }}</dd>
                    </div>
                    @if ($vendor->trade_name)
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Trade name') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->trade_name }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Entity type') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ \App\Models\Vendor::BUSINESS_ENTITY_TYPES[$vendor->business_entity_type] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('TIN') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->tin ?? '—' }}</dd>
                    </div>
                    @if ($vendor->dti_registration_number)
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('DTI Registration No.') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->dti_registration_number }}</dd>
                        </div>
                    @endif
                    @if ($vendor->sec_registration_number)
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('SEC Registration No.') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->sec_registration_number }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Business Permit No.') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->business_permit_number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-cu-muted">{{ __('Nature of business') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->nature_of_business ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-cu-muted">{{ __('Business address') }}</dt>
                        <dd class="mt-0.5 text-sm">{{ $vendor->business_address ?: '—' }}</dd>
                    </div>
                </dl>

                @if ($vendor->representative)
                    <h3 class="mt-6 text-sm font-semibold text-cu-text">{{ __('Owner / representative') }}</h3>
                    <dl class="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Full name') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->representative->fullName() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Date of birth') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->representative->date_of_birth?->format('F j, Y') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Gender') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ \App\Models\VendorRepresentative::GENDERS[$vendor->representative->gender] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-cu-muted">{{ __('Contact number') }}</dt>
                            <dd class="mt-0.5 text-sm">{{ $vendor->representative->contact_number }}</dd>
                        </div>
                        @if ($vendor->representative->government_id_type || $vendor->representative->government_id_number)
                            <div>
                                <dt class="text-xs text-cu-muted">{{ __('Government ID') }}</dt>
                                <dd class="mt-0.5 text-sm">
                                    {{ \App\Models\VendorRepresentative::GOVERNMENT_ID_TYPES[$vendor->representative->government_id_type] ?? '—' }}
                                    @if ($vendor->representative->government_id_number)
                                        · {{ $vendor->representative->government_id_number }}
                                    @endif
                                </dd>
                            </div>
                        @endif
                        @if ($vendor->representative->home_address)
                            <div class="sm:col-span-2">
                                <dt class="text-xs text-cu-muted">{{ __('Home address') }}</dt>
                                <dd class="mt-0.5 text-sm">{{ $vendor->representative->home_address }}</dd>
                            </div>
                        @endif
                    </dl>
                @endif
            </div>
        @endif

        <livewire:settings.profile />
    </div>
</x-page>
