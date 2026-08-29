@include('errors.layout', [
    'statusCode' => 429,
    'heading' => 'Too Many Requests',
    'message' => 'You are doing that a little too quickly. Please wait a moment and try again.',
    'details' => 'ADVS temporarily paused repeated requests from this session.',
    'nextStep' => 'Pause briefly before repeating the action so ADVS can protect the application from overload.',
    'errorKey' => 'TOO_MANY_REQUESTS',
    'icon' => 'clock',
    'primaryAction' => [
        'label' => 'Try Again',
        'kind' => 'reload',
        'icon' => 'arrow-path',
    ],
    'secondaryAction' => [
        'label' => 'Back to Home',
        'href' => route('home'),
        'icon' => 'home',
    ],
])
