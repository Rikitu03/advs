@include('errors.layout', [
    'statusCode' => 419,
    'heading' => 'Session Expired',
    'message' => 'Your session has expired. Please refresh the page and try again.',
    'details' => 'The form session is no longer active, so ADVS stopped the request before saving anything.',
    'nextStep' => 'Sign in again to restore your session before continuing.',
    'errorKey' => 'SESSION_EXPIRED',
    'icon' => 'clock',
    'primaryAction' => [
        'label' => 'Sign In',
        'href' => route('login'),
        'icon' => 'arrow-right-end-on-rectangle',
    ],
    'secondaryAction' => [
        'label' => 'Back to Home',
        'href' => route('home'),
        'icon' => 'home',
    ],
])
