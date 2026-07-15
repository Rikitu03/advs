<?php

use App\Services\Signature\SignatureAuthenticityService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.auth')] class extends Component {
    use WithFileUploads;

    public $photo = null;

    /** Whether the last submission was flagged as software-edited. */
    public bool $rejected = false;

    /** @var list<string> */
    public array $reasons = [];

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            // 10 MB cap (CLAUDE.md §5); min dimensions guard image quality so a
            // tiny/blurry photo of three vertical signatures is rejected early.
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:10240', 'dimensions:min_width=600,min_height=800'],
        ];
    }

    /**
     * A new selection clears any prior rejection and validates the file.
     */
    public function updatedPhoto(): void
    {
        $this->rejected = false;
        $this->reasons = [];

        try {
            $this->validateOnly('photo');
        } catch (ValidationException $e) {
            // Drop the invalid temporary upload so a broken preview never
            // lingers, and surface the reason as a toast in addition to the
            // inline field error.
            $message = $e->validator->errors()->first('photo');

            $this->reset('photo');

            Flux::toast(
                text: $message,
                heading: __('Photo not accepted'),
                variant: 'danger',
            );

            throw $e;
        }
    }

    /**
     * Verify the uploaded signature photo and, if authentic, enroll it as the
     * vendor's reference before email verification.
     */
    public function enroll(SignatureAuthenticityService $authenticity): void
    {
        $this->validate();

        $result = $authenticity->verify($this->photo);

        if (! $result['authentic']) {
            $this->rejected = true;
            $this->reasons = $result['reasons'];
            $this->reset('photo');

            Flux::toast(
                text: __('We could not verify this image as an original capture. Please retake the photo and upload again.'),
                heading: __('Signature photo rejected'),
                variant: 'danger',
            );

            return;
        }

        $user = Auth::user();

        try {
            // extension() derives the extension from the detected MIME type;
            // never trust the client-supplied filename for the stored path.
            $path = $this->photo->storeAs(
                "signatures/{$user->id}",
                'reference-'.now()->timestamp.'.'.$this->photo->extension(),
                'local',
            );

            $user->forceFill([
                'signature_path' => $path,
                'signature_enrolled_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            report($e);

            Flux::toast(
                text: __('Something went wrong while saving your signature. Please try again.'),
                heading: __('Upload failed'),
                variant: 'danger',
            );

            return;
        }

        // Signature enrolled → now (and only now) send the email-verification
        // link. The user carries the freshly set signature_enrolled_at, so
        // User::sendEmailVerificationNotification() passes its enrollment guard
        // and sends synchronously (degrading gracefully on SMTP failure).
        $user->sendEmailVerificationNotification();

        session()->flash('status', 'signature-enrolled');

        $this->redirectRoute('verification.notice', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Enroll your signature')"
        :description="__('Step 3 of 4 — capture your reference signatures before verifying your email.')"
    />

    <x-auth.steps current="signature" />

    {{-- Capture guidance --}}
    <div class="rounded-2xl bg-ink-mute/50 p-4 ring-1 ring-black/5">
        <p class="mb-2.5 font-jakarta text-sm font-semibold text-ink">{{ __('How to capture your signatures') }}</p>
        <ul class="flex flex-col gap-2 font-jakarta text-xs leading-[1.5] text-ink/70">
            @foreach ([
                __('Sign 3 times, stacked vertically, on white bond paper.'),
                __('Photograph the whole page, straight-on, in good lighting.'),
                __('Use the original photo — no filters, cropping, or editing apps.'),
                __('JPG or PNG, up to 10 MB, at least 600 × 800 px.'),
            ] as $rule)
                <li class="flex items-start gap-2.5">
                    <svg class="mt-0.5 size-3.5 shrink-0 text-ink" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m3 8.5 3 3 7-8" />
                    </svg>
                    {{ $rule }}
                </li>
            @endforeach
        </ul>
    </div>

    {{-- Rejection notice --}}
    @if ($rejected)
        <div class="rounded-2xl bg-red-50 p-4 ring-1 ring-red-200" role="alert">
            <div class="flex items-center gap-2 font-jakarta text-sm font-semibold text-red-700">
                <flux:icon icon="x-circle" variant="micro" class="size-4 shrink-0" />
                {{ __('We could not verify this image as an original capture.') }}
            </div>
            <ul class="mt-2 flex flex-col gap-1 font-jakarta text-xs leading-[1.5] text-red-600">
                @foreach ($reasons as $reason)
                    <li class="flex items-start gap-2"><span class="mt-1.5 size-1 shrink-0 rounded-full bg-current"></span> {{ $reason }}</li>
                @endforeach
            </ul>
            <p class="mt-2 font-jakarta text-xs text-red-600">{{ __('Retake the photo of your original signatures and upload again.') }}</p>
        </div>
    @endif

    <form
        wire:submit="enroll"
        class="flex flex-col gap-4"
        x-data
        x-on:livewire-upload-error="$flux.toast({ heading: '{{ __('Upload failed') }}', text: '{{ __('We could not upload your photo. Check your connection and file size (max 10 MB), then try again.') }}', variant: 'danger' })"
    >
        {{-- Upload / preview --}}
        <div>
            <label
                for="signature-photo"
                class="group flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-ink/20 px-4 py-9 text-center transition hover:border-flame hover:bg-flame/5 focus-within:border-flame focus-within:ring-2 focus-within:ring-flame/30"
            >
                @if ($photo && $photo->isPreviewable())
                    <img src="{{ $photo->temporaryUrl() }}" alt="{{ __('Signature preview') }}" class="max-h-48 w-auto rounded-xl object-contain" />
                    <span class="font-jakarta text-xs text-ink/50">{{ __('Choose a different photo') }}</span>
                @else
                    <span class="flex size-11 items-center justify-center rounded-full bg-ink text-white transition-colors group-hover:bg-flame" aria-hidden="true">
                        <flux:icon icon="arrow-up-tray" variant="micro" class="size-5" />
                    </span>
                    <span class="font-jakarta text-sm font-semibold text-ink">{{ __('Upload signature photo') }}</span>
                    <span class="font-jakarta text-xs text-ink/50">{{ __('JPG or PNG · max 10 MB') }}</span>
                @endif
                {{-- Clearing the value on click lets the user re-pick the same
                     file after a rejection (the change event would not fire
                     otherwise, leaving the page looking unresponsive). --}}
                <input
                    id="signature-photo"
                    type="file"
                    wire:model="photo"
                    accept="image/jpeg,image/png"
                    class="sr-only"
                    x-on:click="$event.target.value = ''"
                />
            </label>

            <div wire:loading wire:target="photo" class="mt-2.5 flex items-center gap-2 font-jakarta text-xs text-ink/50">
                <flux:icon icon="arrow-path" variant="micro" class="size-3.5 shrink-0 animate-spin" />
                {{ __('Uploading…') }}
            </div>

            @error('photo')
                <p class="mt-2.5 font-jakarta text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <x-auth.submit wire:loading.attr="disabled" wire:target="enroll,photo">
            {{-- The label stays put and the spinner joins it, rather than the label
                 being swapped out for a bare spinner with nothing to read. --}}
            <flux:icon
                icon="arrow-path"
                variant="micro"
                class="size-4 shrink-0 animate-spin"
                wire:loading
                wire:target="enroll"
            />
            <span wire:loading.remove wire:target="enroll">{{ __('Verify & continue') }}</span>
            <span wire:loading wire:target="enroll">{{ __('Verifying…') }}</span>
        </x-auth.submit>
    </form>

    <div class="text-center">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button
                type="submit"
                class="cursor-pointer font-jakarta text-sm font-semibold text-ink/60 underline-offset-4 transition-colors hover:text-flame hover:underline"
            >
                {{ __('Log out') }}
            </button>
        </form>
    </div>
</div>
