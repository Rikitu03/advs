<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Page Layout
    |---------------------------------------------------------------------------
    |
    | The view used as the layout when rendering a Volt/Livewire component as a
    | full page. Livewire v4 defaults this to the "layouts::app" namespace, but
    | this starter kit keeps its layout as the anonymous Blade component at
    | resources/views/components/layouts/app.blade.php. Point Livewire back at
    | it so full-page Volt components (e.g. the settings pages) render correctly.
    |
    | All other Livewire options are inherited from the package defaults via
    | mergeConfigFrom(); only this key is overridden here.
    |
    */

    'component_layout' => 'components.layouts.app',

];
