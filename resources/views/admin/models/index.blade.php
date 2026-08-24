{{-- Admin ML Model Management — full-page Volt component mount.
     The interactive table lives in resources/views/livewire/admin/models/index.blade.php;
     this Blade shell exists only to satisfy the controller->view() contract used
     by other admin pages (e.g. admin.users.index). Volt's #[Layout] attribute on
     the component overrides this and renders inside the app shell. --}}
@extends('components.layouts.app')

@section('content')
    <livewire:admin.models.index />
@endsection