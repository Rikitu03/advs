@include('errors.layout', [
    'statusCode' => 401,
    'heading' => 'Authentication Required',
    'message' => 'You need to sign in before you can access this page.',
    'details' => 'This page is only available to signed-in ADVS users.',
    'nextStep' => 'Sign in with your ADVS account, then continue to the protected workspace.',
    'errorKey' => 'AUTHENTICATION_REQUIRED',
    'icon' => 'lock-closed',
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
