@php
    // The roster. Each model gets a portrait; drop the image path into
    // config/landing.php and it replaces the lettered placeholder here and in the
    // hero chip that model speaks through.
    $models = config('landing.models');
@endphp

<section class="bg-white py-14 md:py-20">
    <div class="mx-auto max-w-[1024px] px-6 lg:px-0">
        <p class="text-center font-jakarta text-2xl font-extrabold text-black md:text-[2rem]">
            Five models read every document
        </p>

        <p class="mx-auto mt-3 max-w-[460px] text-center font-jakarta text-[0.9375rem] text-ink/60">
            Each one does a single job, and each one has to agree before a submission clears.
        </p>

        <ul class="mt-12 grid grid-cols-2 gap-x-4 gap-y-10 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($models as $model)
                <li class="flex flex-col items-center text-center">
                    <x-landing.model-avatar
                        :model="$model"
                        class="size-24 text-lg md:size-[7.5rem] md:text-xl"
                    />

                    <span class="mt-4 font-jakarta text-[0.9375rem] font-extrabold text-black">
                        {{ $model['name'] }}
                    </span>
                    <span class="mt-1 font-jakarta text-[0.8125rem] text-ink/55">
                        {{ $model['role'] }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
</section>
