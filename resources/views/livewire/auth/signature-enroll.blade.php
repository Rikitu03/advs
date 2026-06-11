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

        session()->flash('status', 'signature-enrolled');

        $this->redirectRoute('verification.notice', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Enroll your signature')"
        :description="__('Step 2 of 3 — capture your reference signatures before verifying your email.')"
    />

    {{-- Step indicator --}}
    <ol class="flex items-center gap-2 text-xs font-medium">
        <li class="flex items-center gap-1.5 text-emerald-600 dark:text-emerald-400">
            <flux:icon icon="check-circle" variant="micro" class="size-4" /> {{ __('Account') }}
        </li>
        <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
        <li class="flex items-center gap-1.5 text-cu-purple">
            <span class="flex size-4 items-center justify-center rounded-full bg-cu-purple text-[10px] text-white">2</span>
            {{ __('Signature') }}
        </li>
        <li class="h-px flex-1 bg-zinc-200 dark:bg-zinc-700"></li>
        <li class="flex items-center gap-1.5 text-zinc-400 dark:text-zinc-500">
            <span class="flex size-4 items-center justify-center rounded-full border border-current text-[10px]">3</span>
            {{ __('Verify email') }}
        </li>
    </ol>

    {{-- Capture guidance --}}
    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
        <p class="mb-2 text-sm font-medium">{{ __('How to capture your signatures') }}</p>
        <ul class="flex flex-col gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
            <li class="flex items-start gap-2"><flux:icon icon="check" variant="micro" class="mt-0.5 size-3.5 shrink-0 text-emerald-500" /> {{ __('Sign 3 times, stacked vertically, on white bond paper.') }}</li>
            <li class="flex items-start gap-2"><flux:icon icon="check" variant="micro" class="mt-0.5 size-3.5 shrink-0 text-emerald-500" /> {{ __('Photograph the whole page, straight-on, in good lighting.') }}</li>
            <li class="flex items-start gap-2"><flux:icon icon="check" variant="micro" class="mt-0.5 size-3.5 shrink-0 text-emerald-500" /> {{ __('Use the original photo — no filters, cropping, or editing apps.') }}</li>
            <li class="flex items-start gap-2"><flux:icon icon="check" variant="micro" class="mt-0.5 size-3.5 shrink-0 text-emerald-500" /> {{ __('JPG or PNG, up to 10 MB, at least 600 × 800 px.') }}</li>
        </ul>
    </div>

    {{-- Rejection notice --}}
    @if ($rejected)
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-500/30 dark:bg-red-500/10" role="alert">
            <div class="flex items-center gap-2 text-sm font-medium text-red-700 dark:text-red-300">
                <flux:icon icon="x-circle" variant="micro" class="size-4" />
                {{ __('We could not verify this image as an original capture.') }}
            </div>
            <ul class="mt-2 flex flex-col gap-1 text-xs text-red-600 dark:text-red-300/90">
                @foreach ($reasons as $reason)
                    <li class="flex items-start gap-2"><span class="mt-1 size-1 shrink-0 rounded-full bg-current"></span> {{ $reason }}</li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs text-red-600 dark:text-red-300/90">{{ __('Please retake the photo of your original signatures and upload again.') }}</p>
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
                class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-zinc-300 px-4 py-8 text-center transition hover:border-cu-purple dark:border-zinc-600 dark:hover:border-cu-purple"
            >
                @if ($photo && $photo->isPreviewable())
                    <img src="{{ $photo->temporaryUrl() }}" alt="{{ __('Signature preview') }}" class="max-h-48 w-auto rounded-lg object-contain" />
                    <span class="text-xs text-zinc-500">{{ __('Tap to choose a different photo') }}</span>
                @else
                    <flux:icon icon="arrow-up-tray" class="size-7 text-zinc-400" />
                    <span class="text-sm font-medium">{{ __('Upload signature photo') }}</span>
                    <span class="text-xs text-zinc-500">{{ __('JPG or PNG · max 10 MB') }}</span>
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

            <div wire:loading wire:target="photo" class="mt-2 flex items-center gap-2 text-xs text-zinc-500">
                <flux:icon icon="arrow-path" variant="micro" class="size-3.5 animate-spin" /> {{ __('Uploading…') }}
            </div>

            @error('photo')
                <p class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled" wire:target="enroll,photo">
            <span wire:loading.remove wire:target="enroll">{{ __('Verify & continue') }}</span>
            <span wire:loading wire:target="enroll">{{ __('Verifying…') }}</span>
        </flux:button>
    </form>

    <div class="text-center">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <flux:link as="button" type="submit" class="cursor-pointer text-sm">{{ __('Log out') }}</flux:link>
        </form>
    </div>
</div>
