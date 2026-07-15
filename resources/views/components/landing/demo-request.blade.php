{{-- The white dome closing the page. There is no demo-request backend, so the
     form composes a prefilled message to the support inbox rather than pretending
     to submit somewhere. --}}
<div class="landing-dome bg-white px-6 pt-16 pb-14 text-center md:pt-20">
    <h2 class="font-jakarta text-xl font-bold text-ink md:text-2xl">
        Request a demo here.
    </h2>

    <form
        x-data="{ email: '' }"
        x-on:submit.prevent="window.location.href = 'mailto:advs_support@gmail.com'
            + '?subject=' + encodeURIComponent('ADVS demo request')
            + '&body=' + encodeURIComponent('Please get in touch with me about a demo.\n\nMy email: ' + email)"
        class="mx-auto mt-6 flex w-full max-w-[449px] flex-col gap-3 sm:flex-row sm:items-center sm:rounded-[20px] sm:border sm:border-ink sm:py-2 sm:pr-2 sm:pl-6"
    >
        <label for="demo-email" class="sr-only">Your email address</label>

        <input
            id="demo-email"
            type="email"
            required
            x-model="email"
            placeholder="Enter email"
            class="h-[60px] w-full rounded-[20px] border border-ink bg-transparent px-6 font-jakarta text-base text-ink placeholder:text-ink/45 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink sm:h-11 sm:rounded-none sm:border-0 sm:px-0"
        >

        <button
            type="submit"
            class="h-[52px] shrink-0 rounded-[20px] bg-ink px-7 font-jakarta text-[0.9375rem] font-semibold text-white transition hover:bg-ink-panel focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink sm:h-11 sm:rounded-[14px]"
        >
            Request a demo
        </button>
    </form>
</div>
